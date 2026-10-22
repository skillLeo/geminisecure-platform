<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The role access matrix — central only, never per estate.
 *
 * The wireframes' two "Role access matrix" screens are the source of truth
 * (Super Admin 45, Community Admin 24) and navigation is generated from this
 * data at runtime: a module a role cannot use is ABSENT from navigation, not
 * disabled or greyed.
 *
 * A grid cell is three orthogonal facts, not one enum (D-007):
 *   level        what may be done
 *   can_approve  whether the irreversible act may be committed
 *   scope        which records are in reach
 *
 * `Scoped` in the Gemini grid narrows WHICH RECORDS; `Entry` in the Estate grid
 * narrows WHICH ACTIONS. A single enum cannot express both without inventing
 * levels that no wireframe shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Roles gain a console and presentation metadata ------------------
        Schema::table('roles', function (Blueprint $table) {
            // Which console this role belongs to. A Gemini role and an estate
            // role never appear in the same navigation.
            $table->string('console', 16)->default('gemini')->after('guard_name');

            // Human label as drawn in the wireframe header, e.g. "Operations
            // Manager". `name` stays the machine key.
            $table->string('label')->nullable()->after('console');

            // Column order in the matrix screen.
            $table->unsignedSmallInteger('sort')->default(0)->after('label');

            // Scope shown under the role name in the Gemini grid header
            // (.role-head-scope), e.g. "Assigned sites only".
            $table->string('scope_default', 24)->default('all')->after('sort');

            $table->index(['console', 'sort']);
        });

        // --- Modules ---------------------------------------------------------
        Schema::create('modules', function (Blueprint $table) {
            $table->id();

            // Unique per console, not globally: `dashboard`, `reports` and
            // `settings` all exist in both consoles as genuinely different
            // modules with different permissions.
            $table->string('key', 64);
            $table->string('console', 16);
            $table->unique(['console', 'key']);

            $table->string('label');
            $table->unsignedSmallInteger('sort')->default(0);

            /*
             * Locked financial modules (D-010, client Ruling 1).
             *
             * The Property Manager may never hold ANY level on a module flagged
             * here, and no super admin, role clone or estate setting may grant
             * one. The rule underneath: whoever commissions work must never be
             * able to pay for it, nor see a resident's financial position.
             *
             * This is deliberately a column rather than a hardcoded list, so the
             * matrix UI can read it and refuse the grant rather than relying on
             * application discipline.
             */
            $table->boolean('is_locked_financial')->default(false);

            $table->timestamps();
            $table->index(['console', 'sort']);
        });

        // --- The matrix itself ----------------------------------------------
        Schema::create('role_module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();

            // none | view | entry | full
            // `entry` sits between view and full: may create and update own
            // drafts; may never delete, approve, export or configure.
            $table->string('level', 16)->default('none');

            // The `approve` verb, held separately from `update` so a role may
            // prepare an irreversible act without committing it (D-008).
            $table->boolean('can_approve')->default(false);

            // all | assigned_sites | own_records
            $table->string('scope', 24)->default('all');

            $table->timestamps();
            $table->unique(['role_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_access');
        Schema::dropIfExists('modules');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['console', 'sort']);
            $table->dropColumn(['console', 'label', 'sort', 'scope_default']);
        });
    }
};
