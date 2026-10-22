<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * GEMINI SECURITY'S PHONE LINE, SET IN THE CONSOLE (the user, 8 Oct 2026). The Resident App's Help & support draws
 * "Call Gemini Security"; the company has given no number yet (Q-M33). Until somebody saves one here the app hides the
 * button — no broken button, no placeholder number — and the moment one is saved it calls it. One row, like the mail
 * and SMS settings beside it; the number is kept in international form (E.164).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_contact_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('support_phone', 20)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_by_name', 160)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_contact_settings');
    }
};
