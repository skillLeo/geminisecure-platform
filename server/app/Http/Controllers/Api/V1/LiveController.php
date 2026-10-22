<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Live\LiveAuthController;
use App\Http\Controllers\Live\LiveCatchUpController;
use App\Live\CatchUp\CatchUp;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Grants;
use App\Live\Auth\Principal;
use App\Live\Auth\Signer;
use App\Live\ChannelName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Live updates while the app is open, for both apps (M11 Part C, DESIGN §3.4, §8.1).
 *
 *   GET  /api/v1/live/config   where the socket is, and which channels this handset holds
 *   POST /api/v1/live/auth     the grants for those channels, bound to one socket
 *
 * Under `alerts:read`, the one capability both apps' tokens already carry — a new
 * capability would be missing from every token already issued — and `pending` is
 * allowed, because a pending account still has its own channel and its claim's. What a
 * token may hold is then decided channel by channel in `ChannelRegistry`, each rule
 * asking the token for the ability of the data it carries: the same rules as the API.
 *
 * NO HOST IN THE CONFIG. The phone derives the socket's host from the API URL it already
 * has, so there is one address to configure and nothing here can point a handset at
 * somewhere else.
 */
final class LiveController extends Controller
{
    public function config(DeviceContext $context, ChannelRegistry $registry): JsonResponse
    {
        self::assertConfigured();

        $principal = Principal::device($context);

        $port = config('live.public_ws_port') ?? config('broadcasting.connections.reverb.options.port', 443);
        $scheme = config('broadcasting.connections.reverb.options.scheme', 'https');
        $build = config('live.build');

        return response()->json([
            'ws' => [
                'key' => (string) config('broadcasting.connections.reverb.key'),
                'port' => is_numeric($port) ? (int) $port : 443,
                'scheme' => $scheme === 'http' ? 'http' : 'https',
                'path' => '',
            ],
            'channels' => array_map(static fn (string $name): array => [
                'name' => $name,
                'streams' => $registry->streamsFor($name),
            ], $registry->handsetChannels($principal)),
            'pulse' => ChannelName::PULSE,
            'build' => is_string($build) && $build !== '' ? $build : null,
            'principal' => $principal->key(),
        ]);
    }

    public function auth(Request $request, DeviceContext $context, ChannelRegistry $registry, Signer $signer, Grants $grants): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:'.LiveAuthController::SOCKET_ID],
            'channel_names' => ['required', 'array', 'list', 'min:1', 'max:'.LiveAuthController::MAX_CHANNELS],
            'channel_names.*' => ['required', 'string', 'max:200'],
        ]);

        self::assertConfigured();

        $principal = Principal::device($context);
        $socketId = (string) $data['socket_id'];

        /** @var list<string> $wires */
        $wires = array_values(array_unique(array_map('strval', (array) $data['channel_names'])));

        $granted = LiveAuthController::grant($registry, $principal, $wires);

        $grants->record($principal, array_values(array_map(static fn (ChannelName $c): string => $c->name, $granted)), $socketId);

        return response()->json([
            'channels' => array_map(static function (string $wire) use ($granted, $signer, $socketId, $principal): array {
                $channel = $granted[$wire] ?? null;

                if ($channel === null) {
                    return ['name' => $wire, 'auth' => null, 'channel_data' => null, 'streams' => [], 'refused' => true];
                }

                return ['name' => $wire, ...$signer->sign($wire, $socketId, $principal), 'streams' => $channel->streams(), 'refused' => false];
            }, $wires),
        ]);
    }

    /**
     * `POST /api/v1/live/catch-up`: the handset's "everything after N", in the web door's shape
     * (§5.1), and the fallback poll wherever the socket cannot connect (§5.6). It needs no Reverb,
     * so it answers whether or not live updates are configured; a pending account is answered for
     * its own channel and its claim's, and refused the rest.
     */
    public function catchUp(Request $request, DeviceContext $context, CatchUp $catchUp): JsonResponse
    {
        return response()->json($catchUp->answer(Principal::device($context), LiveCatchUpController::validated($request)));
    }

    private static function assertConfigured(): void
    {
        if (! Signer::configured()) {
            throw new ApiError(503, 'live_unavailable', 'Live updates are not available from this server right now. The app keeps refreshing itself.');
        }
    }
}
