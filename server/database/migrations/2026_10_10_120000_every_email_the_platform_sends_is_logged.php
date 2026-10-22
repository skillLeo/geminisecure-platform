<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mail_log` — every email the platform hands to its mail server, and what became of it (M18G §7).
 *
 * "I never got the code" took a screenshot and a guess to diagnose on 27 Sep. This is the one screen that answers it:
 * to whom, what kind, when, and the outcome — `accepted` (the mail server took it; never "delivered": the inbox is
 * downstream of anything the platform can know) or `failed`, with the reason. Searchable by address, for a Director or
 * an Operations Manager. The subject is kept with any code in it dotted out; never the body.
 *
 * Central. Kept for 60 days (`mail:prune-log`, daily).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mail_log')) {
            return;
        }

        Schema::create('mail_log', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('sent_at')->index();
            $table->string('recipient', 254)->index();
            $table->string('type', 80);
            $table->string('subject', 200);
            $table->string('outcome', 16);
            $table->string('reason', 400)->nullable();
            $table->string('relay', 120)->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
