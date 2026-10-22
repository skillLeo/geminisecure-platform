<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The central database's hot-path indexes (M11 A2).
 *
 * EVERY INDEX HERE IS DERIVED FROM A STATEMENT THE PLATFORM ACTUALLY RUNS, NOT FROM A COLUMN NAME.
 * Every Gemini page, every estate page, every endpoint both apps call, the panic and the scheduler's
 * own commands were replayed through the real HTTP and console kernels, with every statement recorded
 * (SQL, bindings, time and the file:line that issued it). An index is added only where one of those
 * statements filters, joins or sorts on a column no existing index can serve. The call site is written
 * beside each one; the plans before and after, and how they were measured, are in docs/perf/EXPLAIN.md.
 *
 * THESE TABLES ARE SMALL TODAY, AND THAT IS SAID RATHER THAN HIDDEN. Counted on production, read-only,
 * on 24 September 2026: 91 audit rows, 52 alertness checks, 14 alerts, 9 incidents, no invitations and
 * 62 shifts. At that size MySQL is right to read a whole table, and no time saved can be measured —
 * the audit log's own page still reads all of it with the index there. What IS measured at today's
 * size is that every result is unchanged. What the indexes are for is measured on copies grown by one
 * deterministic script to a year of use (SYNTHETIC rows, labelled so in EXPLAIN.md): the Guard App's
 * sync pull reading 25,002 alertness checks to find one pending falls to 3; the audit page, 12,175
 * rows to 26; who is rostered today, which every panic asks, 3,165 shifts to 46. Every one of these
 * tables grows with use: an audit row per audited act, a check per officer several times an hour, a
 * shift per post per watch.
 *
 * ADDITIONS ONLY. No column, no row and no existing index changes. On MySQL 8.4 each ADD INDEX is
 * built in place with reads and writes to the table allowed throughout. `audit_log` is append-only:
 * its no-update and no-delete triggers are untouched by an index, and HotPathIndexesTest proves they
 * still refuse.
 */
return new class extends Migration
{
    /**
     * [table, index name, columns].
     *
     * @var list<array{0: string, 1: string, 2: list<string>}>
     */
    private const INDEXES = [
        // The Gemini audit log, newest first, 25 to a page: `order by created_at desc, id desc`
        // (AuditLogger.php:313). No index led with created_at, so every page sorted the whole log.
        ['audit_log', 'audit_log_created_at_index', ['created_at']],

        // One guard's own history on their profile: `entity_type = ? and entity_id = ?` newest first
        // (GuardWorkforce.php:1143). Nothing indexed the entity at all.
        ['audit_log', 'audit_log_entity_created_at_index', ['entity_type', 'entity_id', 'created_at']],

        // Every five minutes, `update ... set outcome = 'missed' where outcome = 'pending' and
        // respond_by < ?` (AlertnessChecks.php:36): without this it reads every check ever issued,
        // and under REPEATABLE READ an UPDATE locks what it reads while officers answer theirs. And
        // the one pending check the Guard App's sync pull asks for (SyncController.php:202), the
        // "is one pending" before issuing (AlertnessChecks.php:55) and the answer (PresenceController.php:92).
        ['alertness_checks', 'alertness_checks_outcome_respond_by_index', ['outcome', 'respond_by']],

        // "Open alerts" on the Gemini activity page: `resolved_at is null` (SecurityOperations.php:530).
        ['duress_alerts', 'duress_alerts_resolved_at_index', ['resolved_at']],

        // One site's alerts by time: the live map's average acknowledgement over the last 30 days
        // (LiveMap.php:234), its open alerts newest first (LiveMap.php:520), and the open alerts the
        // Guard App's sync pull hands an officer on duty (SyncController.php:309).
        ['duress_alerts', 'duress_alerts_tenant_id_server_time_index', ['tenant_id', 'server_time']],

        // An estate's pending invitations on Settings → Users, and the console's own:
        // `tenant_id = ? (or is null) and accepted_at is null` (Settings.php:1070, PlatformAdmins.php:112).
        ['invitations', 'invitations_tenant_id_accepted_at_index', ['tenant_id', 'accepted_at']],

        // Open incidents newest first, on the Gemini dashboard and in the bell on every page that
        // shows it: `status = 'open' order by occurred_at desc limit 20` (PlatformNotifications.php:201).
        ['security_incidents', 'security_incidents_status_occurred_at_index', ['status', 'occurred_at']],

        // Who is on duty RIGHT NOW: `actual_start is not null and actual_end is null` — every five
        // minutes (AlertnessChecks.php:44), hourly (ShiftAutoClose.php:247), the Guard App's current
        // shift and colleagues (ShiftsController.php:53, :81), the panic's push audience
        // (PushNotifier.php:117), and the dispatch board, live map and estate security screen
        // (DispatchController.php:1116, LiveMap.php:504, EstateSecurity.php:190). The shift indexes
        // all lead with a guard, post or site and a ROSTERED time, which says nothing about duty.
        ['shifts', 'shifts_on_duty_index', ['actual_end', 'actual_start']],

        // Who is rostered TODAY at a site — asked by every panic (PanicFanOut.php:296) — and the
        // dispatch coverage board and patrol monitor's rostered windows (PostCoverage.php:390,
        // PatrolMonitor.php:611): `tenant_id = ? and rostered_start <= ? and rostered_end > ?`. The
        // index on (tenant_id, rostered_start) cannot bound it: every shift ever worked STARTED
        // before now. Few end after it.
        ['shifts', 'shifts_tenant_id_rostered_end_index', ['tenant_id', 'rostered_end']],

        // The same window for named guards: the patrol monitor's current shifts (PatrolMonitor.php:635),
        // the handset's own shifts in the sync pull (SyncController.php:159) and the rest warning on
        // its list of open shifts (ShiftsController.php:296).
        ['shifts', 'shifts_guard_id_rostered_end_index', ['guard_id', 'rostered_end']],

        // The last handover note at this post, on the Guard App's current shift:
        // `post_id = ? and actual_end is not null ... order by actual_end desc limit 1`
        // (ShiftsController.php:71). Without it, `shifts_on_duty_index` above is the cheaper-looking
        // way to that ORDER BY and walks every post's history — measured, on a copy grown to a year.
        ['shifts', 'shifts_post_id_actual_end_index', ['post_id', 'actual_end']],

        // Officers on post on the Gemini operations page: `status = 'active'` (SecurityOperations.php:522).
        ['shifts', 'shifts_status_index', ['status']],
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
    }

    public function down(): void
    {
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
