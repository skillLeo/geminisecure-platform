<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become something a console can create, rename, delete and re-grant at
 * runtime (M10 §4) — central only, like every table the matrix lives in.
 *
 * NO NEW TABLE, AND NO TENANT MIGRATION. Roles, modules, `role_module_access`
 * and the spatie tables are central by construction (D-012), and `gate:isolation`
 * STEP 9 fails the build if any of them ever appears in an estate database. This
 * adds columns to two tables `App\Support\GrantedTables` already declares
 * mutable, so no grant changes either.
 *
 * WHY `roles` NEEDS A TENANT COLUMN AT ALL. Until now every estate shared one set
 * of estate roles, because nothing could make a role. The moment a Community
 * Super Admin can, the role they make has to belong to their estate and to no
 * other, and a table with no tenant column has physically nowhere to say so.
 * NULL is the platform's own role, shared by every estate (the eight estate
 * roles) or platform-wide (the six Gemini ones); a tenant key is one estate's own
 * role, visible nowhere else. No foreign key, for the same reason
 * `estate_assignments.tenant_id` has none: `tenants` is keyed by a string made by
 * a package migration, and integrity is the service layer's.
 *
 * WHY `is_system`. The fourteen seeded roles are machine keys that code depends
 * on by name — the last-Director guard, Ruling 1's Property Manager lock, the
 * panic escalation to a Head of Security. They may never be renamed or deleted,
 * and a flag is what lets `RoleEditor` refuse that without a hardcoded list of
 * names that the next seeded role would be missing from.
 *
 * WHY `copied_from_id`. The rbac migration says of Ruling 1 that "no super admin,
 * ROLE CLONE or estate setting" may grant the Property Manager a locked financial
 * module. A copy of the Property Manager made at runtime is exactly a role clone,
 * so it has to remember what it was copied from for `Module::isLockedFor()` to
 * keep refusing it after it has been renamed out of recognition.
 */
return new class extends Migration
{
    /**
     * The fourteen roles the matrix seeder creates, written out rather than read
     * from `App\Models\Role`: a migration has to mean the same thing on the day it
     * runs whatever the model's constants have become since.
     */
    private const SEEDED = [
        'gemini.director', 'gemini.operations_manager', 'gemini.head_of_security',
        'gemini.dispatcher', 'gemini.admin_assistant', 'gemini.accountant',
        'estate.community_super_admin', 'estate.president', 'estate.vice_president',
        'estate.secretary', 'estate.property_manager', 'estate.treasurer',
        'estate.admin_assistant', 'estate.head_of_security',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // NULL = the platform's own role; a tenant key = that estate's own.
            $table->string('tenant_id')->nullable()->after('console');

            $table->boolean('is_system')->default(false)->after('tenant_id');

            // The system role a runtime role was copied from, directly or through
            // another copy. No foreign key: a system role is never deleted, and a
            // custom one being deleted must not take its copies' lineage with it.
            $table->unsignedBigInteger('copied_from_id')->nullable()->after('is_system');

            // Who made it, for the screen and the audit trail. No foreign key,
            // so an account being removed never removes a role.
            $table->unsignedBigInteger('created_by_id')->nullable()->after('copied_from_id');

            // Every tenant-fenced role query added by M10 §4 filters on the first
            // two and orders on the third.
            $table->index(['console', 'tenant_id', 'sort'], 'roles_console_tenant_sort_index');
        });

        /*
         * BACKFILLED HERE, NOT LEFT TO THE NEXT SEED. The seeder writes `is_system`
         * too, but the deploy does not run it (STATE.md), and a database migrated
         * without a re-seed would otherwise present all fourteen seeded roles to
         * the editor as ordinary custom roles — renamable and deletable — for as
         * long as nobody remembered. No role other than these could exist before
         * this migration, because nothing could create one.
         */
        DB::table('roles')->whereIn('name', self::SEEDED)->update(['is_system' => true, 'tenant_id' => null]);

        Schema::table('role_module_access', function (Blueprint $table) {
            /*
             * The per-action grid (M10 §4 asks for control "per module and per
             * action"). NULL means "the level's own verb set", which keeps every
             * seeded cell byte-identical; a JSON list of verbs is a `custom` cell.
             * `approve` is never stored here — it stays its own column, the
             * separate axis D-008 keeps it on — and `RoleModuleAccess` strips it
             * if it ever appears.
             */
            $table->json('verbs')->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('role_module_access', function (Blueprint $table) {
            $table->dropColumn('verbs');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex('roles_console_tenant_sort_index');
            $table->dropColumn(['tenant_id', 'is_system', 'copied_from_id', 'created_by_id']);
        });
    }
};
