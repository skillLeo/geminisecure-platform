<?php

declare(strict_types=1);

namespace App\Jobs\Alerts;

use App\Models\AlertEmailDispatch;
use App\Models\DuressAlert;
use App\Notifications\PanicContactAlerted;
use App\Services\Dispatch\HouseholdContactsMail;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Platform\OperatorEstates;
use App\Support\SiteTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * ONE EMAIL TO ONE OF A HOUSEHOLD'S EMERGENCY CONTACTS, and a row saying what became of it (M12 §2.5).
 *
 * IT CARRIES TWO IDS AND NO ADDRESS: the address is read from the estate's database when the job runs, so a contact
 * the resident corrected between the press and the send is the one written to, and no private address sits in a job
 * payload or in `failed_jobs`.
 *
 * SENT NOW, INSIDE THE JOB, so the job knows whether the mail server took it. On a refusal it is TRIED AGAIN — three
 * attempts over about four minutes (M12 §5.1) — and only the last outcome is written: `sent` when the server accepted
 * it, `failed` with a code when every attempt was refused. One row per contact, append-only, written AFTER, so the
 * resident's "your emergency contacts were emailed" can only ever be counted from mail that went.
 */
class SendPanicContactEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** An emergency contact of the household (`household_emergency_contacts`). */
    public const CONTACT = 'contact';

    /** A member of the household itself (`residents`, M18C §4.1). */
    public const MEMBER = 'member';

    public int $tries = 3;

    public function __construct(public readonly int $alertId, public readonly int $contactId, public readonly string $kind = self::CONTACT) {}

    /** @return list<int> seconds before the second and third attempts */
    public function backoff(): array
    {
        return [20, 90];
    }

    public function handle(HouseholdContactsMail $mail): void
    {
        $alert = DuressAlert::query()->with('estate')->find($this->alertId);

        // The raise's refusals, asked again: a job replayed from a dead-letter queue must not write to anybody about a
        // guard's duress, a simulated alert or the demonstration estate either.
        if ($alert === null || ! HouseholdContactsSms::appliesTo($alert) || $alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
            return;
        }

        // Written once: a retry that finds its row already there has nothing left to do.
        if ($this->written()) {
            return;
        }

        $contact = $mail->addressOf($alert, $this->contactId, $this->kind);

        if ($contact === null) {
            // Removed, or its address cleared, since the press: nobody to write to, and said so.
            $this->write($alert, null, $this->kind === self::MEMBER ? 'Household member' : 'Emergency contact', null, AlertEmailDispatch::NO_ADDRESS, 'address_gone');

            return;
        }

        $first = (string) (preg_split('/\s+/u', trim((string) $alert->raised_by_name))[0] ?? '');

        Notification::route('mail', $contact['email'])->notifyNow(new PanicContactAlerted(
            firstName: $first === '' ? 'A resident' : mb_substr($first, 0, 24),
            estate: trim((string) ($alert->estate->name ?? $alert->tenant_id)),
            unit: $alert->unit_reference === null ? null : (string) $alert->unit_reference,
            // "27 Sep 2026, 2:14 AM Jamaica time", the one form (Hassam, 12 Oct): the date with its year, because someone
            // reading it at six in the morning must know it was last night, and the clock named, because the mail leaves
            // the estate and an inbox has no banner to say which clock (M19A §8.7).
            when: SiteTime::local($alert->server_time, (string) $alert->tenant_id)->format('j M Y').', '.SiteTime::named($alert->server_time, (string) $alert->tenant_id),
            isDrill: (bool) $alert->is_drill,
            emergencyNumber: HouseholdContactsMail::EMERGENCY_NUMBER,
            // The subject's time, alone: "2:14 AM Jamaica time"; and the date, alone, for the body's first line.
            at: SiteTime::named($alert->server_time, (string) $alert->tenant_id),
            day: SiteTime::local($alert->server_time, (string) $alert->tenant_id)->format('j M Y'),
        ));

        $this->write($alert, $contact['id'], $contact['relationship'], $contact['email'], AlertEmailDispatch::SENT, null);
    }

    /** Every attempt refused: written down as failed, with a code and never the server's prose. */
    public function failed(?Throwable $e): void
    {
        $alert = DuressAlert::query()->find($this->alertId);

        if ($alert === null || $this->written()) {
            return;
        }

        $contact = rescue(fn () => app(HouseholdContactsMail::class)->addressOf($alert, $this->contactId, $this->kind), null, report: false);
        $this->write($alert, $this->contactId, $contact['relationship'] ?? null, $contact['email'] ?? null, AlertEmailDispatch::FAILED, $e === null ? 'failed' : mb_substr(class_basename($e), 0, 64));
    }

    private function written(): bool
    {
        return AlertEmailDispatch::query()
            ->where('duress_alert_id', $this->alertId)
            ->where('audience', AlertEmailDispatch::HOUSEHOLD)
            ->where($this->kind === self::MEMBER ? 'household_member_id' : 'household_emergency_contact_id', $this->contactId)
            ->exists();
    }

    private function write(DuressAlert $alert, ?int $contactId, ?string $relationship, ?string $email, string $status, ?string $error): void
    {
        $label = trim((string) $relationship);
        $member = $this->kind === self::MEMBER;

        rescue(fn () => AlertEmailDispatch::query()->create([
            'duress_alert_id' => (int) $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'audience' => AlertEmailDispatch::HOUSEHOLD,
            'household_emergency_contact_id' => $member ? null : ($contactId ?? $this->contactId),
            'household_member_id' => $member ? ($contactId ?? $this->contactId) : null,
            'label' => mb_substr($label === '' ? ($member ? 'Household member' : 'Emergency contact') : $label, 0, 120),
            'address' => $email === null ? '—' : AlertEmailDispatch::mask($email),
            'status' => $status,
            'error' => $error,
            'attempts' => max(1, $this->attempts()),
            'attempted_at' => Carbon::now(),
        ]), report: false);
    }
}
