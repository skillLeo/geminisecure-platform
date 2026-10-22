<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Resident App account the estate office revoked stays revoked when its household removes the person
 * (household invites, stage 2, W-ACCEPT, G1).
 *
 * THE FAULT. `MeController::removeMember` suspended every account of a removed member, and so overwrote
 * a REVOKED account's status with `suspended`. What ended with it:
 * - the gate's refusal of that person (`RevokeResidentAccess`, which asks for `status = revoked`);
 * - the office's own restore;
 * - sign-in's `account_revoked` answer.
 * A household act undid the office's. The removal no longer does that (`removeMember`). This puts back
 * the rows it already overwrote.
 *
 * WHY THIS EXACT PREDICATE, and why nothing else can match it:
 * - only `RevokeResidentAccess::revoke()` sets `revoked_at`, and `restore()` clears it;
 * - a self-deletion suspends with `deleted_at` set, and a revoked account cannot reach it, because its
 *   tokens are gone.
 * So `suspended` + `revoked_at` + not deleted can only be a revoked person whom a removal overwrote.
 *
 * Idempotent: a second run matches nothing. `down()` does nothing, because it could not tell the rows it
 * repaired from ones revoked since.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysql')->table('resident_accounts')
            ->where('status', 'suspended')
            ->whereNotNull('revoked_at')
            ->whereNull('deleted_at')
            ->update(['status' => 'revoked', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Nothing to undo: see above.
    }
};
