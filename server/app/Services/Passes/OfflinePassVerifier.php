<?php

declare(strict_types=1);

namespace App\Services\Passes;

use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Verifies a signed visitor pass WITH NO DATABASE, NO NETWORK AND NO FRAMEWORK (13 D1).
 *
 * This class is the specification of what a Guard App handset does at a gate
 * with no signal, written as the code the server itself runs first. It takes
 * the token, the site's PUBLIC keys the handset cached while it was online, the
 * site the handset is posted to, and the handset's clock — and returns a
 * verdict. It touches nothing else: no facade, no model, no config, no clock of
 * its own. `OfflinePassVerificationTest` runs it with every database connection
 * pointed at a port nothing listens on.
 *
 * WHAT IT CANNOT KNOW, and says so. A pass cancelled by the resident after the
 * handset last synced still verifies here, and a single-use pass already used
 * at another gate still verifies here. Both are reconciled when the handset
 * syncs (`POST /gate/verify` online, and `POST /sync/batch` for admissions made
 * offline). That window is the accepted, documented risk of offline admission:
 * the verdict is `valid_offline`, never `valid`, and the handset shows it as
 * "valid pass — not checked against cancellations".
 *
 * THE SECRET KEY IS NEVER HERE. Verification needs only the public key, which is
 * why a handset can hold it and a stolen handset can forge nothing.
 */
final class OfflinePassVerifier
{
    public const VALID_OFFLINE = 'valid_offline';

    public const MALFORMED = 'malformed';

    public const UNKNOWN_KEY = 'unknown_key';

    public const BAD_SIGNATURE = 'bad_signature';

    public const WRONG_SITE = 'wrong_site';

    public const NOT_YET_VALID = 'not_yet_valid';

    public const EXPIRED = 'expired';

    /**
     * How far a handset's clock may disagree before a window edge is given the
     * benefit of the doubt. The same two minutes the API allows for skew.
     */
    public const CLOCK_TOLERANCE_SECONDS = 120;

    /**
     * @param  array<string, array<int, string>>  $publicKeys  site_id => key_version => base64url public key, as cached on the handset
     * @return array{verdict: string, pass_id: string|null, valid_to: string|null, single_use: bool|null}
     */
    public function verify(string $token, array $publicKeys, string $postedSiteId, DateTimeInterface $now): array
    {
        $parsed = PassToken::parse($token);

        if ($parsed === null) {
            return $this->result(self::MALFORMED);
        }

        $payload = $parsed['payload'];
        $siteId = (string) $payload['site_id'];
        $version = (int) $payload['key_version'];

        /*
         * The site first: a pass for another estate is refused before any key
         * is looked up. Nothing from an unverified payload is echoed back — the
         * pass id of a forged token is not a fact.
         */
        if ($siteId !== $postedSiteId) {
            return $this->result(self::WRONG_SITE);
        }

        $encodedKey = $publicKeys[$siteId][$version] ?? null;
        $publicKey = $encodedKey === null ? null : PassToken::unbase64url($encodedKey);

        if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $this->result(self::UNKNOWN_KEY);
        }

        if (! sodium_crypto_sign_verify_detached($parsed['signature'], $parsed['segment'], $publicKey)) {
            return $this->result(self::BAD_SIGNATURE);
        }

        try {
            $from = new DateTimeImmutable((string) $payload['valid_from']);
            $to = new DateTimeImmutable((string) $payload['valid_to']);
        } catch (Throwable) {
            return $this->result(self::MALFORMED);
        }

        $at = $now->getTimestamp();

        if ($at + self::CLOCK_TOLERANCE_SECONDS < $from->getTimestamp()) {
            return $this->result(self::NOT_YET_VALID, $payload);
        }

        if ($at - self::CLOCK_TOLERANCE_SECONDS > $to->getTimestamp()) {
            return $this->result(self::EXPIRED, $payload);
        }

        return $this->result(self::VALID_OFFLINE, $payload);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{verdict: string, pass_id: string|null, valid_to: string|null, single_use: bool|null}
     */
    private function result(string $verdict, ?array $payload = null): array
    {
        return [
            'verdict' => $verdict,
            'pass_id' => $payload === null ? null : (string) $payload['pass_id'],
            'valid_to' => $payload === null ? null : (string) $payload['valid_to'],
            'single_use' => $payload === null ? null : (bool) $payload['single_use'],
        ];
    }
}
