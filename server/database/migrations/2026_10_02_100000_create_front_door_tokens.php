<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE FRONT DOOR (M11 §5.2): the single-use handoff from the central sign-in to an estate's own host.
 *
 * Somebody proves who they are at the front door, on the central host. They are then sent to their estate
 * with a token that estate redeems ONCE, within 60 seconds, to create its OWN session. No cookie is ever
 * shared between hostnames — the session cookie stays host-only (`SESSION_DOMAIN` null) — and the central
 * host never holds a session for an estate account at all. That is the journey that broke the client's
 * meeting on 23 September 2026 (App\Http\Middleware\RedirectToOwnConsole): a session made on one host,
 * expected on another.
 *
 * Only a SHA-256 of the token is stored; the token itself exists in one redirect and nowhere else. Bound to
 * one account and one estate. `used_at` is set by one conditional UPDATE, so two redemptions racing each
 * other cannot both succeed. Who was handed where, when and from which address is written to `audit_log`
 * (append-only); this table is working state, pruned daily (`front-door:prune`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('front_door_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tenant_id');
            $table->string('issued_ip', 45)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->dateTime('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('created_at', 'front_door_tokens_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('front_door_tokens');
    }
};
