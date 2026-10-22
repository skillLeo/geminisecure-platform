<?php

declare(strict_types=1);

namespace App\Support;

/**
 * EVERY TABLE THE GRANT COMMANDS KNOW ABOUT, AND WHICH SIDE OF THE LINE IT IS ON.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * UPDATE and DELETE are withheld from the application user at DATABASE level and
 * granted back one table at a time (DECISIONS.md D-017). `grants:append-only`
 * (central) and `grants:estates` (every estate) are what hand them back, and both
 * read the live `information_schema` so that a table added by a migration is
 * granted the moment the command is next run.
 *
 * That last clause is the whole problem. The commands are correct; REMEMBERING TO
 * RUN THEM is what fails. And nothing catches the omission, because PHPUnit
 * connects as the schema OWNER (`phpunit.xml`, `DB_USERNAME=gs_owner`), which holds
 * every privilege on every table. A table that the application user cannot update
 * is green in every test and broken on the server. It has already happened three
 * times on this project: the estate payroll tables, the estate notices, and then
 * the central supervisor-override tables, where the consequence was that a guard
 * typing a WRONG supervisor PIN would have got a database privilege error instead
 * of a refusal.
 *
 * So this file is the declared list the commands and the test agree on. Adding a
 * table to a migration without adding it here fails
 * `tests/Feature/GrantsCoverageTest.php`, and the failure message says to run the
 * two commands. That is the only part of the chain a person cannot forget.
 *
 * HOW TO ADD A TABLE
 * ------------------
 *   1. Add its name to CENTRAL_MUTABLE (a table in `gs_platform`) or to
 *      ESTATE_MUTABLE (a table in every `gs_estate_*`), in alphabetical order.
 *   2. Only put it in an APPEND_ONLY list if the table must never be edited or
 *      deleted from at all - a ledger, a ballot, an audit trail. An append-only
 *      table also needs BEFORE UPDATE / BEFORE DELETE triggers in its migration;
 *      the grant is the first of two locks, never the only one.
 *   3. Run, through the lock wrapper:
 *          php artisan grants:append-only        # central
 *          php artisan grants:estates            # every estate
 *
 * See docs/DEPLOY.md section 7 for the deploy-time sequence.
 */
final class GrantedTables
{
    /**
     * Central tables (`gs_platform`) that may only ever be inserted into.
     *
     * The application user holds no UPDATE and no DELETE on these, and a trigger
     * refuses the write even for a privileged connection.
     */
    public const CENTRAL_APPEND_ONLY = [
        /*
         * Who was EMAILED about an emergency, and whether the mail server took it (M12 §2.5) — the household's own
         * contacts. `alert_sms_dispatches`' rules exactly: written after the attempt, the address masked, locked
         * twice (this grant, and the triggers in `2026_10_05_130000_a_panic_emails_the_households_own_contacts.php`).
         */
        'alert_email_dispatches',

        /*
         * Who was TEXTED about an emergency, and whether it went (Q-M09). One row per
         * number per alert, written AFTER the attempt and never before, so a row here is
         * always a fact rather than an intention. It is the record of who was told that
         * somebody pressed a panic button, and a record that can be edited afterwards is
         * not a record. Locked twice, like everything else in this list: the withheld
         * grant here, and BEFORE UPDATE / BEFORE DELETE triggers in
         * `2026_09_21_190000_who_a_panic_texts_and_what_was_actually_texted.php`.
         */
        'alert_sms_dispatches',

        'audit_log',

        /*
         * Who a panic was HANDED to, by which channel, and how many milliseconds after
         * the fan-out began (M10 §1.3). One row per destination per channel, written
         * in one insert once every leg has gone. It is the record behind "who was told,
         * and how fast", and a timing that could be edited afterwards would be worth
         * nothing as evidence. Locked twice: the withheld grant here, and BEFORE UPDATE /
         * BEFORE DELETE triggers in
         * `2026_09_24_170000_who_a_panic_was_handed_to_and_how_fast.php`.
         */
        'duress_alert_deliveries',

        /*
         * Where the phone that raised a panic or a duress was while the alert stayed open (guard live tracking, 12 Oct
         * 2026): part of the alert's record, kept for ever like the alert. Locked twice: the withheld grant here, and
         * BEFORE UPDATE / BEFORE DELETE triggers in
         * `2026_11_01_100000_a_guard_on_duty_is_followed_and_a_panic_is_followed_while_open.php`.
         */
        'duress_alert_positions',

        /*
         * Which phones a panic's RING reached, round by round (the guard ring): sent the
         * ring, sent the visible backup, sent the stop, shown on the phone by its own
         * receipt, answered, refused. It is the only evidence of which phones were rung and
         * which said they rang, and the console's "shown on the phone" is counted from it.
         * Locked twice: the withheld grant here, and BEFORE UPDATE / BEFORE DELETE triggers
         * in `2026_09_26_090000_a_panic_rings_every_guard_until_somebody_answers.php`.
         * Its three siblings — the ring, its rounds, its targets — are claimed and finished
         * in normal use and sit in CENTRAL_MUTABLE.
         */
        'duress_alert_ring_events',

        /*
         * The gate log (Q-M43, D-M127). The ONLY record that an admission
         * happened - who was let in, by which officer, on what basis. It was
         * called append-only for weeks while sitting in CENTRAL_MUTABLE with
         * both privileges granted and no trigger; nothing in the application has
         * ever updated or deleted one, so this was unused privilege on the row a
         * court asks for. Locked twice now, like everything else in this list:
         * the withheld grant here, and BEFORE UPDATE / BEFORE DELETE triggers in
         * `2026_09_21_120000_lock_the_gate_log_append_only.php`.
         *
         * `duress_alerts` and `security_incidents` were deliberately NOT swept up
         * with it - both are acknowledged, cancelled and resolved in normal use,
         * and they stay in CENTRAL_MUTABLE.
         */
        'gate_events',

        /*
         * Every email the platform gave up on, after its last attempt (M12 §5.1): what, to whom (masked), how many
         * tries and why. It is what an admin reads to learn that somebody never got their sign-in code, so it is not
         * something to tidy away. Locked twice: the withheld grant here, and BEFORE UPDATE / BEFORE DELETE triggers
         * in `2026_10_06_110000_an_email_that_could_not_be_sent_is_recorded.php`.
         */
        'mail_failures',

        // Gemini's own books (13 B1). Triggers refuse edits already; the grant
        // is the second lock, as it is for every estate's journals.
        'platform_journals',
        'platform_journal_lines',
    ];

    /**
     * Central tables the application user may update and delete from.
     *
     * The last block is created by a migration that does not name its table as a
     * literal string, or by the framework itself, so the coverage test cannot read
     * the name out of the migration file. See NOT_NAMED_LITERALLY_IN_A_MIGRATION.
     *
     * `duress_alerts` and `security_incidents` sit here on purpose and are not to
     * be promoted alongside `gate_events` (Q-M43). They look like the same kind of
     * record and they are not: an alert is ACKNOWLEDGED, CANCELLED and RESOLVED
     * after it is raised, and an incident is worked and closed. Locking them would
     * leave a control room that can never stop flashing. What must never change is
     * the fact that the alert was raised, and that is already held by `audit_log`.
     *
     * `site_time_corrections` is a correction ledger, and it is listed MUTABLE
     * rather than append-only on purpose: it is written only by the two
     * `store_office_typed_..._in_utc` migrations, which run as the schema owner and
     * are unaffected by what gs_app may do. Locking it would revoke a privilege to
     * protect something the privilege never protected. If the application is ever
     * given a reason to write it, that is the moment to reconsider - and it would
     * need BEFORE UPDATE / BEFORE DELETE triggers as well as the grant.
     */
    public const CENTRAL_MUTABLE = [
        /*
         * My account (M32 F9): the code emailed before a password change, and where an account is signed in. Codes are
         * counted against and consumed; a session's row is touched and deleted. The record of each act is `audit_log`.
         */
        'account_codes',
        'account_sessions',

        'activity_log',
        'alertness_checks',
        'api_idempotency_keys',
        'cache',
        'cache_locks',
        'checkpoint_scans',
        'client_adoption',
        'client_messages',

        /*
         * One-time codes proving a console user's alert address (M10 §1.4).
         * MUTABLE because a code is counted against, retired and consumed in
         * normal use. Only a hash of each code is stored; the fact that an
         * address WAS verified lives on `users` and in `audit_log`.
         */
        'contact_verification_codes',

        'credit_notes',

        /*
         * Which phones were a guard's, and when each stopped being (M32 F3): what a fingerprint sign-in proves against,
         * and what says "your supervisor removed this phone on 2 October". MUTABLE: a binding is ended in place when a
         * phone is replaced or removed. Every act on a phone is also in `audit_log`.
         */
        'device_bindings',
        'device_enrolment_codes',
        'device_push_tokens',
        'device_rebind_requests',
        'dispatch_message_reads',
        'dispatch_messages',
        'domains',

        /*
         * A panic's ring (the guard ring): the header whose `answered_at` is written once,
         * the rounds each CLAIMED by one statement and then finished, and the guards rung,
         * whose "can't respond" is recorded on their row. MUTABLE because a claim, a finish
         * and an answer are updates; what must never change — which phones were sent what,
         * and what each said — is `duress_alert_ring_events`, append-only above.
         */
        'duress_alert_ring_rounds',
        'duress_alert_ring_targets',
        'duress_alert_rings',

        /*
         * A resident's panic, from their side (M50 §4.2 #1, #2): the live audio's pieces, and the thread with the
         * control room. MUTABLE because a message's `seen_at` is stamped when the other side is shown it, and both go
         * with their alert (cascade). Nothing in the application edits what was written or heard; who wrote and who
         * started the audio are in `audit_log` too.
         */
        'duress_alert_audio',
        'duress_alert_messages',

        'duress_alerts',
        'estate_assignments',

        // Where an estate itself is — the fence a clock-in falls back to — and its supervisor's number (M12 §1).
        // MUTABLE: the Director moves it in Sites & geofences, and every move is audited with its reason.
        'estate_sites',
        'failed_jobs',

        /*
         * The front door's single-use handoff tokens (M11 §5.2). MUTABLE because a token is
         * redeemed (`used_at`) and pruned; only a hash is stored, and who was handed to which
         * estate is recorded in `audit_log`.
         */
        'front_door_tokens',

        /*
         * On-duty positions and the officer's answers to the location policy (M11 §3, D-174). MUTABLE because a
         * position older than the retention window is DELETED by gs:guard-positions-purge — a retention nobody can
         * enforce is not one — and both go with a guard's record when it is removed (cascade). The code only ever
         * inserts a consent (a changed mind is a new row); every answer is also in `audit_log`, which is append-only.
         */
        'guard_positions',
        'guard_presence_pings',
        // What a guard's phone said about the readings it holds unsent, one row per shift, written over each batch.
        'guard_queue_reports',
        'guard_requests',
        // A stretch of silence from a guard on duty (tracking:signal): raised, then cleared once when a position arrives.
        'guard_signal_alerts',
        'guard_tracking_consents',
        'guards',
        'incident_media',
        'invitations',
        'invoice_lines',
        'invoice_sends',
        'invoices',
        'job_batches',
        'jobs',

        // The live delivery log (M11 Part C). MUTABLE on purpose: the sequencer sets `seq`, the
        // relay `published_at`, the stream its head, and `live:prune` deletes. What an event says
        // is held by the write-once triggers in `2026_10_01_100000_create_the_live_delivery_log.php`.
        // `live_grants` is upserted per channel grant and pruned by `live:reconcile`.
        'live_deliveries',
        'live_events',
        'live_grants',
        'live_streams',

        // Every email the platform sent and what became of it (M18G §7). Mutable only so `mail:prune-log` can drop
        // rows past sixty days; nothing in the application edits one.
        'mail_log',

        'media',
        'modules',
        'package_features',
        'pass_signing_keys',
        'password_reset_tokens',
        'patrol_checkpoints',
        'payment_methods',
        'payroll_runs',
        'payslips',
        'personal_access_tokens',
        'plan_features',
        'plan_price_changes',
        'plans',
        'platform_accounts',

        /*
         * The next journal reference per prefix and month (B085). MUTABLE because
         * it is bumped on every posting: an upsert that locks the row is how two
         * operators posting in the same month take two numbers. It holds no money
         * and no history — only the last number handed out per stem — and the
         * books it numbers, `platform_journals` and `platform_journal_lines`, stay
         * append-only above, neither locked nor granted anything more.
         */
        'platform_journal_sequences',

        /*
         * Where the platform sends mail from (D-162). MUTABLE and deliberately so: the whole
         * point of the row is that the relay can be repointed from the console when the
         * client's own SMTP account replaces the developer's. The password in it is encrypted
         * at rest with APP_KEY, and who changed which fields is held by `audit_log`, which is
         * the append-only record. Locking this table would only mean the relay could never be
         * corrected without a deploy — which is the problem it was built to solve.
         */
        'platform_mail_settings',

        'platform_notification_reads',

        // Dated changes to the per-guard add-on (M10 §3), the twin of
        // `plan_price_changes` above. MUTABLE for the same reason that table is:
        // `applied_at` is stamped after insert, when the change comes due. The
        // record that must never change is also written to `audit_log`.
        'platform_rate_changes',

        'platform_rates',

        /*
         * Which provider the platform texts through (M10 §1). MUTABLE for exactly
         * the reason `platform_mail_settings` above is: the whole point of the row
         * is that the client can connect Twilio or a local provider from the
         * console instead of waiting for a deploy. The token in it is encrypted at
         * rest with APP_KEY, and who changed which FIELDS — never their values — is
         * held by `audit_log`, which is the append-only record.
         */
        'platform_sms_settings',
        'posts',
        'public_requests',
        'push_coalescing',
        'resident_accounts',

        // "Log in with biometrics" (M49, M50 §3): a phone's key is REVOKED (`revoked_at`) and its use stamped
        // (`last_used_at`); every turn on and off is in `audit_log` too.
        'resident_device_keys',

        'resident_otps',
        'role_module_access',

        /*
         * An estate's sample data, retired on launch day (M33 §5, KI-25): one row per `launch:retire-sample-data
         * --confirm`. MUTABLE because `--undo` writes `undone_at` on the row; nothing else of it changes, and the
         * retirement and its undoing are each in `audit_log` too.
         */
        'sample_data_retirements',

        'security_incidents',

        // Every message NOT sent because it was addressed to a person a seeder invented (M35 §5.2): the channel, the
        // kind and the row, never the address. Only ever inserted; MUTABLE like `mail_log`, which it sits beside.
        'seeded_recipient_drops',

        /*
         * Each estate's morning jobs at its own time (M11 §4.2): one claim per (job, estate, local date),
         * released when a run fails and pruned after 90 days — MUTABLE for both. See App\Support\LocalSchedule.
         */
        'scheduled_local_runs',

        'sessions',
        'shift_breaks',
        'shift_claims',
        'shifts',

        // The numbers a panic at a site is texted to (Q-M09). MUTABLE on purpose: the
        // whole point is that the office edits them without a developer when a
        // president changes or an office moves.
        'site_emergency_numbers',

        // What the public site's two forms sent (M14, D-194). MUTABLE so a person's enquiry can be deleted when
        // they ask; the application itself only ever inserts.
        'site_enquiries',

        // How often an estate's on-duty officers' phones send, and its no signal tolerance (12 Oct 2026). MUTABLE: the
        // estate's Settings page updates its one row, and every save is audited.
        'site_tracking_settings',

        'standing_order_acknowledgements',
        'standing_order_sets',
        'standing_order_versions',
        'statutory_filings',
        'statutory_rate_versions',
        'subscription_line_items',
        'subscriptions',
        'supervisor_override_pins',

        // Gemini Security's phone line (8 Oct 2026): one row, UPDATED when the console saves or clears the number; audited.
        'platform_contact_settings',

        /*
         * A resident's Live chat with Gemini Security (M50 §4.3). The conversation is MUTABLE — opened, answered and
         * resolved on one row — and a message is UPDATED once, for `seen_at` ("Delivered"); neither side edits or
         * deletes what was written, and every answer and resolution is in `audit_log` too.
         */
        'support_chat_messages',
        'support_chats',

        'tenants',
        // A gap in an officer's positions, told to their phone once (M22 §1.1). MUTABLE: the row is taken first and
        // the push's outcome written after, and it goes with the positions at the end of their retention.
        'tracking_gap_notices',
        'users',

        // Not created by a `Schema::create('name')` this file's test can read.
        'migrations',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'role_has_permissions',
        'roles',
        'sample_data_ranges',
        'site_time_corrections',
    ];

    /**
     * Estate tables (`gs_estate_*`) that may only ever be inserted into.
     *
     * `journal_lines` belongs here for the same reason `journals` does: a header
     * nobody can edit above lines anybody can edit is not an append-only ledger.
     *
     * `ballot_receipts` and `ballot_marks` belong here for a different reason -
     * invariant 3 rather than invariant 4. Turnout that can be edited proves
     * nothing, and a marks table that can be deleted from row by row
     * de-anonymises itself: remove 317 of 318 and the survivor's choice is
     * attributable by elimination.
     */
    public const ESTATE_APPEND_ONLY = [
        'journals',
        'journal_lines',
        'ballot_receipts',
        'ballot_marks',
    ];

    /** Estate tables the estate's own database user may update and delete from. */
    public const ESTATE_MUTABLE = [
        'accounts',
        'amenities',
        'amenity_bookings',
        'amenity_slots',

        // The answer to a retried write, kept in the estate whose write it was
        // rather than in the central database. Written once, read on a replay and
        // deleted by `api:prune-idempotency`, so mutable, not append-only.
        'api_idempotency_responses',

        // AutoPay (M50 §4.2 #3): turned on and off, its terms changed, and each run's day and outcome stamped on it.
        'autopay_arrangements',

        'ballot_options',
        'ballot_positions',
        'ballots',
        'bank_reconciliations',
        'bank_statement_lines',
        'bill_payments',
        'bills',

        // Budgets (M84 §3.3): an amount per account and month, a plan the committee changes; nothing posts from it.
        'budget_lines',

        'charge_runs',

        // A dues rate changed from a month (M10 §3). MUTABLE: `applied_at` is stamped when the
        // month arrives, and a change for a month not yet billed is corrected in place. The
        // record that never changes is the audit entry each save writes.
        'charge_schedule_rate_changes',

        'charge_schedules',
        'charges',

        // The dashboard's estate-wide tiles and when they were computed (M11 A2). MUTABLE: a
        // snapshot is overwritten each time it is recomputed, and holds nothing underived.
        'dashboard_snapshots',

        'delinquency_flags',
        'documents',
        'dunning_notices',
        'dunning_template_drafts',
        'dunning_templates',
        'employees',
        'estate_features',
        'estate_phases',
        'estate_settings',
        'gate_approval_requests',

        // Saved TEST cards (M50 §4.2 #3): made the default, and removed. Never a card number — see the migration.
        'household_cards',

        'household_emergency_contacts',

        // A household's pets (M50 §5.3d): added, changed and removed in the Resident App, as its vehicles are.
        'household_pets',

        'household_vehicles',
        'households',

        // A manual journal while it is a draft (M84 §3.4): edited until it posts; the posted journal is append-only.
        'journal_drafts',

        // The estate's own live delivery log (M11 Part C), mutable for the central log's reason.
        'live_deliveries',
        'live_events',
        'live_streams',

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
        'notification_defaults',
        'notification_reads',
        // The card gateway (M16 §2.2): an intent's state moves forward as the provider's webhooks arrive, and each event
        // is claimed once on its own row. Mutable, not append-only: an intent is updated in place by design.
        'payment_gateway_events',
        'payment_intents',
        'payment_plan_instalments',
        'payment_plans',
        'payments',
        'payroll_run_exceptions',
        'payroll_run_lines',
        'payroll_runs',
        'receipt_sequences',
        'resident_invites',
        'residents',
        'statutory_filings',
        'ticket_media',
        'unit_claims',
        'unit_collection_flags',
        'units',
        'vendors',
        'visitor_passes',

        // Not created by a `Schema::create('name')` this file's test can read.
        'migrations',
        'site_time_corrections',
    ];

    /**
     * Declared tables that no migration creates with a literal name, so the
     * coverage test must not demand to find them in `database/migrations`.
     *
     * `migrations` is the framework's own. The five permission tables are created
     * by `create_permission_tables.php` as `Schema::create($tableNames['roles'],
     * ...)`, reading `config/permission.php` - a name no static reader can resolve.
     * `site_time_corrections` is created as `Schema::create(self::LEDGER, ...)`.
     *
     * THAT LAST ONE IS WHY MIGRATIONS_NAMING_TABLES_INDIRECTLY EXISTS. A table
     * whose name the coverage test cannot read is a table that can be added
     * without anybody classifying it - which is the exact hole this whole file was
     * built to close, reopened one indirection lower down. `site_time_corrections`
     * was already through it when this was written.
     *
     * @var list<string>
     */
    public const NOT_NAMED_LITERALLY_IN_A_MIGRATION = [
        'migrations',
        'model_has_permissions',
        'model_has_roles',
        'permissions',
        'role_has_permissions',
        'roles',
        'site_time_corrections',
    ];

    /**
     * Every migration that calls `Schema::create()` with something other than a
     * quoted table name, and is therefore invisible to a static reader.
     *
     * A new one fails `tests/Feature/GrantsCoverageTest.php` until its file name is
     * added here AND the table it creates is declared above - which is the point:
     * an indirection is allowed, but only deliberately, and never by accident.
     *
     * @var list<string>
     */
    public const MIGRATIONS_NAMING_TABLES_INDIRECTLY = [
        '2026_09_09_081302_create_permission_tables.php',
        '2026_09_20_161000_store_office_typed_shift_times_in_utc.php',
        '2026_09_20_161000_store_office_typed_times_in_utc.php',
    ];

    /**
     * Every central table this codebase knows about, sorted.
     *
     * @return list<string>
     */
    public static function central(): array
    {
        return self::sorted(array_merge(self::CENTRAL_APPEND_ONLY, self::CENTRAL_MUTABLE));
    }

    /**
     * Every estate table this codebase knows about, sorted.
     *
     * @return list<string>
     */
    public static function estate(): array
    {
        return self::sorted(array_merge(self::ESTATE_APPEND_ONLY, self::ESTATE_MUTABLE));
    }

    /**
     * Tables that exist in a central database but appear nowhere above.
     *
     * They are still granted - both commands read `information_schema`, so a new
     * table is never left out at runtime - but an undeclared table means the
     * classification was never thought about, and an append-only table declared
     * late is the one mistake this model cannot recover from on its own.
     *
     * @param  list<string>  $tablesInDatabase
     * @return list<string>
     */
    public static function undeclaredCentral(array $tablesInDatabase): array
    {
        return self::sorted(array_diff($tablesInDatabase, self::central()));
    }

    /**
     * Tables that exist in an estate database but appear nowhere above.
     *
     * @param  list<string>  $tablesInDatabase
     * @return list<string>
     */
    public static function undeclaredEstate(array $tablesInDatabase): array
    {
        return self::sorted(array_diff($tablesInDatabase, self::estate()));
    }

    /**
     * @param  iterable<int|string, string>  $tables
     * @return list<string>
     */
    private static function sorted(iterable $tables): array
    {
        $unique = array_values(array_unique(is_array($tables) ? $tables : iterator_to_array($tables)));
        sort($unique);

        return $unique;
    }
}
