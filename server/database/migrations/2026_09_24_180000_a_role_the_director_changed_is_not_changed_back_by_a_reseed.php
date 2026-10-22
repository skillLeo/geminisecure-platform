<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether somebody changed a role's permissions at runtime, and who (M10 §4).
 *
 * THE FAULT THIS CLOSES. Since M10 §4 the Director can change what the six
 * seeded Gemini roles may do. `RbacMatrixSeeder` wrote the grid over every
 * seeded role's cells on every run, and every deploy that changes the matrix
 * runs it. So the first such deploy after the client narrowed the Dispatcher
 * would have put the Dispatcher back, silently. "We changed it and it changed
 * back" is exactly what an editable matrix must never do.
 *
 * So the seeder needs to know which seeded roles somebody changed, and those
 * two columns tell it. `RoleEditor::setPermissions()` sets them, and the
 * seeder then leaves that role's existing cells alone, while still giving it a
 * cell for any module it has none for. `RoleEditor::resetToDefault()` and
 * `rbac:reset-role` clear them. These are the deliberate way back.
 *
 * NO FOREIGN KEY on the actor, the same way `created_by_id` has none: removing
 * an account must never change a role, and the audit log names the person
 * regardless.
 *
 * `roles` is already in `GrantedTables::CENTRAL_MUTABLE` and its grants are
 * table-level, so two new columns need no new grant. Run as the schema owner,
 * like every central migration: `migrate --database=mysql_owner`.
 */
return new class extends Migration
{
    /** The six seeded Gemini roles: the only seeded roles an edit can reach. */
    private const GEMINI_SEEDED = [
        'gemini.director', 'gemini.operations_manager', 'gemini.head_of_security',
        'gemini.dispatcher', 'gemini.admin_assistant', 'gemini.accountant',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->timestamp('permissions_edited_at')->nullable()->after('created_by_id');
            $table->unsignedBigInteger('permissions_edited_by_id')->nullable()->after('permissions_edited_at');
        });

        /*
         * BACKFILLED FROM THE AUDIT LOG. A server that ran M10 §4's first release
         * may already hold a Director's change to a seeded Gemini role, recorded
         * as `role.permissions_changed`. Without this, the first re-seed after
         * this migration would undo exactly the change this migration exists to
         * keep. The latest such entry per role supplies the time and the actor.
         * The estate's eight seeded roles cannot be changed at runtime, so they
         * are not looked at.
         */
        foreach (self::GEMINI_SEEDED as $name) {
            $latest = DB::table('audit_log')
                ->where('action', 'role.permissions_changed')
                ->where('entity_type', 'Role')
                ->where('entity_id', $name)
                ->orderByDesc('id')
                ->first(['actor_id', 'created_at']);

            if ($latest !== null) {
                DB::table('roles')->where('name', $name)->where('is_system', true)->update([
                    'permissions_edited_at' => $latest->created_at,
                    'permissions_edited_by_id' => $latest->actor_id,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['permissions_edited_at', 'permissions_edited_by_id']);
        });
    }
};
