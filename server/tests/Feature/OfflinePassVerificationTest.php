<?php

declare(strict_types=1);

use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\PassSigningKeys;
use App\Services\Passes\PassToken;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A visitor pass verifies with the database unreachable — 13 D1, the gate
|--------------------------------------------------------------------------
|
| "The device holds only the public key and verifies signature, window and site
| locally with no database call. Test it with the database connection severed.
| That is the gate."
|
| The pass is issued while the database is up — that is the resident's app,
| online. Then every connection is pointed at a port nothing listens on and
| purged, the severance is proven, and the handset's verifier runs with only
| what a handset holds: the token from the QR code, the site's public keys it
| cached, the site it is posted to, and its own clock.
|
*/

const OFFLINE_CONNECTIONS = ['mysql', 'mysql_owner', 'tenant'];

function severEveryDatabase(): array
{
    $saved = [];

    foreach (OFFLINE_CONNECTIONS as $name) {
        $saved[$name] = config("database.connections.{$name}");

        config([
            "database.connections.{$name}.host" => '127.0.0.1',
            "database.connections.{$name}.port" => 1,
        ]);

        DB::purge($name);
    }

    return $saved;
}

function restoreEveryDatabase(array $saved): void
{
    foreach ($saved as $name => $config) {
        config(["database.connections.{$name}" => $config]);
        DB::purge($name);
    }
}

it('verifies a signed pass with every database connection severed', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $site = FacilitiesFixture::ESTATE;
    $issuedAt = Carbon::parse('2026-09-17 14:00:00', 'UTC');

    // ONLINE: the resident issues a pass for this afternoon.
    $pass = app(VisitorPasses::class)->issue($site, FacilitiesFixture::unit('Lot 47'), null, 'Andrea Fletcher', [
        'category' => 'single',
        'visitor_name' => 'Marcia James',
        'purpose' => 'Family visit',
        'valid_from' => $issuedAt->copy(),
        'valid_to' => $issuedAt->copy()->addHours(2),
    ]);

    // ONLINE: the handset caches the site's PUBLIC keys at its last sync.
    $cachedKeys = [$site => app(PassSigningKeys::class)->publicKeys($site)];
    $token = $pass->token;

    // A second key pair nobody at this estate holds — a forger's.
    $forger = sodium_crypto_sign_keypair();

    $saved = severEveryDatabase();

    try {
        // THE SEVERANCE IS REAL: no connection can reach a server.
        foreach (OFFLINE_CONNECTIONS as $name) {
            expect(fn () => DB::connection($name)->getPdo())->toThrow(PDOException::class);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $verifier = new OfflinePassVerifier;
        $atGate = $issuedAt->copy()->addMinutes(30);

        // 1. The pass, as scanned: valid, with no network and no database.
        $valid = $verifier->verify($token, $cachedKeys, $site, $atGate);

        expect($valid['verdict'])->toBe(OfflinePassVerifier::VALID_OFFLINE)
            ->and($valid['pass_id'])->toBe($pass->pass_id)
            ->and($valid['single_use'])->toBeTrue();

        // 2. One byte of the payload changed: the signature no longer holds.
        [$segment, $signature] = explode('.', $token);
        $payload = json_decode((string) PassToken::unbase64url($segment), true);
        $payload['valid_to'] = $issuedAt->copy()->addYear()->toIso8601ZuluString();
        $tampered = PassToken::base64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES)).'.'.$signature;

        expect($verifier->verify($tampered, $cachedKeys, $site, $atGate)['verdict'])->toBe(OfflinePassVerifier::BAD_SIGNATURE);

        // 3. A pass signed with a key this estate never issued.
        $forgedSegment = PassToken::encodePayload([...$payload, 'valid_to' => $issuedAt->copy()->addHours(2)->toIso8601ZuluString()]);
        $forged = $forgedSegment.'.'.PassToken::base64url(sodium_crypto_sign_detached($forgedSegment, sodium_crypto_sign_secretkey($forger)));

        expect($verifier->verify($forged, $cachedKeys, $site, $atGate)['verdict'])->toBe(OfflinePassVerifier::BAD_SIGNATURE);

        // 4. The window, on the handset's own clock.
        expect($verifier->verify($token, $cachedKeys, $site, $issuedAt->copy()->addHours(3))['verdict'])->toBe(OfflinePassVerifier::EXPIRED)
            ->and($verifier->verify($token, $cachedKeys, $site, $issuedAt->copy()->subHour())['verdict'])->toBe(OfflinePassVerifier::NOT_YET_VALID);

        // 5. The site: a guard posted elsewhere refuses it, and a key version the handset never cached is unknown.
        expect($verifier->verify($token, $cachedKeys, 'someotherestate', $atGate)['verdict'])->toBe(OfflinePassVerifier::WRONG_SITE)
            ->and($verifier->verify($token, [$site => []], $site, $atGate)['verdict'])->toBe(OfflinePassVerifier::UNKNOWN_KEY)
            ->and($verifier->verify('not-a-pass', $cachedKeys, $site, $atGate)['verdict'])->toBe(OfflinePassVerifier::MALFORMED);

        // AND NOT ONE QUERY WAS ATTEMPTED.
        expect($queries)->toBe(0);
    } finally {
        restoreEveryDatabase($saved);
        FacilitiesFixture::boot();
    }
});

it('keeps the signing secret on the server, encrypted, and publishes only public keys', function () {
    FacilitiesFixture::boot();

    $site = FacilitiesFixture::ESTATE;
    $keys = app(PassSigningKeys::class);
    $public = $keys->publicKeys($site);

    $row = DB::connection('mysql')->table('pass_signing_keys')->where('site_id', $site)->orderByDesc('key_version')->first();

    // What a handset receives is 32-byte public keys and nothing else.
    foreach ($public as $version => $encoded) {
        expect(strlen((string) PassToken::unbase64url($encoded)))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    // The secret column is ciphertext: not the key, not base64 of the key, and it decrypts only with the app key.
    expect($row->secret_key_encrypted)->not->toContain($row->public_key)
        ->and(PassToken::unbase64url((string) $row->secret_key_encrypted))->toBeNull()
        ->and(json_encode($public))->not->toContain((string) $row->secret_key_encrypted);

    // Rotation keeps the old public key published, so passes it signed still verify.
    $before = $keys->currentVersion($site);
    $after = $keys->rotate($site);

    expect($after)->toBe($before + 1)
        ->and(array_keys($keys->publicKeys($site)))->toContain($before)
        ->and(array_keys($keys->publicKeys($site)))->toContain($after);
});
