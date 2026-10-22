<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MY ACCOUNT (M32 F9): a console user changes their own password, two-step verification and details, and sees where
 * they are signed in. Additive: two columns on `users` and two tables, nothing existing rewritten.
 *
 *   users.password_changed_at  when the password was last changed; null until the first change from My account
 *                              (a password set by invitation or reset before this is not dated)
 *   users.sign_in_generation   raised by "Sign out everywhere else" and by a password change: a session made under an
 *                              older number ends at its next request (`KeepAccountSession`), wherever its session is
 *                              kept — production keeps them in Redis, where nothing can find "every session of user 42"
 *
 *   account_sessions           where an account is signed in, for the page's list and its "Sign it out": the browser
 *                              and the address it came from, when it was last seen, and the session's id — a hash to
 *                              find it by, and the id itself encrypted, which ending that one session needs
 *   account_codes              the code emailed before a password change: a hash only (HMAC keyed by the application
 *                              key, the account and the purpose), ten minutes, five tries
 *
 * MUTABLE, NOT APPEND-ONLY: rows are touched, retired and deleted in normal use; every act is in `audit_log`, which is
 * the append-only record. Both tables are listed in `GrantedTables::CENTRAL_MUTABLE`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('password_changed_at')->nullable()->after('password');
                $table->unsignedInteger('sign_in_generation')->default(0)->after('password_changed_at');
            });
        }

        if (! Schema::hasTable('account_sessions')) {
            Schema::create('account_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->char('session_hash', 64);
                $table->text('session_key');
                $table->string('console', 16);
                $table->string('user_agent', 255)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('last_seen_at');
                $table->timestamps();

                $table->index(['user_id', 'last_seen_at']);
                $table->unique('session_hash');
            });
        }

        if (! Schema::hasTable('account_codes')) {
            Schema::create('account_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('purpose', 32);
                $table->char('code_hash', 64);
                $table->timestamp('expires_at');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('sent_at');
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'purpose', 'consumed_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_codes');
        Schema::dropIfExists('account_sessions');

        if (Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(['password_changed_at', 'sign_in_generation']);
            });
        }
    }
};
