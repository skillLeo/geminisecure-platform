<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 *   php artisan estate:remove-phase --estate=phoenixparkvillage1 --phase="Phase 6"            what it would do, and why it may or may not
 *   php artisan estate:remove-phase --estate=phoenixparkvillage1 --phase="Phase 6" --confirm  do it
 *   php artisan estate:remove-phase --estate=phoenixparkvillage1 --list                       every phase, with what points at it
 *
 * (The estate and the phase may also be given in that order without the option names, as M18H wrote it.)
 *
 * TEST DATA ON A SCREEN THE CLIENT LOOKS AT (M18H §7.1, M33 §4.1). A phase added by somebody trying the Structure page's
 * "Add phase" sits among an estate's phases, sometimes with the lots it was given. The console has no "remove phase",
 * and should not grow one for this: a phase is a NAME that lots, dues runs, ballots, meetings and notices are keyed on,
 * and a phase that has touched any of them is part of the estate's history (M18H §6: nothing that has touched money, a
 * gate or a person is hard-deleted).
 *
 * SO IT REMOVES A PHASE, AND ITS LOTS, ONLY WHEN NOTHING HANGS OFF EITHER:
 *   - no charge schedule bills the phase, and no ballot position or option, meeting or notice was for it;
 *   - no lot in it has a household, a resident, a charge or a visitor pass — nor any other record that points at a lot:
 *     every table of the estate with a `unit_id` column is asked, the ledger's lines and the payments among them. A
 *     shift is the platform's and is kept by post, never by lot, so none can stand on one; the dry run says so.
 * Anything that does is listed, lot by lot, and nothing is removed.
 *
 * WITHOUT --confirm IT CHANGES NOTHING. It prints the lots it would remove and the units it gives back to the contract.
 * With --confirm the lots and the phase are deleted in one transaction and audited (`estate.phase_removed`, with every
 * lot it took). NOT REVERSIBLE by this command: to put a phase back, add it again on Estate structure with the same lot
 * numbers, which the audit row lists. No ledger line, payment, invoice or audit row is ever touched — a lot with any of
 * them is refused before anything happens.
 */
final class RemoveEmptyPhase extends Command
{
    protected $signature = 'estate:remove-phase
        {estate? : the estate id (or --estate=)}
        {phase? : the phase name, exactly as it is spelled (or --phase=)}
        {--estate= : the estate id}
        {--phase= : the phase name, exactly as it is spelled}
        {--confirm : actually remove it}
        {--list : list every phase and what points at it}';

    protected $description = 'Remove a phase, and its lots, when nothing hangs off either — no lot with a household, resident, charge or pass, no dues run, ballot, meeting or notice. Dry run without --confirm.';

    /** Every place a phase's NAME is stored: [table, column, what it is called]. */
    private const REFERENCES = [
        ['charge_schedules', 'phase', 'charge schedule'],
        ['ballot_positions', 'phase', 'ballot position'],
        ['ballot_options', 'phase', 'ballot option'],
        ['meetings', 'phase', 'meeting'],
        ['notices', 'audience_phase', 'notice'],
    ];

    /** The four §4.1 names, by the table that holds them. Every other `unit_id` table is named by its own name. */
    private const LOT_RECORDS = [
        'households' => 'household',
        'charges' => 'charge',
        'visitor_passes' => 'visitor pass',
    ];

    public function handle(AuditLogger $audit): int
    {
        $key = (string) ($this->option('estate') ?: $this->argument('estate') ?: '');
        $estate = $key === '' ? null : Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error($key === '' ? 'Say which estate: --estate=phoenixparkvillage1. Nothing was changed.' : "No estate with the id \"{$key}\". Nothing was changed.");

            return self::FAILURE;
        }

        if ($this->option('list')) {
            $rows = $estate->run(fn (): array => Phase::query()->orderBy('sequence')->get()
                ->map(fn (Phase $p): array => [$p->name, $p->status, (string) Unit::query()->where('block', $p->name)->count(), $this->describe($this->references($p->name))])->all());
            $this->table(['Phase', 'Status', 'Lots', 'What else names it'], $rows);

            return self::SUCCESS;
        }

        $name = (string) ($this->option('phase') ?: $this->argument('phase') ?: '');
        if ($name === '') {
            $this->error('Name the phase, exactly as it is spelled: --phase="Phase 6" — or use --list. Nothing was changed.');

            return self::FAILURE;
        }

        return $estate->run(function () use ($estate, $name, $audit): int {
            $phase = Phase::query()->where('name', $name)->first();
            if ($phase === null) {
                $this->error("{$estate->name} has no phase called \"{$name}\". Phases: ".Phase::query()->orderBy('sequence')->pluck('name')->implode(', ').'. Nothing was changed.');

                return self::FAILURE;
            }

            $refs = $this->references($name);
            if (array_sum($refs) > 0) {
                $this->error("\"{$name}\" stays: {$this->describe($refs)} name it. A phase that has been used is part of the estate's history. Nothing was changed.");

                return self::FAILURE;
            }

            $lots = Unit::query()->where('block', $name)->orderBy('id')->get(['id', 'reference', 'block', 'street', 'type', 'status']);
            $hanging = $this->hangingOff($lots->pluck('id')->map(intval(...))->all());

            if ($hanging !== []) {
                $this->error("\"{$name}\" stays: its lots are in use. Nothing was changed.");
                $byLot = $lots->keyBy('id');
                foreach ($hanging as $unitId => $what) {
                    $this->line('  '.($byLot[$unitId]->reference ?? 'unit '.$unitId).': '.$this->describe($what));
                }

                return self::FAILURE;
            }

            $count = $lots->count();
            $contracted = DB::connection('mysql')->table('subscriptions')->where('tenant_id', (string) $estate->getTenantKey())->value('unit_count');
            $inUse = Unit::query()->count();

            $this->line("\"{$name}\" at {$estate->name}: named by nothing — no dues run, ballot, meeting or notice — and ".($count === 0 ? 'it has no lots.' : "its {$count} lot".($count === 1 ? '' : 's').' have nothing on them: no household, resident, charge or visitor pass, and no other record of any kind.'));
            $this->line('  A shift is kept by post, never by lot, so none stands on a lot of this phase.');
            if ($count > 0) {
                $this->line('  The lots '.($this->option('confirm') ? 'removed' : 'it would remove').': '.$this->compact($lots->pluck('reference')->map(strval(...))->all()));
            }
            $this->line('  The contract: '.($contracted === null
                ? "no contracted number is set; {$inUse} unit".($inUse === 1 ? '' : 's').' in use become '.($inUse - $count).'.'
                : number_format((int) $contracted).' contracted; in use '.number_format($inUse).' → '.number_format($inUse - $count).'; left '.number_format(max(0, (int) $contracted - $inUse)).' → '.number_format(max(0, (int) $contracted - $inUse + $count)).". It gives back {$count} unit".($count === 1 ? '' : 's').'.'));
            $this->line('  Not reversible by this command: to put the phase back, add it again on Estate structure with the same lot numbers. The audit row lists every lot.');

            if (! $this->option('confirm')) {
                $this->info('Nothing was changed. Run again with --confirm to remove it.');

                return self::SUCCESS;
            }

            DB::connection('tenant')->transaction(function () use ($phase, $name, $estate, $audit, $lots, $count): void {
                Unit::query()->whereIn('id', $lots->pluck('id')->all())->where('block', $name)->delete();
                $phase->delete();
                $audit->record(
                    action: 'estate.phase_removed',
                    entityType: 'Phase',
                    entityId: (string) $phase->id,
                    before: [
                        'name' => $name, 'status' => $phase->status, 'sequence' => $phase->sequence, 'block_count' => $phase->block_count,
                        'lots' => $lots->map(static fn (Unit $u): array => ['reference' => $u->reference, 'street' => $u->street, 'type' => $u->type, 'status' => $u->status])->values()->all(),
                    ],
                    after: ['why' => 'nothing hangs off it (M18H §7.1, M33 §4.1)', 'by' => 'estate:remove-phase', 'lots_removed' => $count, 'units_given_back' => $count],
                    tenantId: (string) $estate->getTenantKey(),
                );
            });

            $this->info("Removed \"{$name}\" from {$estate->name}".($count > 0 ? " with its {$count} lot".($count === 1 ? '' : 's') : '').'. The removal is in the audit log.');

            return self::SUCCESS;
        });
    }

    /** @return array<string, int> what names this phase, by kind */
    private function references(string $name): array
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();
        $found = [];

        foreach (self::REFERENCES as [$table, $column, $what]) {
            if ($schema->hasTable($table) && $schema->hasColumn($table, $column)) {
                $found[$what] = (int) DB::connection('tenant')->table($table)->where($column, $name)->count();
            }
        }

        return $found;
    }

    /**
     * What hangs off each of these lots: every table of the estate with a `unit_id` column, and the residents of their
     * households. Asked of the database itself, so a table added later is asked too.
     *
     * @param  list<int>  $unitIds
     * @return array<int, array<string, int>> unit id => [what => how many], only the lots that have something
     */
    private function hangingOff(array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $db = DB::connection('tenant');
        $tables = array_map(static fn (object $r): string => (string) $r->name, $db->select(
            "SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'unit_id' ORDER BY TABLE_NAME",
            [$db->getDatabaseName()],
        ));

        $found = [];
        foreach ($tables as $table) {
            foreach ($db->table($table)->whereIn('unit_id', $unitIds)->selectRaw('unit_id, COUNT(*) AS n')->groupBy('unit_id')->get() as $row) {
                $found[(int) $row->unit_id][self::LOT_RECORDS[$table] ?? 'row in '.$table] = (int) $row->n;
            }
        }

        foreach ($db->table('residents')->join('households', 'households.id', '=', 'residents.household_id')->whereIn('households.unit_id', $unitIds)
            ->selectRaw('households.unit_id AS unit_id, COUNT(*) AS n')->groupBy('households.unit_id')->get() as $row) {
            $found[(int) $row->unit_id]['resident'] = (int) $row->n;
        }

        ksort($found);

        return $found;
    }

    /**
     * "Lot 2001 to Lot 2010, Lot 2010A" — runs of plain numbers collapsed, anything else as it is spelled.
     *
     * @param  list<string>  $references
     */
    private function compact(array $references): string
    {
        $numbered = [];
        $other = [];
        foreach ($references as $reference) {
            if (preg_match('/^(\D*?)(\d+)$/', $reference, $m) === 1) {
                $numbered[$m[1]][] = (int) $m[2];
            } else {
                $other[] = $reference;
            }
        }

        $parts = [];
        foreach ($numbered as $prefix => $numbers) {
            sort($numbers);
            $start = $prev = null;
            foreach ([...$numbers, null] as $n) {
                if ($n !== null && $prev !== null && $n === $prev + 1) {
                    $prev = $n;

                    continue;
                }
                if ($start !== null) {
                    $parts[] = $start === $prev ? $prefix.$start : $prefix.$start.' to '.$prefix.$prev;
                }
                $start = $prev = $n;
            }
        }

        return implode(', ', [...$parts, ...$other]).' ('.count($references).' lot'.(count($references) === 1 ? '' : 's').')';
    }

    /** @param  array<string, int>  $refs */
    private function describe(array $refs): string
    {
        $named = array_filter($refs);

        return $named === [] ? 'nothing' : implode(', ', array_map(static function (string $what, int $n): string {
            if (str_starts_with($what, 'row in ')) {
                return $n.' row'.($n === 1 ? '' : 's').substr($what, 3);
            }

            return $n.' '.$what.($n === 1 ? '' : (str_ends_with($what, 's') ? 'es' : 's'));
        }, array_keys($named), $named));
    }
}
