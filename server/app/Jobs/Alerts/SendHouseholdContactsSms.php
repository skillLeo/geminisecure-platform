<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Platform\OperatorEstates;
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
 * THE TEXTS TO A HOUSEHOLD'S OWN EMERGENCY CONTACTS, sent off the request and written
 * down one by one (M10 §1.2 item 7).
 *
 * `SendPanicSms`'s sibling, and deliberately NOT the same job with a second list. The
 * site's numbers are central rows an office published; these are private people the
 * resident nominated, read out of the ESTATE's database. Kept apart, a broken estate
 * database costs the household's texts and never the site's, and neither job can hold
 * the other up — both are queued at the same moment on the same panic queue, and
 * neither is chained behind the other.
 *
 * IT CARRIES AN ALERT ID AND NOTHING ELSE, and the numbers are read when it runs. A
 * contact the resident corrected between the press and the send is the one texted,
 * and no phone number ever sits in a job payload or in `failed_jobs`.
 *
 * ONE ROW PER CONTACT, WRITTEN AFTER THE ATTEMPT, audience `household`. The row holds
 * the relationship the resident gave and the number MASKED — never the contact's name
 * and never the full number, which stays in the estate's database where it already
 * was (`AlertSmsDispatch`). The statuses are `SmsResult`'s, unchanged: `sent` only when
 * a provider accepted it, `recorded` under the log driver, which delivered it to nobody.
 *
 * ONE CONTACT'S FAILURE NEVER STOPS THE NEXT, and NO RETRY, both for `SendPanicSms`'s
 * reasons — and the second one matters more here: a retried text to a mother, an hour
 * late, reads as a second emergency.
 */
class SendHouseholdContactsSms implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** See the class note: an emergency text is never re-sent. */
    public int $tries = 1;

    public function __construct(public readonly int $alertId) {}

    public function handle(HouseholdContactsSms $household, SmsGateway $sms): void
    {
        $alert = DuressAlert::query()->with('estate')->find($this->alertId);

        // THE SAME REFUSALS AS `HouseholdContactsSms::raise()`, asked again here: a job
        // replayed from a dead-letter queue must not text a household about a guard's
        // duress, a simulated alert or the demonstration estate either.
        if ($alert === null || ! HouseholdContactsSms::appliesTo($alert)) {
            return;
        }

        if ($alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
            $this->recordTotal($alert, 0, 0);

            return;
        }

        try {
            $read = $household->contacts($alert);
        } catch (Throwable $e) {
            // The estate's database could not be read. Nobody was texted, and the
            // alert says so as a finished 0 rather than "still texting" for ever.
            rescue(static fn () => report($e), report: false);
            $this->recordTotal($alert, 0, 0);

            return;
        }

        $overCap = max(0, $read['on_file'] - count($read['contacts']));

        if ($read['contacts'] === []) {
            $this->recordTotal($alert, 0, $overCap);

            return;
        }

        $body = HouseholdContactsSms::body($alert);
        $delivered = 0;

        foreach ($read['contacts'] as $contact) {
            $e164 = JamaicanNumber::e164($contact['phone']);
            $result = $this->sendOne($sms, $e164, $body);

            if ($result->delivered()) {
                $delivered++;
            }

            $this->write($alert, $contact, $e164, $result);
        }

        $this->recordTotal($alert, $delivered, $overCap);
    }

    /**
     * One contact, wrapped on its own. A number the platform cannot dial is never
     * handed to a provider — it is `unroutable`, which is how a household finds out
     * it typed a number wrong.
     */
    private function sendOne(SmsGateway $sms, ?string $e164, string $body): SmsResult
    {
        $driver = (string) (config('services.sms.driver') ?: 'log');

        if ($e164 === null) {
            return SmsResult::unroutable($driver);
        }

        try {
            return $sms->send($e164, $body);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            // A CODE, never the provider's prose: an exception message can quote the
            // request, and the request is the message and the number.
            return SmsResult::failed($driver, class_basename($e));
        }
    }

    /**
     * Append one row for this contact. Rescued: the message has already gone.
     *
     * @param  array{id: int, relationship: string|null, phone: string}  $contact
     */
    private function write(DuressAlert $alert, array $contact, ?string $e164, SmsResult $result): void
    {
        $relationship = trim((string) $contact['relationship']);

        rescue(static fn () => AlertSmsDispatch::query()->create([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'audience' => AlertSmsDispatch::HOUSEHOLD,
            'site_emergency_number_id' => null,
            'household_emergency_contact_id' => $contact['id'],
            'role' => HouseholdContactsSms::ROLE,

            // What the contact IS to the resident, as the resident wrote it — never who.
            'label' => mb_substr($relationship === '' ? 'Emergency contact' : $relationship, 0, 120),

            // MASKED, and no diallable form: see `AlertSmsDispatch`.
            'number' => JamaicanNumber::mask($e164 ?? $contact['phone']),
            'e164' => null,

            'status' => $result->status,
            'driver' => $result->driver,
            'reference' => $result->reference,
            'error' => $result->error,
            'attempted_at' => Carbon::now(),
        ]), report: false);
    }

    /**
     * How many a provider actually accepted, onto the alert — with the recipients
     * refreshed from the rows, `SendPanicSms::recordTotal()`'s reason: the number the
     * resident is shown describes the attempt that happened, not the one planned.
     */
    private function recordTotal(DuressAlert $alert, int $delivered, int $overCap): void
    {
        rescue(static fn () => $alert->forceFill([
            'household_sms_recipients' => AlertSmsDispatch::query()
                ->where('duress_alert_id', (int) $alert->id)
                ->where('audience', AlertSmsDispatch::HOUSEHOLD)
                ->count(),
            'household_sms_delivered' => $delivered,
            'household_sms_over_cap' => $overCap,
        ])->saveQuietly(), report: false);
    }
}
