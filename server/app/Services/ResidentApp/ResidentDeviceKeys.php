<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Models\ResidentAccount;
use App\Models\ResidentDeviceKey;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "FACE ID / FINGERPRINT" SIGNS A RESIDENT'S PHONE BACK IN (M49; the user, 8 October: "even the option in the setting for
 * biometric is on, it's still not asking me for the fingerprint and it's asking me to add the OTP").
 *
 * WHY A KEY. There is no password, and a signed-out phone keeps no token — so before this, nothing on a phone could prove
 * who held it, and "Log in with biometrics" could only say "log in with your email first". Now, turning the switch on
 * asks the server for a key for THAT install: a random secret, given to the phone once and kept here only as a hash. The
 * phone keeps it in its secure store behind its own biometric prompt, and it outlives a sign-out (not an uninstall). At
 * the log-in screen the phone's prompt opens the store, and the key — with the same install id it was issued to — signs
 * the phone in exactly as a proven emailed code does (AuthController::signedIn): one token for the install, and the
 * account's own checks first.
 *
 * WHEN IT STOPS WORKING — revoked here, never merely forgotten on the phone:
 *   switched_off          the resident turns "Face ID / fingerprint" off on that phone
 *   replaced              a newer key for the same install or the same handset
 *   signed_out_elsewhere  that phone is signed out from another of the account's phones (me.sessions.end)
 *   account_closed        the account is deleted, revoked by the estate office, or suspended — found at sign-in, and
 *                         every key of the account goes
 * A key used with another install's id, another estate, or after any of these is refused in the same words, and the
 * phone forgets it and says to log in with the emailed code.
 */
final class ResidentDeviceKeys
{
    /** Kept apart from the Guard App's handset hash, so the same phone's two apps are never confused. */
    public const HARDWARE_PREFIX = 'gs-resident-handset|';

    public const REFUSED = 'Fingerprint sign-in is not set up on this phone any more. Log in with your email address, then turn on Face ID / fingerprint in Settings → Security.';

    public function __construct(private readonly AuditLogger $audit) {}

    /** The handset identity as stored: a hash, or null when the phone sent none. */
    public static function hardwareHash(?string $hardwareId): ?string
    {
        $id = trim((string) $hardwareId);

        return $id === '' ? null : hash('sha256', self::HARDWARE_PREFIX.$id);
    }

    /**
     * A key for this install, returned ONCE in the clear. Any earlier key of the same install — or of the same handset,
     * an earlier install of this app on it — is revoked in the same transaction.
     */
    public function issue(ResidentAccount $account, string $deviceUid, string $platform, ?string $label, ?string $hardwareId): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hardware = self::hardwareHash($hardwareId);

        DB::connection((new ResidentDeviceKey)->getConnectionName())->transaction(function () use ($account, $deviceUid, $platform, $label, $hardware, $plain): void {
            $this->revokeWhere($account, $deviceUid, $hardware, 'replaced');

            ResidentDeviceKey::query()->create([
                'resident_account_id' => $account->id,
                'tenant_id' => (string) $account->tenant_id,
                'device_uid' => $deviceUid,
                'device_hardware' => $hardware,
                'platform' => $platform,
                'device_label' => ResidentSessions::label($label),
                'key_hash' => hash('sha256', $plain),
            ]);
        });

        $this->audit->record(
            action: 'resident_app.fingerprint_sign_in_on',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: null,
            after: ['device' => ResidentSessions::label($label), 'platform' => $platform],
            tenantId: (string) $account->tenant_id,
        );

        return $plain;
    }

    /** Every live key of this install revoked; how many. */
    public function revoke(ResidentAccount $account, string $deviceUid, string $reason): int
    {
        $revoked = $this->revokeWhere($account, $deviceUid, null, $reason);

        if ($revoked > 0) {
            $this->audit->record(
                action: 'resident_app.fingerprint_sign_in_off',
                entityType: 'ResidentAccount',
                entityId: (string) $account->id,
                before: null,
                after: ['why' => $reason, 'keys' => $revoked],
                tenantId: (string) $account->tenant_id,
            );
        }

        return $revoked;
    }

    /**
     * Somebody else signed in on this install: every OTHER account's live key for it stops working, so a phone handed
     * to another person never opens the last person's account with a fingerprint.
     */
    public function revokeOthersOnInstall(ResidentAccount $account, string $deviceUid): int
    {
        return ResidentDeviceKey::query()
            ->where('device_uid', $deviceUid)
            ->where('resident_account_id', '!=', $account->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => 'another_account', 'updated_at' => Carbon::now()]);
    }

    /** Every live key of the account revoked — the account itself is closed to sign-in. */
    public function revokeAll(ResidentAccount $account, string $reason): int
    {
        return ResidentDeviceKey::query()
            ->where('resident_account_id', $account->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason, 'updated_at' => Carbon::now()]);
    }

    /** Whether this install holds a live key — what "Face ID / fingerprint" shows on a phone just signed in. */
    public function liveFor(ResidentAccount $account, string $deviceUid): bool
    {
        return ResidentDeviceKey::query()
            ->where('resident_account_id', $account->id)
            ->where('device_uid', $deviceUid)
            ->whereNull('revoked_at')
            ->exists();
    }

    /**
     * The account a key signs in — or the refusal, with no token. The key must be live, issued to THIS install and THIS
     * estate; the account must still be one that may sign in (the same checks a proven code meets).
     */
    public function account(Tenant $estate, string $deviceUid, string $plainKey): ResidentAccount
    {
        $key = ResidentDeviceKey::query()->where('key_hash', hash('sha256', $plainKey))->first();

        if (! $key instanceof ResidentDeviceKey
            || ! $key->isLive()
            || ! hash_equals($key->device_uid, $deviceUid)
            || $key->tenant_id !== (string) $estate->getTenantKey()) {
            throw new ApiError(401, 'device_key_invalid', self::REFUSED);
        }

        $account = $key->account;

        if (! $account instanceof ResidentAccount) {
            throw new ApiError(401, 'device_key_invalid', self::REFUSED);
        }

        if ($account->isDeleted() || $account->isRevoked() || $account->status === ResidentAccount::SUSPENDED) {
            $this->revokeAll($account, 'account_closed');

            if ($account->isDeleted()) {
                throw new ApiError(401, 'device_key_invalid', 'This account was deleted. Log in with your email address to see whether it can be restored.');
            }

            throw ApiError::forbidden(
                $account->isRevoked() ? 'account_revoked' : 'account_suspended',
                $account->isRevoked()
                    ? 'The estate office has ended this account\'s access, so you cannot sign in to '.$estate->name.'. If this is wrong, contact the estate office — they can give the access back.'
                    : 'The estate has withdrawn this account\'s access. Contact the estate office.'
            );
        }

        $key->forceFill(['last_used_at' => Carbon::now()])->save();

        return $account;
    }

    private function revokeWhere(ResidentAccount $account, string $deviceUid, ?string $hardware, string $reason): int
    {
        return ResidentDeviceKey::query()
            ->where('resident_account_id', $account->id)
            ->whereNull('revoked_at')
            ->where(static function ($query) use ($deviceUid, $hardware): void {
                $query->where('device_uid', $deviceUid);

                if ($hardware !== null) {
                    $query->orWhere('device_hardware', $hardware);
                }
            })
            ->update(['revoked_at' => Carbon::now(), 'revoked_reason' => $reason, 'updated_at' => Carbon::now()]);
    }
}
