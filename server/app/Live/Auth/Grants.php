<?php

declare(strict_types=1);

namespace App\Live\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EVERY GRANT IS WRITTEN DOWN, FOR THE BACKSTOP (M11 Part C, DESIGN §3.6, §7.4).
 *
 * One `live_grants` row per (principal, channel, socket), upserted with the moment of
 * the grant. `live:reconcile` re-asks the registry about every pair granted in the last
 * 26 hours, and a principal now refused is told `access.changed` and evicted — so an
 * access change somebody forgot to announce still reaches an open socket.
 *
 * Central only, and holding no more than a grant needs: the principal's key, the bare
 * channel name and the socket id. Nothing personal.
 *
 * BOOKKEEPING, NOT THE DECISION. The registry has already decided when this runs; a
 * failed write is reported and the grant stands, because refusing a channel the rules
 * admit — the safety feeds among them — would trade a live screen for a backstop's
 * record of it.
 */
final class Grants
{
    public const TABLE = 'live_grants';

    /** @param  list<string>  $channels  bare names the registry granted */
    public function record(Principal $principal, array $channels, string $socketId): void
    {
        if ($channels === []) {
            return;
        }

        $at = Carbon::now()->format('Y-m-d H:i:s.u');
        $key = $principal->key();

        $rows = array_map(static fn (string $channel): array => [
            'principal' => $key,
            'channel' => $channel,
            'socket_id' => $socketId,
            'granted_at' => $at,
        ], array_values(array_unique($channels)));

        try {
            DB::connection(self::connection())
                ->table(self::TABLE)
                ->upsert($rows, ['principal', 'channel', 'socket_id'], ['granted_at']);
        } catch (QueryException $e) {
            report($e);
        }
    }

    private static function connection(): string
    {
        $name = config('tenancy.database.central_connection');

        return is_string($name) && $name !== '' ? $name : 'mysql';
    }
}
