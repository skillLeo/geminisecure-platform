<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH PHONES WERE A GUARD'S, AND WHEN EACH STOPPED BEING (M32 F3).
 *
 * A guard signed out — the 30-minute rule, a sign-out they chose — now signs back in with a fingerprint, proving the
 * phone with the key it was bound with (`DeviceResume`). And when the server answers that a phone is no longer theirs,
 * the enrolment screen says which case it is: "Your supervisor removed this phone on 2 October." For that the server
 * has to remember a phone AFTER it was replaced, with the key that proves it is that phone; until now binding a new
 * one overwrote the old one's install id and key on the guard's row, and nothing was left to say when or by whom.
 *
 *   device_bindings   one row per phone bound to a guard: its install id and public key, when it was bound, and —
 *                     once it stops being theirs — when, why (`replaced` by another phone, `reenrolled` with a code on
 *                     the same phone, `removed` by the office) and by whom (`supervisor` with their name, or `office`).
 *
 * ADDITIVE: one table, and a row for every phone bound today, read from the guard's own record; nothing existing is
 * rewritten. MUTABLE, not append-only — a binding is ended in place when it ends — and listed in
 * `GrantedTables::CENTRAL_MUTABLE`; every act on a phone is also in `audit_log`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_bindings')) {
            return;
        }

        Schema::create('device_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('guard_id')->constrained('guards')->cascadeOnDelete();
            $table->string('tenant_id')->nullable();
            $table->string('device_uid', 120);
            $table->string('public_key', 64)->nullable();
            $table->timestamp('bound_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 16)->nullable();
            $table->string('ended_by', 16)->nullable();
            $table->string('ended_by_name')->nullable();
            $table->timestamps();

            $table->index('device_uid');
            $table->index(['guard_id', 'ended_at']);
        });

        $this->backfill();
    }

    /**
     * Every phone bound now, from the guard's own record: the binding a fingerprint sign-in proves against.
     * (`M32GuardResumeTest` runs it on its own rows.)
     *
     * ONE INSTALL, ONE GUARD. An install can sit on two guards' rows today — a code for another officer entered on a
     * phone that was somebody's left the first officer's row naming it (phone 2: Andre's, then the reviewer account's,
     * 4 October). The guard it was bound to LAST keeps the open binding; each earlier one is recorded as replaced at
     * that moment, by the office. Rows are only inserted: no guard's own record is touched.
     *
     * @param  list<string>|null  $only  these installs alone (a test); every bound install when null
     * @param  string|null  $connection  the platform's connection; the migration's own when null
     */
    public function backfill(?array $only = null, ?string $connection = null): void
    {
        $now = Carbon::now();
        $db = DB::connection($connection);

        $bound = $db->table('guards')
            ->whereNotNull('device_id')
            ->whereNotNull('device_uid')
            ->when($only !== null, static fn ($query) => $query->whereIn('device_uid', $only))
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'device_uid', 'device_public_key', 'device_enrolled_at']);

        foreach ($bound->groupBy('device_uid') as $guards) {
            // Oldest first, so the open binding is the last row written for the install.
            $ordered = $guards->sortBy([['device_enrolled_at', 'asc'], ['id', 'asc']])->values();
            $latest = $ordered->last();

            foreach ($ordered as $guard) {
                $replaced = $guard->id !== $latest->id;

                $db->table('device_bindings')->insert([
                    'guard_id' => $guard->id,
                    'tenant_id' => $guard->tenant_id,
                    'device_uid' => $guard->device_uid,
                    'public_key' => $guard->device_public_key,
                    'bound_at' => $guard->device_enrolled_at ?? $now,
                    'ended_at' => $replaced ? ($latest->device_enrolled_at ?? $now) : null,
                    'ended_reason' => $replaced ? 'replaced' : null,
                    'ended_by' => $replaced ? 'office' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_bindings');
    }
};
