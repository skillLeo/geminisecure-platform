<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity, central only.
 *
 * Users, roles and permissions all live in gs_platform. An estate never holds
 * its own copy: the role matrix drives navigation generation, so per-estate
 * user or role tables would let two estates drift into different definitions
 * of the same role.
 *
 * Accounts are ISSUED, never self-created. There is no public registration
 * endpoint anywhere in this application. An invitation names its inviter, its
 * estate and its role, and expires in 14 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // gemini | estate — which console this account signs into.
            // A user belongs to exactly one; the two never mix.
            $table->string('console', 16)->default('estate')->after('email');

            // invited | active | suspended
            // `suspended` blocks sign-in. It is never set by arrears:
            // access is never withheld over a billing dispute.
            $table->string('status', 24)->default('invited')->after('console');

            $table->string('phone', 40)->nullable()->after('status');

            // Biometrics never leave the device. This is an enrolment
            // REFERENCE and a verification result, never a template, and
            // there is no image anywhere in this system.
            $table->string('mfa_secret')->nullable()->after('phone');
            $table->boolean('mfa_enabled')->default(false)->after('mfa_secret');

            $table->timestamp('last_login_at')->nullable()->after('mfa_enabled');

            $table->index(['console', 'status']);
        });

        /**
         * Which estates a user may reach.
         *
         * Serves two distinct purposes with one shape:
         *
         *   Estate committee — one row, their estate, carrying their estate
         *   role. This is their whole world.
         *
         *   Gemini staff whose role is scoped to `assigned_sites` (Head of
         *   Security) — one row per estate they are responsible for. Staff
         *   with `all` scope need no rows at all; absence means unrestricted,
         *   which is why scope is read from the ROLE and never inferred from
         *   whether rows exist.
         */
        Schema::create('estate_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // The tenant id is the subdomain. Not a foreign key to `tenants`
            // only because that table's key is a string primary key created by
            // a package migration; integrity is enforced in the service layer.
            $table->string('tenant_id');

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
            $table->index(['tenant_id', 'is_active']);
        });

        /**
         * Invitations. Accounts are issued, never self-created.
         */
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190);
            $table->string('token', 64)->unique();

            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('tenant_id')->nullable();  // null for Gemini staff

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('estate_assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['console', 'status']);
            $table->dropColumn([
                'console', 'status', 'phone',
                'mfa_secret', 'mfa_enabled', 'last_login_at',
            ]);
        });
    }
};
