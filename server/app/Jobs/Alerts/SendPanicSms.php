<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Models\SiteEmergencyNumber;
use App\Services\Dispatch\PanicSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * THE FOUR TEXTS A PANIC RAISES, sent off the request and written down one by one.
 *
 * ALWAYS QUEUED, AND NOTHING UPSTREAM WAITS FOR IT. The alert is already committed,
 * the console already has it, the guards on duty have already been pushed and the
 * escalation ladder has already run — see `Services\Dispatch\PanicSms`. A provider
 * that takes thirty seconds, or never answers, costs this job and nothing else.
 *
 * IT CARRIES AN ALERT ID AND NOTHING ELSE. `SendExpoPush` keeps push addresses out of
 * a failed job's stored payload; four private phone numbers sitting in `failed_jobs`
 * would be worse. The numbers are read from the table when the job runs, so a number
 * the office corrected between the press and the send is the one that is texted.
 *
 * ONE ROW PER NUMBER, WRITTEN AFTER THE ATTEMPT (`alert_sms_dispatches`). A row here
 * is always a fact: `sent` only when a provider accepted the message, `recorded` when
 * the message was written down and delivered to nobody because there is no provider,
 * `failed` when a provider refused, `unroutable` when the stored number has no
 * diallable form. `duress_alerts.sms_delivered` counts the FIRST of those four and
 * nothing else.
 *
 * ONE NUMBER'S FAILURE NEVER STOPS THE NEXT. Each send is wrapped on its own, because
 * a thrown provider on the President's number must not silently cost Gemini Dispatch
 * its text.
 *
 * NO RETRY, DELIBERATELY. `$tries = 1`. Text messages have no idempotency key, the
 * provider may well have accepted a message whose connection then timed out, and a
 * retried emergency text is four people told twice — hours later, when it is no longer
 * true. A push may safely buzz twice (`SendExpoPush` says so in its own words); a text
 * that arrives late reads as a NEW emergency. The failure is recorded instead, which
 * is what the ledger is for.
 */
class SendPanicSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** See the class note: an emergency text is never re-sent. */
    public int $tries = 1;

    public function __construct(public readonly int $alertId) {}

    public function handle(SiteEmergencyNumbers $numbers, SmsGateway $sms): void
    {
        $alert = DuressAlert::query()->with('estate')->find($this->alertId);

        if ($alert === null) {
            return;
        }

        $recipients = $numbers->forSite((string) $alert->tenant_id);

        if ($recipients->isEmpty()) {
            $this->recordTotal($alert, 0);

            return;
        }

        $body = PanicSms::body($alert);
        $delivered = 0;

        foreach ($recipients as $recipient) {
            $result = $this->sendOne($sms, $recipient, $body);

            if ($result->delivered()) {
                $delivered++;
            }

            $this->write($alert, $recipient, $result);
        }

        $this->recordTotal($alert, $delivered);
    }

    /**
     * One number, wrapped on its own.
     *
     * A row the platform cannot dial is never handed to a provider — it is recorded as
     * `unroutable`, which is how an office finds out somebody typed a number wrong.
     */
    private function sendOne(SmsGateway $sms, SiteEmergencyNumber $recipient, string $body): SmsResult
    {
        $driver = (string) (config('services.sms.driver') ?: 'log');

        if (! $recipient->isRoutable()) {
            return SmsResult::unroutable($driver);
        }

        try {
            return $sms->send((string) $recipient->e164, $body);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            // A CODE, never the provider's prose: an exception message can quote the
            // request, and the request is the message and the number.
            return SmsResult::failed($driver, class_basename($e));
        }
    }

    /** Append one row for this number. Rescued: the message has already gone. */
    private function write(DuressAlert $alert, SiteEmergencyNumber $recipient, SmsResult $result): void
    {
        rescue(static fn () => AlertSmsDispatch::query()->create([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'audience' => AlertSmsDispatch::SITE,
            'site_emergency_number_id' => (int) $recipient->id,
            'role' => (string) $recipient->role,
            'label' => (string) $recipient->label,
            'number' => (string) $recipient->number,
            'e164' => $recipient->e164,
            'status' => $result->status,
            'driver' => $result->driver,
            'reference' => $result->reference,
            'error' => $result->error,
            'attempted_at' => Carbon::now(),
        ]), report: false);
    }

    /**
     * How many of them a provider actually accepted, onto the alert.
     *
     * Quietly, and with `sms_recipients` refreshed in the same write: the list may have
     * been edited between the press and the send, and the number the resident is shown
     * must describe the attempt that happened rather than the one that was planned.
     *
     * THE SITE'S ROWS ONLY. The household's own contacts are texted by
     * `SendHouseholdContactsSms` into the same ledger (M10 §1.2 item 7), and a sister's
     * text counted here would tell the resident a fifth estate number had been tried.
     */
    private function recordTotal(DuressAlert $alert, int $delivered): void
    {
        rescue(static fn () => $alert->forceFill([
            'sms_recipients' => AlertSmsDispatch::query()
                ->where('duress_alert_id', (int) $alert->id)
                ->where('audience', AlertSmsDispatch::SITE)
                ->count(),
            'sms_delivered' => $delivered,
        ])->saveQuietly(), report: false);
    }
}
