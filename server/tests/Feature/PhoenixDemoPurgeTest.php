<?php

declare(strict_types=1);

use App\Support\DemonstrationAccounts;
use App\Support\SampleData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| phoenix:demo-purge takes the demonstration out, and nothing else (B096)
|--------------------------------------------------------------------------
|
| The platform's database is SHARED. While a seeding run fills one estate, every
| other estate goes on writing to the same tables — a guard pinging presence, an
| officer hired, somebody accepting an invitation, a handset signing in — and
| their ids fall inside the seeding run's id range. The purge used to delete the
| whole range, with foreign keys off, and a re-seed days later stretched the range
| back over everything written in between.
|
| What the purge may take from a platform table is now a row of THIS estate's, in
| a table the demonstration writes, inside ONE run's own range. A range recorded
| before the fix is read the same way, so the ones already on the server cannot
| reach another estate either.
|
*/

const PURGE_ESTATE = FacilitiesFixture::ESTATE;

const PURGE_OTHER = 'purgeotherestate';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    FacilitiesFixture::boot();
});

/**
 * One row straight into a platform table, foreign keys aside — these are
 * stand-ins for rows other code writes, and only their ownership is under test.
 *
 * @param  array<string, mixed>  $row
 */
function purgeRow(string $table, array $row): int
{
    $db = DB::connection('mysql');

    if (Schema::connection('mysql')->hasColumn($table, 'created_at')) {
        $row += ['created_at' => now(), 'updated_at' => now()];
    }

    $db->statement('SET FOREIGN_KEY_CHECKS = 0');

    try {
        return (int) $db->table($table)->insertGetId($row);
    } finally {
        $db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function purgeShift(string $estate): int
{
    return purgeRow('shifts', ['tenant_id' => $estate, 'post_id' => 1, 'rostered_start' => now(), 'rostered_end' => now()->addHours(12)]);
}

function purgeUser(string $email): int
{
    return purgeRow('users', ['name' => 'Purge probe', 'email' => $email, 'password' => 'not-a-hash']);
}

/**
 * An address on the demonstration domain the seed gives this estate's committee — the domain is what the purge
 * reads (`SampleData::committeeAddresses`), so the name before it is this file's own. It was the seed's own name
 * ("norman-treasurer"), which collided whenever another file had already seeded that very account.
 */
function purgeDemoAddress(string $who): string
{
    return $who.'-purgeprobe-'.bin2hex(random_bytes(3)).'@'.PURGE_ESTATE.DemonstrationAccounts::DEMO_DOMAIN_SUFFIX;
}

function purgeExists(string $table, int $id): bool
{
    return DB::connection('mysql')->table($table)->where('id', $id)->exists();
}

/** @param  array<string, mixed>  $options */
function purgeRun(array $options = ['--confirm' => true]): string
{
    Artisan::call('phoenix:demo-purge', ['--estate' => PURGE_ESTATE, ...$options]);

    FacilitiesFixture::boot();

    return Artisan::output();
}

it('takes only the seeded estate\'s rows out of the platform tables, never what another estate wrote while it ran', function () {
    $before = SampleData::highWater('mysql');

    // What the seeding run wrote for this estate…
    $post = purgeRow('posts', ['tenant_id' => PURGE_ESTATE, 'name' => 'Purge Gate']);
    $shift = purgeShift(PURGE_ESTATE);
    $committee = purgeUser(purgeDemoAddress('dahlia-community-super-admin'));
    $checkpoint = purgeRow('patrol_checkpoints', ['tenant_id' => PURGE_ESTATE, 'label' => 'Purge point', 'code' => 'PURGEPROBE-CP-01']);
    $scan = purgeRow('checkpoint_scans', ['patrol_checkpoint_id' => $checkpoint, 'guard_id' => 1, 'server_time' => now(), 'is_simulated' => true]);
    $gate = purgeRow('gate_events', ['tenant_id' => PURGE_ESTATE, 'verdict' => 'admitted', 'subject' => 'Purge visitor', 'occurred_at' => now(), 'is_simulated' => true]);
    $panic = purgeRow('duress_alerts', ['tenant_id' => PURGE_ESTATE, 'kind' => 'panic', 'status' => 'resolved', 'server_time' => now(), 'is_simulated' => true]);
    $seat = purgeRow('estate_assignments', ['user_id' => $committee, 'tenant_id' => PURGE_ESTATE, 'role_id' => 1, 'is_active' => true]);

    // …what the estate's own people did in those same minutes, which is real…
    $realPanic = purgeRow('duress_alerts', ['tenant_id' => PURGE_ESTATE, 'kind' => 'panic', 'status' => 'raised', 'server_time' => now(), 'is_simulated' => false]);
    $president = purgeUser('the.real.president@example.com');
    $presidentSeat = purgeRow('estate_assignments', ['user_id' => $president, 'tenant_id' => PURGE_ESTATE, 'role_id' => 1, 'is_active' => true]);

    // …and what the rest of the platform wrote.
    $theirShift = purgeShift(PURGE_OTHER);
    $theirCheckpoint = purgeRow('patrol_checkpoints', ['tenant_id' => PURGE_OTHER, 'label' => 'Their point', 'code' => 'PURGEPROBE-CP-99']);
    $theirScan = purgeRow('checkpoint_scans', ['patrol_checkpoint_id' => $theirCheckpoint, 'guard_id' => 2, 'server_time' => now()]);
    $theirGate = purgeRow('gate_events', ['tenant_id' => PURGE_OTHER, 'verdict' => 'admitted', 'subject' => 'Their visitor', 'occurred_at' => now()]);
    $person = purgeUser('accepted.an.invitation@example.com');
    $handset = purgeRow('personal_access_tokens', ['tokenable_type' => 'App\\Models\\Guard', 'tokenable_id' => 2, 'name' => 'A handset elsewhere', 'token' => hash('sha256', 'purge-probe')]);

    // The seed goes on after their shift, so this estate's shifts straddle another estate's id.
    $laterShift = purgeShift(PURGE_ESTATE);

    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    // Recorded as two runs of ids with theirs between, not one range across it.
    expect(DB::connection('mysql')->table('sample_data_ranges')->where('table_name', 'shifts')->orderBy('from_id')->get(['from_id', 'to_id'])->map(static fn (object $r): array => [(int) $r->from_id, (int) $r->to_id])->all())
        ->toBe([[$shift - 1, $shift], [$laterShift - 1, $laterShift]]);

    $mine = [['shifts', $shift], ['shifts', $laterShift], ['users', $committee], ['patrol_checkpoints', $checkpoint], ['checkpoint_scans', $scan], ['estate_assignments', $seat]];
    // No panic row is ever deleted (the user, M52 §4.5): the seed's simulated panic stays beside the real one.
    $theirs = [
        ['duress_alerts', $panic], ['duress_alerts', $realPanic], ['users', $president], ['estate_assignments', $presidentSeat],
        ['shifts', $theirShift], ['patrol_checkpoints', $theirCheckpoint], ['checkpoint_scans', $theirScan], ['gate_events', $theirGate], ['users', $person], ['personal_access_tokens', $handset],
    ];

    // A dry run changes nothing, and says what stays before anybody confirms (M28).
    $dry = purgeRun(['--dry-run' => true]);

    foreach ([['posts', $post], ...$mine, ...$theirs] as [$table, $id]) {
        expect(purgeExists($table, $id))->toBeTrue("{$table} #{$id} was removed by a dry run");
    }
    expect($dry)->toContain('What stays')->toContain('Purge Gate, not placed');

    $output = purgeRun();

    foreach ($mine as [$table, $id]) {
        expect(purgeExists($table, $id))->toBeFalse("{$table} #{$id} is the demonstration's and was left");
    }

    foreach ($theirs as [$table, $id]) {
        expect(purgeExists($table, $id))->toBeTrue("{$table} #{$id} was not the demonstration's and was deleted");
    }

    // A post the seed wrote stays (KI-24): the seed made the Main Gate the client confirmed is real.
    expect(purgeExists('posts', $post))->toBeTrue()
        ->and($output)->toMatch('/posts\s+1 kept — the estate needs it/')
        ->and($output)->toContain('What stayed');

    // The gate log refuses DELETE to everyone; this estate's row is kept and said, and only it is counted.
    expect(purgeExists('gate_events', $gate))->toBeTrue()
        ->and($output)->toContain('gate_events: 1 row(s)');
});

/*
 * WHAT THE ESTATE NEEDS ON LAUNCH DAY STAYS, EVEN WHERE THE SEED WROTE IT FIRST (M28). Provisioning seeds nothing, so
 * the seed was the first to write Phoenix Park's chart of accounts, its receipt counter, its arrears ladder and its
 * amenities, and the purge took them with the sample data: the books could take no payment on launch day, and the
 * first real receipt would have reused a number the kept journals carry.
 */
it('keeps the estate\'s chart, receipt counter, arrears ladder and amenities inside a seeding run, and takes its sample supplier', function () {
    $estate = FacilitiesFixture::platform();
    $tag = bin2hex(random_bytes(3));
    $insert = static fn (string $table, array $row): int => (int) $estate->run(static function () use ($table, $row): int {
        if (Schema::connection('tenant')->hasColumn($table, 'created_at')) {
            $row += ['created_at' => now(), 'updated_at' => now()];
        }

        return (int) DB::connection('tenant')->table($table)->insertGetId($row);
    });
    $exists = static fn (string $table, int $id): bool => (bool) $estate->run(static fn (): bool => DB::connection('tenant')->table($table)->where('id', $id)->exists());

    try {
        $before = $estate->run(static fn (): array => SampleData::highWater('tenant'));
        $kept = [
            'accounts' => $insert('accounts', ['code' => 'PRB'.$tag, 'name' => 'Purge probe account', 'type' => 'asset']),
            'receipt_sequences' => $insert('receipt_sequences', ['prefix' => 'PURGEPROBE'.$tag, 'first_no' => 1, 'last_no' => 41]),
            'dunning_templates' => $insert('dunning_templates', ['key' => 'purge-probe-'.$tag, 'label' => 'Purge probe step', 'stage' => 9, 'channel' => 'email', 'subject' => 'Purge probe', 'body' => 'Purge probe', 'days_overdue' => 400, 'is_active' => false]),
            'amenities' => $insert('amenities', ['name' => 'Purge probe hall '.$tag, 'capacity' => 1, 'is_active' => false, 'is_bookable' => false]),
        ];
        $supplier = $insert('vendors', ['name' => 'Purge probe — seeded supplier '.$tag]);
        $estate->run(static fn () => SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'tenant', $before));

        $dry = purgeRun(['--dry-run' => true]);

        foreach (array_keys($kept) as $table) {
            expect($dry)->toMatch('/'.$table.'\s+1 kept — the estate needs it/');
        }
        expect($dry)->toContain('What stays')->toContain('its books')->toContain('its amenities');

        purgeRun();

        foreach ($kept as $table => $id) {
            expect($exists($table, $id))->toBeTrue("{$table} #{$id} is what the estate needs and was purged");
        }
        expect($exists('vendors', $supplier))->toBeFalse();
    } finally {
        // The estate database is committed, not rolled back: leave nothing of ours in it.
        $estate->run(static function () use ($tag): void {
            $db = DB::connection('tenant');
            $db->table('accounts')->where('code', 'PRB'.$tag)->delete();
            $db->table('receipt_sequences')->where('prefix', 'PURGEPROBE'.$tag)->delete();
            $db->table('dunning_templates')->where('key', 'purge-probe-'.$tag)->delete();
            $db->table('amenities')->where('name', 'Purge probe hall '.$tag)->delete();
            $db->table('vendors')->where('name', 'Purge probe — seeded supplier '.$tag)->delete();
        });

        FacilitiesFixture::boot();
    }
});

it('records each seeding run by itself, so a re-seed never stretches over what was written in between', function () {
    $before = SampleData::highWater('mysql');
    $first = purgeShift(PURGE_ESTATE);
    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    // Days pass. The estate's own roster gains a real watch, another estate hires, a person joins.
    $rostered = purgeShift(PURGE_ESTATE);
    $theirs = purgeShift(PURGE_OTHER);
    $person = purgeUser('joined.between.runs@example.com');

    // "Everything central is refreshed on every run": the re-seed.
    $before = SampleData::highWater('mysql');
    $second = purgeShift(PURGE_ESTATE);
    $committee = purgeUser(purgeDemoAddress('clifton-head-of-security'));
    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    purgeRun();

    expect(purgeExists('shifts', $first))->toBeFalse()
        ->and(purgeExists('shifts', $second))->toBeFalse()
        ->and(purgeExists('users', $committee))->toBeFalse()
        ->and(purgeExists('shifts', $rostered))->toBeTrue()
        ->and(purgeExists('shifts', $theirs))->toBeTrue()
        ->and(purgeExists('users', $person))->toBeTrue();
});

it('reads a range recorded before the fix for this estate\'s own rows only, and says what it left', function () {
    $shift = purgeShift(PURGE_ESTATE);
    $theirs = purgeShift(PURGE_OTHER);
    $committee = purgeUser(purgeDemoAddress('norman-treasurer'));
    $person = purgeUser('a.real.person@example.com');
    $handset = purgeRow('personal_access_tokens', ['tokenable_type' => 'App\\Models\\Guard', 'tokenable_id' => 3, 'name' => 'Somebody\'s handset', 'token' => hash('sha256', 'purge-legacy')]);

    // The shape the old recorder left on the server: one range per table, widened
    // across runs, covering whatever the whole platform wrote inside it.
    foreach (['shifts' => [$shift, $theirs], 'users' => [$committee, $person], 'personal_access_tokens' => [$handset, $handset]] as $table => [$a, $b]) {
        DB::connection('mysql')->table('sample_data_ranges')->insert([
            'tag' => SampleData::PHOENIX_TAG,
            'tenant_id' => PURGE_ESTATE,
            'connection' => 'mysql',
            'table_name' => $table,
            'from_id' => min($a, $b) - 1,
            'to_id' => max($a, $b),
            'append_only' => false,
            'seeded_at' => now(),
        ]);
    }

    $output = purgeRun();

    expect(purgeExists('shifts', $shift))->toBeFalse()
        ->and(purgeExists('users', $committee))->toBeFalse()
        ->and(purgeExists('shifts', $theirs))->toBeTrue()
        ->and(purgeExists('users', $person))->toBeTrue()
        ->and(purgeExists('personal_access_tokens', $handset))->toBeTrue()
        ->and($output)->toContain('left alone');
});

it('takes a demonstration pay run only when every payslip on it is this estate\'s', function () {
    $before = SampleData::highWater('mysql');

    $ours = purgeRow('payroll_runs', ['reference' => 'PURGEP-DEMO-2026-08', 'period_label' => 'August 2026', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'statutory_rate_version_id' => 1]);
    $ourSlip = purgeRow('payslips', ['payroll_run_id' => $ours, 'guard_id' => 1, 'tenant_id' => PURGE_ESTATE, 'gross_minor' => 100, 'net_minor' => 90]);

    // A run the platform paid while the seeding ran, with officers at two estates on it.
    $shared = purgeRow('payroll_runs', ['reference' => 'GEMINI-2026-08', 'period_label' => 'August 2026', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'statutory_rate_version_id' => 1]);
    $sharedHere = purgeRow('payslips', ['payroll_run_id' => $shared, 'guard_id' => 4, 'tenant_id' => PURGE_ESTATE, 'gross_minor' => 100, 'net_minor' => 90]);
    $sharedThere = purgeRow('payslips', ['payroll_run_id' => $shared, 'guard_id' => 5, 'tenant_id' => PURGE_OTHER, 'gross_minor' => 100, 'net_minor' => 90]);

    // And one with no payslips at all, which is nobody's to take.
    $empty = purgeRow('payroll_runs', ['reference' => 'EMPTY-2026-08', 'period_label' => 'August 2026', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'statutory_rate_version_id' => 1]);

    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    purgeRun();

    expect(purgeExists('payroll_runs', $ours))->toBeFalse()
        ->and(purgeExists('payslips', $ourSlip))->toBeFalse()
        ->and(purgeExists('payroll_runs', $shared))->toBeTrue()
        ->and(purgeExists('payslips', $sharedThere))->toBeTrue()
        ->and(purgeExists('payroll_runs', $empty))->toBeTrue()

        // A real officer of this estate, paid on the platform's real run: their
        // payslip is on a run that is not the demonstration's, so it stays too.
        ->and(purgeExists('payslips', $sharedHere))->toBeTrue();
});

it('stores a run\'s own ids as the unbroken runs they form', function () {
    expect(SampleData::spans([]))->toBe([])
        ->and(SampleData::spans([7]))->toBe([[6, 7]])
        ->and(SampleData::spans([3, 4, 5, 7, 9, 10]))->toBe([[2, 5], [6, 7], [8, 10]]);
});

it('forgets only the purged estate\'s ranges, so another estate seeded under the same tag can still be purged', function () {
    $before = SampleData::highWater('mysql');
    purgeShift(PURGE_ESTATE);
    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    $before = SampleData::highWater('mysql');
    $theirs = purgeShift(PURGE_OTHER);
    SampleData::record(SampleData::PHOENIX_TAG, PURGE_OTHER, 'mysql', $before);

    purgeRun();

    $ranges = DB::connection('mysql')->table('sample_data_ranges')->where('tag', SampleData::PHOENIX_TAG);

    expect((clone $ranges)->where('tenant_id', PURGE_ESTATE)->exists())->toBeFalse()
        ->and((clone $ranges)->where('tenant_id', PURGE_OTHER)->exists())->toBeTrue()
        ->and(purgeExists('shifts', $theirs))->toBeTrue();
});

it('records each run of the estate\'s own database by itself too', function () {
    $estate = FacilitiesFixture::platform();

    $vendor = static fn (string $name): int => (int) $estate->run(
        static fn (): int => (int) DB::connection('tenant')->table('vendors')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]),
    );

    $vendorExists = static fn (int $id): bool => (bool) $estate->run(
        static fn (): bool => DB::connection('tenant')->table('vendors')->where('id', $id)->exists(),
    );

    try {
        $before = $estate->run(static fn (): array => SampleData::highWater('tenant'));
        $first = $vendor('Purge probe — seeded, run one');
        $estate->run(static fn () => SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'tenant', $before));

        // Between runs, the office adds a supplier of its own.
        $real = $vendor('Purge probe — the office\'s own supplier');

        $before = $estate->run(static fn (): array => SampleData::highWater('tenant'));
        $second = $vendor('Purge probe — seeded, run two');
        $estate->run(static fn () => SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'tenant', $before));

        purgeRun();

        expect($vendorExists($first))->toBeFalse()
            ->and($vendorExists($second))->toBeFalse()
            ->and($vendorExists($real))->toBeTrue();
    } finally {
        // The estate database is committed, not rolled back: leave nothing of ours in it.
        $estate->run(static fn () => DB::connection('tenant')->table('vendors')->where('name', 'like', 'Purge probe — %')->delete());

        FacilitiesFixture::boot();
    }
});

/*
 * KEEP AN EXPORT FIRST (M16 §1.4). --export writes every row the purge will remove before it removes any, and the
 * purge runs only if the file holds exactly that many. A path that cannot be written — here, one that exists
 * already — refuses the whole purge, and nothing is gone.
 */
it('writes every row it will remove to the export before removing any, and refuses to purge without a whole export', function () {
    $before = SampleData::highWater('mysql');

    $post = purgeRow('posts', ['tenant_id' => PURGE_ESTATE, 'name' => 'Export Gate']);
    $shift = purgeShift(PURGE_ESTATE);
    $committee = purgeUser(purgeDemoAddress('export-probe'));

    SampleData::record(SampleData::PHOENIX_TAG, PURGE_ESTATE, 'mysql', $before);

    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'purge-export-'.bin2hex(random_bytes(6)).'.jsonl';

    try {
        // A dry run with an export writes the file and removes nothing.
        purgeRun(['--dry-run' => true, '--export' => $file]);
        expect(is_file($file))->toBeTrue()
            ->and(purgeExists('posts', $post))->toBeTrue();

        // The file exists now, so a purge naming it again is refused — whole, with nothing deleted.
        $refused = purgeRun(['--confirm' => true, '--export' => $file]);
        expect($refused)->toContain('Refused')
            ->and(purgeExists('posts', $post))->toBeTrue()
            ->and(purgeExists('shifts', $shift))->toBeTrue();

        unlink($file);

        $output = purgeRun(['--confirm' => true, '--export' => $file]);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($file))));
        $rows = array_map(static fn (string $l): array => json_decode($l, true), $lines);
        $exported = collect($rows)->map(static fn (array $r): string => $r['table'].'#'.$r['row']['id'])->all();

        expect($output)->toContain('Exported '.count($lines).' row(s)')
            ->and($exported)->toContain('shifts#'.$shift)
            ->and($exported)->toContain('users#'.$committee)
            ->and(collect($rows)->every(static fn (array $r): bool => in_array($r['connection'], ['mysql', 'tenant'], true)))->toBeTrue()
            // …and exported means gone.
            ->and(purgeExists('shifts', $shift))->toBeFalse()
            ->and(purgeExists('users', $committee))->toBeFalse()
            // A post stays (KI-24), so it is neither exported nor removed.
            ->and($exported)->not->toContain('posts#'.$post)
            ->and(purgeExists('posts', $post))->toBeTrue();
    } finally {
        @unlink($file);
    }
});
