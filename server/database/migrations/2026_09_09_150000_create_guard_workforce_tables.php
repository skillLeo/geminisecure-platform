<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guard workforce - CENTRAL, in gs_platform.
 *
 * Guards are Gemini Security Limited's own employees, not an estate's staff.
 * A guard is posted AT an estate and may be reassigned between them, so the
 * estate is a foreign key on the guard rather than the guard living inside an
 * estate database. This is also what makes the cross-client roster (screen 24)
 * possible without any report fanning out across estate databases.
 *
 * Note what is NOT here: nothing about a resident, a household, a balance or a
 * charge. A guard record touches no estate financial data at all, which is the
 * structural half of invariant 2 - a guard endpoint cannot return an amount
 * owed because the tables it reads hold none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();

            // The estate this post belongs to. Not a foreign key to `tenants`
            // only because that table has a string primary key from a package
            // migration; integrity is the service layer's job.
            $table->string('tenant_id');

            $table->string('name', 120);          // "Main Gate", "Patrol - Phase 2-5"
            $table->string('type', 32)->default('gate');  // gate | patrol | relief
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('guards', function (Blueprint $table) {
            $table->id();

            $table->string('full_name', 160);
            $table->string('employee_number', 32)->unique();

            /*
             * Private Security Regulation Authority licence.
             *
             * The expiry drives the compliance screen and the "Licence expired"
             * status. It is stored as a date rather than a computed boolean so
             * the screen can distinguish expired from expiring-soon, and so a
             * historical run can tell whether a guard was licensed at the time.
             */
            $table->string('psra_number', 32)->unique();
            $table->date('psra_expires_on')->nullable();

            $table->string('employment_type', 24)->default('full_time');

            // active | on_leave | licence_expired | suspended | inactive
            $table->string('status', 32)->default('active');

            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->date('hired_on')->nullable();

            // Current posting. Null for a guard between assignments.
            $table->string('tenant_id')->nullable();
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();

            /*
             * Links to the central users table when the guard has a Guard App
             * account. Nullable: a guard exists as an employee before, and
             * possibly without, ever being issued an app login.
             */
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'full_name']);
            $table->index('tenant_id');
            $table->index('psra_expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guards');
        Schema::dropIfExists('posts');
    }
};
