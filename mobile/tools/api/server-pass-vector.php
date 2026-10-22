<?php
/**
 * Run by `php artisan tinker <this file>` from the Laravel project (read-only reference).
 * Signs ONE synthetic payload with the site's REAL current key — no pass row is written —
 * and saves the token beside the site's published public keys, so the app's verifier can be
 * tested against the server's own sodium implementation (invariant 2, cross-implementation).
 */
use App\Services\Passes\PassSigningKeys;
use App\Services\Passes\PassToken;

$site = 'phoenixpark';
$keys = app(PassSigningKeys::class);
$version = $keys->currentVersion($site);

$payload = [
    'pass_id' => '11111111-2222-4333-8444-555555555555',
    'tenant_id' => $site,
    'site_id' => $site,
    'valid_from' => '2026-09-04T14:00:00-05:00',
    'valid_to' => '2026-09-04T16:00:00-05:00',
    'single_use' => true,
    'nonce' => 'mobile-vector-1',
    'key_version' => $version,
];

$segment = PassToken::encodePayload($payload);
$signed = $keys->sign($site, $segment);
$token = $segment.'.'.PassToken::base64url($signed['signature']);

$out = getenv('GS_VECTOR_OUT');
file_put_contents($out, json_encode([
    'note' => 'Signed by the Laravel server with the real phoenixpark key. Regenerate: npm run api:vector',
    'site_id' => $site,
    'keys' => collect($keys->publicKeys($site))->map(fn ($k, $v) => ['version' => (int) $v, 'public_key' => $k])->values()->all(),
    'token' => $token,
    'payload' => $payload,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "vector written\n";
