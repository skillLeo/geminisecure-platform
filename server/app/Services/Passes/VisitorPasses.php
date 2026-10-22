<?php

declare(strict_types=1);

namespace App\Services\Passes;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Api\AppMatrix;
use App\Live\Events\PassLive;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use App\Services\ResidentApp\HouseholdEntries;
use App\Services\ResidentApp\RevokeResidentAccess;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issuing, cancelling and checking visitor passes (13 D1, D3).
 *
 * ISSUE signs the eight-field payload with the site's current key and stores
 * the exact token the QR code carries. VERIFY ONLINE runs the same
 * `OfflinePassVerifier` a handset runs, then adds the three things only the
 * server knows — the holder is no longer a resident (Q-M44), cancelled, and
 * already used — and, for a single-use pass, consumes it only when the guard
 * records the admission, never on the scan.
 */
class VisitorPasses
{
    /** What a resident may issue a pass for. */
    public const CATEGORIES = ['single', 'recurring', 'contractor', 'delivery'];

    /**
     * A resident's own e-pass — never issued from the visitor form, never
     * restricted (a resident's own entry is exempt, `RestrictionPolicy`), and
     * reusable for its day.
     */
    public const RESIDENT = 'resident';

    /**
     * A RESIDENT'S E-PASS, HALF AN HOUR AT A TIME (M47 §6, the user, 7 October).
     *
     * What the phone shows at the gate is good for THIRTY MINUTES — a screenshot of it is dead within the half hour —
     * and the phone keeps a DAY of them (48), collected whenever the app is open with a connection, so a gate with no
     * signal still opens. Each half hour is its own signed token for the same pass, so a guard's phone checks it
     * offline exactly as before; the pass's ROW (one a day, one gate code) spans 48 hours and is renewed once less than
     * a day of it remains, so a day's half hours always fit inside one row.
     */
    public const RESIDENT_PASS_HOURS = 48;

    /** A day of half hours always fits: a row with less than this left is renewed. */
    public const RESIDENT_AHEAD_HOURS = 24;

    public const RESIDENT_SLOT_MINUTES = 30;

    public const RESIDENT_SLOTS = 48;

    /** The longest a pass may run. Matches the grace a retired key's public half is served for. */
    public const MAX_DAYS = PassSigningKeys::RETIRED_KEY_GRACE_DAYS;

    public function __construct(
        private readonly PassSigningKeys $keys,
        private readonly OfflinePassVerifier $verifier,
    ) {}

    /**
     * @param  array{category: string, visitor_name: string, visitor_phone?: string|null, purpose?: string|null, vehicle_plate?: string|null, no_vehicle?: bool, valid_from: Carbon, valid_to: Carbon}  $details
     */
    public function issue(string $tenantId, Unit $unit, ?int $residentId, string $residentName, array $details, ?string $idempotencyKey = null, bool $simulated = false): VisitorPass
    {
        if (! in_array($details['category'], [...self::CATEGORIES, self::RESIDENT], true)) {
            throw new DomainException('A pass is for a single visit, a recurring visitor, a contractor or a delivery.');
        }

        $from = $details['valid_from']->copy()->utc();
        $to = $details['valid_to']->copy()->utc();

        if (! $to->greaterThan($from)) {
            throw new DomainException('A pass ends after it starts.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new DomainException('A pass runs for at most '.self::MAX_DAYS.' days. A visitor who comes every month gets a new pass each month.');
        }

        $passId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(16));
        $singleUse = ! in_array($details['category'], ['recurring', self::RESIDENT], true);

        // Signed with the version current NOW, and the version goes in the payload.
        $version = $this->keys->currentVersion($tenantId);

        $segment = PassToken::encodePayload([
            'pass_id' => $passId,
            'tenant_id' => $tenantId,
            'site_id' => $tenantId,
            'valid_from' => $from->toIso8601ZuluString(),
            'valid_to' => $to->toIso8601ZuluString(),
            'single_use' => $singleUse,
            'nonce' => $nonce,
            'key_version' => $version,
        ]);

        $signed = $this->keys->sign($tenantId, $segment);

        if ($signed['key_version'] !== $version) {
            // A rotation landed between reading the version and signing. Try once more.
            return $this->issue($tenantId, $unit, $residentId, $residentName, $details, $idempotencyKey, $simulated);
        }

        /*
         * WITH ITS LIVE EVENT (M11 Part C, DESIGN.md §2.9 I1): every member of the household sees the new pass on
         * their own Visitors list, on every phone. Not for a resident's own e-pass, which is theirs alone and is
         * fetched by the phone that shows it.
         */
        return DB::connection('tenant')->transaction(function () use ($tenantId, $passId, $unit, $residentId, $residentName, $details, $from, $to, $singleUse, $nonce, $version, $segment, $signed, $idempotencyKey, $simulated): VisitorPass {
            $pass = $this->create($tenantId, $passId, $unit, $residentId, $residentName, $details, $from, $to, $singleUse, $nonce, $version, $segment, $signed, $idempotencyKey, $simulated);

            if ($pass->category !== self::RESIDENT) {
                PassLive::tell($pass, 'issued');
            }

            return $pass;
        });
    }

    /**
     * The pass's row (see issue()).
     *
     * @param  array{category: string, visitor_name: string, visitor_phone?: string|null, purpose?: string|null, vehicle_plate?: string|null, no_vehicle?: bool, valid_from: Carbon, valid_to: Carbon}  $details
     * @param  array{key_version: int, signature: string}  $signed
     */
    private function create(string $tenantId, string $passId, Unit $unit, ?int $residentId, string $residentName, array $details, Carbon $from, Carbon $to, bool $singleUse, string $nonce, int $version, string $segment, array $signed, ?string $idempotencyKey, bool $simulated): VisitorPass
    {
        return VisitorPass::create([
            'pass_id' => $passId,
            'unit_id' => $unit->id,
            'household_id' => $unit->household?->id,
            'issued_by_resident_id' => $residentId,
            'issued_by_name' => $residentName,
            'category' => $details['category'],
            'visitor_name' => $details['visitor_name'],
            'visitor_phone' => $details['visitor_phone'] ?? null,
            'purpose' => $details['purpose'] ?? null,
            'vehicle_plate' => $details['vehicle_plate'] ?? null,
            'no_vehicle' => (bool) ($details['no_vehicle'] ?? false),
            // Walk in, Drive in or Ride in (M50 §6.3) — null when the app that issued it did not say.
            'arrival' => $details['arrival'] ?? null,
            'valid_from' => $from,
            'valid_to' => $to,
            'single_use' => $singleUse,
            'nonce' => $nonce,
            'key_version' => $version,
            'token' => $segment.'.'.PassToken::base64url($signed['signature']),
            'code' => $this->code($tenantId, $unit),
            'status' => VisitorPass::ACTIVE,
            'idempotency_key' => $idempotencyKey,
            'is_simulated' => $simulated,
        ]);
    }

    /**
     * Verify at the gate, online: the offline checks, then cancellation and use.
     *
     * @return array{verdict: string, pass: VisitorPass|null, reason: string}
     */
    public function verifyOnline(string $tenantId, string $tokenOrCode, Carbon $now): array
    {
        $pass = str_contains($tokenOrCode, '.')
            ? null
            : VisitorPass::query()->where('code', strtoupper(trim($tokenOrCode)))->first();

        $token = $pass === null ? $tokenOrCode : $pass->token;

        $offline = $this->verifier->verify($token, [$tenantId => $this->keys->publicKeys($tenantId)], $tenantId, $now);

        if ($offline['verdict'] !== OfflinePassVerifier::VALID_OFFLINE) {
            return ['verdict' => $offline['verdict'], 'pass' => null, 'reason' => $this->reason($offline['verdict'])];
        }

        $pass ??= VisitorPass::query()->where('pass_id', $offline['pass_id'])->first();

        if ($pass === null) {
            // Signed by this site's key and unknown to its database: a restore, or a forgery with a stolen key.
            return ['verdict' => 'unknown_pass', 'pass' => null, 'reason' => 'The signature is this estate\'s, and no such pass is on record. Call the estate office.'];
        }

        /*
         * THE HOLDER IS NO LONGER A RESIDENT (Q-M44) — asked BEFORE cancellation, and
         * that order is the whole point. Revoking an account cancels the person's e-pass,
         * so without this the guard would read "the resident cancelled this pass", which
         * is both untrue and useless: it tells an officer nothing about who is standing at
         * the barrier. A resident's e-pass is the one credential that says "admit this
         * person as a resident", and when the office has ended their access the guard has
         * to be told exactly that, and where to send them.
         */
        if ($pass->category === self::RESIDENT && RevokeResidentAccess::isRevokedResident($tenantId, $pass->issued_by_resident_id)) {
            return [
                'verdict' => 'revoked_resident',
                'pass' => $pass,
                'reason' => 'The estate office has ended this person\'s resident access. They are no longer a resident of '.
                    // Every pass has its unit: a required key the unit cannot be deleted from under.
                    $pass->unit->reference.'. Do not admit them as a resident — direct them to the Visitor Entrance.',
            ];
        }

        if ($pass->category === self::RESIDENT && ($outside = $this->outsideHelperHours($tenantId, $pass, $now)) !== null) {
            return $outside;
        }

        if ($pass->status === VisitorPass::CANCELLED) {
            return ['verdict' => 'cancelled', 'pass' => $pass, 'reason' => 'The resident cancelled this pass.'];
        }

        if ($pass->status === VisitorPass::USED && $pass->single_use) {
            // The words a guard reads at the gate, on the SITE'S clock (M4 §3.1) — `used_at` is
            // stored as a true UTC moment, so printing its digits raw would name the wrong hour.
            $usedAt = $pass->used_at === null ? null : SiteTime::local($pass->used_at, $tenantId)->format('g:i A, M j');

            return ['verdict' => 'already_used', 'pass' => $pass, 'reason' => 'This single-visit pass was already used at '.$usedAt.'.'];
        }

        return ['verdict' => 'valid', 'pass' => $pass, 'reason' => 'Valid pass.'];
    }

    /**
     * The resident's own e-pass: the row still good for at least a day more, or a new one for the next two. Signed like
     * any pass; what the phone shows is one of its half hours (`residentSlots`).
     */
    public function residentPass(string $tenantId, Unit $unit, int $residentId, string $residentName, bool $simulated = false): VisitorPass
    {
        $now = Carbon::now();

        $current = VisitorPass::query()
            ->where('category', self::RESIDENT)
            ->where('issued_by_resident_id', $residentId)
            ->where('unit_id', $unit->id)
            ->where('status', VisitorPass::ACTIVE)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now->copy()->addHours(self::RESIDENT_AHEAD_HOURS))
            ->orderByDesc('valid_to')
            ->first();

        return $current ?? $this->issue($tenantId, $unit, $residentId, $residentName, [
            'category' => self::RESIDENT,
            'visitor_name' => $residentName,
            'purpose' => 'Resident',
            'valid_from' => $now->copy()->subMinutes(5),
            'valid_to' => $now->copy()->addHours(self::RESIDENT_PASS_HOURS),
        ], simulated: $simulated);
    }

    /**
     * The e-pass's half hours, from the one this moment is in: up to a day of them (48), each its own token for the same
     * pass — the same pass id and nonce, its own half hour signed in — so a guard's phone verifies each offline in its
     * own thirty minutes and no other. Read with one key read, however many.
     *
     * `$open`, for a Helper (8 Oct 2026): only the half hours whose start it allows are signed — their days and hours —
     * so outside them there is no token for a phone to show or a gate to accept, with a signal or without one.
     *
     * @param  (callable(Carbon): bool)|null  $open
     * @return list<array{valid_from: string, valid_to: string, token: string}>
     */
    public function residentSlots(string $tenantId, VisitorPass $pass, ?Carbon $at = null, ?callable $open = null): array
    {
        $first = ($at ?? Carbon::now())->copy()->utc()->floorMinutes(self::RESIDENT_SLOT_MINUTES);
        $end = $pass->valid_to->copy()->utc();

        $windows = [];
        for ($k = 0; $k < self::RESIDENT_SLOTS; $k++) {
            $from = $first->copy()->addMinutes($k * self::RESIDENT_SLOT_MINUTES);
            if ($from->greaterThanOrEqualTo($end)) {
                break;
            }
            if ($open !== null && ! $open($from->copy())) {
                continue;
            }
            $windows[] = [$from, $from->copy()->addMinutes(self::RESIDENT_SLOT_MINUTES)->min($end)];
        }

        if ($windows === []) {
            return [];
        }

        $signed = $this->keys->signEach($tenantId, $windows, static fn (array $window, int $version): string => PassToken::encodePayload([
            'pass_id' => $pass->pass_id,
            'tenant_id' => $tenantId,
            'site_id' => $tenantId,
            'valid_from' => $window[0]->toIso8601ZuluString(),
            'valid_to' => $window[1]->toIso8601ZuluString(),
            'single_use' => false,
            'nonce' => $pass->nonce,
            'key_version' => $version,
        ]));

        return array_map(static fn (array $window, array $one): array => [
            'valid_from' => (string) SiteTime::iso($window[0]),
            'valid_to' => (string) SiteTime::iso($window[1]),
            'token' => $one['segment'].'.'.PassToken::base64url($one['signature']),
        ], $windows, $signed);
    }

    /**
     * A HELPER'S E-PASS OUTSIDE THEIR DAYS AND HOURS (8 Oct 2026), checked online — the typed gate code included, which
     * the row's own token would otherwise admit at any hour. Answered in the verdicts every Guard App already says in
     * the right words, the pass's window set to the one that applies: before their hours (or on a day off) "PASS NOT
     * YET VALID · starts at …", after them "EXPIRED PASS · expired at …", paused or past their end date "PASS
     * CANCELLED". Each can still be overridden with a note, as those verdicts always can. Null: let the usual checks run.
     *
     * @return array{verdict: string, pass: VisitorPass, reason: string}|null
     */
    private function outsideHelperHours(string $tenantId, VisitorPass $pass, Carbon $now): ?array
    {
        $person = $pass->issued_by_resident_id === null ? null : Resident::withRemoved()->find((int) $pass->issued_by_resident_id);

        if ($person === null || AccessLevels::of($person) !== AppMatrix::HELPER) {
            return null;
        }

        $window = AccessRules::window($person);
        $why = AccessRules::refusedAt($window, $now, $tenantId);

        if ($why === null) {
            return null;
        }

        if ($why === 'paused' || $why === 'ended') {
            return ['verdict' => 'cancelled', 'pass' => $pass, 'reason' => $why === 'paused' ? 'The household has paused this helper\'s e-Pass.' : 'This helper\'s e-Pass ended on '.Carbon::parse((string) $window['until'])->format('M j, Y').'.'];
        }

        // The window that applies, drawn on the pass the guard sees — never saved to the row.
        $shown = clone $pass;
        $local = SiteTime::local($now, $tenantId);

        if ($why === 'after') {
            $shown->valid_to = $local->copy()->setTimeFromTimeString($window['to'])->utc();

            return ['verdict' => OfflinePassVerifier::EXPIRED, 'pass' => $shown, 'reason' => 'This helper\'s hours ended at '.AccessRules::clockWords($window['to']).' ('.AccessRules::windowLine($window).').'];
        }

        // Before their hours today, or a day they do not come: the next time they may.
        $next = $local->copy()->setTimeFromTimeString($window['from']);
        for ($i = 0; $i < 8 && ($next->lessThanOrEqualTo($local) || AccessRules::refusedAt($window, $next->copy()->utc(), $tenantId) !== null); $i++) {
            $next = $local->copy()->addDays($i + 1)->setTimeFromTimeString($window['from']);
        }
        $shown->valid_from = $next->utc();

        return ['verdict' => OfflinePassVerifier::NOT_YET_VALID, 'pass' => $shown, 'reason' => 'This helper may come '.AccessRules::windowLine($window).'.'];
    }

    /** A single-use pass is consumed when the admission is recorded, not when it is scanned. */
    public function consume(VisitorPass $pass, Carbon $at): void
    {
        if ($pass->single_use && $pass->status === VisitorPass::ACTIVE) {
            // Used, and every gate handset refuses it from now on — signal or not (M11 Part C, §2.9 I7).
            DB::connection('tenant')->transaction(function () use ($pass, $at): void {
                $pass->forceFill(['status' => VisitorPass::USED, 'used_at' => $at])->save();
                PassLive::tell($pass, 'used');
            });
        }
    }

    public function cancel(VisitorPass $pass): VisitorPass
    {
        if ($pass->status === VisitorPass::USED) {
            throw new DomainException('This pass has already been used, so there is nothing left to cancel.');
        }

        if ($pass->status !== VisitorPass::CANCELLED) {
            /*
             * CANCELLED, AND REFUSED AT EVERY GATE AT ONCE (M11 Part C, DESIGN.md §2.9 I5): the guards' handsets learn
             * it from `estate.{t}.passes` before their next pull, so a pass cancelled while its visitor is on the way
             * is refused on the scan, even with no signal after it. And every member's Visitors list moves.
             */
            DB::connection('tenant')->transaction(function () use ($pass): void {
                $pass->forceFill(['status' => VisitorPass::CANCELLED, 'cancelled_at' => Carbon::now()])->save();
                PassLive::tell($pass, 'cancelled');
            });
        }

        return $pass;
    }

    /**
     * The visitor was let in on this pass (GateController::entry), a recurring one as much as a single-use one: the
     * moment goes on the pass, and the household hears it (M11 §2, DESIGN.md §13.3 (1), §2.9 I14) — with the gate and
     * the guard, so their Visitors tab can say "Entered · Main Gate · by Guard Whyte" (M47 §5.4).
     */
    public function admitted(VisitorPass $pass, Carbon $at, ?string $post = null, ?string $guard = null): void
    {
        DB::connection('tenant')->transaction(function () use ($pass, $at, $post, $guard): void {
            $pass->forceFill(['last_admitted_at' => $at, 'last_admitted_post' => $post, 'last_admitted_by' => $guard])->save();
            PassLive::tell($pass, 'admitted');
        });

        // A Helper or a Dependent came in on their own e-Pass: the household is told, by the estate's rule (8 Oct 2026).
        app(HouseholdEntries::class)->came($pass, $at, $post, 'in');
    }

    /** A pass changed by its household (PassesController): every member's list moves (§2.9 I3, I4). */
    public function changed(VisitorPass $pass, string $verb, callable $write): void
    {
        DB::connection('tenant')->transaction(function () use ($pass, $verb, $write): void {
            $write();
            PassLive::tell($pass, $verb);
        });
    }

    private function reason(string $verdict): string
    {
        return match ($verdict) {
            OfflinePassVerifier::EXPIRED => 'This pass has expired. Admitting now needs an override with a reason.',
            OfflinePassVerifier::NOT_YET_VALID => 'This pass is not valid yet.',
            OfflinePassVerifier::WRONG_SITE => 'This pass is for a different estate.',
            OfflinePassVerifier::UNKNOWN_KEY, OfflinePassVerifier::BAD_SIGNATURE => 'This pass was not issued by this estate. Do not admit on it.',
            default => 'This is not a pass code this estate issues.',
        };
    }

    /** "PPV2-4471": the estate's receipt prefix and phase, and four digits. Unique within the estate. */
    private function code(string $tenantId, Unit $unit): string
    {
        $prefix = (string) (Tenant::query()->whereKey($tenantId)->value('receipt_prefix') ?? strtoupper(substr($tenantId, 0, 3)));
        $phase = preg_match('/(\d+)/', (string) $unit->block, $m) === 1 ? $m[1] : '0';

        // Free among every code the unique index holds, a retired sample pass's too (M33 §5).
        do {
            $code = substr(preg_replace('/[^A-Z]/', '', $prefix) ?: 'GS', 0, 4).$phase.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (Retirements::seeingEverything(static fn (): bool => VisitorPass::query()->where('code', $code)->exists()));

        return $code;
    }
}
