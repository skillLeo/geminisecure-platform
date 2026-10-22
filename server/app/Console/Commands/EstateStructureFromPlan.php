<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Estate\PhasesFromLots;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 *   php artisan estate:structure-from-plan --estate=phoenixparkvillage1                 a DRY RUN: reads, prints, changes nothing
 *   php artisan estate:structure-from-plan --estate=phoenixparkvillage1 --confirm       the real run, after reading the dry run
 *   php artisan estate:structure-from-plan --estate=phoenixparkvillage1 --restore=DIR   puts back what a real run's backup holds
 *
 * THE REAL PHOENIX PARK (M57.5 §2, §4.2, §5). The estate's phases become the site plan's runs of lots (Phase 1 is lots
 * 1 to 356, … Phase 5 is 1578 to 1999), each with the blocks the plan draws in it, and every unit's phase stops being a
 * stored copy and is worked out by MySQL from its lot number (PhasesFromLots). Nobody moves: no unit, household,
 * resident, charge, payment, ticket, booking or vote changes hands or is renumbered. What changes is the phase written
 * beside a lot, and the titles of tickets that had the old phase typed into them.
 *
 * ONE ESTATE, NAMED, ALWAYS. There is no "every estate" form: the plan is one estate's (`resources/estate/…`).
 *
 * THE DRY RUN SAYS, BEFORE ANYTHING: the estate and its database, with `SELECT DATABASE()` as proof that every
 * statement runs there; every table touched with its row count and each statement with its WHERE; the backup the real
 * run writes first, and where; every household whose phase changes, by name and lot; every unit the plan does not have
 * (a "Lot 2000", the seed's "Lot 1999A") and everything that points at it; the records that reach by phase and so
 * follow the new phases; and the panic, vote and audit rows it never touches, counted, so the real run can prove it.
 *
 * NOTHING IS DELETED. A unit the plan does not have stays on the register, filed under no phase. No lot is
 * renumbered, so nothing can be orphaned. The real run counts the untouched rows before and after and stops loudly if
 * any count moved.
 */
class EstateStructureFromPlan extends Command
{
    protected $signature = 'estate:structure-from-plan
        {--estate= : the estate (its subdomain), always named}
        {--plan= : the plan file (default resources/estate/phoenix-park-plan.json)}
        {--confirm : make the change (after reading the dry run)}
        {--restore= : put back the backup in this folder (a real run prints it)}';

    protected $description = 'Make an estate\'s phases the site plan\'s runs of lots, worked out from each unit\'s lot number (dry run unless --confirm)';

    /** "…: Phase 2 · Lot 47" — the place a resident's ticket title was written with (M47 §11.5). */
    private const TITLE_PLACE = '/Phase (\d+) · Lot (\d+)\b/u';

    public function handle(AuditLogger $audit): int
    {
        $key = trim((string) $this->option('estate'));

        if ($key === '') {
            $this->error('Name the estate: --estate=phoenixparkvillage1. Nothing was changed.');

            return self::INVALID;
        }

        $estate = Tenant::query()->find($key);

        if (! $estate instanceof Tenant) {
            $this->error('There is no estate "'.$key.'". Nothing was changed.');

            return self::INVALID;
        }

        if (($restore = trim((string) $this->option('restore'))) !== '') {
            return $this->restore($estate, $restore, $audit);
        }

        try {
            $plan = $this->readPlan((string) ($this->option('plan') ?: resource_path('estate/phoenix-park-plan.json')));
        } catch (DomainException $refusal) {
            $this->error($refusal->getMessage().' Nothing was changed.');

            return self::INVALID;
        }

        $confirm = (bool) $this->option('confirm');
        $stamp = Carbon::now()->format('Ymd-His');
        $backupDir = storage_path('app/private/structure-backups/'.$key.'-'.$stamp);

        $report = $estate->run(fn (): array => $this->survey($plan));

        $this->printReport($estate, $plan, $report, $backupDir, $confirm);

        if (! $confirm) {
            $this->newLine();
            $this->info('DRY RUN: nothing was changed. To make the change, run the same command with --confirm.');

            return self::SUCCESS;
        }

        return $this->apply($estate, $plan, $report, $backupDir, $audit);
    }

    /* ------------------------------------------------------------------ */
    /* the plan */
    /* ------------------------------------------------------------------ */

    /**
     * The plan file, checked: every phase a run of lots, no two sharing a lot, and, where the plan draws blocks, the
     * blocks covering the phase exactly (their units adding up to its count, every lot in one block).
     *
     * @return array{file: string, sha256: string, source: string, phases: list<array{name: string, first: int, last: int, count: int, blocks: list<array{letter: string, units: int, lots: list<array{0: int, 1: int}>}>}>}
     */
    private function readPlan(string $file): array
    {
        if (! is_file($file)) {
            throw new DomainException('No plan file at '.$file.'.');
        }

        $raw = (string) file_get_contents($file);
        $json = json_decode($raw, true);

        if (! is_array($json) || ! is_array($json['phases'] ?? null) || $json['phases'] === []) {
            throw new DomainException('The plan file '.$file.' has no phases.');
        }

        $phases = [];

        foreach ($json['phases'] as $p) {
            $phase = [
                'name' => trim((string) ($p['name'] ?? '')),
                'first' => (int) ($p['first_lot'] ?? 0),
                'last' => (int) ($p['last_lot'] ?? 0),
                'count' => (int) ($p['count'] ?? 0),
                'blocks' => array_values(array_map(static fn (array $b): array => [
                    'letter' => (string) ($b['letter'] ?? ''),
                    'units' => (int) ($b['units'] ?? 0),
                    'lots' => array_values(array_map(static fn (array $run): array => [(int) $run[0], (int) $run[1]], (array) ($b['lots'] ?? []))),
                ], (array) ($p['blocks'] ?? []))),
            ];

            if ($phase['last'] - $phase['first'] + 1 !== $phase['count']) {
                throw new DomainException(sprintf('%s runs from Lot %d to Lot %d, which is %d lots, not the %d it says.', $phase['name'], $phase['first'], $phase['last'], $phase['last'] - $phase['first'] + 1, $phase['count']));
            }

            if ($phase['blocks'] !== []) {
                $seen = [];

                foreach ($phase['blocks'] as $block) {
                    $lots = 0;

                    foreach ($block['lots'] as [$from, $to]) {
                        for ($lot = $from; $lot <= $to; $lot++) {
                            if ($lot < $phase['first'] || $lot > $phase['last']) {
                                throw new DomainException(sprintf('Block %s of %s holds Lot %d, outside the phase.', $block['letter'], $phase['name'], $lot));
                            }

                            if (isset($seen[$lot])) {
                                throw new DomainException(sprintf('Lot %d is in two blocks of %s.', $lot, $phase['name']));
                            }

                            $seen[$lot] = true;
                            $lots++;
                        }
                    }

                    if ($lots !== $block['units']) {
                        throw new DomainException(sprintf('Block %s of %s draws %d units and holds %d lots.', $block['letter'], $phase['name'], $block['units'], $lots));
                    }
                }

                if (count($seen) !== $phase['count']) {
                    throw new DomainException(sprintf('The blocks of %s hold %d lots and the phase has %d.', $phase['name'], count($seen), $phase['count']));
                }
            }

            $phases[] = $phase;
        }

        PhasesFromLots::check(array_map(static fn (array $p): array => ['name' => $p['name'], 'first' => $p['first'], 'last' => $p['last']], $phases));

        // The boundary the plan draws, placed on the map (M57.5 §4.5): optional, and checked to be a real shape.
        $boundary = null;

        if (is_array($json['boundary'] ?? null)) {
            $points = array_values(array_filter((array) ($json['boundary']['points'] ?? []), static fn ($p): bool => is_array($p) && count($p) === 2
                && is_numeric($p[0]) && is_numeric($p[1]) && abs((float) $p[0]) <= 90 && abs((float) $p[1]) <= 180));

            if (count($points) < 3 || count($points) !== count((array) ($json['boundary']['points'] ?? []))) {
                throw new DomainException('The plan\'s boundary is not a list of at least three [latitude, longitude] points.');
            }

            $boundary = [
                'points' => array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $points),
                'source' => mb_substr((string) ($json['boundary']['source'] ?? 'the site plan'), 0, 255),
            ];
        }

        return [
            'file' => $file,
            'sha256' => hash('sha256', $raw),
            'source' => (string) ($json['source'] ?? ''),
            'phases' => $phases,
            'boundary' => $boundary,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the survey: everything read, nothing written */
    /* ------------------------------------------------------------------ */

    /**
     * Inside the estate, read only.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function survey(array $plan): array
    {
        $db = DB::connection('tenant');
        $ranges = array_map(static fn (array $p): array => ['name' => $p['name'], 'first' => $p['first'], 'last' => $p['last']], $plan['phases']);

        $database = (string) $db->getDatabaseName();
        $proof = (string) $db->selectOne('select database() as db')->db;

        $phaseRows = $db->table('estate_phases')->orderBy('sequence')->get()->map(static fn (object $r): array => (array) $r)->all();
        $byName = [];
        foreach ($phaseRows as $row) {
            $byName[mb_strtolower((string) $row['name'])] = $row;
        }

        $phaseStatements = [];
        $nextSequence = (int) (collect($phaseRows)->max('sequence') ?? 0);

        foreach ($plan['phases'] as $p) {
            $set = ['first_lot' => $p['first'], 'last_lot' => $p['last'], 'block_count' => count($p['blocks']), 'blocks' => json_encode($p['blocks'], JSON_UNESCAPED_SLASHES)];
            $row = $byName[mb_strtolower($p['name'])] ?? null;

            $phaseStatements[] = $row === null
                ? ['kind' => 'insert', 'name' => $p['name'], 'set' => $set + ['name' => $p['name'], 'sequence' => ++$nextSequence, 'status' => 'active'], 'before' => null]
                : ['kind' => 'update', 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'set' => $set, 'before' => $row];
        }

        $planNames = array_map(static fn (array $p): string => mb_strtolower($p['name']), $plan['phases']);
        $otherPhases = array_values(array_filter($phaseRows, static fn (array $r): bool => ! in_array(mb_strtolower((string) $r['name']), $planNames, true)));

        // Every unit, its phase today and its phase from the plan.
        $units = $db->table('units')->orderBy('id')->get(['id', 'reference', 'block', 'status'])->all();
        $moves = [];
        $changed = [];
        $outside = [];

        foreach ($units as $unit) {
            $lot = PhasesFromLots::lotNumberOf((string) $unit->reference);
            $after = PhasesFromLots::phaseOf($lot, $ranges);
            $before = $unit->block === null ? null : (string) $unit->block;
            $move = ($before ?? 'no phase').' to '.($after ?? 'no phase');
            $moves[$move] = ($moves[$move] ?? 0) + 1;

            if ($after === null) {
                $outside[] = ['id' => (int) $unit->id, 'reference' => (string) $unit->reference, 'status' => (string) $unit->status, 'phase' => $before];
            }

            if ($before !== $after) {
                $changed[(int) $unit->id] = ['reference' => (string) $unit->reference, 'before' => $before, 'after' => $after, 'status' => (string) $unit->status];
            }
        }

        ksort($moves);

        // The plan's lots the register does not have.
        $have = [];
        foreach ($units as $unit) {
            if (($lot = PhasesFromLots::lotNumberOf((string) $unit->reference)) !== null) {
                $have[$lot] = true;
            }
        }
        $missing = [];
        foreach ($ranges as $range) {
            for ($lot = $range['first']; $lot <= $range['last']; $lot++) {
                if (! isset($have[$lot])) {
                    $missing[] = $lot;
                }
            }
        }

        // Everything that points at a unit the plan does not have.
        $pointing = [];
        foreach ($outside as $unit) {
            $pointing[$unit['reference']] = $this->pointingAt($unit['id']);
        }

        // The households and people on lots whose phase changes: nobody moves; their lot's phase is written differently.
        $people = [];
        if ($changed !== []) {
            $households = $db->table('households')->whereIn('unit_id', array_keys($changed))->get(['id', 'unit_id', 'name'])->all();
            $residents = $db->table('residents')->whereIn('household_id', array_map(static fn (object $h): int => (int) $h->id, $households))
                ->whereNull('removed_at')->orderByDesc('is_primary')->orderBy('id')->get(['id', 'household_id', 'full_name', 'is_primary'])->all();

            foreach ($households as $household) {
                $unit = $changed[(int) $household->unit_id];
                $names = [];
                foreach ($residents as $resident) {
                    if ((int) $resident->household_id === (int) $household->id) {
                        $names[] = (string) $resident->full_name.((bool) $resident->is_primary ? ' (primary)' : '');
                    }
                }
                $people[] = ['unit' => $unit['reference'], 'household' => (string) $household->name, 'household_id' => (int) $household->id, 'residents' => $names, 'before' => $unit['before'], 'after' => $unit['after']];
            }

            usort($people, static fn (array $a, array $b): int => (PhasesFromLots::lotNumberOf($a['unit']) ?? PHP_INT_MAX) <=> (PhasesFromLots::lotNumberOf($b['unit']) ?? PHP_INT_MAX));
        }

        // Ticket titles that carry a phase the plan says is wrong for the lot in them.
        $titles = [];
        foreach ($db->table('maintenance_tickets')->where('title', 'like', '%Phase % · Lot %')->orderBy('id')->get(['id', 'number', 'title'])->all() as $ticket) {
            $fixed = (string) preg_replace_callback(self::TITLE_PLACE, static function (array $m) use ($ranges): string {
                $right = PhasesFromLots::phaseOf((int) $m[2], $ranges);

                return $right === null ? $m[0] : $right.' · Lot '.$m[2];
            }, (string) $ticket->title);

            if ($fixed !== (string) $ticket->title) {
                $titles[] = ['id' => (int) $ticket->id, 'number' => $ticket->number, 'before' => (string) $ticket->title, 'after' => $fixed];
            }
        }

        // Records that reach households BY PHASE: untouched, and from the change on they reach the households in that
        // phase as the plan draws it.
        $reach = [
            'notices addressed to one phase' => Schema::connection('tenant')->hasColumn('notices', 'audience_phase')
                ? $db->table('notices')->whereNotNull('audience_phase')->get(['id', 'title', 'audience_phase'])->map(static fn (object $n): string => '#'.$n->id.' "'.$n->title.'" ('.$n->audience_phase.')')->all() : [],
            'meetings for one phase' => $db->table('meetings')->whereNotNull('phase')->get(['id', 'title', 'phase'])->map(static fn (object $m): string => '#'.$m->id.' "'.$m->title.'" ('.$m->phase.')')->all(),
            'ballot seats for one phase (their votes are not touched)' => $db->table('ballot_positions')->whereNotNull('phase')->get(['id', 'name', 'phase'])->map(static fn (object $p): string => '#'.$p->id.' '.$p->name.' ('.$p->phase.')')->all(),
            'dues schedules for one phase (the next run bills the units now in it)' => $db->table('charge_schedules')->whereNotNull('phase')->get(['id', 'description', 'phase'])->map(static fn (object $s): string => '#'.$s->id.' '.$s->description.' ('.$s->phase.')')->all(),
        ];

        return [
            'database' => $database,
            'proof' => $proof,
            'phase_rows' => count($phaseRows),
            'phase_statements' => $phaseStatements,
            'other_phases' => $otherPhases,
            'unit_count' => count($units),
            'moves' => $moves,
            'changed' => $changed,
            'occupied_changed' => count(array_filter($changed, static fn (array $u): bool => $u['status'] === 'occupied')),
            'outside' => $outside,
            'pointing' => $pointing,
            'lot_2000' => $db->table('units')->where('reference', 'Lot 2000')->first(['id', 'reference', 'status', 'block']),
            'missing' => $missing,
            'people' => $people,
            'titles' => $titles,
            'ticket_count' => (int) $db->table('maintenance_tickets')->count(),
            'reach' => $reach,
            'derived' => PhasesFromLots::derived('tenant'),
            'sql' => PhasesFromLots::derivedSql($ranges, $db),
            'untouched' => $this->untouchedCounts((string) tenant()?->getTenantKey()),
            // The estate's row of the platform's site table, and the boundary it holds today.
            'site' => (array) (DB::connection('mysql')->table('estate_sites')->where('tenant_id', (string) tenant()?->getTenantKey())->first(['tenant_id', 'boundary', 'boundary_source']) ?? []),
        ];
    }

    /**
     * Rows that point at one unit, by table (estate database). A unit with anything here is never deleted.
     *
     * @return array<string, int>
     */
    private function pointingAt(int $unitId): array
    {
        $db = DB::connection('tenant');
        $counts = [];

        foreach (['households', 'charges', 'payments', 'maintenance_tickets', 'amenity_bookings', 'unit_claims', 'visitor_passes', 'payment_plans', 'unit_collection_flags', 'gate_approval_requests'] as $table) {
            if (Schema::connection('tenant')->hasTable($table) && Schema::connection('tenant')->hasColumn($table, 'unit_id')) {
                $counts[$table] = (int) $db->table($table)->where('unit_id', $unitId)->count();
            }
        }

        return $counts;
    }

    /**
     * The rows this command must never touch, counted (M57.5 §7: "No panic, duress, vote or audit row is ever touched").
     *
     * @return array<string, int>
     */
    private function untouchedCounts(string $tenantId): array
    {
        $estate = DB::connection('tenant');
        $central = DB::connection('mysql');

        return [
            'duress_alerts (this estate, platform database)' => (int) $central->table('duress_alerts')->where('tenant_id', $tenantId)->count(),
            'ballot_receipts (votes cast)' => Schema::connection('tenant')->hasTable('ballot_receipts') ? (int) $estate->table('ballot_receipts')->count() : 0,
            'ballot_marks (votes cast)' => Schema::connection('tenant')->hasTable('ballot_marks') ? (int) $estate->table('ballot_marks')->count() : 0,
            'audit_log (this estate, platform database)' => (int) $central->table('audit_log')->where('tenant_id', $tenantId)->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the report */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $r
     */
    private function printReport(Tenant $estate, array $plan, array $r, string $backupDir, bool $confirm): void
    {
        $this->info(($confirm ? 'ESTATE STRUCTURE FROM THE SITE PLAN' : 'ESTATE STRUCTURE FROM THE SITE PLAN: DRY RUN, nothing is changed'));
        $this->line('Estate:    '.$estate->getTenantKey().' ('.$estate->name.')');
        $this->line('Database:  '.$r['database']);
        $this->line('Proof:     SELECT DATABASE() on the connection every statement below runs on = '.$r['proof'].($r['proof'] === $r['database'] ? '  (the same database)' : '  MISMATCH'));
        $this->line('Plan:      '.$plan['file'].' (sha256 '.$plan['sha256'].')');
        $this->line('Source:    '.$plan['source']);

        $this->newLine();
        $this->info('1. PHASES: table estate_phases, '.$r['phase_rows'].' rows in this database');
        foreach ($r['phase_statements'] as $s) {
            $p = collect($plan['phases'])->firstWhere('name', $s['name']) ?? collect($plan['phases'])->first(static fn (array $x): bool => mb_strtolower($x['name']) === mb_strtolower($s['name']));
            $was = $s['before'] === null ? 'not on the register' : sprintf('lots %s, %d blocks', $s['before']['first_lot'] === null ? 'not set' : $s['before']['first_lot'].' to '.$s['before']['last_lot'], (int) $s['before']['block_count']);
            $this->line(sprintf('   %s: today %s; from the plan, lots %d to %d (%s lots), %s', $s['name'], $was, $p['first'], $p['last'], number_format($p['count']), $p['blocks'] === [] ? 'no blocks drawn on the plan' : count($p['blocks']).' blocks ('.implode(', ', array_column($p['blocks'], 'letter')).')'));
            $this->line($s['kind'] === 'update'
                ? sprintf('      UPDATE estate_phases SET first_lot = %d, last_lot = %d, block_count = %d, blocks = <the plan\'s %d blocks> WHERE id = %d AND name = \'%s\'', $p['first'], $p['last'], count($p['blocks']), count($p['blocks']), $s['id'], $s['name'])
                : sprintf('      INSERT INTO estate_phases (name, sequence, first_lot, last_lot, block_count, blocks, status) VALUES (\'%s\', %d, %d, %d, %d, <blocks>, \'active\')', $s['name'], $s['set']['sequence'], $p['first'], $p['last'], count($p['blocks'])));
        }
        foreach ($r['other_phases'] as $other) {
            $this->line('   '.$other['name'].': not on the plan, left exactly as it is (no unit will be filed under it).');
        }

        $this->newLine();
        $this->info('2. UNITS: table units, '.number_format($r['unit_count']).' rows. The phase column becomes worked out from the lot number.');
        if ($r['derived']) {
            $this->line('   The phase is already worked out from the lot here; the statement below re-states the plan\'s runs.');
        }
        $this->line('      '.$r['sql']);
        $this->line('      WHERE: none. A column\'s definition covers the whole table, and the table is this estate\'s own, in the database proven above.');
        $this->line('   Phase per unit, today and from the plan:');
        foreach ($r['moves'] as $move => $count) {
            $this->line(sprintf('      %-30s %s', $move, number_format($count)));
        }
        $this->line(sprintf('   Units whose phase changes: %s, of which %s have a household on them. No unit is renumbered, moved or deleted.', number_format(count($r['changed'])), number_format($r['occupied_changed'])));

        $this->newLine();
        $this->info('3. UNITS THE PLAN DOES NOT HAVE (kept on the register under no phase; nothing is deleted)');
        $this->line('   Lot 2000: '.($r['lot_2000'] === null ? 'none in this estate.' : 'EXISTS (unit '.$r['lot_2000']->id.', '.$r['lot_2000']->status.'). Treated as below.'));
        if ($r['outside'] === []) {
            $this->line('   Every unit on the register is on the plan.');
        }
        foreach ($r['outside'] as $unit) {
            $points = array_filter($r['pointing'][$unit['reference']] ?? []);
            $this->line(sprintf('   %s (unit %d, %s, today %s): %s', $unit['reference'], $unit['id'], $unit['status'], $unit['phase'] ?? 'no phase', $points === [] ? 'nothing points at it' : 'pointed at by '.implode(', ', array_map(static fn (string $t, int $n): string => $n.' '.$t, array_keys($points), $points))));
        }
        $this->line('   The plan\'s lots the register does not have: '.($r['missing'] === [] ? 'none.' : count($r['missing']).' ('.implode(', ', array_slice($r['missing'], 0, 12)).(count($r['missing']) > 12 ? ', …' : '').'). Not created by this command.'));

        $this->newLine();
        $this->info('4. HOUSEHOLDS ON LOTS WHOSE PHASE CHANGES: '.count($r['people']).' (nobody is re-homed: unit, household and every record stay; only the phase beside the lot changes)');
        foreach ($r['people'] as $row) {
            $this->line(sprintf('   %s · %s · %s · %s to %s', $row['unit'], $row['household'], $row['residents'] === [] ? 'no one on the register' : implode(', ', $row['residents']), $row['before'] ?? 'no phase', $row['after'] ?? 'no phase'));
        }

        $this->newLine();
        $this->info('5. TICKET TITLES THAT CARRY A WRONG PHASE: table maintenance_tickets, '.number_format($r['ticket_count']).' rows, '.count($r['titles']).' to correct');
        foreach ($r['titles'] as $t) {
            $this->line(sprintf('   #%s "%s" becomes "%s"', $t['number'] ?? $t['id'], $t['before'], $t['after']));
            $this->line(sprintf('      UPDATE maintenance_tickets SET title = <the new title> WHERE id = %d AND title = <exactly the old title>', $t['id']));
        }

        $this->newLine();
        $this->info('6. RECORDS THAT REACH BY PHASE: read only, not touched; from the change they reach the households in that phase on the plan');
        foreach ($r['reach'] as $what => $rows) {
            $this->line(sprintf('   %s: %d%s', $what, count($rows), $rows === [] ? '' : ' ('.implode('; ', array_slice($rows, 0, 6)).(count($rows) > 6 ? '; …' : '').')'));
        }

        $this->newLine();
        $this->info('7. NEVER TOUCHED, COUNTED NOW AND AGAIN AFTER THE REAL RUN');
        foreach ($r['untouched'] as $what => $count) {
            $this->line(sprintf('   %-48s %s', $what, number_format($count)));
        }

        if ($plan['boundary'] !== null) {
            $this->newLine();
            $this->info('8. THE ESTATE\'S BOUNDARY: platform table estate_sites, '.($r['site'] === [] ? 'no row for this estate yet' : '1 row for this estate').'; today '.(($r['site']['boundary'] ?? null) === null ? 'no boundary (the 50 m circle only)' : 'a boundary is recorded and is replaced'));
            $this->line('   '.$plan['boundary']['source']);
            $this->line($r['site'] === []
                ? sprintf('      INSERT INTO estate_sites (tenant_id, boundary, boundary_source) VALUES (\'%s\', <%d points>, <the source above>)', $estate->getTenantKey(), count($plan['boundary']['points']))
                : sprintf('      UPDATE estate_sites SET boundary = <%d points>, boundary_source = <the source above> WHERE tenant_id = \'%s\'', count($plan['boundary']['points']), $estate->getTenantKey()));
            $this->line('   The circle stays as it is: posts and clock-ins still use it. The maps draw the boundary, and a guard\'s position more than 5 km outside it is refused.');
        }

        $this->newLine();
        $this->info('9. BACKUP, WRITTEN BEFORE ANY STATEMENT ON THE REAL RUN');
        $this->line('   '.$backupDir);
        $this->line('   units.jsonl (every unit\'s id, reference and phase as stored today), estate_phases.jsonl, ticket_titles.jsonl, manifest.json');
        $this->line('   To put it back: php artisan estate:structure-from-plan --estate='.$estate->getTenantKey().' --restore='.$backupDir);
    }

    /* ------------------------------------------------------------------ */
    /* the real run */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $r
     */
    private function apply(Tenant $estate, array $plan, array $r, string $backupDir, AuditLogger $audit): int
    {
        if ($r['proof'] !== $r['database']) {
            $this->error('The connection did not prove to be '.$r['database'].'. Nothing was changed.');

            return self::FAILURE;
        }

        $ranges = array_map(static fn (array $p): array => ['name' => $p['name'], 'first' => $p['first'], 'last' => $p['last']], $plan['phases']);
        $key = (string) $estate->getTenantKey();

        // 1. The backup, before anything.
        if (! is_dir($backupDir) && ! mkdir($backupDir, 0750, true) && ! is_dir($backupDir)) {
            $this->error('The backup folder could not be made: '.$backupDir.'. Nothing was changed.');

            return self::FAILURE;
        }

        $estate->run(function () use ($backupDir, $r, $plan): void {
            $db = DB::connection('tenant');
            $write = static function (string $file, iterable $rows): void {
                $handle = fopen($file, 'wb');
                foreach ($rows as $row) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                }
                fclose($handle);
            };
            $write($backupDir.'/units.jsonl', $db->table('units')->orderBy('id')->get(['id', 'reference', 'block'])->map(static fn (object $u): array => (array) $u));
            $write($backupDir.'/estate_phases.jsonl', $db->table('estate_phases')->orderBy('id')->get()->map(static fn (object $p): array => (array) $p));
            $write($backupDir.'/ticket_titles.jsonl', $r['titles']);
            file_put_contents($backupDir.'/manifest.json', json_encode([
                'estate' => tenant()?->getTenantKey(),
                'database' => $r['database'],
                'written_at' => Carbon::now()->toIso8601String(),
                'plan' => ['file' => $plan['file'], 'sha256' => $plan['sha256']],
                'phase_was_derived' => $r['derived'],
                'untouched_before' => $r['untouched'],
                // The estate's site row as it was (null: there was none), so a restore puts its boundary back exactly.
                'estate_site_before' => $r['site'] === [] ? null : ['boundary' => $r['site']['boundary'] ?? null, 'boundary_source' => $r['site']['boundary_source'] ?? null],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        });

        $this->info('Backup written: '.$backupDir);

        // 2. The column, as the schema owner (tenancy ended). First, because it is the step that can refuse.
        try {
            PhasesFromLots::derive($r['database'], $ranges);
        } catch (Throwable $failure) {
            $this->error('The phase column could not be changed: '.$failure->getMessage().' Nothing else was changed; the backup is in '.$backupDir.'.');

            return self::FAILURE;
        }

        Unit::forgetPhaseDerivation();
        $this->info('units.block is now worked out from the lot number.');

        // 3. The phases and the titles, in one transaction in the estate.
        try {
            $estate->run(function () use ($r, $backupDir): void {
                DB::connection('tenant')->transaction(function () use ($r, $backupDir): void {
                    $db = DB::connection('tenant');
                    $now = Carbon::now();

                    $inserted = [];

                    foreach ($r['phase_statements'] as $s) {
                        if ($s['kind'] === 'update') {
                            $db->table('estate_phases')->where('id', $s['id'])->where('name', $s['name'])->update($s['set'] + ['updated_at' => $now]);
                        } else {
                            $inserted[] = (int) $db->table('estate_phases')->insertGetId($s['set'] + ['officers_assigned' => null, 'created_at' => $now, 'updated_at' => $now]);
                        }
                    }

                    // A phase this run added is taken away again by a restore.
                    file_put_contents($backupDir.'/inserted_phases.json', json_encode($inserted));

                    foreach ($r['titles'] as $t) {
                        $db->table('maintenance_tickets')->where('id', $t['id'])->where('title', $t['before'])->update(['title' => $t['after'], 'updated_at' => $now]);
                    }
                });
            });
        } catch (Throwable $failure) {
            $this->error('The phases could not be recorded: '.$failure->getMessage().' The phase column already follows the plan; run the same command again to finish. Backup: '.$backupDir.'.');

            return self::FAILURE;
        }

        // The boundary, on the platform's site row for this estate only.
        if ($plan['boundary'] !== null) {
            $set = ['boundary' => json_encode($plan['boundary']['points']), 'boundary_source' => $plan['boundary']['source'], 'updated_at' => Carbon::now()];

            if ($r['site'] === []) {
                DB::connection('mysql')->table('estate_sites')->insert($set + ['tenant_id' => $key, 'created_at' => Carbon::now()]);
            } else {
                DB::connection('mysql')->table('estate_sites')->where('tenant_id', $key)->update($set);
            }

            $this->info('The estate\'s boundary is recorded ('.count($plan['boundary']['points']).' points).');
        }

        // 4. Recorded, and checked.
        $after = $estate->run(function () use ($audit, $r, $plan, $backupDir, $key): array {
            $audit->record(
                action: 'estate.structure_from_plan',
                entityType: 'Estate',
                entityId: $key,
                before: ['phases' => array_map(static fn (array $s): ?array => $s['before'] === null ? null : ['name' => $s['before']['name'], 'first_lot' => $s['before']['first_lot'], 'last_lot' => $s['before']['last_lot'], 'block_count' => $s['before']['block_count']], $r['phase_statements']), 'phase_was_derived' => $r['derived']],
                after: ['plan_sha256' => $plan['sha256'], 'phases' => array_map(static fn (array $p): array => ['name' => $p['name'], 'first_lot' => $p['first'], 'last_lot' => $p['last'], 'blocks' => count($p['blocks'])], $plan['phases']), 'units_rephased' => count($r['changed']), 'titles_corrected' => count($r['titles']), 'backup' => $backupDir],
                tenantId: $key,
                actorName: 'estate:structure-from-plan',
            );

            return [
                'counts' => DB::connection('tenant')->table('units')->selectRaw('block, count(*) as n')->groupBy('block')->pluck('n', 'block')->all(),
                'untouched' => $this->untouchedCounts($key),
            ];
        });

        $this->newLine();
        $this->info('Done. Units per phase now:');
        foreach ($plan['phases'] as $p) {
            $n = (int) ($after['counts'][$p['name']] ?? 0);
            $this->line(sprintf('   %s: %s on the register of %s on the plan', $p['name'], number_format($n), number_format($p['count'])));
        }
        // A null phase comes back as the empty key.
        $this->line('   No phase (not on the plan): '.number_format((int) ($after['counts'][''] ?? 0)));

        // The audit row this run wrote is the one expected change to the estate's audit count.
        $moved = [];
        foreach ($r['untouched'] as $what => $count) {
            $expected = str_starts_with($what, 'audit_log') ? $count + 1 : $count;
            if (($after['untouched'][$what] ?? null) !== $expected) {
                $moved[] = $what.': '.$count.' before, '.($after['untouched'][$what] ?? 'unread').' after';
            }
        }

        if ($moved !== []) {
            $this->error('A row this command never touches changed while it ran: '.implode('; ', $moved).'. Look before anything else.');

            return self::FAILURE;
        }

        $this->info('Untouched rows: the same before and after (the audit log holds this run\'s one entry more).');

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */
    /* restore */
    /* ------------------------------------------------------------------ */

    private function restore(Tenant $estate, string $dir, AuditLogger $audit): int
    {
        $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true);

        if (! is_array($manifest) || ($manifest['estate'] ?? null) !== $estate->getTenantKey()) {
            $this->error('No backup of '.$estate->getTenantKey().' in '.$dir.'. Nothing was changed.');

            return self::INVALID;
        }

        $read = static fn (string $file): array => array_values(array_filter(array_map(static fn (string $line): ?array => json_decode($line, true), file($dir.'/'.$file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
        $units = $read('units.jsonl');
        $phases = $read('estate_phases.jsonl');
        $titles = $read('ticket_titles.jsonl');

        $database = $estate->run(static fn (): string => (string) DB::connection('tenant')->getDatabaseName());

        if ($database !== ($manifest['database'] ?? null)) {
            $this->error('The backup is of '.($manifest['database'] ?? '?').' and the estate\'s database is '.$database.'. Nothing was changed.');

            return self::INVALID;
        }

        // The column first: back to stored, holding what was stored before the run (unless it was already derived then).
        if (! (bool) ($manifest['phase_was_derived'] ?? false)) {
            $values = [];
            foreach ($units as $unit) {
                $values[(int) $unit['id']] = $unit['block'] ?? null;
            }
            PhasesFromLots::store($database, $values);
            Unit::forgetPhaseDerivation();
        }

        // The phases the real run added (none when every plan phase was already on the register).
        $added = json_decode((string) @file_get_contents($dir.'/inserted_phases.json'), true);
        $added = is_array($added) ? array_map('intval', $added) : [];

        $estate->run(function () use ($phases, $titles, $audit, $dir, $estate, $added): void {
            DB::connection('tenant')->transaction(function () use ($phases, $titles, $added): void {
                $db = DB::connection('tenant');

                if ($added !== []) {
                    $db->table('estate_phases')->whereIn('id', $added)->delete();
                }

                foreach ($phases as $phase) {
                    $db->table('estate_phases')->where('id', $phase['id'])->update([
                        'first_lot' => $phase['first_lot'] ?? null,
                        'last_lot' => $phase['last_lot'] ?? null,
                        'block_count' => $phase['block_count'],
                        'blocks' => $phase['blocks'] ?? null,
                    ]);
                }

                foreach ($titles as $title) {
                    $db->table('maintenance_tickets')->where('id', $title['id'])->where('title', $title['after'])->update(['title' => $title['before']]);
                }
            });

            $audit->record(action: 'estate.structure_restored', entityType: 'Estate', entityId: (string) $estate->getTenantKey(), after: ['backup' => $dir], tenantId: (string) $estate->getTenantKey(), actorName: 'estate:structure-from-plan');
        });

        // The boundary as it was: put back on the site row, or taken off a row that had none.
        if (array_key_exists('estate_site_before', $manifest)) {
            $before = $manifest['estate_site_before'];
            DB::connection('mysql')->table('estate_sites')->where('tenant_id', (string) $estate->getTenantKey())->update([
                'boundary' => $before['boundary'] ?? null,
                'boundary_source' => $before['boundary_source'] ?? null,
            ]);
        }

        $this->info('Restored from '.$dir.'.');

        return self::SUCCESS;
    }
}
