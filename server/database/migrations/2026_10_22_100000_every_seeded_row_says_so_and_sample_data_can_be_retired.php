<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY ROW A SEEDER WRITES SAYS SO, AND AN ESTATE'S SAMPLE DATA CAN BE RETIRED WITHOUT BEING DELETED (M33 §5, KI-25).
 *
 * THE MARK on the platform's tables that belong to an estate — `seeded_at` and `seeded_by`, as on the estate's own
 * (`tenant/2026_10_22_100000_every_seeded_row_says_so.php`). Additive, nullable, no default.
 *
 * `sample_data_retirements`, one row per time `launch:retire-sample-data --confirm` was run for an estate:
 *   tenant_id, estate_database   whose sample data, and the database it is in
 *   retired_at, retired_by       when, and who ran the command
 *   undone_at, undone_by         `--undo`: the rows are shown again, and this row stays as the record that they were not
 *   export_path, export_sha256,  the invoices written out before anything was hidden (M33 §5.6), and how many rows
 *   export_rows
 *   spans                        the id ranges, per connection and table, of rows written before the mark existed
 *   kept                         the ids of sample rows a real record points at, which therefore stay shown
 *   counts                       what was hidden, kept and left, table by table, as the command printed it
 *
 * It is the only thing the retirement writes. No ledger line, payment, invoice or audit row is touched; nothing is deleted.
 */
return new class extends Migration
{
    private const MARKED = [
        'alertness_checks', 'audit_log', 'checkpoint_scans', 'client_adoption', 'client_messages', 'credit_notes',
        'device_bindings', 'dispatch_messages', 'duress_alerts', 'estate_assignments', 'estate_sites', 'gate_events',
        'guard_requests', 'guards', 'invitations', 'invoice_lines', 'invoices', 'pass_signing_keys',
        'patrol_checkpoints', 'payment_methods', 'payroll_runs', 'payslips', 'posts', 'resident_accounts',
        'security_incidents', 'shifts', 'site_emergency_numbers', 'standing_order_acknowledgements',
        'standing_order_sets', 'standing_order_versions', 'subscription_line_items', 'subscriptions',
    ];

    public function up(): void
    {
        foreach (self::MARKED as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (! Schema::hasColumn($name, 'seeded_at')) {
                    $table->timestamp('seeded_at')->nullable();
                }
                if (! Schema::hasColumn($name, 'seeded_by')) {
                    $table->string('seeded_by', 64)->nullable();
                }
            });
        }

        if (! Schema::hasTable('sample_data_retirements')) {
            Schema::create('sample_data_retirements', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_id', 64);
                $table->string('estate_database', 128);
                $table->timestamp('retired_at');
                $table->string('retired_by', 191);
                $table->timestamp('undone_at')->nullable();
                $table->string('undone_by', 191)->nullable();
                $table->string('export_path', 500)->nullable();
                $table->char('export_sha256', 64)->nullable();
                $table->unsignedInteger('export_rows')->default(0);
                $table->json('spans');
                $table->json('kept');
                $table->json('counts');
                $table->timestamps();

                $table->index(['tenant_id', 'undone_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_data_retirements');

        foreach (self::MARKED as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['seeded_at', 'seeded_by'] as $column) {
                    if (Schema::hasColumn($name, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
