<?php

declare(strict_types=1);

namespace App\Http\Controllers\Live;

use App\Http\Controllers\Controller;
use App\Live\Auth\Principal;
use App\Live\CatchUp\CatchUp;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /live/catch-up` — a console session's "everything after N" (M11 Part C, DESIGN §5.1).
 *
 *   {"channels":[{"name":"estate.pp.facilities","c":null,"e":1042,"ep":{"e":"01J9ZC3K"}}, …]}   1 to 200
 *
 * Bare channel names, as `live.shell` and each page's `live` prop list them. Every one is decided
 * again for this session (CatchUp); the answer names each, `ok`, `refused` or `reset`. The same
 * answer, in the same shape, is the handsets' `live.catch_up` (LiveController::catchUp).
 *
 * It needs no socket and no Reverb: while the socket is down this IS the screen's live update,
 * polled (§5.6), so it answers whether or not live updates are configured.
 */
final class LiveCatchUpController extends Controller
{
    public function __invoke(Request $request, CatchUp $catchUp): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($catchUp->answer(Principal::forRequest($user, $request), self::validated($request)));
    }

    /**
     * The request, checked and typed: what both doors accept.
     *
     * @return list<array{name: string, c: int|null, e: int|null, ep: array<string, string>}>
     */
    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'channels' => ['required', 'array', 'list', 'min:1', 'max:'.CatchUp::MAX_CHANNELS],
            'channels.*' => ['required', 'array'],
            'channels.*.name' => ['required', 'string', 'max:200'],
            'channels.*.c' => ['nullable', 'integer', 'min:0'],
            'channels.*.e' => ['nullable', 'integer', 'min:0'],
            'channels.*.ep' => ['nullable', 'array'],
            'channels.*.ep.c' => ['nullable', 'string', 'max:26'],
            'channels.*.ep.e' => ['nullable', 'string', 'max:26'],
        ]);

        $channels = [];

        foreach ((array) $data['channels'] as $entry) {
            $entry = (array) $entry;
            $ep = [];

            foreach (['c', 'e'] as $stream) {
                $value = $entry['ep'][$stream] ?? null;

                if (is_string($value) && $value !== '') {
                    $ep[$stream] = $value;
                }
            }

            $channels[] = [
                'name' => (string) $entry['name'],
                'c' => isset($entry['c']) ? (int) $entry['c'] : null,
                'e' => isset($entry['e']) ? (int) $entry['e'] : null,
                'ep' => $ep,
            ];
        }

        return $channels;
    }
}
