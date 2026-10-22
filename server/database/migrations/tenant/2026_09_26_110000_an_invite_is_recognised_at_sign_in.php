<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invite is recognised when its invitee signs in (household invites, stage 2, W-ACCEPT).
 *
 * Stage 1 made an invite actually go. Its invitee still had to fill in the claim form: the lot, the
 * phase and their name, all things the household had already told the estate. Now the invited address,
 * proven by the one-time code, IS the claim. The estate office confirms it with one tap, or, when the
 * estate trusts its primary residents' invites, nobody has to.
 *
 *   unit_claims.invite_id              the invite a claim was made from; null for an ordinary claim
 *   resident_invites.claim_id          the claim it became
 *   resident_invites.refused_reason    the OFFICE-ONLY code of a refusal at the address:
 *                                      in_use, other_household, revoked_account, revoked_name,
 *                                      refusal_limit or stale_offline_add. The app is never shown it
 *                                      (`ResidentInvites::outcome` never emits it). The office reads it
 *                                      in words.
 *   resident_invites (email) index     acceptance asks every live invite for one address
 *   estate_settings.trust_primary_invites   OFF by default. The estate office chooses it, with a reason
 *
 * Estate databases only, all three tables estate-mutable. No append-only table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('invite_id')->nullable()->after('household_id');
            $table->index('invite_id');
        });

        Schema::table('resident_invites', function (Blueprint $table) {
            $table->unsignedBigInteger('claim_id')->nullable()->after('claimed_at');
            $table->string('refused_reason', 32)->nullable()->after('last_error');
            $table->index('email');
        });

        Schema::table('estate_settings', function (Blueprint $table) {
            $table->boolean('trust_primary_invites')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('estate_settings', function (Blueprint $table) {
            $table->dropColumn('trust_primary_invites');
        });

        Schema::table('resident_invites', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn(['claim_id', 'refused_reason']);
        });

        Schema::table('unit_claims', function (Blueprint $table) {
            $table->dropIndex(['invite_id']);
            $table->dropColumn('invite_id');
        });
    }
};
