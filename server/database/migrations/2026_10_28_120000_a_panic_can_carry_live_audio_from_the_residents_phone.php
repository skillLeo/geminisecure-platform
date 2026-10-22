<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "SHARE LIVE AUDIO" ON THE PANIC SCREEN (board resident-app-06; M50 §4.2 #1): while it is on, the resident's phone
 * records a few seconds at a time and sends each piece; the control room listens on the alert's page as they arrive.
 * Central, beside the alert. A piece is a FILE on the central private disk `panic_audio` (storage/app/private/
 * panic-audio, which tenancy does not re-root) — never a public URL — and this row is its record. Kept with the alert,
 * as its messages are: what was heard during an emergency is part of what happened.
 *
 *   seq           the phone's own count from 0, so a piece sent twice by a phone that lost its signal is one row
 *   duration_ms   how long the phone says it is
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duress_alert_audio', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duress_alert_id')->constrained('duress_alerts')->cascadeOnDelete();
            $table->string('tenant_id', 64);
            $table->unsignedInteger('seq');
            $table->string('path', 255);
            $table->string('mime', 40);
            $table->unsignedInteger('bytes');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['duress_alert_id', 'seq'], 'duress_alert_audio_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duress_alert_audio');
    }
};
