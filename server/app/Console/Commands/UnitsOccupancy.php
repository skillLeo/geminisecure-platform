<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Live\Auth\EstateScope;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\SiteTime;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan units:occupancy --estate=phoenixparkvillage1             what it finds, and both numbers
 *   php artisan units:occupancy --estate=phoenixparkvillage1 --confirm   correct the flags
 *
 * THE 443 AGAINST 441 DRIFT (M81; M85 A8): "two units flagged occupied with no household behind them. The query is
 * written. Run it, fix what it finds, and report both numbers." The dashboard's "Occupied units" counts the lots
 * flagged occupied; the register counts the households living on the lots. The query (`docs/reports/M69_443_VERSUS_
 * 441.md`) named two causes: a unit made without a status is "occupied" by the column's default, and removing a
 * household leaves its lot flagged. This prints what that query prints, unit by unit, with both numbers before and
 * after, and with --confirm makes each lot's flag say what the register says:
 *
 *   - flagged occupied, nobody living there (never, or since a household was removed): vacant;
 *   - somebody living there, not flagged occupied: occupied.
 *
 * Nothing else moves: not a household, not a resident, not a charge (vacant lots are billed the same, M82 answer 7).
 * A lot with more than one household, and units off the lot plan, are listed and left. Without --confirm nothing is
 * written. Run on production by a person who has read the dry run, never by a deploy.
 */
class UnitsOccupancy extends Command
{
    protected $signature = 'units:occupancy
        {--estate= : the estate (phoenixparkvillage1 on production)}
        {--confirm : correct the flags; without it nothing is written}';

    protected $description = 'List the lots whose occupied flag disagrees with the household register, and with --confirm correct them';

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->option('estate');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('Say which estate: --estate=phoenixparkvillage1. There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('confirm');

        return EstateScope::run($key, function () use ($estate, $key, $apply, $audit): int {
            $this->info(($apply ? 'Correcting the occupied flags at ' : 'What --confirm would correct at ').$estate->name.' ('.$key.')'.($apply ? '' : '. Nothing is written without --confirm.'));

            $lots = Unit::lots()->orderBy('block')->orderBy('reference')->get(['id', 'reference', 'block', 'status']);
            $ids = $lots->modelKeys();

            // The households living on each lot now, as the estate counts them (M87 §1.4, `Household::counted()`: a
            // household with a resident in it, the 441 the dashboard and the register say); which lots hold a household
            // with nobody in it; and when one was last removed from a lot (the register's own columns).
            $living = Household::query()->counted()->whereIn('households.unit_id', $ids)->toBase()
                ->groupBy('households.unit_id')->selectRaw('households.unit_id AS unit_id, COUNT(*) AS n')
                ->pluck('n', 'unit_id')->map(intval(...))->all();
            $present = DB::connection('tenant')->table('households')->whereNull('removed_at')->whereIn('unit_id', $ids)
                ->distinct()->pluck('unit_id')->flip()->all();
            $removed = DB::connection('tenant')->table('households')->whereNotNull('removed_at')->whereIn('unit_id', $ids)
                ->groupBy('unit_id')->selectRaw('unit_id, MAX(removed_at) AS last')->pluck('last', 'unit_id')->all();

            $empty = $lots->filter(static fn (Unit $unit): bool => $unit->status === 'occupied' && ! isset($living[$unit->id]))->values();
            $unflagged = $lots->filter(static fn (Unit $unit): bool => $unit->status !== 'occupied' && isset($living[$unit->id]))->values();
            $crowded = $lots->filter(static fn (Unit $unit): bool => ($living[$unit->id] ?? 0) > 1)->values();
            $offPlan = Unit::query()->whereNotIn('id', $ids)->orderBy('reference')->get(['id', 'reference', 'status']);

            $occupied = $lots->where('status', 'occupied')->count();
            $households = array_sum($living);
            $after = $occupied - $empty->count() + $unflagged->count();

            $this->newLine();
            $this->line('Lots marked occupied: '.$occupied.'. Households on the lots: '.$households.'.');

            $this->listed('FLAGGED OCCUPIED, NOBODY LIVING THERE', $empty, fn (Unit $unit): array => [
                $unit->reference, (string) $unit->block,
                match (true) {
                    isset($removed[$unit->id]) => 'a household removed here on '.Carbon::parse((string) $removed[$unit->id], 'UTC')->setTimezone(SiteTime::zone())->format('M j, Y'),
                    isset($present[$unit->id]) => 'a household with nobody in it',
                    default => 'no household recorded here',
                },
                'vacant',
            ]);
            $this->listed('LIVED IN, NOT FLAGGED OCCUPIED', $unflagged, fn (Unit $unit): array => [
                $unit->reference, (string) $unit->block, ($living[$unit->id] ?? 0).' living here', 'occupied',
            ]);
            $this->listed('MORE THAN ONE HOUSEHOLD ON A LOT (left as it is)', $crowded, fn (Unit $unit): array => [
                $unit->reference, (string) $unit->block, ($living[$unit->id] ?? 0).' living here', 'unchanged',
            ]);
            $this->listed('UNITS OFF THE LOT PLAN, IN NEITHER NUMBER (left as they are)', $offPlan, fn (Unit $unit): array => [
                $unit->reference, '', 'flagged '.$unit->status, 'unchanged',
            ]);

            $this->line('NOT TOUCHED: any household, resident or charge; a lot with more than one household; a unit off the plan.');
            $this->newLine();

            if ($empty->isEmpty() && $unflagged->isEmpty()) {
                $this->info('Every lot\'s flag says what the register says. Nothing was changed.');

                return self::SUCCESS;
            }

            $tally = $empty->count().' lot'.($empty->count() === 1 ? '' : 's').' marked vacant, '.$unflagged->count().' marked occupied.';
            $numbers = 'Lots marked occupied: '.$after.'. Households on the lots: '.$households.'.';

            if (! $apply) {
                $this->line('After --confirm: lots marked occupied: '.$after.'. Households on the lots: '.$households.'.');
                $this->info('Would be: '.$tally.' Nothing is written without --confirm.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use ($empty, $unflagged): void {
                if ($empty->isNotEmpty()) {
                    Unit::query()->whereKey($empty->modelKeys())->update(['status' => 'vacant']);
                }

                if ($unflagged->isNotEmpty()) {
                    Unit::query()->whereKey($unflagged->modelKeys())->update(['status' => 'occupied']);
                }
            });

            $audit->record(
                action: 'estate.unit_occupancy_corrected',
                entityType: 'Estate',
                entityId: $key,
                before: ['occupied_lots' => $occupied, 'households_on_lots' => $households],
                after: [
                    'marked_vacant' => $empty->pluck('reference')->all(),
                    'marked_occupied' => $unflagged->pluck('reference')->all(),
                    'occupied_lots' => $after,
                    'households_on_lots' => $households,
                ],
                tenantId: $key,
            );

            $this->info('Done: '.$tally.' '.$numbers);

            return self::SUCCESS;
        });
    }

    /**
     * One finding: its heading with its count, then its lots.
     *
     * @param  Collection<int, Unit>  $units
     * @param  callable(Unit): list<string>  $row
     */
    private function listed(string $heading, Collection $units, callable $row): void
    {
        $this->newLine();
        $this->line($heading.': '.$units->count());

        if ($units->isNotEmpty()) {
            $this->table(['Lot', 'Phase', 'The register', 'Flag after --confirm'], $units->map($row)->all());
        }
    }
}
