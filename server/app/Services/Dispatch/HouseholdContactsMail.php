<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Jobs\Alerts\SendPanicContactEmail;
use App\Models\DuressAlert;
use App\Models\Estate\Resident;
use App\Notifications\PanicContactAlerted;
use App\Services\Platform\MailLog;
use App\Services\Platform\OperatorEstates;
use App\Support\CentralQueue;
use App\Support\Seeded\SeededRecipients;
use Throwable;

/**
 * THE HOUSEHOLD'S OWN EMERGENCY CONTACTS ARE EMAILED (M12 §2.5).
 *
 * "Emergency contacts take a phone number and an email. Panic emails them and the estate immediately; the number is
 * there for the dispatcher to call." The estate is emailed on every panic already (`PanicFanOut`'s escalation mail,
 * `escalated_contacts`). The household's contacts were only ever texted — and no SMS provider is connected, so in
 * practice nobody was told. An email goes today.
 *
 * `HouseholdContactsSms`'s sibling, on its rules:
 *   - A RESIDENT'S PANIC ONLY (`HouseholdContactsSms::appliesTo`): an officer's duress tells no household.
 *   - NOT FROM THE DEMONSTRATION ESTATE, NOT FOR A SIMULATED ALERT: real private addresses, and a reviewer pressing a
 *     button must never make a stranger's inbox say somebody is in danger.
 *   - THAT HOUSEHOLD, IN THAT ESTATE, found the way the texts find it (`withContacts`).
 *   - AND THE HOUSEHOLD'S OWN MEMBERS (M18C §4.1: every stored email is used), never the person who pressed.
 *   - AT MOST FIVE CONTACTS AND TEN MEMBERS, each address once (a sister listed twice is emailed once).
 *   - BEHIND NOTHING. All it does here is count and queue one job per contact on the panic queue; the job carries an
 *     alert id and a contact id and no address, and reads the address when it runs.
 *
 * ONE JOB PER CONTACT, and that is what makes a RETRY safe here when it is not for a text (`SendHouseholdContactsSms`
 * never retries: a text an hour late reads as a second emergency). A mail server that refuses for a moment is
 * retried within minutes (M12 §5.1's backoff), and a retry can only ever resend the ONE mail that did not go —
 * never the four that did.
 */
final class HouseholdContactsMail
{
    /** At most this many of a household's contacts are emailed for one alert — the texts' cap. */
    public const CAP = HouseholdContactsSms::CAP;

    /** And at most this many of the household's own members (M18C §4.1) — a household is rarely larger. */
    public const MEMBER_CAP = 10;

    /** Jamaica's police, as the phone's panic screen names them (the apps' EMERGENCY_NUMBER). */
    public const EMERGENCY_NUMBER = '119';

    public function __construct(private readonly HouseholdContactsSms $household) {}

    /**
     * Count the household's contacts with an address, write the count down, and queue one mail each.
     *
     * @return int|null how many contacts will be emailed — NOT how many were — or null when the leg failed
     */
    public function raise(DuressAlert $alert): ?int
    {
        if (! HouseholdContactsSms::appliesTo($alert)) {
            return 0;
        }

        try {
            // A drill emails none of the household's contacts (M69 SEC-2; M73 §0.2): it wakes nobody outside its officers.
            if ($alert->is_simulated || $alert->is_drill || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
                $this->write($alert, 0);

                return 0;
            }

            $sample = [];
            $contacts = $this->contacts($alert, $sample);
            $this->write($alert, count($contacts));

            // Nobody a seeder invented is written to (M35 §5): each one left out is counted as a message not sent.
            foreach ($sample as [$table, $id]) {
                SeededRecipients::leftOut('mail', MailLog::typeOf(PanicContactAlerted::class), (string) $alert->tenant_id, $table, $id);
            }

            foreach ($contacts as $contact) {
                $job = CentralQueue::pin(new SendPanicContactEmail((int) $alert->id, $contact['id'], $contact['kind']));
                $urgentQueue = config('push.urgent_queue');

                if (is_string($urgentQueue) && $urgentQueue !== '') {
                    $job->onQueue($urgentQueue);
                }

                dispatch($job);
            }

            return count($contacts);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return null;
        }
    }

    /**
     * EVERYBODY TO EMAIL (M12 §2.5, M18C §4.1): the household's emergency contacts in the order they were nominated
     * (at most five), then the household's own MEMBERS — every one with an email of their own, never the person who
     * pressed (at most ten). Each ADDRESS once: a member who is also listed as a contact is written to once. A member
     * whose alerts go to the primary resident has no address here; the primary is a member and is written to.
     *
     * NEVER A PERSON A SEEDER INVENTED (M35 §5). The demonstration seed gave residents addresses on a live public mail
     * service, and a real household sits in a seeded one (Lot 47: the seed's Andrea Fletcher beside a real owner). Such
     * a person is left out here — into `$sample`, [table, id] — so the count the resident and the dispatcher are shown
     * is of real people only. The mail transport refuses them as well (`SeededRecipients`); this is what keeps the count
     * honest.
     *
     * @param  list<array{0: string, 1: int}>  $sample
     * @return list<array{id: int, kind: string, relationship: string|null, email: string}>
     */
    public function contacts(DuressAlert $alert, array &$sample = []): array
    {
        if (! HouseholdContactsSms::appliesTo($alert)) {
            return [];
        }

        $rows = $this->household->withContacts($alert, static fn ($contacts) => $contacts
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('id')
            ->get(['id', 'relationship', 'email', 'seeded_at'])) ?? collect();

        $members = $this->household->withHousehold($alert, static fn (int $household, ?int $raiser) => Resident::query()
            ->where('household_id', $household)
            ->when($raiser !== null, static fn ($q) => $q->whereKeyNot($raiser))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id', 'relationship', 'email', 'seeded_at'])) ?? collect();

        $tenant = (string) $alert->tenant_id;
        $seen = [];
        $out = [];
        $take = static function (iterable $people, string $kind, int $cap) use (&$seen, &$out, &$sample, $tenant): void {
            $taken = 0;
            $table = $kind === SendPanicContactEmail::MEMBER ? 'residents' : 'household_emergency_contacts';

            foreach ($people as $row) {
                $email = trim((string) $row->email);
                $key = mb_strtolower($email);

                if ($email === '' || isset($seen[$key]) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }

                if (SeededRecipients::isSeededEstatePerson($tenant, $table, (int) $row->id, $row->seeded_at ?? null)) {
                    $sample[] = [$table, (int) $row->id];

                    continue;
                }

                $seen[$key] = true;
                $out[] = ['id' => (int) $row->id, 'kind' => $kind, 'relationship' => $row->relationship === null ? null : (string) $row->relationship, 'email' => $email];

                if (++$taken === $cap) {
                    return;
                }
            }
        };

        $take($rows, SendPanicContactEmail::CONTACT, self::CAP);
        $take($members, SendPanicContactEmail::MEMBER, self::MEMBER_CAP);

        return $out;
    }

    /**
     * One person's address, read when the mail is sent — the one the household has now, not the one at the press.
     *
     * @return array{id: int, kind: string, relationship: string|null, email: string}|null
     */
    public function addressOf(DuressAlert $alert, int $contactId, string $kind = SendPanicContactEmail::CONTACT): ?array
    {
        foreach ($this->contacts($alert) as $contact) {
            if ($contact['id'] === $contactId && $contact['kind'] === $kind) {
                return $contact;
            }
        }

        return null;
    }

    /** Quietly, `HouseholdContactsSms::write()`'s reason: this runs inside the alert's own announcement. */
    private function write(DuressAlert $alert, int $recipients): void
    {
        rescue(static fn () => $alert->forceFill(['household_email_recipients' => $recipients])->saveQuietly(), report: false);
    }
}
