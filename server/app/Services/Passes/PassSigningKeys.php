<?php

declare(strict_types=1);

namespace App\Services\Passes;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Each site's Ed25519 signing keys (13 D1).
 *
 * THE SECRET KEY NEVER LEAVES THIS CLASS. It is generated here, encrypted with
 * the application key before it touches the database, and decrypted only inside
 * `sign()`. `publicKeys()` — what a handset caches — reads the public column and
 * nothing else, and `PassSigningKeysTest` asserts no response, audit entry or
 * log line carries the encrypted or plain secret.
 *
 * ROTATION IS PER SITE, AND OLD KEYS STAY PUBLISHED. A pass carries the version
 * that signed it. Rotating retires the current key for SIGNING, but its public
 * half stays in `publicKeys()` until every pass it signed has expired — a
 * handset that stopped trusting yesterday's key would refuse visitors holding
 * passes issued an hour before the rotation.
 */
class PassSigningKeys
{
    /** How long a retired key's public half is still served. The longest a pass may run. */
    public const RETIRED_KEY_GRACE_DAYS = 31;

    /**
     * Sign a canonical payload segment with the site's current key.
     *
     * @return array{signature: string, key_version: int}
     */
    public function sign(string $siteId, string $segment): array
    {
        $key = $this->current($siteId);
        $secret = PassToken::unbase64url(Crypt::decryptString((string) $key->secret_key_encrypted));

        if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException("The signing key for site [{$siteId}] could not be read.");
        }

        $signature = sodium_crypto_sign_detached($segment, $secret);
        sodium_memzero($secret);

        return ['signature' => $signature, 'key_version' => (int) $key->key_version];
    }

    /** The version a new pass for this site will be signed with, creating the first key if needed. */
    public function currentVersion(string $siteId): int
    {
        return (int) $this->current($siteId)->key_version;
    }

    /**
     * Sign several payloads with the site's current key, read once — a resident's e-pass, half an hour at a time
     * (M47 §6). Each segment is built by `$segmentOf` with the version that will sign it, so the version a payload
     * names and the key that signs it can never disagree.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T, int): string  $segmentOf
     * @return list<array{segment: string, signature: string, key_version: int}>
     */
    public function signEach(string $siteId, array $items, callable $segmentOf): array
    {
        $key = $this->current($siteId);
        $version = (int) $key->key_version;
        $secret = PassToken::unbase64url(Crypt::decryptString((string) $key->secret_key_encrypted));

        if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException("The signing key for site [{$siteId}] could not be read.");
        }

        try {
            $signed = [];
            foreach ($items as $item) {
                $segment = $segmentOf($item, $version);
                $signed[] = ['segment' => $segment, 'signature' => sodium_crypto_sign_detached($segment, $secret), 'key_version' => $version];
            }

            return $signed;
        } finally {
            sodium_memzero($secret);
        }
    }

    /**
     * What a handset caches: every public key a still-valid pass may carry.
     *
     * @return array<int, string> key_version => base64url public key
     */
    public function publicKeys(string $siteId): array
    {
        $this->current($siteId);

        return DB::connection('mysql')
            ->table('pass_signing_keys')
            ->where('site_id', $siteId)
            ->where(fn ($query) => $query
                ->whereNull('retired_at')
                ->orWhere('retired_at', '>=', Carbon::now()->subDays(self::RETIRED_KEY_GRACE_DAYS)))
            ->orderBy('key_version')
            ->pluck('public_key', 'key_version')
            ->map(static fn ($key): string => (string) $key)
            ->all();
    }

    /** Retire the current key and activate a new one. Returns the new version. */
    public function rotate(string $siteId): int
    {
        return DB::connection('mysql')->transaction(function () use ($siteId): int {
            $latest = (int) DB::connection('mysql')->table('pass_signing_keys')
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->max('key_version');

            DB::connection('mysql')->table('pass_signing_keys')
                ->where('site_id', $siteId)
                ->whereNull('retired_at')
                ->update(['retired_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            return $this->generate($siteId, $latest + 1);
        });
    }

    private function current(string $siteId): object
    {
        $key = DB::connection('mysql')
            ->table('pass_signing_keys')
            ->where('site_id', $siteId)
            ->whereNull('retired_at')
            ->orderByDesc('key_version')
            ->first();

        if ($key !== null) {
            return $key;
        }

        $this->generate($siteId, 1);

        return DB::connection('mysql')->table('pass_signing_keys')
            ->where('site_id', $siteId)
            ->whereNull('retired_at')
            ->orderByDesc('key_version')
            ->firstOrFail();
    }

    private function generate(string $siteId, int $version): int
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        DB::connection('mysql')->table('pass_signing_keys')->insertOrIgnore([
            'site_id' => $siteId,
            'key_version' => $version,
            'public_key' => PassToken::base64url($public),
            'secret_key_encrypted' => Crypt::encryptString(PassToken::base64url($secret)),
            'activated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        sodium_memzero($secret);
        sodium_memzero($pair);

        return $version;
    }
}
