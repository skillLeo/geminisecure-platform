<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EVERY ROW A SEEDER WRITES SAYS SO (M33 §5.2, KI-25) — the estate's own tables.
 *
 *   seeded_at   when a seeding command wrote the row; null on every row a person, a phone or the office created
 *   seeded_by   which command (`phoenix:demo-seed`, `db:seed`, …)
 *
 * Set by the query builder while a seeding command runs (`App\Support\Seeded\SampleAwareBuilder`), and never by the
 * application, which cannot write either column. What `launch:retire-sample-data` hides is read from them.
 *
 * Additive: two nullable columns on each table, no default, no index (the scope asks "is it null", never "since when").
 * Rows written before this migration carry no mark; the ranges the demonstration seed recorded say which they are.
 * MySQL adds a trailing nullable column in place, so the append-only tables' triggers and grants are untouched.
 * Every table a seeder writes is here but two, a ballot's marks and its receipts, which carry nothing a column could
 * link to a voter.
 * `SeededMarksTest` holds this list to `SeededTables`: a table added later is marked by a migration of its own.
 */
return new class extends Migration
{
    // Not `ballot_marks` or `ballot_receipts`: a mark holds its option and a random id and nothing else, and a receipt
    // carries no clock, so no column can line one up with the other (invariant 3, BallotUnlinkabilityTest). Each is
    // sample when its option, or its ballot, is (SeededTables::ESTATE_THROUGH_PARENT).
    private const TABLES = [
        'accounts', 'amenities', 'amenity_bookings', 'amenity_slots', 'ballot_options',
        'ballot_positions', 'ballots', 'bank_reconciliations', 'bank_statement_lines',
        'bill_payments', 'bills', 'charge_runs', 'charge_schedule_rate_changes', 'charge_schedules', 'charges',
        'delinquency_flags', 'documents', 'dunning_notices', 'dunning_template_drafts', 'dunning_templates',
        'employees', 'estate_features', 'estate_phases', 'estate_settings', 'gate_approval_requests',
        'household_emergency_contacts', 'household_vehicles', 'households', 'journal_lines', 'journals',
        'maintenance_ticket_activity', 'maintenance_tickets', 'meeting_agenda_items', 'meeting_attendance',
        'meeting_minutes', 'meeting_rsvps', 'meetings', 'nominations', 'notice_reads', 'notices',
        'notification_defaults', 'payment_gateway_events', 'payment_intents', 'payment_plan_instalments',
        'payment_plans', 'payments', 'payroll_run_exceptions', 'payroll_run_lines', 'payroll_runs',
        'receipt_sequences', 'resident_invites', 'residents', 'statutory_filings', 'ticket_media', 'unit_claims',
        'unit_collection_flags', 'units', 'vendors', 'visitor_passes',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
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
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
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
