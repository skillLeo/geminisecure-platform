<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every estate's hot-path indexes (M11 A2).
 *
 * EVERY INDEX HERE IS DERIVED FROM A STATEMENT THE PLATFORM ACTUALLY RUNS, AT PHOENIX PARK'S OWN SIZE.
 * Every estate page, every endpoint both apps call, the panic and the scheduler's commands were
 * replayed against an estate built by `phoenix:demo-seed` — 2,000 lots, 441 households, 12,015
 * charges, 11,726 payments, 23,792 journal lines, each within 0.1% of what production held when it
 * was counted, read-only, on 24 September 2026 (2,000 / 441 / 12,017 / 11,734 / 23,814), and with
 * production's own index set, all 198 indexes — with every statement recorded (SQL, bindings, time
 * and the file:line that issued it). The call site is written beside each index. The plans before and
 * after, the times, and how they were taken are in docs/perf/EXPLAIN.md: two copies of identical data,
 * one before and one after, each statement timed on both in random order, round after round.
 *
 * EVERY STATEMENT RETURNS THE SAME ROWS IN THE SAME ORDER, EXCEPT WHERE THE ORDER WAS NEVER DEFINED.
 * A dues receipt run gives 1,836 payments one `received_at`; which of them the activity feed called
 * newest was whatever the sort produced, and it changed with the depth read — measured, the first ten
 * of LIMIT 10, 30 and 60 were three different lists. An index reads ties in its own order instead.
 * Where that could reach a screen or a payload, the query now names its tie-break (ActivityLog,
 * SyncController, PostCoverage) or its order (Governance::turnoutByPhase, whose phases would otherwise
 * arrive in collation order through `units_block_status_index`), so it gives one answer at every depth
 * and on every plan. Everything else was measured identical, row for row and in order.
 *
 * ONE EXISTING INDEX IS REPLACED, NOT KEPT BESIDE ITS SUCCESSOR. `journal_lines(account_id, id)`
 * becomes `(account_id, id, unit_id, debit_minor, credit_minor, entry_ref)`: the same leading columns
 * in the same order, so every plan that used the old one can use the new one the same way — and it now
 * also holds every column the receivable balance and the trial balance read, so neither visits the
 * table. The old one is then a strict left prefix of the new: kept beside it, every journal statement
 * measured chose the same plan it chooses without it, so keeping it would cost every posting a second
 * write and buy nothing. It is added FIRST and the old one dropped SECOND, because `account_id` is a
 * foreign key and MySQL refuses to leave it without an index for a moment (tried: error 1553). The
 * alternative that led with unit, `(account_id, unit_id, …)`, was measured and rejected: it made the
 * balance 1.7x faster, the as-at balance that elections eligibility reads 3.9x SLOWER, and changed the
 * order three grouped reads return their groups in.
 *
 * NOTHING ELSE CHANGES: no column, no row, no other existing index. On MySQL 8.4 every ADD INDEX is
 * built in place with reads and writes allowed throughout. `journal_lines` is append-only: its
 * no-update, no-delete and no-late-addition triggers, and the header's must-balance trigger, read only
 * `entry_ref` and `journals.reference`, which keep their own indexes; `documents` keeps its retention
 * triggers. HotPathIndexesTest proves all of them still refuse.
 */
return new class extends Migration
{
    /** The index `journal_lines_account_ledger_index` supersedes. */
    private const REPLACED = 'journal_lines_account_id_id_index';

    /**
     * [table, index name, columns].
     *
     * @var list<array{0: string, 1: string, 2: list<string>}>
     */
    private const INDEXES = [
        // Every unit's receivable balance, `account 1200 group by unit_id`, read on every dashboard,
        // arrears, residents and unit page and by elections eligibility (Dues.php:624), and the trial
        // balance by account on the chart of accounts and the bills page (Ledger.php:549).
        ['journal_lines', 'journal_lines_account_ledger_index', ['account_id', 'id', 'unit_id', 'debit_minor', 'credit_minor', 'entry_ref']],

        // What each unit has paid to date, and when it last paid: `status = 'recorded' group by
        // unit_id` — the FIFO ageing behind every arrears figure (Dues.php:1081) and the arrears
        // register's "last paid" column (Dues.php:1037). Covering, so no row is read.
        ['payments', 'payments_status_unit_received_index', ['status', 'unit_id', 'received_at', 'amount_minor']],

        // The month's takings on the receipts register: `status = 'recorded' and received_at between
        // ? and ?` (Receipts.php:234). The index above holds the same columns in the wrong order for a
        // date range, so the month was a read of every payment; this reads the month.
        ['payments', 'payments_status_received_at_index', ['status', 'received_at', 'amount_minor']],

        // The newest payments: the receipts register's 200 (Receipts.php:175) and the dashboard's and
        // activity log's feed (ActivityLog.php:91). No index led with received_at, so each sorted every
        // payment to print ten.
        ['payments', 'payments_received_at_index', ['received_at']],

        // The FIFO walk, every charge due by today in `due_on, id` order (Dues.php:1073). `id` is
        // written into the index so the index IS that order and the sort disappears; the other three
        // columns are what the walk reads.
        ['charges', 'charges_due_on_fifo_index', ['due_on', 'id', 'status', 'unit_id', 'amount_minor']],

        // The phase: the Guard App's phase list with its lot counts (ProfileController.php:73), and every
        // "distinct block" and "group by block" the dashboard, structure, arrears, dues and governance
        // screens read (Dues.php:804, :955, DuesController.php:301, Residents.php:1378,
        // Governance.php:647, ChargeSchedules.php:161).
        ['units', 'units_block_status_index', ['block', 'status']],

        // "New resident verified" on the dashboard and activity log, newest first (ActivityLog.php:139).
        ['residents', 'residents_verified_at_index', ['verified_at']],

        // The documents kept against one record — a unit's statements, a meeting's papers, a pay run's
        // files (Documents.php:224). The existing index leads with `kind`, which this read does not
        // filter on.
        ['documents', 'documents_subject_index', ['subject_type', 'subject_id']],

        // Tickets closed, newest first, and tickets still open, oldest first — the dashboard, the
        // activity log and the bill form's ticket list (ActivityLog.php:159, :281; PayablesController.php:186).
        ['maintenance_tickets', 'maintenance_tickets_closed_reported_index', ['closed_at', 'reported_at']],

        // Bookings newest first in the activity feed (ActivityLog.php:176).
        ['amenity_bookings', 'amenity_bookings_created_at_index', ['created_at']],

        // Held deposits whose booking has ended — the dashboard's attention list (ActivityLog.php:341)
        // and the daily `amenities:settle` (Amenities.php:910).
        ['amenity_bookings', 'amenity_bookings_deposit_ends_index', ['deposit_state', 'ends_at']],

        // Reminders sent, newest first, in the activity log (ActivityLog.php:202). `dunning:run` adds to
        // this every morning; production already holds 164.
        ['dunning_notices', 'dunning_notices_sent_at_index', ['sent_at']],

        // The next meeting on the dashboard (DashboardController.php:106) and a year's meetings for the
        // minutes report and the governance screen (Reports.php:378, Governance.php:1453), and the
        // Resident App's meetings newest first (CommunityController.php:332).
        ['meetings', 'meetings_starts_at_index', ['starts_at']],

        // What the Guard App's sync pull asks for on every pull: passes still valid that were cancelled
        // or used since the handset last asked (SyncController.php:223).
        ['visitor_passes', 'visitor_passes_valid_to_index', ['valid_to']],

        // A household's passes in the Resident App, newest first: `unit_id = ? ... order by valid_from
        // desc limit 100` (PassesController.php:59). The unit's index is (unit_id, status), which cannot
        // give that order, so every pass of the unit was read and sorted.
        ['visitor_passes', 'visitor_passes_unit_id_valid_from_index', ['unit_id', 'valid_from']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
                $blueprint->index($columns, $name);
            });
        }

        // Only once its successor exists, which the loop above has just made sure of.
        if (Schema::hasIndex('journal_lines', 'journal_lines_account_ledger_index')
            && Schema::hasIndex('journal_lines', self::REPLACED)) {
            Schema::table('journal_lines', function (Blueprint $blueprint): void {
                $blueprint->dropIndex(self::REPLACED);
            });
        }
    }

    public function down(): void
    {
        // The replaced index comes back FIRST, so account_id is never without an index.
        if (Schema::hasTable('journal_lines') && ! Schema::hasIndex('journal_lines', self::REPLACED)) {
            Schema::table('journal_lines', function (Blueprint $blueprint): void {
                $blueprint->index(['account_id', 'id'], self::REPLACED);
            });
        }

        foreach (array_reverse(self::INDEXES) as [$table, $name]) {
            if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                $blueprint->dropIndex($name);
            });
        }
    }
};
