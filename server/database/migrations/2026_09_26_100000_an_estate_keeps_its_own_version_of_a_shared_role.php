<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An estate edits one of the eight shared roles as its own (M11 B4).
 *
 * THE CLIENT'S WORDS: the Community Super Admin adds, changes and removes roles and permissions,
 * and "copy it first, then edit the copy" is not that. So the first change an estate makes to a
 * shared role makes THAT ESTATE'S VERSION of it, behind the scenes. The version:
 * - is an exact copy of the shared role;
 * - carries its label and its place in the list;
 * - takes over this estate's holders and invitations;
 * - is what the estate sees in the shared role's place from then on.
 * Every other estate keeps the shared role as it was. "Reset to GeminiSecure default" puts this
 * estate's people back on the shared role and removes the version.
 *
 * `version_of_id` names the shared role a version stands for. It is set on a version and never on
 * anything else: a plain copy ("Copy…") is a new role of the estate's own, with a name and a label
 * of its own, and stands for nothing. A version also keeps `copied_from_id` pointing at the same
 * shared role, as every copy does. That is what keeps the lineage rules true for it without a line
 * of theirs changing:
 * - Ruling 1 binds a version of the Property Manager (`Role::derivesFrom()`);
 * - a version of the estate's Head of Security is still texted on a panic
 *   (`SiteEscalation::estateHeadOfSecurityRoles()`).
 *
 * ONE VERSION PER ESTATE PER SHARED ROLE, held by the unique key. MySQL lets many rows share a
 * NULL in a unique key, so the plain roles, with no `version_of_id`, are untouched by it.
 *
 * Central only. `roles` is in `GrantedTables::CENTRAL_MUTABLE`, so no grant changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('version_of_id')->nullable()->after('copied_from_id');
            $table->unique(['tenant_id', 'version_of_id'], 'roles_tenant_version_of_unique');
            $table->index('version_of_id', 'roles_version_of_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_tenant_version_of_unique');
            $table->dropIndex('roles_version_of_id_index');
            $table->dropColumn('version_of_id');
        });
    }
};
