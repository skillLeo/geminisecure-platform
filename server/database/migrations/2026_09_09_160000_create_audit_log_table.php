<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log - CENTRAL and APPEND-ONLY.
 *
 * Receives entries from both contexts, each tagged with the tenant it
 * concerned. Central rather than per estate so that a platform-wide access
 * review can be answered without opening every estate database, and so that an
 * estate cannot edit the record of what was done to it.
 *
 * "Not editable by anyone including platform staff" is enforced by a trigger
 * here and a withheld grant applied by `php artisan grants:append-only`. The
 * Director role holds Full on this module and still cannot alter a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();

            // Null for a platform-level action that concerned no single estate.
            $table->string('tenant_id')->nullable();

            // Null for an action taken by the system rather than a person.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalised deliberately: the log must still read correctly
            // years later, after the actor's account has been renamed or
            // deleted. A join that returns null tells the reader nothing.
            $table->string('actor_name', 160)->nullable();
            $table->string('actor_role', 120)->nullable();

            $table->string('action', 120);          // role.permission_changed
            $table->string('entity_type', 120)->nullable();
            $table->string('entity_id', 64)->nullable();

            // What changed. JSON rather than text so a later reviewer can
            // diff programmatically rather than by reading prose.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('action');
        });

        DB::unprepared("
            CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only and is not editable by anyone'
        ");

        DB::unprepared("
            CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
            FOR EACH ROW SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_log is append-only'
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_log_no_delete');

        Schema::dropIfExists('audit_log');
    }
};
