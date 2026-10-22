<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\PanicSms;
use App\Services\Platform\OperatorEstates;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\JamaicanNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * THE TEXTS TO THE SITE'S TWO HEADS OF SECURITY, sent off the request and written down
 * one by one (M10 §1.2 items 4 and 5).
 *
 * `SendPanicSms`'s and `SendHouseholdContactsSms`'s sibling, for `SendHouseholdContactsSms`'s
 * reason: different people, read from a different place, and a fault here costs these
 * texts and never the site's or the household's. All three are queued at the same moment
 * on the same panic queue, and none is chained behind another.
 *
 * IT CARRIES AN ALERT ID AND NOTHING ELSE, and the plan is made again when it runs, so a
 * Head of Security who corrected their phone between the press and the send is texted at
 * the number they corrected it to.
 *
 * THE MESSAGE IS THE SITE'S OWN (`PanicSms::body()`): where, what, the site's clock and
 * the alert number, names nobody, DRILL first on a drill. A Head of Security is exactly
 * who the alert number is for — it is what they open on the console.
 *
 * ONLY A CONNECTED PROVIDER IS ASKED. While `SmsSettings::canSend()` is false the message
 * goes to the log driver instead — `recorded`, written down and delivered to nobody —
 * which is the honest row, and the one the household's and the site's texts write too.
 *
 * ONE ROW PER HEAD OF SECURITY WITH A PHONE, WRITTEN AFTER THE ATTEMPT, audience `staff`:
 * the user id, the role, the number MASKED, never the name. A number already being
 * texted for this alert is `deduplicated` and not sent. A Head of Security with no phone
 * on file has no row — there is no number to write down — and is counted on the alert.
 *
 * ONE FAILURE NEVER STOPS THE NEXT, and NO RETRY, both for `SendPanicSms`'s reasons.
 */
class SendHeadsOfSecuritySms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** See the class note: an emergency text is never re-sent. */
    public int $tries = 1;

    public function __construct(public readonly int $alertId) {}

    public function handle(HeadsOfSecuritySms $heads, SmsGateway $sms, SmsSettings $settings): void
    {
        $alert = DuressAlert::query()->with('estate')->find($this->alertId);

        if ($alert === null) {
            return;
        }

        // `raise()`'s refusals, asked again: a replayed job must not text anybody about a
        // simulated alert or the demonstration estate either.
        if ($alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
            $this->recordTotal($alert, 0);

            return;
        }

        try {
            $plan = $heads->plan($alert);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
            $this->recordTotal($alert, 0);

            return;
        }

        $gateway = $settings->canSend() ? $sms : new LogSmsGateway;
        $driver = $gateway instanceof LogSmsGateway ? LogSmsGateway::DRIVER : (string) (config('services.sms.driver') ?: LogSmsGateway::DRIVER);

        $body = PanicSms::body($alert);
        $delivered = 0;

        foreach ($plan as $head) {
            if ($head['phone'] === null) {
                continue;
            }

            if ($head['duplicate'] !== null) {
                $this->write($alert, $head, AlertSmsDispatch::DEDUPLICATED, $driver, null, $head['duplicate']);

                continue;
            }

            $result = $head['e164'] === null
                ? SmsResult::unroutable($driver)
                : $this->sendOne($gateway, $driver, $head['e164'], $body);

            if ($result->delivered()) {
                $delivered++;
            }

            $this->write($alert, $head, $result->status, $result->driver, $result->reference, $result->error);
        }

        $this->recordTotal($alert, $delivered);
    }

    /** One Head of Security, wrapped on its own. */
    private function sendOne(SmsGateway $sms, string $driver, string $e164, string $body): SmsResult
    {
        try {
            return $sms->send($e164, $body);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            // A CODE, never the provider's prose: `SendPanicSms::sendOne()`'s reason.
            return SmsResult::failed($driver, class_basename($e));
        }
    }

    /**
     * Append one row for this Head of Security. Rescued: the message has already gone.
     *
     * @param  array{group: string, user_id: int, phone: string|null, e164: string|null, duplicate: string|null, site_number_id: int|null}  $head
     */
    private function write(DuressAlert $alert, array $head, string $status, string $driver, ?string $reference, ?string $error): void
    {
        rescue(static fn () => AlertSmsDispatch::query()->create([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'audience' => AlertSmsDispatch::STAFF,

            // The site number whose text covered a deduplicated Head of Security.
            'site_emergency_number_id' => $head['site_number_id'],
            'household_emergency_contact_id' => null,
            'user_id' => $head['user_id'],

            // The office, never the person.
            'role' => HeadsOfSecuritySms::ROLES[$head['group']],
            'label' => HeadsOfSecuritySms::LABELS[$head['group']],

            // MASKED, and no diallable form: see `AlertSmsDispatch`.
            'number' => JamaicanNumber::mask($head['e164'] ?? $head['phone']),
            'e164' => null,

            'status' => $status,
            'driver' => $driver,
            'reference' => $reference,
            'error' => $error === null ? null : substr($error, 0, 64),
            'attempted_at' => Carbon::now(),
        ]), report: false);
    }

    /**
     * How many a provider actually accepted, onto the alert, with the recipients
     * refreshed from the rows — `SendPanicSms::recordTotal()`'s reason. A deduplicated
     * row is not a recipient; it is a text that was deliberately not sent twice.
     */
    private function recordTotal(DuressAlert $alert, int $delivered): void
    {
        rescue(static function () use ($alert, $delivered): void {
            $rows = AlertSmsDispatch::query()
                ->where('duress_alert_id', (int) $alert->id)
                ->where('audience', AlertSmsDispatch::STAFF);

            $alert->forceFill([
                'staff_sms_recipients' => (clone $rows)->where('status', '!=', AlertSmsDispatch::DEDUPLICATED)->count(),
                'staff_sms_delivered' => $delivered,
                'staff_sms_deduplicated' => (clone $rows)->where('status', AlertSmsDispatch::DEDUPLICATED)->count(),
            ])->saveQuietly();
        }, report: false);
    }
}
