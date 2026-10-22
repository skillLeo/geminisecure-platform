<?php

declare(strict_types=1);

namespace App\Services\Devices;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Live\Channels;
use App\Live\Events\RecordLive;
use App\Models\Guard;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\GuardPhoneRecovered;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Reviewer\ReviewerMode;
use App\Support\CentralQueue;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Binding a handset to a guard, and issuing the token it speaks with.
 *
 * ONE DEVICE PER GUARD, AND BINDING A NEW ONE REVOKES THE OLD. `Guard::
 * deviceIsBound()` already says why: the binding is what stops one phone
 * starting shifts for several people. A guard who replaces a lost handset must
 * end up with exactly one working token, or the lost one keeps clocking them in.
 *
 * THE ABILITIES COME FROM THE APP MATRIX (13 D1). A Guard App handset carries
 * `AppMatrix::abilitiesFor(GUARD)` — computed, never listed here — so the token
 * and the routes cannot disagree about what a guard's phone may do.
 *
 * THREE WAYS IN (13 D1):
 *
 *   `device:enrol` at the office      the operator's bootstrap; prints a token
 *   a one-time enrolment code         `POST /devices/enrol` with the code, the
 *                                     handset's UID, platform and public key
 *   a REBIND                          a guard who already has a handset: the
 *                                     request waits for a supervisor, whose
 *                                     decision is recorded against the guard's
 *                                     shift, and only then does the old token die
 *
 * THE PLAINTEXT TOKEN IS RETURNED ONCE AND NEVER STORED. Sanctum keeps a hash.
 *
 * AND A FOURTH, FOR THE SAME HANDSET ONLY (M46 §1): RECOVERY. A phone whose app data was wiped (a reinstall, "Clear
 * storage") comes back as a new install with a new key, and its spent code used to be dead — a guard locked out at
 * night with nobody to ask. Now the binding also keeps the handset's own identity, hashed (`hardware_hash`: Android's
 * per-app device id, which a reinstall of the same app keeps), and the code remembers the binding it made. The same
 * code works again only from the SAME handset, for the SAME guard, while that handset is still the guard's last one
 * and was not taken out of service; it is recorded as a recovery, never an enrolment, in the audit log. On any other
 * handset a used code stays dead — it never becomes a portable credential.
 */
class DeviceEnrolment
{
    /** How long an enrolment code works. */
    public const CODE_HOURS = 24;

    /** What the handset's identity is hashed with: the raw id is never stored. */
    private const HARDWARE_PREFIX = 'gs-hw-v1|';

    /**
     * Said for every code that cannot be used here — unknown, expired, or spent on another handset — alike. Not "ask the
     * office for a new one", which is no route back at 2am with the office shut (M46 §1.3): it says what does work.
     */
    public const CODE_REFUSED = 'This code can\'t set up this phone. Check it and try again: a code that has been used works again only on the phone it first set up. A new phone needs a new code from your supervisor.';

    /** The handset identity as stored: a hash, or null when the phone sent none. */
    public static function hardwareHash(?string $hardwareId): ?string
    {
        $id = trim((string) $hardwareId);

        return $id === '' ? null : hash('sha256', self::HARDWARE_PREFIX.$id);
    }

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReviewerMode $reviewer,
    ) {}

    /**
     * Bind a handset to this guard and issue its token.
     *
     * `$decidedBy` is the supervisor who approved the move to this handset (a rebind), named on the binding it ends so
     * the phone it replaced can be told who removed it (M32 F3); null when no supervisor decided — the office's own
     * `device:enrol`, a code on a guard's first phone, or the same phone again.
     *
     * `$recovery` is set when the same handset is taking back the binding it already had (M46 §1, `recover()`): the
     * code, and the binding and install it recovers. That binding ends `recovered`, and the act is audited as
     * `device.recovered` — never as an enrolment.
     *
     * @param  array{device_uid?: string|null, platform?: string|null, public_key?: string|null, hardware_id?: string|null, hardware_hash?: string|null}  $device
     * @param  array{code_id: int, binding_id: int, device_uid: string}|null  $recovery
     * @return array{device_id: string, token: string, abilities: list<string>, binding_id: int|null}
     */
    public function enrol(Guard $guard, ?string $deviceLabel = null, array $device = [], ?string $decidedBy = null, ?array $recovery = null): array
    {
        if ($guard->status !== 'active') {
            throw new DomainException(
                $guard->full_name.' is '.$guard->statusLabel().'. A handset is bound to somebody who is '.
                'working, and a token issued to a suspended guard is a credential nobody is watching.'
            );
        }

        $uid = isset($device['device_uid']) && $device['device_uid'] !== '' ? (string) $device['device_uid'] : null;

        // Already hashed when it comes from a rebind request; hashed here when it comes from the phone.
        $hardware = $device['hardware_hash'] ?? self::hardwareHash($device['hardware_id'] ?? null);

        /*
         * WHAT THIS BINDING ENDS, said as what it is (M32 F3): this guard's own phone replaced by another, enrolled
         * again with a code, or recovered after a reinstall. And an install is one guard's at a time: a phone set up for
         * somebody else is no longer the last officer's, and their session on it goes with it.
         */
        $this->endBindings($guard, $uid, $decidedBy, $recovery === null ? null : 'recovered', $hardware);

        if ($uid !== null) {
            $others = DB::connection('mysql')->table('device_bindings')->where('device_uid', $uid)->whereNull('ended_at')->where('guard_id', '!=', $guard->id)->pluck('guard_id');

            foreach (Guard::query()->whereKey($others->all())->get() as $previous) {
                $this->endBindings($previous, $uid, $decidedBy, 'replaced');
                $this->revoke($previous);
            }
        }

        /*
         * Revoked BEFORE the new one is minted, not after. A failure between the
         * two must leave a guard with no handset rather than with two.
         */
        $this->revoke($guard);

        $deviceId = (string) Str::uuid();
        $abilities = AppMatrix::abilitiesFor(AppMatrix::GUARD);

        $token = $guard->createToken(
            name: $deviceLabel ?? ('Handset — '.$guard->employee_number),
            abilities: $abilities,
        );

        $guard->forceFill([
            'device_id' => $deviceId,
            'device_label' => $deviceLabel ?? $guard->device_label,
            'device_uid' => $device['device_uid'] ?? null,
            'device_platform' => $device['platform'] ?? null,
            'device_public_key' => $device['public_key'] ?? null,
            'device_enrolled_at' => Carbon::now(),
        ])->save();

        // The binding a fingerprint sign-in proves against, kept after it ends so the phone can be told why (M32 F3) —
        // with the handset's own identity, hashed, so the same handset can recover it after a reinstall (M46 §1).
        $bindingId = null;
        if ($uid !== null) {
            $bindingId = (int) DB::connection('mysql')->table('device_bindings')->insertGetId([
                'guard_id' => $guard->id,
                'tenant_id' => $guard->tenant_id,
                'device_uid' => $uid,
                'public_key' => $device['public_key'] ?? null,
                'hardware_hash' => $hardware,
                'bound_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        // A recovery is the same handset taking back its own binding: recorded as that, never as a new enrolment.
        $this->audit->record(
            action: $recovery === null ? 'device.enrolled' : 'device.recovered',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            before: $recovery === null ? [] : ['binding' => $recovery['binding_id'], 'device_uid' => $recovery['device_uid']],
            after: [
                'device_id' => $deviceId,
                'device_label' => $guard->device_label,
                'device_uid' => $device['device_uid'] ?? null,
                'platform' => $device['platform'] ?? null,
                'abilities' => $abilities,
                ...($recovery === null ? [] : ['binding' => $bindingId, 'enrolment_code' => $recovery['code_id']]),
            ],
            tenantId: $guard->tenant_id,
        );

        return ['device_id' => $deviceId, 'token' => $token->plainTextToken, 'abilities' => $abilities, 'binding_id' => $bindingId];
    }

    /**
     * Issue a one-time enrolment code for this guard. The code is returned once;
     * only its hash is kept.
     */
    public function issueCode(Guard $guard, ?User $by = null): string
    {
        if ($guard->status !== 'active') {
            throw new DomainException($guard->full_name.' is '.$guard->statusLabel().'. An enrolment code is issued to somebody who is working.');
        }

        // Grouped for reading aloud over a phone: "4F7K-9QXM".
        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        $code = strtr($code, ['O' => '8', '0' => '9', 'I' => '7', 'L' => '6', '1' => '3']);

        /*
         * ONE LIVE CODE PER GUARD (DESIGN §11 row 6). "Issue another code" is this code's resend, and it left
         * the earlier one working for the rest of its day — two codes read aloud down two telephones, either of
         * which enrols a handset as this officer. The earlier unused ones stop working the moment this is issued.
         */
        $retired = DB::connection('mysql')->table('device_enrolment_codes')
            ->where('guard_id', $guard->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['expires_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($retired > 0) {
            $this->audit->record(
                action: 'device.enrolment_code_reissued',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['retired_codes' => $retired],
                tenantId: $guard->tenant_id,
            );
        }

        DB::connection('mysql')->table('device_enrolment_codes')->insert([
            'guard_id' => $guard->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Carbon::now()->addHours(self::CODE_HOURS),
            'issued_by' => $by?->getKey(),
            'issued_by_name' => $by?->name,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'device.enrolment_code_issued',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            after: ['expires_in_hours' => self::CODE_HOURS],
            tenantId: $guard->tenant_id,
        );

        return $code;
    }

    /**
     * A handset presenting an enrolment code (`POST /devices/enrol`).
     *
     * @param  array{enrolment_code: string, device_uid: string, platform: string, public_key: string, label?: string|null, hardware_id?: string|null}  $request
     * @return array{status: string, guard: Guard, token?: string, abilities?: list<string>, rebind_request_id?: int, claim_secret?: string, recovered?: bool}
     */
    public function enrolWithCode(array $request): array
    {
        $code = DB::connection('mysql')->table('device_enrolment_codes')
            ->where('code_hash', hash('sha256', strtoupper(trim($request['enrolment_code']))))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        /*
         * THE APP-STORE REVIEWER'S STATIC CODE (M5 §3), and only ever theirs.
         *
         * Looked up AFTER the ordinary one-time code, so nothing about the real path changes:
         * a reviewer's code is a code no `device_enrolment_codes` row holds, and it is matched
         * against the `reviewer` accounts alone. A guard without the flag presenting the same
         * string falls straight through to the refusal below, which is the test that matters.
         */
        $reviewerGuard = $code !== null ? null : $this->reviewerGuardFor((string) $request['enrolment_code']);

        if ($code === null && $reviewerGuard === null) {
            // A spent code is tried once more as a RECOVERY of the handset it set up — and is dead anywhere else.
            return $this->recover($request) ?? throw ApiError::unprocessable('enrolment_code_invalid', self::CODE_REFUSED);
        }

        $publicKey = $this->publicKeyOf((string) $request['public_key']);

        $guard = $reviewerGuard ?? Guard::query()->findOrFail($code->guard_id);

        if ($guard->status !== 'active') {
            throw ApiError::forbidden('guard_not_active', $guard->full_name.' is not on active duty, so a handset cannot be bound to them.');
        }

        $device = ['device_uid' => $request['device_uid'], 'platform' => $request['platform'], 'public_key' => $publicKey, 'hardware_id' => $request['hardware_id'] ?? null];

        /*
         * THE REVIEWER'S HANDSET IS BOUND AT ONCE (M5 §3, device binding). No code row to
         * spend — the static code does not expire and is not consumed — and no rebind waiting
         * on a supervisor the reviewer cannot telephone. Both facts are written to the audit
         * log on every use, so revoking the flag leaves a complete record of what it allowed.
         */
        if ($reviewerGuard !== null) {
            $result = $this->enrol($reviewerGuard, $request['label'] ?? null, $device);

            $this->reviewer->record(ReviewerMode::STATIC_CODE, $reviewerGuard, ['for' => 'device_enrolment']);
            $this->reviewer->record(ReviewerMode::DEVICE_BINDING, $reviewerGuard, [
                'device_uid' => $device['device_uid'],
                'platform' => $device['platform'],
            ]);

            return ['status' => 'enrolled', 'guard' => $reviewerGuard, 'token' => $result['token'], 'abilities' => $result['abilities']];
        }

        return DB::connection('mysql')->transaction(function () use ($code, $guard, $device, $request): array {
            DB::connection('mysql')->table('device_enrolment_codes')->where('id', $code->id)->update(['used_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            /*
             * FIRST HANDSET, OR THE SAME ONE AGAIN: bound now. A guard re-enrolling
             * the phone already bound to them (a reinstall) is not a rebind — the
             * same install, or, after a reinstall gave it a new one, the same
             * handset (M46 §1).
             */
            if (! $guard->deviceIsBound() || ($guard->device_uid !== null && $guard->device_uid === $device['device_uid']) || $this->isTheBoundHandset($guard, $device['hardware_id'])) {
                $result = $this->enrol($guard, $request['label'] ?? null, $device);
                // The code remembers the binding it made: the one handset it can recover later (M46 §1).
                DB::connection('mysql')->table('device_enrolment_codes')->where('id', $code->id)->update(['binding_id' => $result['binding_id'], 'updated_at' => Carbon::now()]);

                return ['status' => 'enrolled', 'guard' => $guard, 'token' => $result['token'], 'abilities' => $result['abilities']];
            }

            /*
             * A DIFFERENT HANDSET FOR A GUARD WHO ALREADY HAS ONE. Not bound until a
             * supervisor says so: a lost phone reported by the guard and a cloned
             * code used by somebody else look identical from here.
             */
            $secret = Str::random(40);

            $requestId = DB::connection('mysql')->table('device_rebind_requests')->insertGetId([
                'guard_id' => $guard->id,
                'enrolment_code_id' => $code->id,
                'device_uid' => $device['device_uid'],
                'platform' => $device['platform'],
                'public_key' => $device['public_key'],
                'hardware_hash' => self::hardwareHash($device['hardware_id']),
                'label' => $request['label'] ?? null,
                'claim_hash' => hash('sha256', $secret),
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->audit->record(
                action: 'device.rebind_requested',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['request' => $requestId, 'device_uid' => $device['device_uid'], 'platform' => $device['platform']],
                tenantId: $guard->tenant_id,
            );

            self::tellConsole($requestId, $guard);

            return ['status' => 'pending_approval', 'guard' => $guard, 'rebind_request_id' => $requestId, 'claim_secret' => $secret];
        });
    }

    /**
     * A supervisor's decision on a rebind, recorded against the guard's shift.
     *
     * THE SHIFT IS THE ONE THE GUARD IS ON NOW, or the next one rostered — the
     * shift the new handset will first clock. Recorded on the request so the
     * roster can say "handset replaced, approved by Owen Grant" beside it.
     */
    public function decideRebind(int $requestId, bool $approve, User $by, ?string $note = null): object
    {
        return DB::connection('mysql')->transaction(function () use ($requestId, $approve, $by, $note): object {
            $request = DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null || $request->status !== 'pending') {
                throw new DomainException('That rebind request has already been decided.');
            }

            $shift = Shift::query()
                ->where('guard_id', $request->guard_id)
                ->where('rostered_end', '>=', Carbon::now())
                ->orderBy('rostered_start')
                ->first();

            DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->update([
                'status' => $approve ? 'approved' : 'denied',
                'decided_by' => $by->getKey(),
                'decided_by_name' => $by->name,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
                'shift_id' => $shift?->id,
                'updated_at' => Carbon::now(),
            ]);

            $guard = Guard::query()->findOrFail($request->guard_id);

            $this->audit->record(
                action: $approve ? 'device.rebind_approved' : 'device.rebind_denied',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['request' => $requestId, 'shift' => $shift?->id, 'note' => $note],
                tenantId: $guard->tenant_id,
            );

            self::tellConsole($requestId, $guard);

            return DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->first();
        });
    }

    /**
     * The handset collecting its token after approval. The token is minted HERE,
     * at collection — the old handset keeps working until the new one is actually
     * in the guard's hand.
     *
     * @return array{status: string, token?: string, abilities?: list<string>, decision_note?: string|null}
     */
    public function collect(int $requestId, string $claimSecret, string $deviceUid): array
    {
        return DB::connection('mysql')->transaction(function () use ($requestId, $claimSecret, $deviceUid): array {
            $request = DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null || ! hash_equals((string) $request->claim_hash, hash('sha256', $claimSecret)) || $request->device_uid !== $deviceUid) {
                throw ApiError::notFound('not_found', 'No such rebind request for this handset.');
            }

            if ($request->status !== 'approved') {
                return ['status' => $request->status === 'pending' ? 'pending_approval' : (string) $request->status, 'decision_note' => $request->decision_note];
            }

            $guard = Guard::query()->findOrFail($request->guard_id);
            // The supervisor who approved the move is who removed the old phone (M32 F3): its binding is ended in their name.
            $result = $this->enrol($guard, $request->label, [
                'device_uid' => $request->device_uid,
                'platform' => $request->platform,
                'public_key' => $request->public_key,
                'hardware_hash' => $request->hardware_hash,
            ], $request->decided_by_name === null ? null : (string) $request->decided_by_name);

            DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->update(['status' => 'collected', 'updated_at' => Carbon::now()]);
            // The code that asked for this move now names the binding it made: the handset it can recover (M46 §1).
            if ($request->enrolment_code_id !== null) {
                DB::connection('mysql')->table('device_enrolment_codes')->where('id', $request->enrolment_code_id)->update(['binding_id' => $result['binding_id'], 'updated_at' => Carbon::now()]);
            }

            self::tellConsole($requestId, $guard);

            return ['status' => 'approved', 'token' => $result['token'], 'abilities' => $result['abilities']];
        });
    }

    /**
     * THE GUARD'S PAGE ON THE GEMINI CONSOLE MOVES WITH THE REQUEST (M18, found on production). Asked for, decided,
     * collected: each is told to the Gemini workforce feed as `request.changed`, which the guard's page reloads on,
     * so Approve / Refuse appears the moment the new handset asks and goes the moment somebody decides. A reference
     * only (RecordLive), in the write's own transaction. A guard on no estate has no feed to tell.
     */
    private static function tellConsole(int $requestId, Guard $guard): void
    {
        if ($guard->tenant_id === null) {
            return;
        }

        $tenant = (string) $guard->tenant_id;

        RecordLive::tell('request', $requestId, $tenant, static fn (): array => Channels::gemini('workforce', $tenant));
    }

    /**
     * The reviewer guard whose STATIC code this is, or null (M5 §3).
     *
     * Scanned over the flagged accounts only — there is at most a handful on the whole
     * platform — and compared with `hash_equals` through `ReviewerMode`, which refuses any
     * account that does not carry the flag however its hash column reads.
     */
    private function reviewerGuardFor(string $presented): ?Guard
    {
        foreach (Guard::query()->where('reviewer', true)->whereNotNull('reviewer_code_hash')->get() as $candidate) {
            if (ReviewerMode::codeMatches($candidate, $presented)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A SPENT CODE FROM THE HANDSET IT SET UP (M46 §1): the phone taking back a binding it already had, after its app
     * data was wiped — a reinstall, "Clear storage" — left it a new install with a new key. Null when it is not that.
     *
     * Every one of these must hold, or the caller answers exactly as it answers an unknown code, so a refusal tells
     * nobody which codes exist:
     *   - the code was used, and names the binding it made (or the one a recovery made after it);
     *   - that binding is still the code's guard's, and still open: not removed by the office, not replaced by another
     *     phone, not superseded by a later code — and no later binding on the same handset, another officer's, is open;
     *   - it carries a handset identity, and the phone presenting the code has the same one.
     *
     * ON ANY OTHER HANDSET A USED CODE STAYS DEAD: it never becomes a credential that moves. The old binding ends
     * `recovered`, the new install is bound in its place, and the code names the new binding. Audited as
     * `device.recovered` (`enrol()`), and mailed to every Head of Security who covers the estate.
     *
     * @param  array{enrolment_code: string, device_uid: string, platform: string, public_key: string, label?: string|null, hardware_id?: string|null}  $request
     * @return array{status: string, guard: Guard, token: string, abilities: list<string>, recovered: true}|null
     */
    private function recover(array $request): ?array
    {
        $hardware = self::hardwareHash($request['hardware_id'] ?? null);

        if ($hardware === null) {
            return null;
        }

        $codeHash = hash('sha256', strtoupper(trim($request['enrolment_code'])));

        $recovered = DB::connection('mysql')->transaction(function () use ($codeHash, $hardware, $request): ?array {
            $code = DB::connection('mysql')->table('device_enrolment_codes')
                ->where('code_hash', $codeHash)
                ->whereNotNull('used_at')
                ->whereNotNull('binding_id')
                ->lockForUpdate()
                ->first();

            $binding = $code === null ? null : DB::connection('mysql')->table('device_bindings')->where('id', $code->binding_id)->lockForUpdate()->first();

            if ($binding === null || (int) $binding->guard_id !== (int) $code->guard_id || $binding->ended_at !== null
                || ! is_string($binding->hardware_hash) || ! hash_equals($binding->hardware_hash, $hardware)) {
                return null;
            }

            // The handset is this guard's only while no later binding on it — another officer's — is open.
            $taken = DB::connection('mysql')->table('device_bindings')
                ->where('hardware_hash', $hardware)
                ->whereNull('ended_at')
                ->where('id', '>', $binding->id)
                ->exists();

            $guard = Guard::query()->find($code->guard_id);

            if ($taken || ! $guard instanceof Guard || $guard->device_uid !== $binding->device_uid) {
                return null;
            }

            if ($guard->status !== 'active') {
                throw ApiError::forbidden('guard_not_active', $guard->full_name.' is not on active duty, so a handset cannot be bound to them.');
            }

            $result = $this->enrol($guard, $request['label'] ?? null, [
                'device_uid' => $request['device_uid'],
                'platform' => $request['platform'],
                'public_key' => $this->publicKeyOf((string) $request['public_key']),
                'hardware_id' => $request['hardware_id'],
            ], null, ['code_id' => (int) $code->id, 'binding_id' => (int) $binding->id, 'device_uid' => (string) $binding->device_uid]);

            DB::connection('mysql')->table('device_enrolment_codes')->where('id', $code->id)->update(['binding_id' => $result['binding_id'], 'updated_at' => Carbon::now()]);

            return ['status' => 'enrolled', 'guard' => $guard, 'token' => $result['token'], 'abilities' => $result['abilities'], 'recovered' => true];
        });

        // Told once it is true: after the commit, and never in a way that could undo it.
        if ($recovered !== null) {
            $this->reportRecovery($recovered['guard']);
        }

        return $recovered;
    }

    /**
     * Is this the handset the guard's open binding was made on — the same phone, after a reinstall gave it a new
     * install (M46 §1)? Never true for a phone that sent no identity, or a binding that has none.
     */
    private function isTheBoundHandset(Guard $guard, ?string $hardwareId): bool
    {
        $hardware = self::hardwareHash($hardwareId);

        if ($hardware === null) {
            return false;
        }

        $open = DB::connection('mysql')->table('device_bindings')->where('guard_id', $guard->id)->whereNull('ended_at')->value('hardware_hash');

        return is_string($open) && hash_equals($open, $hardware);
    }

    /**
     * "THE HEAD OF SECURITY SEES IT" (M46 §1.2): a mail to every active Head of Security who covers the estate, at the
     * address they proved — `SupervisorOverride::report()`'s own rule — beside the `device.recovered` audit entry. Not
     * from the demonstration estate (nobody is woken for a guard who does not exist), and a mail that cannot be sent is
     * reported, never allowed to undo the recovery.
     */
    private function reportRecovery(Guard $guard): void
    {
        if ($guard->tenant_id === null || OperatorEstates::isDemonstration((string) $guard->tenant_id)) {
            return;
        }

        $tenant = (string) $guard->tenant_id;

        rescue(static function () use ($guard, $tenant): void {
            $heads = User::whoCanAccessEstate(
                User::query()->role(Role::HEAD_OF_SECURITY, 'web')->where('status', 'active')->get(),
                $tenant,
            )->filter(static fn (User $head): bool => $head->alertEmail() !== null)->values();

            if ($heads->isEmpty()) {
                return;
            }

            Notification::send($heads, CentralQueue::pin(new GuardPhoneRecovered(
                guardName: $guard->full_name,
                employeeNumber: (string) $guard->employee_number,
                estate: (string) ($guard->estate->name ?? $tenant),
                recoveredAt: SiteTime::stamp(Carbon::now(), $tenant, 'M j, Y g:i A'),
            )));
        });
    }

    /** The handset's public key as stored: base64url without padding, and refused unless it is 32 bytes of Ed25519. */
    private function publicKeyOf(string $presented): string
    {
        $publicKey = rtrim(strtr($presented, '+/', '-_'), '=');
        $decoded = base64_decode(strtr($publicKey, '-_', '+/').str_repeat('=', (4 - strlen($publicKey) % 4) % 4), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ApiError::unprocessable('public_key_invalid', 'The handset\'s public key must be an Ed25519 public key: 32 bytes, base64url-encoded.');
        }

        return $publicKey;
    }

    /**
     * Take a handset out of service. The device binding goes with the tokens, and its record says it was removed by
     * the office (M32 F3) — unless `enrol()` has already ended it as replaced or enrolled again.
     */
    public function revoke(Guard $guard): void
    {
        $this->endBindings($guard, null, null);

        $guard->tokens()->delete();

        if ($guard->device_id !== null) {
            $guard->forceFill(['device_id' => null])->save();
        }
    }

    /**
     * End this guard's open bindings (M32 F3), each with when, why and by whom: `replaced` by another phone (or the
     * phone set up for another officer, `$reason`), `reenrolled` with a code on the same one — the same install, or
     * the same handset under a new install (M46 §1) — `recovered` by that handset with its own spent code, or
     * `removed` (no phone follows it); by the supervisor named, the office, or — a recovery — the guard's own phone.
     */
    private function endBindings(Guard $guard, ?string $nextUid, ?string $decidedBy, ?string $reason = null, ?string $nextHardware = null): void
    {
        $open = DB::connection('mysql')->table('device_bindings')->where('guard_id', $guard->id)->whereNull('ended_at')->get(['id', 'device_uid', 'hardware_hash']);

        foreach ($open as $binding) {
            $samePhone = $nextUid !== null && ($binding->device_uid === $nextUid
                || ($nextHardware !== null && is_string($binding->hardware_hash) && hash_equals($binding->hardware_hash, $nextHardware)));

            DB::connection('mysql')->table('device_bindings')->where('id', $binding->id)->update([
                'ended_at' => Carbon::now(),
                'ended_reason' => $reason ?? ($nextUid === null ? 'removed' : ($samePhone ? 'reenrolled' : 'replaced')),
                'ended_by' => $reason === 'recovered' ? 'guard' : ($decidedBy === null ? 'office' : 'supervisor'),
                'ended_by_name' => $decidedBy,
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
