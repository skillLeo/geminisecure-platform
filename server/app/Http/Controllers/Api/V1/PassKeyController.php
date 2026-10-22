<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Passes\PassSigningKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/sites/{site}/pass-keys — the public half, for a handset to cache (13 D1).
 *
 * PUBLIC KEYS ONLY, and only the guard's own site. The secret half never leaves
 * `PassSigningKeys`; this controller could not return it if it tried, because
 * the method it calls reads the public column and nothing else.
 */
class PassKeyController extends Controller
{
    /** How often a handset should refresh its cache even without a sync. */
    private const REFRESH_HOURS = 6;

    public function index(string $site, DeviceContext $context, PassSigningKeys $keys): JsonResponse
    {
        if ($site !== $context->tenantId) {
            throw ApiError::forbidden('wrong_site', 'A handset caches the keys for the site it is posted to, and only those.');
        }

        $current = $keys->currentVersion($site);

        return response()->json([
            'site_id' => $site,
            'algorithm' => 'Ed25519',
            'keys' => collect($keys->publicKeys($site))
                ->map(static fn (string $key, int $version): array => ['version' => $version, 'public_key' => $key, 'current' => $version === $current])
                ->values()
                ->all(),
            'refresh_after' => Carbon::now()->addHours(self::REFRESH_HOURS)->toIso8601String(),
        ]);
    }
}
