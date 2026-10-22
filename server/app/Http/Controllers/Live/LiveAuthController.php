<?php

declare(strict_types=1);

namespace App\Http\Controllers\Live;

use App\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Grants;
use App\Live\Auth\Principal;
use App\Live\Auth\Signer;
use App\Live\ChannelName;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * `POST /live/auth` — a console session's live channels, signed (M11 Part C, DESIGN §3.4).
 *
 * In the web middleware group (so `EndSessionOfInactiveAccount` has already signed out a
 * suspended account, and `auth` answers it 401), without the two members that build a
 * page, and throttled per principal (`live-auth`): a batch counts once.
 *
 * TWO FORMS.
 *
 *   batch   {"socket_id": "…", "channel_names": ["private-live.user.5", …]}   up to 200
 *           → 200 {"channels": {"<name>": {"auth", "channel_data", "streams"}}, "refused": ["<name>"]}
 *   single  {"socket_id": "…", "channel_name": "private-live.user.5"}         Pusher's own
 *           → 200 {"auth", "channel_data"}, or 403 with the one refusal body
 *
 * Names are wire names, exactly as the socket subscribes to them, and the answer is keyed
 * by the name as sent. Only `private-live.` names are ever signed here: a presence alias,
 * a legacy `private-estate.*` name and anything the grammar does not read are refused
 * like any other refusal. The legacy channel keeps its own door, `/broadcasting/auth`.
 *
 * ONE REFUSAL. A malformed name, another estate's channel, a record that is not yours
 * and one that does not exist all get the same answer, so the door says nothing about
 * what exists (§3.2). Validation of the request itself — a socket id that is not one, an
 * empty or oversized batch — is a 422, which says nothing about any channel.
 */
final class LiveAuthController extends Controller
{
    /** The most channels one batch may ask for; catch-up takes the same (§5.1). */
    public const MAX_CHANNELS = 200;

    /** The one body every single-form refusal carries, byte for byte. */
    public const REFUSAL = ['error' => ['code' => 'channel_refused', 'message' => 'This live channel is not available to you.']];

    /** A socket id as Pusher and Reverb write it. */
    public const SOCKET_ID = '/\A\d{1,15}\.\d{1,15}\z/';

    public function __invoke(Request $request, ChannelRegistry $registry, Signer $signer, Grants $grants): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(self::REFUSAL, 403);
        }

        $batch = $request->has('channel_names');

        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:'.self::SOCKET_ID],
            ...($batch
                ? [
                    'channel_names' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_CHANNELS],
                    'channel_names.*' => ['required', 'string', 'max:200'],
                ]
                : ['channel_name' => ['required', 'string', 'max:200']]),
        ]);

        if (! Signer::configured()) {
            throw new ApiError(503, 'live_unavailable', 'Live updates are not configured on this server. The screen keeps refreshing itself.');
        }

        $principal = Principal::forRequest($user, $request);
        $socketId = (string) $data['socket_id'];

        /** @var list<string> $wires */
        $wires = $batch ? array_values(array_unique(array_map('strval', (array) $data['channel_names']))) : [(string) $data['channel_name']];

        $granted = self::grant($registry, $principal, $wires);

        $signed = [];

        foreach ($granted as $wire => $channel) {
            $signed[$wire] = $signer->sign($wire, $socketId, $principal) + ['streams' => $channel->streams()];
        }

        $grants->record($principal, array_values(array_map(static fn (ChannelName $c): string => $c->name, $granted)), $socketId);

        if (! $batch) {
            $wire = $wires[0];

            return isset($signed[$wire])
                ? response()->json(['auth' => $signed[$wire]['auth'], 'channel_data' => $signed[$wire]['channel_data']])
                : response()->json(self::REFUSAL, 403);
        }

        return response()->json([
            'channels' => $signed === [] ? new stdClass : $signed,
            'refused' => array_values(array_diff($wires, array_keys($signed))),
        ]);
    }

    /**
     * The wire names the registry grants, with their parsed channel.
     *
     * @param  list<string>  $wires
     * @return array<string, ChannelName>
     */
    public static function grant(ChannelRegistry $registry, Principal $principal, array $wires): array
    {
        $channels = [];

        foreach ($wires as $wire) {
            $channel = ChannelName::fromWire($wire);

            if ($channel !== null) {
                $channels[$wire] = $channel;
            }
        }

        $answers = $registry->decideMany($principal, array_values(array_map(static fn (ChannelName $c): string => $c->name, $channels)));

        return array_filter($channels, static fn (ChannelName $c): bool => $answers[$c->name] ?? false);
    }
}
