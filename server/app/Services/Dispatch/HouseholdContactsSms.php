<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Models\DuressAlert;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Services\Estate\EmergencyContactMerge;
use App\Services\Platform\OperatorEstates;
use App\Support\CentralQueue;
use App\Support\Seeded\SeededRecipients;
use App\Support\SiteTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE HOUSEHOLD'S OWN EMERGENCY CONTACTS ARE TEXTED — M10 §1.2 item 7.
 *
 * "The household's nominated emergency contacts — SMS as already built." It was not.
 * `household_emergency_contacts` has been stored in the estate's database since 13 D3,
 * and until now the only way a resident's sister learned of a panic was the resident's
 * own phone opening its Messages app — the one step the client says a person in a panic
 * will not remember: "The only thing they remember is that they sent a panic alert."
 *
 * A SEPARATE LEG FROM THE SITE'S NUMBERS, AND NAMED SO. `PanicSms` texts the ESTATE's
 * emergency numbers — offices and roles the estate publishes (Q-M09). This texts the
 * RESIDENT's contacts — private people they nominated. Different people, a different
 * message, a different database to read them from, and every count kept apart by the
 * ledger's `audience` column so neither can be read back as the other.
 *
 * NEVER A GUARD'S DURESS. An officer under duress has no household on this platform,
 * and a silent duress in particular must reach nobody outside the control room. The
 * test is the alert itself — no `guard_id`, not `duress` — and it is asked here, in the
 * job, and in `PanicSmsOutcome`, so no single missed check can text anybody about one.
 *
 * AT THE SAME MOMENT AS EVERY OTHER LEG, AND BEHIND NONE OF THEM. `PanicFanOut` calls
 * this alongside the site's texts. All it does synchronously is find the household,
 * count its contacts and queue ONE job on the panic queue — never chained behind the
 * site's job, never behind a mail, never behind a dispatcher. A provider that is slow,
 * broken or absent cannot reach the console, a push, or the site's own texts.
 *
 * ONLY THAT ONE HOUSEHOLD, ONLY IN THAT ONE ESTATE.
 * ================================================
 * The route from a panic to a household is the ACCOUNT that raised it
 * (`duress_alerts.raised_by_account_id`), checked to belong to the alert's own estate,
 * then that account's unit and the unit's household — exactly the resolution
 * `ResidentAccounts::home()` makes for every other Resident App read, so the contacts
 * texted are the contacts the resident sees on their own household screen. Not the
 * unit reference, which is a string a second phase may repeat. The estate's database is
 * opened for the ALERT's tenant and no other, read with `WHERE household_id = ?`, and
 * closed again before any text is sent.
 *
 * AT MOST FIVE, AND THE REST RECORDED. A panic is an alarm to the people closest to
 * the resident, not a broadcast. The first five the household nominated are texted; any
 * beyond are counted onto the alert (`household_sms_over_cap`) so a dispatcher can see
 * that somebody on file was deliberately not texted, rather than wondering why.
 *
 * NOT FROM THE DEMONSTRATION ESTATE, and not for a simulated alert — `PanicSms`'s
 * reason exactly, with a sharper edge: these are real private numbers, and a reviewer
 * pressing a button must never make a stranger's phone say somebody is in danger.
 */
final class HouseholdContactsSms
{
    /** At most this many of a household's contacts are texted for one alert. */
    public const CAP = 5;

    /** One GSM-7 segment, `PanicSms::MAX_LENGTH`'s reasoning. */
    public const MAX_LENGTH = 160;

    /** What a household row in `alert_sms_dispatches` records as its `role`. */
    public const ROLE = 'household_contact';

    /**
     * Whether this alert has a household leg at all.
     *
     * A resident's panic does; an officer's duress never does. Static, so the job and
     * the outcome ask the identical question rather than three similar ones.
     */
    public static function appliesTo(DuressAlert $alert): bool
    {
        return $alert->guard_id === null && (string) $alert->kind !== 'duress';
    }

    /**
     * Count the household's contacts, write the count down, and queue the texts.
     *
     * NULL MEANS THE LEG FAILED, and is kept apart from 0 on purpose. 0 is a fact about
     * the household — nobody on file, or no leg at all on an officer's duress — and a
     * failure recorded as 0 would read, in `duress_alert_deliveries`, as "the household
     * has no emergency contacts" about a household whose database could not be read.
     *
     * @return int|null how many contacts will be texted — NOT how many were delivered —
     *                  or null when the leg could not be queued
     */
    public function raise(DuressAlert $alert): ?int
    {
        if (! self::appliesTo($alert)) {
            // No leg, and the columns stay NULL — "does not apply", which the
            // resident's API says as a null rather than as a zero.
            return 0;
        }

        try {
            // A drill texts none of the household's contacts (M69 SEC-2; M73 §0.2): it wakes nobody outside its officers.
            if ($alert->is_simulated || $alert->is_drill || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
                $this->write($alert, 0, 0, 0);

                return 0;
            }

            // PEOPLE, NOT ROWS (DESIGN §14): one number listed four times is one person on file. And never a person a
            // seeder invented (M35 §5): each one left out is counted as a text not sent.
            $sample = [];
            $onFile = $this->withContacts($alert, function ($contacts) use ($alert, &$sample): int {
                return count(EmergencyContactMerge::distinct($this->real($alert, $contacts->orderBy('id')->get(['id', 'phone', 'seeded_at']), $sample)));
            }) ?? 0;

            foreach ($sample as $id) {
                SeededRecipients::leftOut('sms', 'Panic text to a household contact', (string) $alert->tenant_id, 'household_emergency_contacts', $id);
            }

            if ($onFile === 0) {
                // Nothing to wait for: said as a 0, never left as a null the app polls for ever.
                $this->write($alert, 0, 0, 0);

                return 0;
            }

            $recipients = min($onFile, self::CAP);

            // WRITTEN BEFORE THE JOB IS QUEUED, for `PanicSms::raise()`'s reason: on a
            // synchronous queue the job finishes inside `dispatch()`, and a write after
            // it would put "still texting" back over the job's own final answer.
            $this->write($alert, $recipients, null, $onFile - $recipients);

            /*
             * THE CENTRAL QUEUE, AND THE PANIC QUEUE ON IT — `PanicSms`'s two reasons:
             * an estate database has no `jobs` table (`Support\CentralQueue`), and a
             * panic must not wait behind an estate-wide notice's twenty jobs.
             *
             * THE JOB CARRIES AN ALERT ID AND NOTHING ELSE. Five private phone numbers
             * in a failed job's stored payload would be a leak the ledger does not make.
             */
            $job = CentralQueue::pin(new SendHouseholdContactsSms((int) $alert->id));
            $urgentQueue = config('push.urgent_queue');

            if (is_string($urgentQueue) && $urgentQueue !== '') {
                $job->onQueue($urgentQueue);
            }

            dispatch($job);

            return $recipients;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            /*
             * FINISHED, WITH NOTHING DELIVERED. If the job was never queued nothing will
             * ever come back to finish it, and a null left here would have the panic
             * screen say "still texting your contacts" until the resident gave up on it.
             * 0 is the truth: no text was delivered.
             */
            rescue(fn () => $this->write(
                $alert,
                (int) ($alert->getAttribute('household_sms_recipients') ?? 0),
                0,
                (int) ($alert->getAttribute('household_sms_over_cap') ?? 0),
            ), report: false);

            return null;
        }
    }

    /**
     * The contacts to text, and how many the household had on file.
     *
     * THE FULL NUMBER IS RETURNED TO THE JOB AND GOES NOWHERE ELSE. It is handed to the
     * gateway and then masked onto the ledger; it is never stored centrally, never in a
     * job payload and never in a log. The contact's NAME is not even selected.
     *
     * @return array{contacts: list<array{id: int, relationship: string|null, phone: string}>, on_file: int}
     */
    public function contacts(DuressAlert $alert): array
    {
        if (! self::appliesTo($alert)) {
            return ['contacts' => [], 'on_file' => 0];
        }

        $read = $this->withContacts($alert, function ($contacts) use ($alert): array {
            /*
             * IN THE ORDER THEY WERE NOMINATED. The first five a household added are the
             * ones it thought of first; alphabetical — the household screen's order —
             * would let a surname decide who is told that somebody is in danger.
             *
             * AND FIVE DIFFERENT PEOPLE (DESIGN §14). The first five ROWS were texted, and a
             * household that had saved one number four times had that person texted four
             * times and a fifth never. Copies of a number — compared as a diallable number —
             * are dropped first, keeping the row nominated first, and then five are taken;
             * `on_file` counts people, so the over-cap count does too.
             */
            $people = EmergencyContactMerge::distinct($this->real($alert, $contacts->orderBy('id')->get(['id', 'relationship', 'phone', 'seeded_at'])));
            $onFile = count($people);
            $rows = collect(array_slice($people, 0, self::CAP));

            return [
                'contacts' => $rows->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'relationship' => $row->relationship === null ? null : (string) $row->relationship,
                    'phone' => (string) $row->phone,
                ])->values()->all(),
                'on_file' => $onFile,
            ];
        });

        return $read ?? ['contacts' => [], 'on_file' => 0];
    }

    /**
     * The contacts that are people — never one a seeder invented (M35 §5), who are put into `$sample` by id instead.
     * The SMS driver refuses their numbers as well (`ScreenedSmsGateway`); this keeps "on file" a count of real people.
     *
     * @param  iterable<object>  $rows
     * @param  list<int>  $sample
     * @return list<object>
     */
    private function real(DuressAlert $alert, iterable $rows, array &$sample = []): array
    {
        $tenant = (string) $alert->tenant_id;
        $real = [];

        foreach ($rows as $row) {
            if (SeededRecipients::isSeededEstatePerson($tenant, 'household_emergency_contacts', (int) $row->id, $row->seeded_at ?? null)) {
                $sample[] = (int) $row->id;

                continue;
            }

            $real[] = $row;
        }

        return $real;
    }

    /**
     * WHO, WHERE and WHEN, in words a stranger understands, in one segment.
     *
     *   "GeminiSecure: Andrea raised an emergency alert at Phoenix Park Village 1,
     *    Lot 47, at 9:41 PM. Estate security has been alerted."
     *
     * THE FIRST NAME, AND ONLY THE FIRST NAME. `PanicSms` names nobody, and rightly: the
     * estate's numbers are a control room and a committee, who drive to a lot, not to a
     * person. A household contact is the opposite case. They were nominated BY the
     * resident, they do not know which lot is whose, and "a panic at Lot 47" means
     * nothing to a sister in Montego Bay — so the one thing that makes the text useful
     * is who. A first name is enough for the person who was chosen to recognise; a full
     * name on a lock screen is more than that and is left off.
     *
     * AND NOTHING ELSE. No kind of emergency (the resident may not have said, and a
     * guess would be worse than nothing), no alert number, no link and no code: a text
     * that asks its reader to tap something or quote something is what a scam looks
     * like, and it is one a frightened relative might act on. "Estate security has been
     * alerted" is true by construction — the control room's copy of the alert is the
     * committed row itself, before any of this runs.
     *
     * If the whole would run past one segment, the ESTATE NAME is shortened — never the
     * name, never the lot, never the time.
     */
    public static function body(DuressAlert $alert): string
    {
        $first = (string) (preg_split('/\s+/u', trim((string) $alert->raised_by_name))[0] ?? '');
        $first = $first === '' ? 'A resident' : mb_substr($first, 0, 24);

        $estate = trim((string) ($alert->estate->name ?? $alert->tenant_id));
        $unit = trim((string) ($alert->unit_reference ?? ''));

        // THE ONE CLOCK: stored in UTC, read out on the SITE's clock by its IANA name.
        // "9:38 AM JA" — the contact may be anywhere; the time is the estate's, and says so (M19A §8.7).
        $when = SiteTime::tagged($alert->server_time, (string) $alert->tenant_id);

        // A DRILL SAYS SO FIRST (M10 §1.3), `PanicSms::body()`'s rule: a relative told at
        // 3 AM that somebody raised an emergency alert must not have to guess it was a test.
        $head = 'GeminiSecure: '.$alert->drillPrefix().$first.' raised an emergency alert at ';
        $rest = ($unit === '' ? '' : ', '.$unit).', at '.$when.'.';
        $tail = ' Estate security has been alerted.';

        $room = self::MAX_LENGTH - mb_strlen($head) - mb_strlen($rest) - mb_strlen($tail);

        if ($room < mb_strlen($estate)) {
            // Never below something a person can still recognise.
            $estate = rtrim(mb_substr($estate, 0, max(12, $room)));
        }

        return $head.$estate.$rest.$tail;
    }

    /**
     * Run `$read` against THIS alert's household's contacts, inside THIS alert's estate.
     *
     * NULL WHEN THERE IS NO HOUSEHOLD TO READ — no account, an account of another
     * estate, no unit, no household. Never a guess, never a fallback to "a" household.
     *
     * TENANCY IS PUT BACK EXACTLY AS IT WAS FOUND, in a `finally`, because stancl's own
     * `Tenant::run()` restores nothing when its callback throws — and this runs on the
     * path of a panic, inside a `/api/v1/duress` request whose estate is already open.
     * Opening the same estate again is a no-op; on a queue worker it opens the alert's
     * estate and ends tenancy afterwards.
     *
     * PUBLIC FOR THE MAIL LEG (M12 §2.5, `HouseholdContactsMail`): the same household, found the same way, so the
     * contacts emailed are exactly the contacts texted and the ones the resident sees on their household screen.
     *
     * @template T
     *
     * @param  callable(Builder): T  $read
     * @return T|null
     */
    public function withContacts(DuressAlert $alert, callable $read): mixed
    {
        return $this->inHousehold($alert, $read, false);
    }

    /**
     * THE HOUSEHOLD ITSELF, found the same way (M18C §4.1): `$read` gets the household's id and the raiser's own
     * resident id, inside the estate, so the mail leg can write to the MEMBERS as well as the contacts.
     *
     * @template T
     *
     * @param  callable(int, int|null): T  $read
     * @return T|null
     */
    public function withHousehold(DuressAlert $alert, callable $read): mixed
    {
        return $this->inHousehold($alert, $read, true);
    }

    private function inHousehold(DuressAlert $alert, callable $read, bool $household): mixed
    {
        $tenantId = (string) $alert->tenant_id;
        $accountId = $alert->getAttribute('raised_by_account_id');

        if ($accountId === null || $tenantId === '') {
            return null;
        }

        /*
         * THE ACCOUNT MUST BE THE ALERT'S OWN ESTATE'S. An account id is only unique
         * centrally; checking the estate here means no alert — however it came to be
         * written — can walk from one estate's panic into another estate's household.
         */
        $unitId = ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $accountId)
            ->value('unit_id');

        $estate = $unitId === null ? null : Tenant::query()->find($tenantId);

        if (! $estate instanceof Tenant) {
            return null;
        }

        $original = tenant();

        try {
            tenancy()->initialize($estate);

            // `ResidentAccounts::home()`'s own resolution: the account's unit, and the
            // unit's current household.
            $householdId = Unit::query()->with('household')->find((int) $unitId)?->household?->id;

            if ($householdId === null) {
                return null;
            }

            // A reader of the whole household (M18C §4.1) is handed who it is and whose press this was.
            if ($household) {
                $raiser = ResidentAccount::query()->whereKey((int) $accountId)->value('resident_id');

                return $read((int) $householdId, $raiser === null ? null : (int) $raiser);
            }

            return $read(DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', (int) $householdId));
        } finally {
            if ($original !== null) {
                tenancy()->initialize($original);
            } else {
                tenancy()->end();
            }
        }
    }

    /**
     * The three counts, onto the alert, quietly — `PanicSms::write()`'s reasons: this
     * runs inside the alert's own `created` announcement, and the handset that raised
     * the alert is answered from this same in-memory model.
     */
    private function write(DuressAlert $alert, int $recipients, ?int $delivered, int $overCap): void
    {
        rescue(static fn () => $alert->forceFill([
            'household_sms_recipients' => $recipients,
            'household_sms_delivered' => $delivered,
            'household_sms_over_cap' => $overCap,
        ])->saveQuietly(), report: false);
    }
}
