<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\Grants;
use App\Live\Auth\Principal;
use App\Live\Events\AccessLive;
use App\Live\LiveBus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 *   php artisan live:reconcile
 *
 * THE BACKSTOP FOR A PERMISSION CHANGE NOBODY ANNOUNCED (DESIGN.md §7.4), every five minutes.
 *
 * Every grant of the last 26 hours is in `live_grants`. Each distinct (principal, channel) pair is
 * asked of the registry again, exactly as the socket's door would ask it now. A principal that is
 * now refused ANY channel it was granted is told so: `access.changed {effect: reduced}` is recorded
 * through LiveBus on its own channel, in a central transaction, so the open console reloads its
 * page (every gate asked again) and the relay's evictor closes its sockets 250 ms after publishing
 * it, and they are authorised again under the rules as they are. Its refused pairs are removed, and
 * grants older than 26 hours are pruned.
 *
 * It catches an access writer that forgot to record its event, a hand-run seeder, a row changed in
 * the database directly. It forces no resubscription on anybody still admitted, and changes no
 * permission: it only asks, and tells.
 */
class LiveReconcile extends Command
{
    /** How far back grants are asked about (§7.4): a day, and a margin for a long shift. */
    public const HOURS = 26;

    protected $signature = 'live:reconcile';

    protected $description = 'Ask the registry again about every live grant of the last 26 hours, and evict any principal now refused one';

    public function handle(ChannelRegistry $registry): int
    {
        $db = DB::connection((string) config('tenancy.database.central_connection', 'mysql'));
        $since = Carbon::now()->subHours(self::HOURS)->format('Y-m-d H:i:s.u');

        $pruned = $db->table(Grants::TABLE)->where('granted_at', '<', $since)->delete();

        /** @var array<string, list<string>> $byPrincipal */
        $byPrincipal = [];

        foreach ($db->table(Grants::TABLE)->select(['principal', 'channel'])->distinct()->orderBy('principal')->cursor() as $row) {
            $byPrincipal[(string) $row->principal][] = (string) $row->channel;
        }

        $evicted = 0;
        $failed = 0;

        foreach ($byPrincipal as $key => $channels) {
            try {
                $principal = Principal::fromKey($key);
                $refused = $principal === null
                    ? $channels
                    : array_keys(array_filter($registry->decideMany($principal, $channels), static fn (bool $allowed): bool => ! $allowed));

                if ($refused === []) {
                    continue;
                }

                $db->transaction(static function () use ($db, $principal, $key, $refused): void {
                    // A principal that no longer exists has no channel to be told on; its grants simply go.
                    if ($principal !== null) {
                        LiveBus::record(AccessLive::for($principal, 'reduced'));
                    }

                    $db->table(Grants::TABLE)->where('principal', $key)->whereIn('channel', $refused)->delete();
                });

                $evicted++;
                $this->line("  {$key}: refused ".count($refused).' channel(s) it had been granted; told, and its sockets are closed by the relay');
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->line("  <fg=red>FAIL</> {$key}: ".class_basename($e));
            }
        }

        $this->line(sprintf('Reconciled %d principal(s): %d told their access was reduced; %d grant(s) older than %d hours pruned.', count($byPrincipal), $evicted, $pruned, self::HOURS));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
