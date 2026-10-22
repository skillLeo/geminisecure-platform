<?php

declare(strict_types=1);

namespace App\Support\Seeded;

/**
 * WHICH TABLES CARRY THE SEEDED MARK, WHICH ARE RETIRED ON LAUNCH, AND WHICH NEVER ARE (M33 §5, KI-25).
 *
 *   MARKED     every tenant-scoped table: `seeded_at` and `seeded_by`, set by a seeder at the moment a row is created
 *              (`Seeding`, `SampleAwareBuilder`) and null on every row a person creates.
 *   RETIRED    what `launch:retire-sample-data` hides on launch day: every marked table but the KEPT ones.
 *   KEPT       what the estate needs on launch day whoever wrote it first — its lots and phases (the 2,000 lots of
 *              Phoenix Park are the client's own, M26), its chart of accounts and receipt counter, its fee schedule,
 *              arrears ladder and amenities, its settings and features, its gates, its signing key, its emergency
 *              numbers and its contract. `SampleData::PROTECTED_TABLES` keeps the same for the same reasons (KI-24).
 *
 * EXPLICIT, AND HELD TO THE SCHEMA. Read on every query, so it is a list rather than a schema lookup;
 * `SeededMarksTest` fails the moment a table exists that is on none of these lists.
 */
final class SeededTables
{
    /** An estate's own tables that are machinery — a migration log, a cache, a delivery queue — never records. */
    public const ESTATE_MACHINERY = [
        'api_idempotency_responses',
        'dashboard_snapshots',
        'live_deliveries',
        'live_events',
        'live_streams',
        'migrations',
        'notification_reads',
        'site_time_corrections',
    ];

    /** An estate's own tables that are kept on launch day: its structure and its configuration. */
    public const ESTATE_KEPT = [
        'accounts',
        'amenities',
        'amenity_slots',
        'charge_schedule_rate_changes',
        'charge_schedules',
        'dunning_template_drafts',
        'dunning_templates',
        'estate_features',
        'estate_phases',
        'estate_settings',
        'notification_defaults',
        'receipt_sequences',
        'units',
    ];

    /** An estate's own tables whose seeded rows are retired on launch day. */
    public const ESTATE_RETIRED = [
        'amenity_bookings',
        // A household's AutoPay and its saved TEST cards (M50 §4.2 #3): demonstration money, retired with the rest.
        'autopay_arrangements',
        'ballot_options',
        'ballot_positions',
        'ballots',
        'bank_reconciliations',
        'bank_statement_lines',
        'bill_payments',
        'bills',
        // M84's budgets and manual journal drafts: a person's records, marked and retired like the rest.
        'budget_lines',
        'charge_runs',
        'charges',
        'delinquency_flags',
        'documents',
        'dunning_notices',
        'employees',
        'gate_approval_requests',
        'household_cards',
        'household_emergency_contacts',
        'household_pets',
        'household_vehicles',
        'households',
        'journal_drafts',
        'journal_lines',
        'journals',
        'maintenance_ticket_activity',
        'maintenance_tickets',
        'meeting_agenda_items',
        'meeting_attendance',
        'meeting_minutes',
        'meeting_rsvps',
        'meetings',
        'nominations',
        'notice_reads',
        'notices',
        'payment_gateway_events',
        'payment_intents',
        'payment_plan_instalments',
        'payment_plans',
        'payments',
        'payroll_run_exceptions',
        'payroll_run_lines',
        'payroll_runs',
        'resident_invites',
        'residents',
        'statutory_filings',
        'ticket_media',
        'unit_claims',
        'unit_collection_flags',
        'vendors',
        'visitor_passes',
    ];

    /**
     * An estate's tables that carry NO mark of their own and are sample when what they hang from is: a ballot's marks
     * and its receipts. A mark holds its option and a random id and nothing else, and a receipt carries no clock, on
     * purpose: a column the two could be lined up by would trace a mark to a voter (invariant 3;
     * `BallotUnlinkabilityTest` holds both tables to it). Each is the demonstration's when its option, or its ballot, is
     * — and a receipt also when it sits in a range the seed recorded — and is retired with it.
     *
     * @var array<string, array{0: string, 1: string}> table => [foreign key, parent table]
     */
    public const ESTATE_THROUGH_PARENT = [
        'ballot_marks' => ['ballot_option_id', 'ballot_options'],
        'ballot_receipts' => ['ballot_id', 'ballots'],
    ];

    /**
     * The platform's tables that belong to an estate, and how a row says which estate:
     * a column, or the parent it hangs from. `payroll_runs` is the one with no estate of its own and no single parent:
     * a run is an estate's when every payslip on it is.
     *
     * @var array<string, array{0: 'column', 1: string}|array{0: 'parent', 1: string, 2: string}|array{0: 'payroll'}>
     */
    public const CENTRAL_MARKED = [
        'alertness_checks' => ['parent', 'shift_id', 'shifts'],
        'audit_log' => ['column', 'tenant_id'],
        'checkpoint_scans' => ['parent', 'patrol_checkpoint_id', 'patrol_checkpoints'],
        'client_adoption' => ['column', 'tenant_id'],
        'client_messages' => ['column', 'tenant_id'],
        'credit_notes' => ['column', 'tenant_id'],
        'device_bindings' => ['column', 'tenant_id'],
        'dispatch_messages' => ['column', 'tenant_id'],
        // What a resident and the control room say on a panic, and its live audio (M50 §4.2 #1–#2).
        'duress_alert_audio' => ['column', 'tenant_id'],
        'duress_alert_messages' => ['column', 'tenant_id'],
        'duress_alerts' => ['column', 'tenant_id'],
        'estate_assignments' => ['column', 'tenant_id'],
        'estate_sites' => ['column', 'tenant_id'],
        'gate_events' => ['column', 'tenant_id'],
        'guard_requests' => ['column', 'tenant_id'],
        'guards' => ['column', 'tenant_id'],
        'invitations' => ['column', 'tenant_id'],
        'invoice_lines' => ['parent', 'invoice_id', 'invoices'],
        'invoices' => ['column', 'tenant_id'],
        'pass_signing_keys' => ['column', 'site_id'],
        'patrol_checkpoints' => ['column', 'tenant_id'],
        'payment_methods' => ['column', 'tenant_id'],
        'payroll_runs' => ['payroll'],
        'payslips' => ['column', 'tenant_id'],
        'posts' => ['column', 'tenant_id'],
        'resident_accounts' => ['column', 'tenant_id'],
        'security_incidents' => ['column', 'tenant_id'],
        'shifts' => ['column', 'tenant_id'],
        'site_emergency_numbers' => ['column', 'tenant_id'],
        'standing_order_acknowledgements' => ['parent', 'standing_order_set_id', 'standing_order_sets'],
        'standing_order_sets' => ['column', 'tenant_id'],
        'standing_order_versions' => ['parent', 'standing_order_set_id', 'standing_order_sets'],
        'subscription_line_items' => ['column', 'tenant_id'],
        'subscriptions' => ['column', 'tenant_id'],
        // A resident's Live chat with Gemini Security (M50 §4.3); its messages hang from it.
        'support_chats' => ['column', 'tenant_id'],
    ];

    /**
     * The platform's estate tables that are kept on launch day: the estate's gates, key, numbers, sites and contract;
     * the adoption figures, which are worked out again from what is shown (and kept by a key a hidden row would block);
     * and who may sign in — a seat at the estate, a resident's sign-in address, a phone bound to an officer. Access is
     * not this command's: switching the demonstration's accounts off is DEMO_MODE's (LAUNCH_CHECKLIST), and an officer
     * who is retired has no record to sign in to.
     */
    public const CENTRAL_KEPT = [
        'client_adoption',
        'device_bindings',
        'estate_assignments',
        'estate_sites',
        'pass_signing_keys',
        'posts',
        'resident_accounts',
        'site_emergency_numbers',
        'subscription_line_items',
        'subscriptions',
    ];

    /**
     * Platform tables that carry an estate and are machinery or a person's credential, never a seeder's record: a
     * delivery ledger, a cache, a sign-in code, a role definition. `SeededMarksTest` holds every `tenant_id` table to
     * being here or in `CENTRAL_MARKED`.
     */
    public const CENTRAL_MACHINERY = [
        'alert_email_dispatches',
        'alert_sms_dispatches',
        'api_idempotency_keys',
        'device_push_tokens',
        'domains',
        'duress_alert_deliveries',
        'duress_alert_ring_events',
        'duress_alert_ring_rounds',
        'duress_alert_ring_targets',
        'duress_alert_rings',
        'front_door_tokens',
        'guard_positions',
        'live_events',
        'mail_failures',
        'mail_log',
        'platform_journal_lines',
        'push_coalescing',
        // "Face ID / fingerprint" on a resident's own phone (M49): a credential, like a sign-in code.
        'resident_device_keys',
        'resident_otps',
        'roles',
        'sample_data_ranges',
        'sample_data_retirements',
        'scheduled_local_runs',
        'seeded_recipient_drops',
        'tracking_gap_notices',
    ];

    /** The two mark columns. */
    public const MARKS = ['seeded_at', 'seeded_by'];

    public static function marked(string $connection, string $table): bool
    {
        return match ($connection) {
            'tenant' => in_array($table, self::ESTATE_RETIRED, true) || in_array($table, self::ESTATE_KEPT, true),
            'mysql' => array_key_exists($table, self::CENTRAL_MARKED),
            default => false,
        };
    }

    public static function retired(string $connection, string $table): bool
    {
        return match ($connection) {
            'tenant' => in_array($table, self::ESTATE_RETIRED, true) || array_key_exists($table, self::ESTATE_THROUGH_PARENT),
            'mysql' => array_key_exists($table, self::CENTRAL_MARKED) && ! in_array($table, self::CENTRAL_KEPT, true),
            default => false,
        };
    }

    /** @return list<string> every estate table whose sample is retired: the marked ones, and those retired with their parent */
    public static function estateRetired(): array
    {
        $all = [...self::ESTATE_RETIRED, ...array_keys(self::ESTATE_THROUGH_PARENT)];
        sort($all);

        return $all;
    }

    /** @return list<string> every estate table that carries the mark */
    public static function estateMarked(): array
    {
        $all = [...self::ESTATE_RETIRED, ...self::ESTATE_KEPT];
        sort($all);

        return $all;
    }

    /** @return list<string> every platform table whose seeded rows are retired */
    public static function centralRetired(): array
    {
        return array_values(array_filter(array_keys(self::CENTRAL_MARKED), static fn (string $t): bool => ! in_array($t, self::CENTRAL_KEPT, true)));
    }
}
