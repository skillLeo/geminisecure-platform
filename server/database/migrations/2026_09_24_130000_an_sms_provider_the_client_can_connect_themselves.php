<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `platform_sms_settings` — so the client can connect a provider (M10 §1).
 *
 * WHY IT MATTERS MORE THAN IT LOOKS. The platform has no SMS gateway. §1 wants
 * both Heads of Security notified "at the same time" by e-mail AND text, and it
 * keeps the household's emergency contacts on SMS — and none of that can happen
 * until somebody buys an account with a provider. Every one of those legs is
 * currently email-only, and the estate's own notification settings offer an SMS
 * switch that silently does nothing.
 *
 * THE POINT IS THAT IT NEEDS NO DEPLOY. `AppServiceProvider` already binds
 * `SmsGateway` from `config('services.sms.driver')` with one match arm per
 * provider, so the code path exists and is waiting on credentials. Put those in
 * a settings table the Director can edit, and connecting Twilio or a local
 * Jamaican provider is a form rather than a release — which is what the client
 * asked for and what the mail settings already do.
 *
 * MODELLED ON `platform_mail_settings`, DELIBERATELY. One row, credentials
 * encrypted at rest with APP_KEY, write-only in the interface, and a
 * `verified_at` stamped by a test send. A second shape for the same idea would
 * be a second set of rules to keep in step.
 *
 * `token` IS A TEXT COLUMN because an encrypted value is far longer than the
 * secret inside it — the same reason `platform_mail_settings.password` is text
 * rather than a varchar of a plausible length.
 *
 * NOTHING HERE IS A SECRET IN THE REPOSITORY. The table is empty until somebody
 * fills it in through the console.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_sms_settings')) {
            return;
        }

        Schema::create('platform_sms_settings', function (Blueprint $table): void {
            $table->id();

            // `log` until a real one is chosen — the driver that writes the
            // message down and delivers it to nobody, which is what the platform
            // does today and says plainly everywhere it matters.
            $table->string('driver', 32)->default('log');

            $table->string('account_sid', 191)->nullable();
            $table->text('token')->nullable();

            // The number or alphanumeric sender ID a text appears to come from.
            $table->string('from', 32)->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();

            // Stamped by a successful test send, and cleared whenever the
            // credentials change: settings that were once verified are not
            // verified settings.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sms_settings');
    }
};
