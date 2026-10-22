<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE GEMINI CONSOLE'S SECOND FACTOR (M11 §0.3).
 *
 * The Gemini door has said, since it was built, "Two-factor authentication is required for every sign-in, no
 * exceptions" beside a button reading "Continue with 2FA" — and nothing asked for a second factor. The columns
 * the identity migration reserved (`mfa_secret`, `mfa_enabled`) were never written. This makes them real:
 *
 *   mfa_secret          TEXT now: it holds the authenticator key ENCRYPTED with the application key, which is
 *                       longer than the 255 characters reserved for it in plain text
 *   mfa_confirmed_at    when the account proved its authenticator with a first code — until then it is not
 *                       enrolled, whatever `mfa_secret` holds
 *   mfa_recovery_codes  the SHA-256 of each unused one-time recovery code, encrypted; a code is removed when used
 *   mfa_last_step       the 30-second time step of the last code accepted, so the same code cannot be used twice
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable()->change();
            $table->dateTime('mfa_confirmed_at')->nullable()->after('mfa_enabled');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_confirmed_at');
            $table->unsignedBigInteger('mfa_last_step')->nullable()->after('mfa_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_confirmed_at', 'mfa_recovery_codes', 'mfa_last_step']);
            $table->string('mfa_secret')->nullable()->change();
        });
    }
};
