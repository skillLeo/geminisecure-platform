<?php

declare(strict_types=1);

namespace App\Services\Devices;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Models\DevicePushToken;
use App\Models\Guard;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Dispatch\SupervisorOverride;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SIGNING BACK IN ON A PHONE THAT IS STILL THE GUARD'S, WITHOUT A CODE (M32 F3).
 *
 * "A signed-out guard gets back in with his fingerprint, never a code." The enrolment code binds a phone once. After
 * that, a guard signed out by the 30-minute rule, or by a sign-out they chose, signs back in on that phone alone: the
 * PHONE proves itself with the key it was bound with, and the PERSON proves themselves to the phone (a fingerprint or
 * a face, which never leaves it) or — when the phone cannot match one — to this server, with a supervisor's PIN checked
 * exactly as the clock-in override checks it.
 *
 *   challenge   a one-time challenge for an install: 32 random bytes, two minutes, one use, kept as a hash in the cache
 *               under the install's id. Asking again replaces it.
 *   resume      the install, the challenge, and an Ed25519 signature over "gs-resume-v1|<install>|<challenge>" made with
 *               the bound key, then the method:
 *                 check           is this install still bound? Nothing is issued; it is what the phone's screens say
 *                 biometric       the phone has just matched the guard: a new token
 *                 supervisor_pin  a supervisor's address and PIN, checked by `SupervisorOverride`: a new token
 *
 * THE KEY IS WHAT IS PROVEN. Nothing is said about an install until its signature checks out against the key it was
 * bound with: an unknown install and a wrong signature get the same answer, so this tells nobody which phones exist.
 *
 * A REMOVED PHONE IS TOLD WHEN, AND BY WHOM. A binding is kept after it ends (`device_bindings`), with its key, so a
 * phone another phone replaced can still prove itself and be answered in one line, the date the estate's own: "Your
 * supervisor removed this phone on 2 October. Ask the office for a new code."
 *
 * A NEW TOKEN; THE OLD ONES GONE; THE PUSH ADDRESS KEPT. The phone's alerts must not stop for a moment, so the guard's
 * live push addresses move to the new token in the same transaction that deletes the old ones. Every sign-in this way
 * is audited (`device.resumed`), with the method, and the supervisor where it was their PIN.
 */
final class DeviceResume
{
    /** How long a challenge may be answered. */
    public const CHALLENGE_SECONDS = 120;

    public const METHODS = ['check', 'biometric', 'supervisor_pin'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisorOverride $override,
    ) {}

    /**
     * A one-time challenge for this install.
     *
     * @return array{challenge: string, expires_at: string}
     */
    public function challenge(string $deviceUid): array
    {
        $challenge = self::base64Url(random_bytes(32));

        Cache::put(self::cacheKey($deviceUid), hash('sha256', $challenge), self::CHALLENGE_SECONDS);

        return ['challenge' => $challenge, 'expires_at' => (string) SiteTime::iso(Carbon::now()->addSeconds(self::CHALLENGE_SECONDS))];
    }

    /** What the phone signs: the install and the challenge, so neither can be lifted onto another. */
    public static function message(string $deviceUid, string $challenge): string
    {
        return 'gs-resume-v1|'.$deviceUid.'|'.$challenge;
    }

    /**
     * Sign back in, or say why not.
     *
     * @param  array{device_uid: string, challenge: string, signature: string, method: string, supervisor?: string|null, pin?: string|null, hardware_id?: string|null}  $request
     * @return array{state: 'bound'|'resumed', guard: Guard, token?: string, abilities?: list<string>}
     */
    public function resume(array $request): array
    {
        $uid = $request['device_uid'];

        // One use, whatever the answer: the challenge is taken off the shelf the moment it is presented.
        $expected = Cache::pull(self::cacheKey($uid));

        if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $request['challenge']))) {
            throw ApiError::unprocessable('challenge_expired', 'That sign-in took too long to reach the server, or was already used. Try again.');
        }

        // The install's binding: the one open now if there is one, else the latest — the guard it was bound to when it was removed.
        $binding = DB::connection('mysql')->table('device_bindings')->where('device_uid', $uid)
            ->orderByRaw('ended_at IS NULL DESC')->orderByDesc('id')->first()
            ?? self::fromGuardRecord($uid);

        if ($binding === null || ! self::signed((string) $binding->public_key, $uid, $request['challenge'], $request['signature'])) {
            throw self::notEnrolled();
        }

        $guard = Guard::query()->find($binding->guard_id);

        if (! $guard instanceof Guard) {
            throw self::notEnrolled();
        }

        if ($binding->ended_at !== null || $guard->device_uid !== $uid || ! $guard->deviceIsBound()) {
            throw self::removed($binding, $guard);
        }

        // ResolveDevice's own rule: a guard not on active duty has no handset that can act for them.
        if ($guard->status !== 'active') {
            throw ApiError::forbidden('guard_not_active', $guard->full_name.' is not on active duty, so this phone cannot sign in for them. Ask the office.');
        }

        self::recordHandset($binding, $request['hardware_id'] ?? null);

        if ($request['method'] === 'check') {
            return ['state' => 'bound', 'guard' => $guard];
        }

        $supervisor = null;

        if ($request['method'] === 'supervisor_pin') {
            $supervisor = $this->override->verify((string) ($request['supervisor'] ?? ''), (string) ($request['pin'] ?? ''), (string) $guard->tenant_id);

            if (! $supervisor instanceof User) {
                // The clock-in override's one sentence for every kind of "no" (ClockInOverrideController).
                throw ApiError::forbidden('override_refused', 'That supervisor and PIN were not accepted. Check both and try again. After '.SupervisorOverride::MAX_FAILED_ATTEMPTS.' wrong tries a PIN is locked until the supervisor sets a new one in the console.');
            }
        }

        return DB::connection('mysql')->transaction(function () use ($guard, $request, $supervisor, $uid): array {
            $abilities = AppMatrix::abilitiesFor(AppMatrix::GUARD);
            $token = $guard->createToken(name: $guard->device_label ?? ('Handset — '.$guard->employee_number), abilities: $abilities);
            $tokenId = (int) $token->accessToken->getKey();

            // The phone's alerts do not stop for a moment: its push addresses move to the new token before the old ones go.
            DevicePushToken::query()->live()
                ->where('principal_type', DevicePushToken::GUARD)
                ->where('principal_id', $guard->id)
                ->update(['access_token_id' => $tokenId]);

            $guard->tokens()->whereKeyNot($tokenId)->delete();

            $this->audit->record(
                action: 'device.resumed',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: [
                    'method' => $request['method'],
                    'device_uid' => $uid,
                    ...($supervisor instanceof User ? ['supervisor' => $supervisor->id, 'supervisor_name' => $supervisor->name] : []),
                ],
                tenantId: $guard->tenant_id,
            );

            return ['state' => 'resumed', 'guard' => $guard, 'token' => $token->plainTextToken, 'abilities' => $abilities];
        });
    }

    /** Is this the bound key's signature over this install and this challenge? */
    public static function signed(string $publicKey, string $deviceUid, string $challenge, string $signature): bool
    {
        $key = self::fromBase64Url($publicKey);
        $sig = self::fromBase64Url($signature);

        if ($key === null || $sig === null || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::message($deviceUid, $challenge), $key);
    }

    /**
     * WHICH HANDSET THIS IS, FOR A BINDING THAT DOES NOT SAY (M46 §1). A phone bound before handsets were recorded
     * names its own once its key has proven it is the bound install — and that is what lets the same handset recover
     * this binding with its spent code after a reinstall. Recorded once and never overwritten; a phone that sends none
     * changes nothing.
     */
    private static function recordHandset(object $binding, ?string $hardwareId): void
    {
        $hardware = DeviceEnrolment::hardwareHash($hardwareId);

        if ($hardware === null || isset($binding->hardware_hash)) {
            return;
        }

        DB::connection('mysql')->table('device_bindings')->where('id', $binding->id)->whereNull('hardware_hash')->update(['hardware_hash' => $hardware, 'updated_at' => Carbon::now()]);
    }

    /**
     * A phone bound before bindings were kept — between the migration that recorded every bound phone and this code —
     * has its binding on the guard's own row only. It is recorded now, from that row, and answered like any other.
     */
    private static function fromGuardRecord(string $uid): ?object
    {
        $guard = Guard::query()->where('device_uid', $uid)->whereNotNull('device_id')->first();

        if (! $guard instanceof Guard || $guard->device_public_key === null) {
            return null;
        }

        $id = DB::connection('mysql')->table('device_bindings')->insertGetId([
            'guard_id' => $guard->id,
            'tenant_id' => $guard->tenant_id,
            'device_uid' => $uid,
            'public_key' => $guard->device_public_key,
            'bound_at' => $guard->device_enrolled_at ?? Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return DB::connection('mysql')->table('device_bindings')->where('id', $id)->first();
    }

    private static function notEnrolled(): ApiError
    {
        return ApiError::forbidden('device_not_enrolled', 'This phone has not been set up for GeminiSecure yet. Ask the office for a code.');
    }

    /** §6.3's second case, in one line: when, and by whom, on the estate's own calendar. */
    private static function removed(object $binding, Guard $guard): ApiError
    {
        $at = $binding->ended_at !== null ? Carbon::parse((string) $binding->ended_at, 'UTC') : null;
        // `guard`: the handset recovered its binding under a new install of the app (M46 §1), and this copy was left behind.
        $by = in_array($binding->ended_by, ['supervisor', 'guard'], true) ? (string) $binding->ended_by : 'office';
        $on = $at === null ? '' : SiteTime::local($at, $guard->tenant_id === null ? null : (string) $guard->tenant_id)->format('j F');

        // A date, on the estate's calendar: `local()`, not `stamp()`, whose zone tag belongs beside a time in a list.
        $message = match (true) {
            $at === null => 'This phone is no longer set up for '.$guard->full_name.'. Ask the office for a new code.',
            $by === 'guard' => 'This phone was set up again for '.$guard->full_name.' on '.$on.', and this copy of the app was signed out then. Use the app it was set up on.',
            default => ($by === 'supervisor' ? 'Your supervisor' : 'The office').' removed this phone on '.$on.'. Ask the office for a new code.',
        };

        return new ApiError(403, 'device_removed', $message, details: ['removed_at' => SiteTime::iso($at), 'removed_by' => $by]);
    }

    private static function cacheKey(string $deviceUid): string
    {
        return 'device-resume:'.hash('sha256', $deviceUid);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function fromBase64Url(string $text): ?string
    {
        $plain = strtr(rtrim($text, '='), '-_', '+/');
        $decoded = base64_decode($plain.str_repeat('=', (4 - strlen($plain) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
