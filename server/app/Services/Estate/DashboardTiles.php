<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Support\Seeded\Retirements;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE DASHBOARD'S ESTATE-WIDE TILES, COMPUTED ONCE AND KEPT — M11 A2.
 *
 * "Dashboard tiles that count or sum across the whole estate must not compute on every page
 * load. Cache them with a sensible TTL, or maintain them incrementally, and show when the
 * figure was computed." Every committee member opening the console used to pay for the whole
 * receivable summed and grouped by phase — 23,792 journal lines at Phoenix Park — and for every
 * maintenance ticket loaded to count the open ones. Those figures change when a charge, a
 * payment, a lot or a ticket changes, and not otherwise.
 *
 * SO THEY ARE KEPT, AND RECOMPUTED THE MOMENT THEIR DATA MOVES. Each part is stored with the
 * stamp of the data it was computed from — see `STAMPS`, which one cheap statement reads back —
 * and served while the stamp still matches:
 *
 *   structure   units, the estate's households (M87 §1.4), open maintenance tickets
 *               stamp: `units`, `households`, `residents` and `maintenance_tickets`
 *   position    what the estate is owed, and the same by phase (the bars)
 *               stamp: `journals` — every balance is journal lines, and a line is never written
 *               without its header — and `units`, whose phase a bar groups by
 *
 * A charge posted, a receipt keyed, a lot added or a ticket closed changes a stamp, and the next
 * dashboard load computes that part afresh: a treasurer who posts a payment and opens the
 * dashboard sees it. `TTL_SECONDS` is the backstop — a figure is never served older than that
 * whatever its stamp says — and the screen prints when every figure it shows was computed.
 *
 * THE FIGURES ARE THE SERVICES' OWN, NOT A SECOND ARITHMETIC. The position is
 * `Dues::position()` — the same ageing total and phase bars `ageing()` and `arrearsByPhase()`
 * give — and the open count is `Maintenance::queueBoard()`'s own tile. This class decides only
 * when they are read.
 *
 * THE POSITION IS NEVER READ FOR A VIEWER WHO MAY NOT HAVE IT (M10 §5). It is its own row, and
 * the dashboard asks for it only for a viewer `Dues::maySeeEstatePosition()` admits — so for
 * anybody else it is neither computed nor fetched, exactly as before it was cached.
 *
 * IN THE ESTATE'S OWN DATABASE (`dashboard_snapshots`), which is why no estate's figures can be
 * served to another, and why it works on every cache driver: see the table's migration.
 */
final class DashboardTiles
{
    /** The longest a figure is served without being recomputed, whatever its stamp says. */
    public const TTL_SECONDS = 600;

    public function __construct(
        private readonly Dues $dues,
        private readonly Maintenance $maintenance,
    ) {}

    /**
     * Units, the estate's households and open maintenance tickets.
     *
     * @return array{units: int, occupied: int, open_maintenance: int, computed_at: Carbon}
     */
    public function structure(): array
    {
        return $this->remember('structure', $this->stamp(['units', 'households', 'residents', 'maintenance_tickets']), function (): array {
            /*
             * The same three reads the dashboard made before it kept them: the unit totals
             * `Residents::structureBoard()` prints, and the open-ticket tile of board 17.
             */
            // The estate's lots (M60 §C2): a unit off the site plan is on the register and is not a lot.
            $units = (int) Unit::lots()->count();
            // The estate's households (M87 §1.4), never the units flagged occupied: a flag with nobody behind it is the
            // drift that read 443 against 441. The register and a meeting's quorum count the same rule.
            $occupied = (int) Household::query()->counted()->count();

            $open = 0;

            // Board 17's own tile, from its own walk — not a page of its queue (M32 F5).
            foreach ($this->maintenance->tiles()['kpis'] as $kpi) {
                if ($kpi['key'] === 'open') {
                    $open = (int) $kpi['value'];

                    break;
                }
            }

            return ['units' => $units, 'occupied' => $occupied, 'open_maintenance' => $open];
        });
    }

    /**
     * What the estate is owed, in total and by phase — the committee's picture.
     *
     * ONLY FOR A VIEWER WHO MAY READ THE ESTATE'S POSITION. The caller asks
     * `Dues::maySeeEstatePosition()` first; this does not.
     *
     * @return array{outstanding_minor: int, phases: array<string, int>, computed_at: Carbon}
     */
    public function position(): array
    {
        return $this->remember('position', $this->stamp(['journals', 'units']), function (): array {
            $position = $this->dues->position();

            return ['outstanding_minor' => array_sum($position['ageing']), 'phases' => $position['by_phase']];
        });
    }

    /** Throw the kept figures away — the next dashboard load computes them. */
    public function forget(): void
    {
        DB::connection('tenant')->table('dashboard_snapshots')->delete();
    }

    /**
     * The kept figures while their stamp matches and they are younger than the TTL; the freshly
     * computed ones, kept, otherwise.
     *
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    private function remember(string $key, string $stamp, Closure $compute): array
    {
        $tenant = DB::connection('tenant');
        $now = Carbon::now();

        $kept = $tenant->table('dashboard_snapshots')->where('key', $key)->first();

        if ($kept !== null && $kept->version === $stamp) {
            $computedAt = Carbon::parse((string) $kept->computed_at, 'UTC');

            if ($computedAt->greaterThan($now->copy()->subSeconds(self::TTL_SECONDS))) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $kept->payload, true, flags: JSON_THROW_ON_ERROR);

                return [...$payload, 'computed_at' => $computedAt];
            }
        }

        $payload = $compute();

        $tenant->table('dashboard_snapshots')->upsert(
            [[
                'key' => $key,
                'version' => $stamp,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['version', 'payload', 'computed_at', 'updated_at'],
        );

        return [...$payload, 'computed_at' => $now->copy()->startOfSecond()];
    }

    /**
     * What each table's stamp is made of — exactly the columns its figures depend on.
     *
     * A COUNT AND A HIGHEST ID catch a row added or removed. A CHECKSUM OVER THE COLUMNS THAT
     * MATTER catches a row changed: the XOR of every row's CRC32 moves when any one row's phase
     * or status moves, in any order, however many changes land in the same second — which a
     * latest-`updated_at` would not, at one-second resolution. `journals` is append-only, so its
     * count and highest id are its whole history.
     *
     *   units                a lot added or removed, moved to another phase, occupied or vacated
     *   households           a household added, moved to another lot, removed or restored
     *   residents            a resident joining a household, leaving it, removed or restored
     *   maintenance_tickets  a ticket raised, or its status changed (the open tile reads nothing else)
     *   journals             any posting at all, and every balance is postings
     */
    private const STAMPS = [
        'units' => "CONCAT_WS(':', COUNT(*), COALESCE(MAX(id), 0), COALESCE(BIT_XOR(CRC32(CONCAT_WS(':', id, COALESCE(block, ''), status))), 0))",
        // What `Household::scopeCounted()` reads (M87 §1.4): a household moved or removed, a resident joining or leaving.
        'households' => "CONCAT_WS(':', COUNT(*), COALESCE(MAX(id), 0), COALESCE(BIT_XOR(CRC32(CONCAT_WS(':', id, unit_id, COALESCE(removed_at, '')))), 0))",
        'residents' => "CONCAT_WS(':', COUNT(*), COALESCE(MAX(id), 0), COALESCE(BIT_XOR(CRC32(CONCAT_WS(':', id, household_id, COALESCE(removed_at, '')))), 0))",
        'maintenance_tickets' => "CONCAT_WS(':', COUNT(*), COALESCE(MAX(id), 0), COALESCE(BIT_XOR(CRC32(CONCAT_WS(':', id, status))), 0))",
        'journals' => "CONCAT_WS(':', COUNT(*), COALESCE(MAX(id), 0))",
    ];

    /**
     * The stamp of the tables a part is read from, in one statement.
     *
     * @param  list<key-of<self::STAMPS>>  $tables
     */
    private function stamp(array $tables): string
    {
        $columns = array_map(static fn (string $table): string => '(SELECT '.self::STAMPS[$table]." FROM `{$table}`) AS `{$table}`", $tables);

        $row = (array) DB::connection('tenant')->selectOne('SELECT '.implode(', ', $columns));

        // Retiring the estate's sample data, or undoing it, changes every figure without changing a row (M33 §5).
        return implode('|', array_map(static fn (string $table): string => $table.'='.$row[$table], $tables))
            .'|'.Retirements::stamp((string) DB::connection('tenant')->getDatabaseName());
    }
}
