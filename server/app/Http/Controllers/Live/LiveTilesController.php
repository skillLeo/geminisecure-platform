<?php

declare(strict_types=1);

namespace App\Http\Controllers\Live;

use App\Http\Controllers\Controller;
use App\Live\Tiles\TileRegistry;
use App\Models\User;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * `GET /live/tiles?keys[]=duress_open&keys[]=…` — the counts a screen re-reads (DESIGN.md §4.4).
 *
 *   → {"tiles": {"duress_open": 3, "nope": null}, "as_of": "2026-10-02T14:03:11+00:00"}
 *
 * Each tile is asked its own gate for this viewer; one they may not see, and one that does not
 * exist, both answer null, so the endpoint says nothing about which tiles there are. Values come
 * from the per-audience cache (TileRegistry).
 */
final class LiveTilesController extends Controller
{
    public const MAX_KEYS = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'keys' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_KEYS],
            'keys.*' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{0,63}\z/'],
        ]);

        $tiles = [];

        foreach (array_values(array_unique(array_map('strval', (array) $data['keys']))) as $key) {
            $tiles[$key] = TileRegistry::read($key, $user);
        }

        return response()->json([
            'tiles' => $tiles === [] ? new stdClass : $tiles,
            'as_of' => SiteTime::iso(Carbon::now()),
        ]);
    }
}
