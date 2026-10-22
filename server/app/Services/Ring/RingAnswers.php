<?php

declare(strict_types=1);

namespace App\Services\Ring;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Api\V1\Guard\ResponseController;
use App\Jobs\Ring\SendRingPush;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertRingEvent as Event;
use App\Models\DuressAlertRingTarget as Target;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Dispatch\AlertProgress;
use App\Services\Dispatch\AlertProgressRefused;
use App\Support\CentralQueue;
use App\Support\SessionWindow;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WHAT A RUNG PHONE MAY SAY BACK — "I'm responding", "can't respond", and its receipts —
 * on the ring ticket, not the bearer token (the guard ring §6.5, §6.6).
 *
 * "I'M RESPONDING" IS THE EXISTING CLAIM, and nothing else: `AlertProgress::enRoute()`,
 * the very call `alerts.en_route` makes. The same refusals, the same writes, the same
 * audit entry, the resident's "an officer is on the way", the live channel — and the
 * ring's stop, off the row's own save. `acknowledged_at` stays the control room's act.
 *
 * IT IS REFUSED WHEN IT IS STALE (G10). A tap made with no signal and sent twenty minutes
 * later would tell a resident an officer is on the way, make this guard the only handset
 * that can record the response, and stop every other phone ringing — after the guard has
 * probably radioed or moved on. How long ago is measured on the phone's own monotonic
 * clock, so a phone whose clock is wrong can neither make a fresh tap stale nor a stale one
 * fresh (the lesson of B066).
 *
 * THE ESTATE IS OPENED AND THE GUARD IS THE ACTOR, exactly as a bearer request leaves them
 * (`ResolveDevice`, `auth:sanctum`) — so the audit reads "Marcus Grant · Guard App
 * handset", never "the system" (G23) — and both are put back in `finally`.
 *
 * IT NEVER TOUCHES THE SESSION. The idle window is not widened and a ring does not
 * reopen it (ruling R3): `session_open` is read, and `last_used_at` is never written.
 */
final class RingAnswers
{
    public const RESPONDING = 'responding';

    public const CANNOT = 'cannot';

    /** "Can't respond" from the lock screen: this round only (ruling R10b). */
    public const SCOPE_ROUND = 'round';

    /** "Can't respond" confirmed after unlocking: every later round too. */
    public const SCOPE_ALERT = 'alert';

    public const STALE = 'ring_answer_stale';

    public const ALREADY_YOURS = 'already_yours';

    public function __construct(
        private readonly AlertProgress $progress,
        private readonly AuditLogger $audit,
        private readonly GuardRing $ring,
    ) {}

    /**
     * Who is speaking: the ticket, and then the rows behind it — still true now.
     *
     * @return array{claims: array{a: int, t: string, g: int, p: int, r: int, s: int, i: int, k: string}, token: DevicePushToken, guard: Guard, alert: DuressAlert|null}
     *
     * @throws ApiError
     */
    public function caller(mixed $ticket): array
    {
        $claims = RingTicket::verify($ticket);

        $token = DevicePushToken::query()->live()
            ->whereKey($claims['p'])
            ->where('principal_type', DevicePushToken::GUARD)
            ->where('principal_id', $claims['g'])
            ->where('tenant_id', $claims['t'])
            ->where('app', AppMatrix::GUARD)
            ->first();

        $guard = $token === null ? null : Guard::query()
            ->whereKey($claims['g'])
            ->where('tenant_id', $claims['t'])
            ->where('status', 'active')
            ->whereNotNull('device_id')
            ->first();

        if ($token === null || $guard === null) {
            throw RingTicket::invalid();
        }

        $alert = null;

        if ($claims['k'] === RingTicket::RING) {
            $alert = DuressAlert::query()->with(['estate', 'respondingGuard'])
                ->whereKey($claims['a'])
                ->where('tenant_id', $claims['t'])
                ->first();

            // THE SAME ANSWER AS A WRONG ID: no estate learns that another's alert exists.
            if ($alert === null || ! Target::query()->where('duress_alert_id', $claims['a'])->where('guard_id', $claims['g'])->exists()) {
                throw RingTicket::invalid();
            }
        }

        return ['claims' => $claims, 'token' => $token, 'guard' => $guard, 'alert' => $alert];
    }

    /**
     * "I'm responding" or "can't respond".
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    public function answer(mixed $ticket, string $answer, ?int $ageMs, string $scope, ?Carbon $deviceTime): array
    {
        $caller = $this->caller($ticket);
        ['claims' => $claims, 'token' => $token, 'guard' => $guard, 'alert' => $alert] = $caller;

        if ($alert === null) {
            throw new ApiError(403, RingTicket::INVALID, 'A test ring cannot answer an alert.');
        }

        $event = ['duress_alert_id' => (int) $alert->id, 'tenant_id' => (string) $alert->tenant_id, 'round' => $claims['r'], 'guard_id' => (int) $guard->id, 'push_token_id' => (int) $token->id];

        // G10: measured on the phone's monotonic clock, never against the phone's wall clock.
        if ($answer === self::RESPONDING && ($ageMs ?? 0) > max(1, (int) config('ring.answer_stale_seconds', 120)) * 1000) {
            Event::once([...$event, 'event' => Event::REFUSED, 'detail' => 'stale']);

            throw new ApiError(409, self::STALE, 'This was tapped more than '.self::minutes((int) config('ring.answer_stale_seconds', 120)).' ago and never reached the server, so it was not recorded. Radio dispatch to say whether you are going.');
        }

        return $this->asTheGuard($guard, $deviceTime, function () use ($answer, $scope, $alert, $guard, $token, $claims, $event, $ageMs, $deviceTime): array {
            if ($answer === self::CANNOT) {
                return $this->cannot($alert, $guard, $token, $claims, $event, $scope);
            }

            $code = null;

            try {
                $alert = $this->progress->enRoute($alert, $guard, $deviceTime);
            } catch (AlertProgressRefused $refused) {
                $alert->refresh();

                // A replay of this guard's own claim, or their bearer app got there first.
                $mine = in_array($refused->errorCode, [AlertProgressRefused::ALREADY_EN_ROUTE, AlertProgressRefused::ALREADY_ON_SCENE], true)
                    && (int) $alert->responding_guard_id === (int) $guard->id;

                if (! $mine) {
                    throw new ApiError($refused->status, $refused->errorCode, $refused->getMessage());
                }

                $code = self::ALREADY_YOURS;
            }

            Event::once([...$event, 'event' => Event::ANSWERED_RESPONDING]);

            rescue(fn () => $this->audit->record(
                action: 'alert.ring_answered',
                entityType: 'DuressAlert',
                entityId: (string) $alert->id,
                after: ['answer' => self::RESPONDING, 'round' => $claims['r'], 'push_token_id' => (int) $token->id, 'age_ms' => $ageMs, 'code' => $code, 'device_time' => $deviceTime?->toIso8601String()],
                tenantId: (string) $alert->tenant_id,
            ), report: false);

            $alert->loadMissing(['respondingGuard', 'estate']);

            return [
                'recorded' => true,
                'answer' => self::RESPONDING,
                'code' => $code,
                'session_open' => self::sessionOpen($token),
                'alert' => ResponseController::shape($alert, $guard, $this->progress),
            ];
        });
    }

    /**
     * A receipt from the phone: what it did with a ring, and — beside it — its readiness now.
     *
     * @throws ApiError
     */
    public function receipt(mixed $ticket, string $event, ?string $detail, mixed $ready): void
    {
        ['claims' => $claims, 'token' => $token, 'guard' => $guard] = $this->caller($ticket);

        $test = $claims['k'] === RingTicket::TEST;
        $clean = $detail === null ? null : trim((string) preg_replace('/[^\x20-\x7E]/', '', $detail));

        Event::once([
            'duress_alert_id' => $test ? 0 : $claims['a'],
            'tenant_id' => $claims['t'],
            'round' => $claims['r'],
            'test_seq' => $claims['s'],
            'guard_id' => (int) $guard->id,
            'push_token_id' => (int) $token->id,
            'event' => $event,
            'detail' => $clean === '' ? null : $clean,
        ]);

        $now = Carbon::now();
        $changes = [];

        if ($event === Event::SHOWN) {
            $changes['ring_last_shown_at'] = $now;

            if ($test) {
                $changes['ring_proven_at'] = $now;
            }
        }

        // G15: every receipt carries the phone's readiness at that moment, so the console's is never days old.
        $report = RingReadiness::fromBits($ready, is_array($token->ring_readiness) ? $token->ring_readiness : null);

        if ($report !== null) {
            $changes['ring_readiness'] = $report;
            $changes['ring_state'] = RingReadiness::state($report);
            $changes['ring_checked_at'] = $now;
        }

        if ($changes !== []) {
            $token->forceFill($changes)->save();
        }
    }

    /**
     * A test ring to THIS sign-in's phone, and nobody else's. Queued on the panic queue.
     *
     * @return array{sent: bool, ring_state: string|null}
     *
     * @throws ApiError
     */
    public function test(DeviceContext $context, ?int $accessTokenId): array
    {
        $guard = $context->guardOrFail();

        $token = DevicePushToken::query()->live()
            ->ownedBy($context->tenantId, DevicePushToken::GUARD, (int) $guard->id)
            ->where('app', AppMatrix::GUARD)
            ->when($accessTokenId !== null, static fn ($q) => $q->where('access_token_id', $accessTokenId))
            ->latest('id')
            ->first();

        if ($token === null) {
            throw ApiError::conflict('no_push_address', 'This phone has no push address yet: allow notifications, then try again.');
        }

        if (! RingSender::capable($token)) {
            throw ApiError::conflict('ring_not_supported', 'This phone has not reported call-style alerts: update the Guard App.');
        }

        /*
         * EACH TEST ITS OWN NUMBER (G24), so a second test a minute later sends and records like
         * the first. Claimed by writing the request down — `test_requested`, a true fact about
         * the phone — before the send is queued; a number another request took first is skipped.
         */
        $seq = (int) Event::query()->where('duress_alert_id', 0)->where('push_token_id', $token->id)->max('test_seq');

        do {
            $seq++;
            $claimed = Event::once(['duress_alert_id' => 0, 'tenant_id' => $context->tenantId, 'round' => 0, 'test_seq' => $seq, 'guard_id' => (int) $guard->id, 'push_token_id' => (int) $token->id, 'event' => Event::TEST_REQUESTED]);
        } while (! $claimed && $seq < 10_000);

        $job = CentralQueue::pin(new SendRingPush([(int) $token->id], 0, 0, SendRingPush::TEST, $seq));
        $queue = RingQueue::name();

        // The ring's own queue, as a real ring is (GuardRing::queue): a test that waits behind mail proves nothing.
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);

        return ['sent' => true, 'ring_state' => $token->ring_state];
    }

    /**
     * "Can't respond": for the whole alert, or — from the lock screen — for this round only.
     *
     * @param  array{a: int, t: string, g: int, p: int, r: int, s: int, i: int, k: string}  $claims
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function cannot(DuressAlert $alert, Guard $guard, DevicePushToken $token, array $claims, array $event, string $scope): array
    {
        $scope = $scope === self::SCOPE_ROUND ? self::SCOPE_ROUND : self::SCOPE_ALERT;

        Event::once([...$event, 'event' => Event::ANSWERED_CANNOT, 'detail' => $scope]);

        if ($scope === self::SCOPE_ALERT) {
            Target::query()->where('duress_alert_id', (int) $alert->id)->where('guard_id', (int) $guard->id)->whereNull('answer')
                ->update(['answer' => Target::CANNOT, 'answered_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        }

        rescue(fn () => $this->audit->record(
            action: 'alert.ring_declined',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            after: ['scope' => $scope, 'round' => $claims['r'], 'push_token_id' => (int) $token->id],
            tenantId: (string) $alert->tenant_id,
        ), report: false);

        /*
         * EVERY OFFICER RUNG AT THE RAISE SAID THEY CANNOT: the escalation goes now, not at
         * 60 s. A lock-screen decline does not count — it is a pocket press as often as not.
         */
        if ($scope === self::SCOPE_ALERT) {
            $raise = Target::query()->where('duress_alert_id', (int) $alert->id)->where('first_round', 0)->get(['answer']);

            if ($raise->isNotEmpty() && $raise->every(static fn (Target $t): bool => $t->answer === Target::CANNOT) && RingRule::answered($alert) === null) {
                $this->ring->escalateNow($alert);
            }
        }

        return ['recorded' => true, 'answer' => self::CANNOT, 'code' => null, 'session_open' => self::sessionOpen($token)];
    }

    /**
     * Run this as the guard, at their estate — as a bearer request would — and put both back.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function asTheGuard(Guard $guard, ?Carbon $deviceTime, callable $work): mixed
    {
        $opened = false;
        $previousGuard = Auth::getDefaultDriver();

        try {
            $estate = Tenant::query()->find((string) $guard->tenant_id);

            if ($estate instanceof Tenant && tenant()?->getTenantKey() !== $estate->getTenantKey()) {
                tenancy()->initialize($estate);
                $opened = true;
            }

            app()->instance(DeviceContext::class, new DeviceContext(AppMatrix::GUARD, (string) $guard->tenant_id, $guard, null, Carbon::now(), $deviceTime));

            // G23: the audit's actor is the guard, "Guard App handset", as `auth:sanctum` leaves a bearer request.
            Auth::shouldUse('sanctum');
            Auth::guard('sanctum')->setUser($guard);

            return $work();
        } finally {
            $sanctum = Auth::guard('sanctum');

            // The request guard keeps the user it was handed; nothing after this request may inherit it.
            if (method_exists($sanctum, 'forgetUser')) {
                $sanctum->forgetUser();
            }

            Auth::shouldUse($previousGuard);
            app()->forgetInstance(DeviceContext::class);

            if ($opened) {
                tenancy()->end();
            }
        }
    }

    /**
     * Whether the Guard App's own session on this phone is still open — READ, never renewed.
     * A ring answered on a timed-out phone cannot open the alert there; the phone then says so.
     */
    public static function sessionOpen(DevicePushToken $token): bool
    {
        if ($token->access_token_id === null) {
            return false;
        }

        try {
            $row = DB::connection('mysql')->table('personal_access_tokens')->where('id', (int) $token->access_token_id)->first(['last_used_at', 'created_at', 'tokenable_type', 'tokenable_id']);
        } catch (Throwable) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        $at = static fn (mixed $value): ?CarbonInterface => $value === null ? null : Carbon::parse((string) $value, 'UTC');
        // The same rule as a request (M22 §1.3): a guard on shift is signed in for the shift.
        $onDuty = ($row->tokenable_type ?? null) === Guard::class ? Shift::onDutyUntil((int) $row->tokenable_id) : null;

        return ! SessionWindow::expired(SessionWindow::lastTouched($at($row->last_used_at ?? null), $at($row->created_at ?? null), $onDuty), Carbon::now());
    }

    private static function minutes(int $seconds): string
    {
        return $seconds % 60 === 0 ? ($seconds / 60).' minute'.($seconds === 60 ? '' : 's') : $seconds.' seconds';
    }
}
