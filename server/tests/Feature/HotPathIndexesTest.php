<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The hot-path indexes are there, exactly as measured (M11 A2)
|--------------------------------------------------------------------------
|
| Every index below was derived from a statement the platform runs and measured at
| Phoenix Park's size (docs/perf/EXPLAIN.md). This file reads information_schema
| for each one — name, columns and their order — in the central database and in
| an estate database built by the real tenant migrations, so an index cannot be
| dropped, renamed or reordered without this failing and saying which.
|
| The expectations are written out here rather than read from the migrations: a
| migration that quietly lost an entry would otherwise agree with itself.
|
| Three of the tables indexed carry triggers — two append-only ledgers and the
| documents' retention rule — so it proves every one of those still refuses, and
| that the journal's account foreign key still holds after its old index was
| replaced.
|
| And an index must not reorder a screen. Where a new index reads rows that tie
| on their sort in its own order, or reads groups in collation order, the query
| now names its order, and the last part of this file proves each one: in the
| rows a screen or a payload shows, and in the SQL itself, because the fixture
| estate is too small for MySQL to choose the index a production estate gets.
|
*/

use App\Api\Catalogue;
use App\Models\Estate\Ballot;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\Governance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;

/** @return array<string, array<string, list<string>>> table => index name => columns, in order */
function hotPathCentralIndexes(): array
{
    return [
        'audit_log' => [
            'audit_log_created_at_index' => ['created_at'],
            'audit_log_entity_created_at_index' => ['entity_type', 'entity_id', 'created_at'],
        ],
        'alertness_checks' => ['alertness_checks_outcome_respond_by_index' => ['outcome', 'respond_by']],
        'duress_alerts' => [
            'duress_alerts_resolved_at_index' => ['resolved_at'],
            'duress_alerts_tenant_id_server_time_index' => ['tenant_id', 'server_time'],
        ],
        'invitations' => ['invitations_tenant_id_accepted_at_index' => ['tenant_id', 'accepted_at']],
        'security_incidents' => ['security_incidents_status_occurred_at_index' => ['status', 'occurred_at']],
        'shifts' => [
            'shifts_on_duty_index' => ['actual_end', 'actual_start'],
            'shifts_tenant_id_rostered_end_index' => ['tenant_id', 'rostered_end'],
            'shifts_guard_id_rostered_end_index' => ['guard_id', 'rostered_end'],
            'shifts_post_id_actual_end_index' => ['post_id', 'actual_end'],
            'shifts_status_index' => ['status'],
        ],
    ];
}

/** @return array<string, array<string, list<string>>> */
function hotPathEstateIndexes(): array
{
    return [
        'journal_lines' => ['journal_lines_account_ledger_index' => ['account_id', 'id', 'unit_id', 'debit_minor', 'credit_minor', 'entry_ref']],
        'payments' => [
            'payments_status_unit_received_index' => ['status', 'unit_id', 'received_at', 'amount_minor'],
            'payments_status_received_at_index' => ['status', 'received_at', 'amount_minor'],
            'payments_received_at_index' => ['received_at'],
        ],
        'charges' => ['charges_due_on_fifo_index' => ['due_on', 'id', 'status', 'unit_id', 'amount_minor']],
        'units' => ['units_block_status_index' => ['block', 'status']],
        'residents' => ['residents_verified_at_index' => ['verified_at']],
        'documents' => ['documents_subject_index' => ['subject_type', 'subject_id']],
        'maintenance_tickets' => ['maintenance_tickets_closed_reported_index' => ['closed_at', 'reported_at']],
        'amenity_bookings' => [
            'amenity_bookings_created_at_index' => ['created_at'],
            'amenity_bookings_deposit_ends_index' => ['deposit_state', 'ends_at'],
        ],
        'dunning_notices' => ['dunning_notices_sent_at_index' => ['sent_at']],
        'meetings' => ['meetings_starts_at_index' => ['starts_at']],
        'visitor_passes' => [
            'visitor_passes_valid_to_index' => ['valid_to'],
            'visitor_passes_unit_id_valid_from_index' => ['unit_id', 'valid_from'],
        ],
    ];
}

/**
 * Every index on every table of one schema, as information_schema reports it.
 *
 * @return array<string, array<string, array{columns: list<string>, unique: bool}>> table => index => definition
 */
function hotPathSchemaIndexes(string $schema): array
{
    $rows = DB::connection('mysql')->select(
        'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS n, COLUMN_NAME AS c, SUB_PART AS p, COLLATION AS d
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        [$schema],
    );

    $indexes = [];
    foreach ($rows as $row) {
        $indexes[$row->t][$row->i]['columns'][] = $row->c.($row->p === null ? '' : '('.$row->p.')').($row->d === 'D' ? ' DESC' : '');
        $indexes[$row->t][$row->i]['unique'] = (int) $row->n === 0;
    }

    return $indexes;
}

/** @param array<string, array<string, list<string>>> $expected */
function hotPathAssertIndexes(string $schema, array $expected): void
{
    $actual = hotPathSchemaIndexes($schema);

    foreach ($expected as $table => $indexes) {
        foreach ($indexes as $name => $columns) {
            // MySQL refuses a longer identifier; said here so the budget is visible where the names are.
            expect(strlen($name))->toBeLessThanOrEqual(64, "{$name} is longer than MySQL's 64-character limit");

            expect($actual[$table][$name] ?? null)->not->toBeNull("{$schema}.{$table} has no index {$name}");
            expect($actual[$table][$name]['columns'])->toBe($columns, "{$schema}.{$table}.{$name} is not on (".implode(', ', $columns).') in that order');
            expect($actual[$table][$name]['unique'])->toBeFalse("{$schema}.{$table}.{$name} should not be unique: it orders and filters, it does not constrain");
        }
    }
}

/** @return list<string> "schema.table: a, b share (cols)" for every set of indexes with one column list */
function hotPathExactDuplicates(string $schema): array
{
    $duplicates = [];

    foreach (hotPathSchemaIndexes($schema) as $table => $indexes) {
        $byColumns = [];
        foreach ($indexes as $name => $definition) {
            $byColumns[implode(',', $definition['columns'])][] = $name;
        }
        foreach ($byColumns as $columns => $names) {
            if (count($names) > 1) {
                $duplicates[] = "{$schema}.{$table}: ".implode(', ', $names)." share ({$columns})";
            }
        }
    }

    return $duplicates;
}

/**
 * Run $body inside the fixture estate with tenancy initialised, in a transaction on the estate
 * connection that is rolled back whatever happens, so nothing it writes outlives it.
 *
 * Tenancy FIRST, then the transaction: initialising tenancy purges and reopens the `tenant`
 * connection, and a transaction opened before that would be on a connection nobody reads any more.
 */
function hotPathInEstate(callable $body): void
{
    tenancy()->initialize(FacilitiesFixture::platform());
    $estate = DB::connection('tenant');
    $estate->beginTransaction();

    try {
        $body($estate);
    } finally {
        $estate->rollBack();
        tenancy()->end();
        FacilitiesFixture::boot();
    }
}

/**
 * The SQL of every statement $body makes, in order.
 *
 * @return list<string>
 */
function hotPathStatementsOf(callable $body): array
{
    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $body();

    return $statements;
}

beforeEach(function (): void {
    FacilitiesFixture::boot();
});

it('has every central hot-path index, on its columns, in order', function (): void {
    hotPathAssertIndexes(DB::connection('mysql')->getDatabaseName(), hotPathCentralIndexes());
});

it('has every estate hot-path index, on its columns, in order, in a database the tenant migrations built', function (): void {
    hotPathAssertIndexes(FacilitiesFixture::database(), hotPathEstateIndexes());
});

it('has replaced the journal account index rather than kept it beside its successor, and the account foreign key still holds', function (): void {
    $journal = hotPathSchemaIndexes(FacilitiesFixture::database())['journal_lines'];

    expect($journal)->not->toHaveKey('journal_lines_account_id_id_index');

    // `account_id` is a foreign key; MySQL needs an index led by it, and the successor is one.
    $foreignKeys = DB::connection('mysql')->select(
        'SELECT kcu.COLUMN_NAME AS c, kcu.REFERENCED_TABLE_NAME AS r
           FROM information_schema.KEY_COLUMN_USAGE kcu
           JOIN information_schema.TABLE_CONSTRAINTS tc
             ON tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND tc.TABLE_NAME = kcu.TABLE_NAME AND tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = ?',
        [FacilitiesFixture::database(), 'journal_lines', 'FOREIGN KEY'],
    );

    expect(collect($foreignKeys)->contains(fn ($fk): bool => $fk->c === 'account_id' && $fk->r === 'accounts'))->toBeTrue()
        ->and($journal['journal_lines_account_ledger_index']['columns'][0])->toBe('account_id');

    // And it is ENFORCED, not merely declared: a line against an account that does not exist is
    // refused. Inside a transaction that is rolled back, so the fixture's ledger is untouched.
    $estate = DB::connection('tenant');
    $estate->beginTransaction();

    try {
        expect(fn () => $estate->table('journal_lines')->insert([
            'entry_ref' => 'M11-FK-PROBE-'.bin2hex(random_bytes(4)),
            'account_id' => 999_999_999,
            'line_no' => 1,
            'debit_minor' => 100,
            'credit_minor' => 0,
            'currency' => 'JMD',
        ]))->toThrow(QueryException::class, 'foreign key constraint');
    } finally {
        $estate->rollBack();
    }
});

it('leaves no two indexes on one table over the same columns, in either database', function (): void {
    expect(hotPathExactDuplicates(DB::connection('mysql')->getDatabaseName()))->toBe([])
        ->and(hotPathExactDuplicates(FacilitiesFixture::database()))->toBe([]);
});

it('keeps the journal append-only now that it carries a new index', function (): void {
    $estate = DB::connection('tenant');
    $line = $estate->table('journal_lines')->orderBy('id')->first();

    expect($line)->not->toBeNull('the fixture estate has no journal line to try the triggers on');

    expect(fn () => $estate->table('journal_lines')->where('id', $line->id)->update(['memo' => 'rewritten']))
        ->toThrow(QueryException::class, 'journal_lines is append-only');

    expect(fn () => $estate->table('journal_lines')->where('id', $line->id)->delete())
        ->toThrow(QueryException::class, 'journal_lines is append-only');

    expect($estate->table('journal_lines')->where('id', $line->id)->value('memo'))->toBe($line->memo);
});

it('keeps an issued document retained as issued now that documents carries a new index', function (): void {
    $estate = DB::connection('tenant');
    $estate->beginTransaction();

    try {
        $id = $estate->table('documents')->insertGetId([
            'kind' => 'statement', 'subject_type' => 'unit', 'subject_id' => 'M11-PROBE',
            'title' => 'M11 trigger probe', 'filename' => 'm11-probe.pdf', 'content_type' => 'application/pdf',
            'status' => 'ready', 'path' => 'documents/m11-probe.pdf', 'bytes' => 1, 'sha256' => str_repeat('0', 64),
            'requested_by_name' => 'HotPathIndexesTest', 'issued_at' => now(), 'retain_until' => now()->addYears(7),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(fn () => $estate->table('documents')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'cannot be deleted before its retain_until date');

        expect(fn () => $estate->table('documents')->where('id', $id)->update(['retain_until' => now()->addYear()]))
            ->toThrow(QueryException::class, 'never shortened');

        expect(fn () => $estate->table('documents')->where('id', $id)->update(['sha256' => str_repeat('1', 64)]))
            ->toThrow(QueryException::class, 'retained as issued');

        // Found again through the new index, exactly as issued.
        expect($estate->table('documents')->where('subject_type', 'unit')->where('subject_id', 'M11-PROBE')->value('sha256'))
            ->toBe(str_repeat('0', 64));
    } finally {
        $estate->rollBack();
    }
});

it('keeps the audit log append-only now that it carries two new indexes', function (): void {
    $central = DB::connection('mysql');
    $central->beginTransaction();

    try {
        $id = $central->table('audit_log')->insertGetId([
            'action' => 'test.hot_path_indexes',
            'entity_type' => 'HotPathIndexesTest',
            'entity_id' => bin2hex(random_bytes(6)),
            'created_at' => now(),
        ]);

        expect(fn () => $central->table('audit_log')->where('id', $id)->update(['action' => 'rewritten']))
            ->toThrow(QueryException::class, 'audit_log is append-only');

        expect(fn () => $central->table('audit_log')->where('id', $id)->delete())
            ->toThrow(QueryException::class, 'audit_log is append-only');

        expect($central->table('audit_log')->where('id', $id)->value('action'))->toBe('test.hot_path_indexes');
    } finally {
        // Nothing this test wrote outlives it: the row goes with the transaction.
        $central->rollBack();
    }
});

/*
 * AN INDEX MUST NOT REORDER A SCREEN.
 *
 * The election results board and the turnout report list phases in the order
 * `Governance::turnoutByPhase()` receives them from `units group by block`, which
 * had no ORDER BY of its own. Read through a temporary table (the only way before
 * `units_block_status_index`), groups came back in the order each phase's FIRST
 * LOT WAS CREATED. Read through that index they come back in `block` collation
 * order — the same list for every estate on production today, and a different
 * one for any estate whose phases were not created in alphabetical order ("Phase
 * 10" sorts before "Phase 2"; measured on the production-size copies). So the
 * order the screen always showed is now asked for in the query, and this proves
 * it holds with the index.
 *
 * Inside a transaction that is rolled back: the fixture estate is shared by every
 * file that boots it, and two extra phases would be somebody else's surprise.
 */
it('lists election turnout by phase in the order each phase was created, whatever the index reads first', function (): void {
    $estate = DB::connection('tenant');
    $estate->beginTransaction();

    try {
        // Created Zeta first, then Alpha: creation order and alphabetical order disagree.
        foreach (['M11 Zeta Phase', 'M11 Alpha Phase'] as $phase) {
            foreach ([1, 2] as $n) {
                Unit::query()->create(['reference' => $phase.' · Lot '.$n, 'block' => $phase, 'street' => $phase.' Drive', 'type' => 'residential', 'status' => 'vacant']);
            }
        }

        $ballot = new Ballot;
        $ballot->id = 0; // no receipts: only the eligible side is under test

        $order = array_values(array_filter(
            array_column(app(Governance::class)->turnoutByPhase($ballot), 'phase'),
            static fn (string $phase): bool => str_starts_with($phase, 'M11 '),
        ));

        expect($order)->toBe(['M11 Zeta Phase', 'M11 Alpha Phase']);
    } finally {
        $estate->rollBack();
    }
});

/*
 * WHAT SHARES A MOMENT IS LISTED NEWEST RECORDED FIRST, AT EVERY DEPTH.
 *
 * A dues receipt run gives hundreds of payments one `received_at` (1,836 at Phoenix
 * Park on 3 September). Before the index, which of them the dashboard called newest
 * was whatever the sort produced, and it changed with the depth read — measured, the
 * first ten of LIMIT 10, 30 and 60 were three different lists — so the dashboard and
 * the activity log disagreed. The feed now names `id` after the time, which is the
 * order the index reads anyway, and it is one list at every depth. Twelve payments
 * and twelve residents at one moment later than anything else in the feed are its
 * first rows, so both depths can be read off it.
 */
it('lists payments recorded at one moment newest first, the same at the dashboard\'s depth and the log\'s', function (): void {
    hotPathInEstate(function ($estate): void {
        $unit = Unit::query()->orderBy('id')->firstOrFail();
        $moment = now()->addYears(5)->startOfSecond();

        foreach (range(1, 12) as $n) {
            $estate->table('payments')->insert([
                'unit_id' => $unit->id, 'receipt_no' => sprintf('M11-TIE-%02d', $n), 'amount_minor' => 100_00, 'currency' => 'JMD',
                'method' => 'cash', 'received_at' => $moment, 'status' => 'recorded', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $receipts = static fn (array $rows): array => array_values(array_filter(array_map(
            static fn (array $row): ?string => preg_match('/receipt (M11-TIE-\d+)/', (string) $row['detail'], $m) === 1 ? $m[1] : null,
            $rows,
        )));

        $log = app(ActivityLog::class);
        $newestFirst = array_map(static fn (int $n): string => sprintf('M11-TIE-%02d', $n), range(12, 1));

        expect($receipts($log->feed(ActivityLog::PAGE)['rows']))->toBe($newestFirst)
            ->and($receipts($log->feed(ActivityLog::DASHBOARD_ROWS)['rows']))->toBe(array_slice($newestFirst, 0, ActivityLog::DASHBOARD_ROWS));
    });
});

it('lists residents verified at one moment newest first, the same at the dashboard\'s depth and the log\'s', function (): void {
    hotPathInEstate(function ($estate): void {
        // A household of its own: the fixture estate is lots and facilities, and has none to borrow.
        $unit = Unit::query()->orderBy('id')->firstOrFail();
        $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'M11 Tie household', 'access_restricted' => false]);
        $moment = now()->addYears(5)->startOfSecond();

        foreach (range(1, 12) as $n) {
            $estate->table('residents')->insert([
                'household_id' => $household->id, 'full_name' => sprintf('M11 Tie %02d', $n), 'relationship' => 'owner',
                'is_primary' => 0, 'status' => 'verified', 'verified_at' => $moment, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $names = static fn (array $rows): array => array_values(array_filter(
            array_column($rows, 'detail'),
            static fn (string $detail): bool => str_starts_with($detail, 'M11 Tie '),
        ));

        $log = app(ActivityLog::class);
        $newestFirst = array_map(static fn (int $n): string => sprintf('M11 Tie %02d', $n), range(12, 1));

        expect($names($log->feed(ActivityLog::PAGE)['rows']))->toBe($newestFirst)
            ->and($names($log->feed(ActivityLog::DASHBOARD_ROWS)['rows']))->toBe(array_slice($newestFirst, 0, ActivityLog::DASHBOARD_ROWS));
    });
});

it('ends every sorted, limited read the activity feed and the bell make on a newly indexed table with a unique key', function (): void {
    hotPathInEstate(function (): void {
        $log = app(ActivityLog::class);
        $viewer = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

        $statements = hotPathStatementsOf(static function () use ($log, $viewer): void {
            $log->feed(ActivityLog::PAGE); // deeper than the dashboard, so the reminders are read too
            $log->attention($viewer);      // the overdue tickets and the deposits still held
        });

        $indexed = ['payments', 'residents', 'maintenance_tickets', 'amenity_bookings', 'dunning_notices'];
        $reads = [];

        foreach ($statements as $sql) {
            if (preg_match('/^select \* from `('.implode('|', $indexed).')`.* order by (.+) limit \d+$/i', $sql, $m) === 1) {
                $reads[$m[1]][] = $m[2];
            }
        }

        // Every one of them was read, and every one ends its ORDER BY on `id`.
        expect(array_keys($reads))->toEqualCanonicalizing($indexed);

        foreach ($reads as $table => $orders) {
            foreach ($orders as $order) {
                expect($order)->toMatch('/`id` (asc|desc)$/', "{$table} is sorted by {$order}, which can tie");
            }
        }
    });
});

/*
 * THE GUARD APP'S REVOKED PASSES, IN THE ORDER THEY WERE ISSUED.
 *
 * `sync.pull` had no ORDER BY for them: read whole, the table gave them by id;
 * `visitor_passes_valid_to_index` gives them by expiry. The handset looks each one
 * up by `pass_id`, so no behaviour hung on it — but the payload is the payload, so
 * the query says `id`. Two passes whose expiry runs the other way round from their
 * issue prove it in the response, and the SQL proves it for a production estate.
 */
it('hands the Guard App its revoked passes in the order they were issued, whatever the index reads first', function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'M11 Pull Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Officer M11 Pull', 'employee_number' => 'GS-M11-PULL', 'psra_number' => 'PSRA-M11-PULL',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($guard, 'M11 pull handset')['token'];

    // Committed, because the request opens the estate on a connection of its own; removed below.
    $unit = Unit::query()->orderBy('id')->firstOrFail();
    $issued = [];

    try {
        // Issued first, expiring LAST: id order and expiry order disagree.
        foreach ([['M11A', 5], ['M11B', 2]] as [$code, $hours]) {
            $passId = (string) Str::uuid();
            DB::connection('tenant')->table('visitor_passes')->insert([
                'pass_id' => $passId, 'unit_id' => $unit->id, 'issued_by_name' => 'HotPathIndexesTest', 'category' => 'single',
                'visitor_name' => 'M11 Visitor '.$code, 'valid_from' => now()->subHour(), 'valid_to' => now()->addHours($hours),
                'single_use' => 1, 'nonce' => bin2hex(random_bytes(16)), 'key_version' => 1, 'token' => 'm11-probe', 'code' => $code.bin2hex(random_bytes(3)),
                'status' => 'cancelled', 'cancelled_at' => now()->subMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $issued[] = $passId;
        }

        $response = null;
        $statements = hotPathStatementsOf(function () use (&$response, $token): void {
            app('auth')->forgetGuards();
            $response = test()->withToken($token)
                ->getJson('/api/v1/'.Catalogue::endpoints()['sync.pull']['uri'].'?'.http_build_query(['since' => now()->subHour()->toIso8601String()]))
                ->assertOk();
        });

        FacilitiesFixture::boot();

        $revoked = array_values(array_filter(
            array_column($response->json('passes.revoked'), 'pass_id'),
            static fn (string $id): bool => in_array($id, $issued, true),
        ));

        expect($revoked)->toBe($issued);

        $read = array_values(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'select * from `visitor_passes` where `valid_to` >=')));

        expect($read)->toHaveCount(1)
            ->and($read[0])->toEndWith('order by `id` asc');
    } finally {
        FacilitiesFixture::boot();
        DB::connection('tenant')->table('visitor_passes')->whereIn('pass_id', $issued)->delete();
        DB::connection('mysql')->rollBack();
    }
});
