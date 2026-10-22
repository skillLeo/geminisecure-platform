<?php

declare(strict_types=1);

use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Notice;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Notices;
use App\Services\Gemini\GuardWorkforce;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\SampleAwareBuilder;
use App\Support\Seeded\SeededTables;
use App\Support\Seeded\Seeding;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstateScaleFixture;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An estate's sample data is retired on launch day, and nothing is deleted (M33 §5, KI-25)
|--------------------------------------------------------------------------
|
| `launch:retire-sample-data` hides an estate's sample rows from every list, count, report and total and keeps them
| in the database; a dry run first, --confirm to act, the invoices written out before anything is hidden, --undo to
| show them again — for that estate and no other. The scale estate is the subject: every row of it was written by the
| seeders, exactly as Phoenix Park's on production was, and nothing here writes to it but a few rows of its own,
| removed again after. A command opens the estate and closes it again, so the fixture is booted after each one.
|
*/

beforeEach(function () {
    EstateScaleFixture::boot();
    DB::connection('mysql')->beginTransaction();
    $this->estate = EstateScaleFixture::enter();
    $this->export = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gs-m33-retire-'.bin2hex(random_bytes(4));
    $this->made = [];
});

afterEach(function () {
    // The platform's rows first, whatever else fails: an open transaction here would hold the next test's locks.
    try {
        while (DB::connection('mysql')->transactionLevel() > 0) {
            DB::connection('mysql')->rollBack();
        }
    } finally {
        Retirements::forget();

        Retirements::seeingEverything(function (): void {
            foreach ($this->made as [$database, $table, $id]) {
                config(['database.connections.tenant' => array_merge(config('database.connections.mysql'), ['database' => $database])]);
                DB::purge('tenant');
                DB::connection('tenant')->table($table)->where('id', $id)->delete();
            }
        });

        EstateScaleFixture::boot();

        foreach (glob($this->export.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->export)) {
            rmdir($this->export);
        }
    }
});

/** A row of the estate's own written by the application — a real one — remembered so it is removed afterwards. */
function madeHere(object $test, string $table, array $row): int
{
    $id = DB::connection('tenant')->table($table)->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
    $test->made[] = [DB::connection('tenant')->getDatabaseName(), $table, $id];

    return $id;
}

/** Run the command at the scale estate, then point the connections back at it. */
function retireScale(object $test, array $more = [], ?Closure $expect = null, bool $succeeds = true): void
{
    $command = $test->artisan('launch:retire-sample-data', ['--tenant' => EstateScaleFixture::ESTATE, '--export-to' => $test->export, ...$more]);

    if ($expect !== null) {
        $expect($command);
    }

    $succeeds ? $command->assertSuccessful() : $command->assertFailed();
    $command->run();
    EstateScaleFixture::boot();
}

it('says table by table what it would hide, and changes nothing without --confirm', function () {
    // One phrase per printed line: a line that holds two satisfies only the first of them (PendingCommand's mocks).
    retireScale($this, [], static fn ($command) => $command
        ->expectsOutputToContain('What it hides')
        ->expectsOutputToContain('Money it takes out of the estate\'s figures')
        ->expectsOutputToContain('(they balance, so every report still balances); 0 lines hidden apart from their journal (none: a journal goes with every line of it)')
        ->expectsOutputToContain('Nothing was changed. Run again with --confirm'));

    expect(DB::connection('mysql')->table(Retirements::TABLE)->where('tenant_id', EstateScaleFixture::ESTATE)->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(EstateScaleFixture::CHARGES)
        ->and(is_dir($this->export))->toBeFalse('a dry run writes no export');
});

it('hides the sample from every list, count, report and total, keeps every row, and writes the invoices out first', function () {
    // A real person living in a seeded household, and a real notice: the household stays, and so do they.
    $household = (int) Household::query()->orderBy('id')->value('id');
    $person = madeHere($this, 'residents', ['household_id' => $household, 'full_name' => 'M33 Real Person', 'email' => 'm33.real@example.test', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED]);
    $notice = madeHere($this, 'notices', ['title' => 'M33 a real notice', 'body' => 'From the office.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL]);

    // The company seed's platform rows for the estate, marked as it writes them.
    $this->artisan('company:demo-seed', ['--estate' => EstateScaleFixture::ESTATE])->assertSuccessful()->run();
    EstateScaleFixture::boot();

    $officers = Guard::query()->where('tenant_id', EstateScaleFixture::ESTATE)->count();
    $trialBefore = app(Ledger::class)->trialBalance()->sum(static fn (array $row): int => $row['debit_minor']);
    $balancesBefore = app(Dues::class)->unitBalances();
    $reachBefore = app(Notices::class)->householdReach([Notice::query()->findOrFail($notice)])[$notice]['audience'];

    retireScale($this, ['--confirm' => true], static fn ($command) => $command
        ->expectsOutputToContain('Kept shown, because a real record points at them')
        ->expectsOutputToContain('estate households: 1 kept (1 row in residents)')
        ->expectsOutputToContain('Retired:'));

    // Every row is still in the database.
    Retirements::seeingEverything(function () use ($officers): void {
        expect(DB::connection('tenant')->table('charges')->count())->toBe(EstateScaleFixture::CHARGES)
            ->and(DB::connection('tenant')->table('households')->count())->toBe(EstateScaleFixture::HOUSEHOLDS)
            ->and(Guard::query()->where('tenant_id', EstateScaleFixture::ESTATE)->count())->toBe($officers);
    });

    // Lists and counts.
    expect($officers)->toBeGreaterThan(0)
        ->and(Charge::query()->count())->toBe(0)
        ->and(Household::query()->pluck('id')->all())->toBe([$household])
        ->and(Resident::query()->pluck('id')->all())->toBe([$person])
        ->and(Notice::query()->pluck('id')->all())->toBe([$notice])
        ->and(Guard::query()->where('tenant_id', EstateScaleFixture::ESTATE)->count())->toBe(0)
        ->and(Invoice::query()->where('tenant_id', EstateScaleFixture::ESTATE)->count())->toBe(0)
        // What the estate needs on launch day stays whoever wrote it: its two thousand lots, its chart of accounts.
        ->and(Unit::query()->count())->toBe(EstateScaleFixture::LOTS)
        ->and(DB::connection('tenant')->table('accounts')->count())->toBeGreaterThan(0);

    // Totals and reports.
    expect((int) DB::connection('tenant')->table('charges')->sum('amount_minor'))->toBe(0)
        ->and((int) DB::connection('tenant')->table('payments')->sum('amount_minor'))->toBe(0)
        ->and($trialBefore)->toBeGreaterThan(0)
        ->and(app(Ledger::class)->trialBalance()->sum(static fn (array $row): int => $row['debit_minor']))->toBe(0)
        ->and($balancesBefore)->not->toBe([])
        ->and(app(Dues::class)->unitBalances())->toBe([])
        ->and($reachBefore)->toBeGreaterThan(1)
        ->and(app(Notices::class)->householdReach([Notice::query()->findOrFail($notice)])[$notice]['audience'])->toBe(1);

    // The export was written first, whole, and the retirement says where.
    $retirement = DB::connection('mysql')->table(Retirements::TABLE)->where('tenant_id', EstateScaleFixture::ESTATE)->whereNull('undone_at')->sole();
    $lines = file($retirement->export_path, FILE_IGNORE_NEW_LINES);
    $tables = array_count_values(array_map(static fn (string $line): string => json_decode($line, true)['table'], $lines));

    expect(hash_file('sha256', $retirement->export_path))->toBe($retirement->export_sha256)
        ->and(count($lines))->toBe((int) $retirement->export_rows)
        ->and($tables['charges'])->toBe(EstateScaleFixture::CHARGES)
        ->and($tables['invoices'] ?? 0)->toBeGreaterThan(0)
        ->and($tables['invoice_lines'] ?? 0)->toBeGreaterThan(0)
        ->and(json_decode($lines[0], true)['row'])->toHaveKey('seeded_by');
});

it('leaves out a hidden row in every shape a query takes: joins, sub-selects, OR, unions, pages, updates and deletes', function () {
    $household = (int) Household::query()->orderBy('id')->value('id');
    $person = madeHere($this, 'residents', ['household_id' => $household, 'full_name' => 'M33 Real Person', 'email' => 'm33.shapes@example.test', 'relationship' => 'owner', 'is_primary' => false, 'status' => Resident::VERIFIED]);
    $seededTitle = (string) Notice::query()->orderBy('id')->value('title');

    retireScale($this, ['--confirm' => true]);
    $db = DB::connection('tenant');

    expect($db->table('units')->join('households', 'households.unit_id', '=', 'units.id')->count())->toBe(1)
        // A LEFT JOIN to a hidden row reads as no row: every lot is listed, one with its household.
        ->and($db->table('units')->leftJoin('households', 'households.unit_id', '=', 'units.id')->count())->toBe(EstateScaleFixture::LOTS)
        ->and($db->table('units')->leftJoin('households', 'households.unit_id', '=', 'units.id')->whereNotNull('households.id')->count())->toBe(1)
        ->and($db->table('units')->leftJoin('households as h', 'h.unit_id', '=', 'units.id')->whereNotNull('h.id')->count())->toBe(1)
        ->and(Unit::query()->whereHas('households')->count())->toBe(1)
        ->and(Unit::query()->whereDoesntHave('charges')->count())->toBe(EstateScaleFixture::LOTS)
        ->and((int) Unit::query()->withCount('charges')->get()->sum('charges_count'))->toBe(0)
        ->and($db->table('households')->whereIn('id', $db->table('residents')->select('household_id'))->count())->toBe(1)
        ->and($db->table('residents')->where('id', $person)->orWhere('full_name', 'like', '%householder%')->count())->toBe(1)
        ->and($db->table('notices')->where('title', $seededTitle)->orWhere('id', '>', 0)->count())->toBe(0)
        ->and($db->table('charges')->select('id')->union($db->table('payments')->select('id'))->get()->count())->toBe(0)
        ->and($db->table('charges')->paginate(25)->total())->toBe(0)
        ->and(Charge::query()->where('amount_minor', '>', 0)->exists())->toBeFalse()
        ->and($db->table('charges')->whereExists(static fn ($q) => $q->selectRaw('1')->from('units')->whereColumn('units.id', 'charges.unit_id'))->count())->toBe(0)
        ->and($db->table('units')->whereExists(static fn ($q) => $q->selectRaw('1')->from('charges')->whereColumn('charges.unit_id', 'units.id'))->count())->toBe(0)
        ->and($db->query()->fromSub($db->table('charges')->select('unit_id'), 'c')->count())->toBe(0);

    // An update or a delete reaches only what is shown: the hidden rows are not the application's to change. Inside a
    // transaction rolled back, so a scope that failed could not change the estate every other test measures.
    $db->beginTransaction();

    try {
        $renamed = $db->table('notices')->update(['title' => 'M33 renamed']);
        $deleted = $db->table('charges')->where('id', '>', 0)->delete();
        $left = Retirements::seeingEverything(static fn (): array => [DB::connection('tenant')->table('charges')->count(), DB::connection('tenant')->table('notices')->where('title', 'M33 renamed')->count()]);
    } finally {
        $db->rollBack();
    }

    expect($renamed)->toBe(0)
        ->and($deleted)->toBe(0)
        ->and($left)->toBe([EstateScaleFixture::CHARGES, 0]);
});

it('hides what a seed wrote before the mark existed: the ranges it recorded, and the company seed\'s rows by what they say', function () {
    // Two notices written as production's were before the mark: unmarked. The first is in a range the seed recorded.
    $old = madeHere($this, 'notices', ['title' => 'M33 recorded by a seed', 'body' => 'Old sample.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL]);
    $real = madeHere($this, 'notices', ['title' => 'M33 written after it', 'body' => 'Real.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL]);
    DB::connection('mysql')->table('sample_data_ranges')->insert(['tag' => 'phoenix-demo', 'tenant_id' => EstateScaleFixture::ESTATE, 'connection' => 'tenant', 'table_name' => 'notices', 'from_id' => $old - 1, 'to_id' => $old, 'append_only' => false, 'seeded_at' => now()]);

    // One of the company seed's officers as it wrote them, unmarked; and an officer the office added by hand.
    $seedOfficer = DB::connection('mysql')->table('guards')->insertGetId(['tenant_id' => EstateScaleFixture::ESTATE, 'employee_number' => 'GS-2001', 'full_name' => 'Andre Chambers', 'psra_number' => 'PSRA-JM-40118', 'status' => 'active', 'employment_type' => 'full_time', 'created_at' => now(), 'updated_at' => now()]);
    $handOfficer = DB::connection('mysql')->table('guards')->insertGetId(['tenant_id' => EstateScaleFixture::ESTATE, 'employee_number' => 'GS-2015', 'full_name' => 'M33 Hand Entered', 'psra_number' => 'M33-HAND-01', 'status' => 'active', 'employment_type' => 'full_time', 'created_at' => now(), 'updated_at' => now()]);

    retireScale($this, ['--confirm' => true]);

    expect(Notice::query()->pluck('id')->all())->toBe([$real])
        ->and(Guard::query()->where('tenant_id', EstateScaleFixture::ESTATE)->pluck('id')->all())->toBe([$handOfficer])
        ->and(Retirements::seeingEverything(static fn () => Guard::query()->whereKey([$seedOfficer, $handOfficer])->count()))->toBe(2);
});

it('counts every generated number past the hidden ones, and refuses a hidden officer\'s licence, so nothing collides', function () {
    $ticket = (int) DB::connection('tenant')->table('maintenance_tickets')->max('number');
    $stem = 'CHG-'.now()->format('Y-m').'-';
    $highestCharge = (int) substr((string) Charge::query()->where('reference', 'like', $stem.'%')->orderByDesc('reference')->value('reference'), strlen($stem));

    // An officer a seeding command wrote, holding the platform's highest number and a licence nobody else has.
    Seeding::run('company:demo-seed', static fn () => DB::connection('mysql')->table('guards')->insert([
        'tenant_id' => EstateScaleFixture::ESTATE, 'employee_number' => 'GS-98765', 'full_name' => 'M33 Seed Officer', 'psra_number' => 'M33-SEED-01',
        'status' => 'active', 'employment_type' => 'full_time', 'created_at' => now(), 'updated_at' => now(),
    ]));

    retireScale($this, ['--confirm' => true]);

    expect(Charge::query()->count())->toBe(0)
        ->and(Guard::query()->where('psra_number', 'M33-SEED-01')->exists())->toBeFalse()
        ->and((new ReflectionMethod(Maintenance::class, 'nextNumber'))->invoke(app(Maintenance::class)))->toBe($ticket + 1)
        ->and((new ReflectionMethod(Dues::class, 'nextSequence'))->invoke(app(Dues::class), 'CHG', now()))->toBe($highestCharge + 1)
        ->and(app(GuardWorkforce::class)->nextEmployeeNumber())->toBe('GS-98766');

    // A unique licence number is unique among every officer on file, a hidden one too: refused, never a database error.
    $validator = (new ReflectionMethod(GuardWorkforce::class, 'validateNewGuard'))->invoke(app(GuardWorkforce::class), FacilitiesFixture::geminiViewer(Role::DIRECTOR), [
        'full_name' => 'M33 New Officer', 'psra_number' => 'M33-SEED-01', 'psra_expires_on' => now()->addYear()->toDateString(), 'phone' => '876 555 0133',
        'email' => 'm33.officer@gmail.com', 'employment_type' => 'full_time', 'standard_rate' => '425',
    ]);
    EstateScaleFixture::boot();

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('psra_number'))->toBe('That PSRA licence number already belongs to another guard on the platform.');
});

it('refuses what it must, and says why', function () {
    $this->artisan('launch:retire-sample-data', [])->expectsOutputToContain('Say which estate')->assertFailed()->run();
    $this->artisan('launch:retire-sample-data', ['--tenant' => 'no-such-estate'])->expectsOutputToContain('No estate with the id')->assertFailed()->run();

    Tenant::withoutEvents(static fn () => (new Tenant)->forceFill(['id' => 'm33demo', 'name' => 'M33 demonstration estate', 'receipt_prefix' => 'MDM', 'status' => 'active', 'demo' => true])->save());
    $this->artisan('launch:retire-sample-data', ['--tenant' => 'm33demo', '--confirm' => true])->expectsOutputToContain('is the demonstration estate')->assertFailed()->run();
    EstateScaleFixture::boot();

    retireScale($this, ['--undo' => true], static fn ($command) => $command->expectsOutputToContain('is not retired'), succeeds: false);
    retireScale($this, ['--confirm' => true]);
    retireScale($this, ['--confirm' => true], static fn ($command) => $command->expectsOutputToContain('has been retired since'), succeeds: false);
});

it('undoes a retirement for that estate and no other', function () {
    // A second estate with sample of its own, retired too.
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    $theirs = Seeding::run('phoenix:demo-seed', fn (): int => madeHere($this, 'notices', ['title' => 'M33 the other estate\'s sample', 'body' => 'Sample.', 'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'M33', 'kind' => Notice::GENERAL]));
    $this->artisan('launch:retire-sample-data', ['--tenant' => FacilitiesFixture::ESTATE, '--confirm' => true, '--export-to' => $this->export])->assertSuccessful()->run();
    EstateScaleFixture::boot();

    retireScale($this, ['--confirm' => true]);
    expect(Charge::query()->count())->toBe(0);

    retireScale($this, ['--undo' => true], static fn ($command) => $command->expectsOutputToContain('Nothing was changed. Run again with --undo --confirm'));
    expect(Charge::query()->count())->toBe(0);

    retireScale($this, ['--undo' => true, '--confirm' => true], static fn ($command) => $command->expectsOutputToContain('Shown again'));
    expect(Charge::query()->count())->toBe(EstateScaleFixture::CHARGES)
        ->and(DB::connection('mysql')->table(Retirements::TABLE)->where('tenant_id', EstateScaleFixture::ESTATE)->whereNotNull('undone_at')->count())->toBe(1);

    FacilitiesFixture::boot();
    $stillHidden = ! Notice::query()->whereKey($theirs)->exists();
    $stillThere = Retirements::seeingEverything(static fn (): bool => Notice::query()->whereKey($theirs)->exists());
    EstateScaleFixture::boot();

    expect($stillHidden)->toBeTrue('the other estate stays retired')
        ->and($stillThere)->toBeTrue();
});

it('adds nothing to any query while no estate has retired', function () {
    expect(Retirements::any())->toBeFalse()
        ->and(DB::connection('tenant')->table('charges')->leftJoin('units', 'units.id', '=', 'charges.unit_id')->where('amount_minor', '>', 0)->orWhere('charges.id', 1)->toSql())->not->toContain('seeded_at')
        ->and(DB::connection('tenant')->query())->toBeInstanceOf(SampleAwareBuilder::class)
        ->and(DB::connection('mysql')->query())->toBeInstanceOf(SampleAwareBuilder::class);
});

it('puts the hiding condition into every raw statement that reads a retired table', function () {
    $retired = implode('|', [...SeededTables::estateRetired(), ...SeededTables::centralRetired()]);

    // Where a raw statement may read a retired table as it is, and why.
    $allowed = [
        'Console/Commands/GateLedger.php' => 'a gate checks the database itself (a blind command)',
        'Console/Commands/GateTenantIsolation.php' => 'a gate checks the database itself (a blind command)',
        'Console/Commands/RetireSampleData.php' => 'writes the conditions itself',
        'Support/Seeded/Retirements.php' => 'is the conditions',
        'Support/Seeded/SeededPeople.php' => 'who holds an address: a retired seeded person is still one, and is never written to (M35 §5)',
        'Services/Estate/DashboardTiles.php' => 'change stamps, not figures; the retirement is in the stamp',
        'Services/Estate/Maintenance.php' => 'a sort key: the name of the ticket\'s own vendor',
        'Services/Estate/Settings.php' => 'a sort key: the invoice\'s own lines',
        'Support/SampleData.php' => 'the purge\'s own bookkeeping',
    ];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            // SQL's own words in any case; a table's name as SQL writes it, so "from Residents → …" in a sentence is not one.
            if (preg_match('/\b(?i:from|join)\s+`?('.$retired.')`?(\s|$|\)|,|\'|")/', $token[1], $m) !== 1) {
                continue;
            }

            // The statement the string belongs to, to its end: does it ask Retirements?
            $asks = false;
            for ($j = $i; $j < count($tokens) && $tokens[$j] !== ';'; $j++) {
                $asks = $asks || (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === 'Retirements');
            }

            expect($asks || array_key_exists($relative, $allowed))
                ->toBeTrue("{$relative}:{$token[2]} reads `{$m[1]}` in raw SQL without the hiding condition: add Retirements::and(…) to the statement, or say here why it may see retired rows.");
        }
    }
});
