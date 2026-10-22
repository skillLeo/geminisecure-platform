<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estate core: the physical community and who lives in it.
 *
 * These tables exist ONLY in gs_estate_<subdomain>. There is no tenant_id
 * column anywhere in this file — the database boundary IS the tenant boundary,
 * so a tenant_id would be redundant at best and a second, disagreeing source of
 * truth at worst.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();   // e.g. "PP-14B"
            $table->string('block', 32)->nullable();
            $table->string('street', 120)->nullable();
            $table->string('type', 32)->default('residential');
            $table->string('status', 24)->default('occupied');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('name', 160);

            /*
             * The boolean a guard is permitted to see. Never an amount, never a
             * bucket, never a history — invariant 2.
             *
             * Set only by explicit action while Q-005 is open: nothing in this
             * system auto-restricts on arrears yet, and a declined card or a
             * gateway outage must never set it.
             */
            $table->boolean('access_restricted')->default(false);

            $table->timestamps();
            $table->index('access_restricted');
        });

        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('households')->cascadeOnDelete();

            /*
             * Links to the CENTRAL users table. Deliberately not a foreign key:
             * it crosses a database boundary, and MySQL cannot constrain across
             * databases. Integrity is enforced in the service layer.
             */
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('full_name', 160);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('relationship', 32)->default('owner');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
        Schema::dropIfExists('households');
        Schema::dropIfExists('units');
    }
};
