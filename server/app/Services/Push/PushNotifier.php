<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE ONE PLACE A PUSH IS DECIDED (M4 §3.2): who is told, whether their phone
 * wants it, whether they were told a moment ago — and then a queued job.
 *
 * THE ENTRY POINTS, for any package that has something to say to a closed app:
 *
 *   toGuard($estate, $guardId, $message)          one guard's handset
 *   toGuards($estate, $guardIds, $message)        several
 *   toGuardsOnDuty($estate, $message, $except)    everybody clocked on at the estate
 *                                                 right now — e.g. a resident's
 *                                                 "happening now" report
 *   toAccount($estate, $accountId, $message)      one Resident App account
 *   toAccounts($estate, $accountIds, $message)    several
 *   toUnit($estate, $unitId, $message)            every active account of a unit
 *   toEstateResidents($estate, $message, $units)  every active account of the estate,
 *                                                 or of the units given
 *
 * Each answers how many phones were queued, and NEVER THROWS: a push is a courtesy
 * on top of something that has already happened (an alert recorded, a ticket
 * moved), and no failure here may turn that into an error. What to say, and to
 * whom, for the platform's own events lives in `PushAnnouncer`.
 *
 * THE ESTATE IS PART OF EVERY LOOKUP. A recipient is (estate, kind, id); a guard id
 * or account id from another estate finds nothing, because the token rows are read
 * by all three. There is no entry point that takes a principal without an estate.
 *
 * WHO STILL COUNTS. A push address is only as good as the sign-in it came from:
 * when the API token it registered with is gone (sign-out, a revoked or re-bound
 * handset, a household member removed), when the guard is no longer active or has
 * no handset bound, when the account is suspended — the address is revoked here,
 * at the moment it would have been used, whichever code path ended the access.
 *
 * PREFERENCES. A phone that switched a category off is skipped — except for the
 * types with no category (`PushTypes`): panic, duress, an alertness check, somebody
 * at the gate. Those ignore every mute. A phone inside its quiet hours is skipped
 * for everything but the URGENT types; a resident who turned Email on is also sent
 * the news by email (`ResidentEmails`), whether or not they have a phone to push.
 *
 * COALESCING. One push per (recipient, type, record) inside `push.coalesce_seconds`:
 * six changes to one ticket are one buzz. Urgent types are never coalesced.
 *
 * AND THEY DO NOT WAIT. An urgent push is queued on `push.urgent_queue`, which every
 * worker is told to read before the default one — otherwise a duress alert sits
 * behind an estate-wide notice's twenty jobs of network. See `config/push.php`.
 */
class PushNotifier
{
    public function __construct(private readonly ResidentEmails $emails) {}

    public function toGuard(string $tenantId, int $guardId, PushMessage $message): int
    {
        return $this->toGuards($tenantId, [$guardId], $message);
    }

    /** @param  iterable<int>  $guardIds */
    public function toGuards(string $tenantId, iterable $guardIds, PushMessage $message): int
    {
        return $this->deliver($tenantId, DevicePushToken::GUARD, $guardIds, $message);
    }

    /**
     * Every guard clocked on at this estate NOW, except (optionally) one — the guard
     * who raised the alert is not told about their own.
     */
    public function toGuardsOnDuty(string $tenantId, PushMessage $message, ?int $exceptGuardId = null): int
    {
        return $this->safely(fn (): int => $this->toGuards(
            $tenantId,
            $this->onDutyGuardIds($tenantId, $exceptGuardId),
            $message,
        ));
    }

    /**
     * WHICH guards are clocked on, without pushing to them.
     *
     * Split out of `toGuardsOnDuty()` for M10 §1. The panic fan-out used to be a
     * ladder — on-duty guards, and only if that reached nobody, the officers
     * rostered today — so the two sets were never pushed to in the same breath
     * and could not overlap. The client has ruled the ladder out: a panic reaches
     * everyone who can help, at once.
     *
     * THE TWO SETS OVERLAP HEAVILY. A guard clocked on is almost always also
     * rostered for today, and calling `toGuardsOnDuty()` and then
     * `toGuards(rosteredToday())` dispatches two separate jobs: the same handset
     * buzzes twice for one panic, and `alerted_handsets` counts it twice. That
     * number is what the resident's own screen shows them — "2 officers have been
     * alerted" when one was is a false claim about how much help is coming, which
     * is the worst thing this product could tell somebody who has just pressed a
     * panic button.
     *
     * So the fan-out takes both id lists, unions them, and pushes ONCE.
     *
     * @return list<int>
     */
    public function onDutyGuardIds(string $tenantId, ?int $exceptGuardId = null): array
    {
        return $this->safely(fn (): array => Shift::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('guard_id')
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->when($exceptGuardId !== null, static fn ($q) => $q->where('guard_id', '!=', $exceptGuardId))
            ->pluck('guard_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all(), []);
    }

    public function toAccount(string $tenantId, int $accountId, PushMessage $message): int
    {
        return $this->toAccounts($tenantId, [$accountId], $message);
    }

    /** @param  iterable<int>  $accountIds */
    public function toAccounts(string $tenantId, iterable $accountIds, PushMessage $message): int
    {
        return $this->deliver($tenantId, DevicePushToken::RESIDENT, $accountIds, $message);
    }

    /** Every ACTIVE Resident App account linked to this unit — the household's phones. */
    public function toUnit(string $tenantId, int $unitId, PushMessage $message): int
    {
        return $this->toEstateResidents($tenantId, $message, [$unitId]);
    }

    /**
     * Every active account of the estate — or, given unit ids, of those units only
     * (a notice addressed to one phase).
     *
     * @param  list<int>|null  $unitIds
     */
    public function toEstateResidents(string $tenantId, PushMessage $message, ?array $unitIds = null): int
    {
        return $this->safely(function () use ($tenantId, $message, $unitIds): int {
            $accounts = ResidentAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('status', ResidentAccount::ACTIVE)
                ->whereNotNull('unit_id')
                ->when($unitIds !== null, static fn ($q) => $q->whereIn('unit_id', $unitIds))
                ->pluck('id');

            $ids = $accounts->map(static fn ($id): int => (int) $id)->all();

            // Only the accounts whose access level reaches the screen this push opens — read only when a push can go at all.
            if (config('push.enabled', true)) {
                $ids = app(PushAudience::class)->allowed($tenantId, $message->type, $ids);
            }

            return $this->toAccounts($tenantId, $ids, $message);
        });
    }

    /** @param  iterable<int>  $principalIds */
    private function deliver(string $tenantId, string $principalType, iterable $principalIds, PushMessage $message): int
    {
        return $this->safely(function () use ($tenantId, $principalType, $principalIds, $message): int {
            $ids = collect($principalIds)->map(static fn ($id): int => (int) $id)->unique()->values();
            $app = $principalType === DevicePushToken::GUARD ? AppMatrix::GUARD : AppMatrix::RESIDENT;

            // Off (the test suite's default) stops HERE: nothing is looked up, no coalescing window is claimed.
            if ($ids->isEmpty() || $tenantId === '' || PushTypes::app($message->type) !== $app || ! config('push.enabled', true)) {
                return 0;
            }

            $tokens = $this->entitledTokens($tenantId, $principalType, $ids->all());

            $category = PushTypes::category($message->type);
            $urgent = PushTypes::urgent($message->type);

            /*
             * QUIET HOURS (M50 §4.2 #5): a phone inside its own quiet hours, on the estate's clock, is not pushed — unless
             * the type is URGENT (panic, somebody at the gate), which is exactly the board's "Urgent alerts still come
             * through". Nothing is lost: what the push would have said is still in the app.
             */
            $local = SiteTime::now($tenantId);
            $wanted = $tokens->reject(static fn (DevicePushToken $token): bool => $token->mutes($category) || (! $urgent && $token->quietAt($local)));

            // The same news by EMAIL for the residents who turned Email on (M50 §4.2 #4) — phones or none.
            if ($principalType === DevicePushToken::RESIDENT) {
                $this->emails->fromPush($tenantId, $ids->all(), $message);
            }

            // Coalesced per RECIPIENT, not per phone: a resident with two phones gets one buzz on each, once.
            $recipients = $wanted->pluck('principal_id')->map(static fn ($id): int => (int) $id)->unique()
                ->filter(fn (int $principalId): bool => $urgent || $this->firstInWindow($tenantId, $principalType, $principalId, $message))
                ->all();

            $send = $wanted->filter(static fn (DevicePushToken $token): bool => in_array((int) $token->principal_id, $recipients, true))->pluck('id');

            if ($send->isEmpty()) {
                return 0;
            }

            foreach ($send->chunk((int) config('push.batch', ExpoPush::MAX_BATCH)) as $chunk) {
                $job = new SendExpoPush(
                    tokenIds: $chunk->map(static fn ($id): int => (int) $id)->values()->all(),
                    title: $message->title,
                    body: $message->body,
                    data: $message->data(),
                    channel: PushTypes::channel($message->type),
                    urgent: $urgent,
                    ttl: $message->ttl,
                );

                $connection = config('push.queue_connection', 'central');

                if (is_string($connection) && $connection !== '' && config("queue.connections.{$connection}") !== null) {
                    $job->onConnection($connection);
                }

                /*
                 * A PANIC DOES NOT QUEUE BEHIND A NOTICE. An estate-wide notice is one job per
                 * hundred phones, each worth up to twelve seconds of network; on a single default
                 * queue a duress alert raised behind them waits for all of it. The urgent types go
                 * on their own queue, which every worker is told to read FIRST (`config/push.php`).
                 */
                $urgentQueue = config('push.urgent_queue');

                if ($urgent && is_string($urgentQueue) && $urgentQueue !== '') {
                    $job->onQueue($urgentQueue);
                }

                dispatch($job);
            }

            return $send->count();
        });
    }

    /**
     * THE LIVE, ENTITLED PUSH ADDRESSES OF THESE PRINCIPALS AT THIS ESTATE — the lookup every
     * push makes, and nothing else: no mute, no coalescing, no send.
     *
     * Split out of `deliver()` unchanged for the guard ring, which sends its own messages but
     * must reach exactly the phones today's push reaches, by exactly today's rules: the
     * estate, the principal and the app on every row, and `stillEntitled()` (an active guard
     * still posted here with a handset bound, a sign-in that still exists). A ring, a stop and
     * a late sweep ask it again at the moment they send (G7), so an address re-pointed to
     * another estate between the ring and the stop is never sent this estate's stop.
     *
     * @param  iterable<int>  $principalIds
     * @return Collection<int, DevicePushToken>
     */
    public function entitledTokens(string $tenantId, string $principalType, iterable $principalIds): Collection
    {
        $ids = collect($principalIds)->map(static fn ($id): int => (int) $id)->unique()->values();
        $app = $principalType === DevicePushToken::GUARD ? AppMatrix::GUARD : AppMatrix::RESIDENT;

        if ($ids->isEmpty() || $tenantId === '') {
            return new Collection;
        }

        $tokens = DevicePushToken::query()->live()
            ->where('tenant_id', $tenantId)
            ->where('principal_type', $principalType)
            ->where('app', $app)
            ->whereIn('principal_id', $ids->all())
            ->get();

        return $this->stillEntitled($tenantId, $principalType, $tokens);
    }

    /**
     * Drop — and revoke — the addresses whose sign-in has ended.
     *
     * @param  Collection<int, DevicePushToken>  $tokens
     * @return Collection<int, DevicePushToken>
     */
    private function stillEntitled(string $tenantId, string $principalType, Collection $tokens): Collection
    {
        if ($tokens->isEmpty()) {
            return $tokens;
        }

        $principals = $tokens->pluck('principal_id')->unique()->all();

        $entitled = $principalType === DevicePushToken::GUARD
            ? Guard::query()->whereIn('id', $principals)->where('tenant_id', $tenantId)->where('status', 'active')->whereNotNull('device_id')->pluck('id')
            : ResidentAccount::query()->whereIn('id', $principals)->where('tenant_id', $tenantId)->where('status', '!=', ResidentAccount::SUSPENDED)->pluck('id');

        $entitled = $entitled->map(static fn ($id): int => (int) $id)->all();

        $accessTokens = $tokens->pluck('access_token_id')->filter()->unique()->all();
        $alive = $accessTokens === []
            ? []
            : DB::connection('mysql')->table('personal_access_tokens')->whereIn('id', $accessTokens)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        [$keep, $dead] = $tokens->partition(static fn (DevicePushToken $token): bool => in_array((int) $token->principal_id, $entitled, true)
            && ($token->access_token_id === null || in_array((int) $token->access_token_id, $alive, true)));

        if ($dead->isNotEmpty()) {
            DevicePushToken::query()->whereKey($dead->pluck('id')->all())->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => DevicePushToken::ACCESS_REVOKED]);
        }

        return $keep->values();
    }

    /**
     * Whether this recipient has NOT been pushed this (type, record) inside the
     * window — and if so, claims the window. One statement decides, so two requests
     * racing on the same ticket cannot both win.
     */
    private function firstInWindow(string $tenantId, string $principalType, int $principalId, PushMessage $message): bool
    {
        $window = (int) config('push.coalesce_seconds', 300);

        if ($window <= 0) {
            return true;
        }

        $now = Carbon::now();
        $key = ['tenant_id' => $tenantId, 'principal_type' => $principalType, 'principal_id' => $principalId, 'type' => $message->type, 'record' => $message->record()];
        $table = static fn () => DB::connection('mysql')->table('push_coalescing');

        if ($table()->insertOrIgnore([...$key, 'last_pushed_at' => $now, 'suppressed' => 0]) === 1) {
            return true;
        }

        if ($table()->where($key)->where('last_pushed_at', '<=', $now->copy()->subSeconds($window))->update(['last_pushed_at' => $now, 'suppressed' => 0]) === 1) {
            return true;
        }

        $table()->where($key)->increment('suppressed');

        return false;
    }

    /** @param  callable(): int  $work */
    /**
     * Run it, and never let a push failure reach the caller.
     *
     * `$whenItFails` is what to answer instead. It defaults to 0 because every
     * caller but one returns a count of handsets reached; `onDutyGuardIds()`
     * returns a list, and a 0 there would be a type error at the exact moment
     * something has already gone wrong.
     *
     * @template TFallback
     *
     * @param  TFallback  $whenItFails
     * @return TFallback|mixed
     */
    private function safely(callable $work, mixed $whenItFails = 0): mixed
    {
        try {
            return $work();
        } catch (Throwable $e) {
            // Reported, never thrown — and the report is the exception alone: no title, no body, no token.
            rescue(static fn () => report($e), report: false);

            return $whenItFails;
        }
    }
}
