<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\ApiError;
use App\Models\DevicePushToken;
use App\Models\PersonalAccessToken;
use App\Models\ResidentAccount;
use App\Services\Audit\AuditLogger;
use App\Services\Reviewer\ReviewerMode;
use App\Support\SessionWindow;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EVERY PHONE SIGNED IN TO ONE RESIDENT APP ACCOUNT, AND SIGNING ONE OF THEM OUT (the client, 26 Sep: two phones on
 * the same account, and each could see only itself).
 *
 * A "phone" here is a sign-in: one API token, one per install (`auth.otp.verify` replaces an install's own). What is
 * listed is what the account's owner needs to recognise and end one — the model the phone gave at sign-in, its
 * platform, when it signed in and when it was last used — and nothing that identifies the handset beyond its model.
 *
 * A SIGN-IN PAST THE IDLE WINDOW IS STILL LISTED. `SessionWindow` refuses it until the person signs in again, but
 * keeps the row on purpose: the phone's push address hangs off it, so that phone is still told about somebody at
 * the gate. A lost phone is exactly that case, so it is listed ("timed out") and can be signed out here, which
 * deletes the row — and with it the push address.
 *
 * THE DEMONSTRATION ACCOUNT LISTS ONLY THE CALLER. Every reviewer and every member of the public who taps "Explore a
 * demo community" signs in to the same account; the other sessions are other people's.
 *
 * ENDING ONE IS NOT SIGNING OUT HERE. The caller's own sign-in is refused with the way to do that — "Sign out of this
 * phone", `auth.sign_out` — because that button also clears the phone, and this endpoint cannot.
 */
class ResidentSessions
{
    /** What every Resident App sign-in's token is named, before the install's own id (AuthController::signedIn). */
    public const TOKEN_PREFIX = 'resident-app:';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ResidentDeviceKeys $keys,
    ) {}

    /** What the phone calls itself, made safe to show: one line, printable, at most 80 characters — or null. */
    public static function label(?string $given): ?string
    {
        if ($given === null) {
            return null;
        }

        // Runs of white space (tabs and new lines among them) become one space FIRST, then any other control or
        // format character — a NUL, a right-to-left override that would turn a name round — is dropped.
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', (string) preg_replace('/\s+/u', ' ', $given)));

        // Android's emulator calls itself by its build ("sdk_gphone64_x86_64"): a person reads "Android emulator" (M49).
        if (preg_match('/^(sdk_gphone|sdk_gphone64|gphone|google_sdk|Android SDK built for)/i', $clean) === 1) {
            return 'Android emulator';
        }

        return $clean === '' ? null : mb_substr($clean, 0, 80);
    }

    /**
     * THE SAME HANDSET SIGNED IN AGAIN (M49: "seven active sessions across two phones, four of them the same phone"). A
     * reinstall gives the app a new install id, so "one token per install" alone kept every earlier install's sign-in
     * beside the new one. The handset's own id (Android's per-app device id, which a reinstall keeps) names the phone:
     * every OTHER sign-in of this account from the same handset is ended here — token and push address, audited — and
     * its fingerprint key with it. Returns how many were ended.
     */
    public function retireHandset(ResidentAccount $account, ?string $hardware, int $keep): int
    {
        if ($hardware === null || ReviewerMode::isReviewer($account)) {
            return 0;
        }

        $earlier = PersonalAccessToken::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->where('device_hardware', $hardware)
            ->whereKeyNot($keep)
            ->pluck('id');

        $ended = 0;

        foreach ($earlier as $id) {
            try {
                $this->end($account, (int) $id, $keep, 'the same phone signed in again');
                $ended++;
            } catch (ApiError) {
                // Ended a moment ago by another request: nothing left to end.
            }
        }

        return $ended;
    }

    /**
     * @return list<array{id: int, device: string|null, platform: string|null, signed_in_at: string|null, last_used_at: string|null, current: bool, timed_out: bool}>
     */
    public function of(ResidentAccount $account, ?int $current): array
    {
        $now = Carbon::now();

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->when(ReviewerMode::isReviewer($account), static fn ($query) => $query->whereKey($current ?? 0))
            ->get(['id', 'device_label', 'device_platform', 'created_at', 'last_used_at']);

        return $tokens
            // A sign-in past its idle window IS listed (`timed_out`): that phone still gets the household's notifications,
            // so it must stay where it can be signed out. What stops the list filling with ONE phone is retireHandset():
            // the same handset signing in again ends its earlier sign-ins (M49).
            ->map(static function (PersonalAccessToken $token) use ($current, $now): array {
                $touched = SessionWindow::lastTouched($token->last_used_at, $token->created_at);

                return [
                    'id' => (int) $token->id,
                    'device' => $token->getAttribute('device_label'),
                    'platform' => $token->getAttribute('device_platform'),
                    'signed_in_at' => $token->created_at === null ? null : SiteTime::iso($token->created_at),
                    'last_used_at' => $touched === null ? null : SiteTime::iso($touched),
                    'current' => (int) $token->id === $current,
                    'timed_out' => (int) $token->id !== $current && SessionWindow::expired($touched, $now),
                ];
            })
            // This phone first, then the one used most recently.
            ->sortBy([['current', 'desc'], ['last_used_at', 'desc']])
            ->values()
            ->all();
    }

    /**
     * Sign one of the account's OTHER phones out: its token is deleted, so its next request is refused, and its push
     * address goes with it.
     *
     * @return array{id: int, signed_out: bool}
     */
    public function end(ResidentAccount $account, int $session, ?int $current, string $by = 'the account holder, from another of their phones'): array
    {
        if ($session === $current) {
            throw ApiError::conflict('this_phone', 'This is the phone you are using. To sign it out, use "Sign out of this phone" — that also clears what the app keeps on it.');
        }

        $token = PersonalAccessToken::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->when(ReviewerMode::isReviewer($account), static fn ($query) => $query->whereRaw('1 = 0'))
            ->whereKey($session)
            ->first();

        // Another account's sign-in and one that does not exist are the same answer.
        if (! $token instanceof PersonalAccessToken) {
            throw ApiError::notFound('not_found', 'That phone is not signed in to your account any more.');
        }

        $before = ['device' => $token->getAttribute('device_label'), 'platform' => $token->getAttribute('device_platform'), 'signed_in_at' => $token->created_at?->toIso8601String()];

        $addresses = DB::connection('mysql')->transaction(function () use ($token, $account): int {
            $removed = 0;

            if (DB::connection('mysql')->getSchemaBuilder()->hasTable('device_push_tokens')) {
                $removed = DB::connection('mysql')->table('device_push_tokens')
                    ->where('principal_type', DevicePushToken::RESIDENT)
                    ->where('principal_id', $account->id)
                    ->where('access_token_id', $token->id)
                    ->delete();
            }

            $token->delete();

            return (int) $removed;
        });

        // That phone's fingerprint sign-in goes with its session (M49): signed out from elsewhere means signed out.
        $install = str_starts_with((string) $token->name, self::TOKEN_PREFIX) ? substr((string) $token->name, strlen(self::TOKEN_PREFIX)) : null;
        $keys = $install === null || $install === '' ? 0 : $this->keys->revoke($account, $install, 'signed_out_elsewhere');

        $this->audit->record(
            action: 'resident_app.phone_signed_out',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            before: $before,
            after: ['by' => $by, 'push_addresses_removed' => $addresses, 'fingerprint_keys_revoked' => $keys],
            tenantId: (string) $account->tenant_id,
        );

        return ['id' => $session, 'signed_out' => true];
    }

    /**
     * SIGN OUT EVERY OTHER PHONE AT ONCE (the client, 27 Sep: "add the button sign out all devices"). Each of this
     * account's sign-ins but the caller's is ended exactly as `end()` ends one — token and push address — and audited
     * one by one, so the record says which phones went. The phone asking stays signed in: that is "Sign out of this
     * phone", which also clears it. The demonstration account's other sign-ins are other people's and are not touched.
     *
     * @return array{signed_out: int}
     */
    public function endOthers(ResidentAccount $account, ?int $current): array
    {
        if (ReviewerMode::isReviewer($account)) {
            return ['signed_out' => 0];
        }

        $others = PersonalAccessToken::query()
            ->where('tokenable_type', $account->getMorphClass())
            ->where('tokenable_id', $account->getKey())
            ->when($current !== null, static fn ($query) => $query->whereKeyNot($current))
            ->pluck('id');

        $ended = 0;

        foreach ($others as $id) {
            try {
                $this->end($account, (int) $id, $current);
                $ended++;
            } catch (ApiError) {
                // Signed out a moment ago by another tap: nothing left to end.
            }
        }

        return ['signed_out' => $ended];
    }
}
