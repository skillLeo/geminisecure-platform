<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Jobs\Alerts\SendHeadsOfSecuritySms;
use App\Models\DuressAlert;
use App\Services\Platform\OperatorEstates;
use App\Support\CentralQueue;
use App\Support\JamaicanNumber;
use Throwable;

/**
 * BOTH HEADS OF SECURITY ARE TEXTED — M10 §1.2 items 4 and 5.
 *
 * "The Gemini Head of Security for that site — dashboard alert plus email (and SMS when
 * a provider exists)." "The estate's own Head of Security — dashboard alert plus
 * email/SMS." The client's own reason is the whole case for the text: "you can't expect
 * a guy to walk around with a mobile web page open." The mail was built; this is the
 * text, to the number each of them gave on §1.4's verification page
 * (`users.contact_phone`).
 *
 * EVERY PANIC, A GUARD'S DURESS INCLUDED. These are the same two people the escalation
 * mail reaches on every alert, and an officer under duress is exactly the emergency a
 * Head of Security exists for. Only the household leg is a resident's alone.
 *
 * WHO, FOUND THE WAY THE MAIL FINDS THEM — AND BESIDE IT, NOT THROUGH IT.
 * ======================================================================
 *   GEMINI   `SiteEscalation::headsOfSecurity()`, read and not changed: every active
 *            Gemini Head of Security who may reach THIS estate (`canAccessEstate`, the
 *            role's assigned sites). A Head of Security assigned only to another estate
 *            is never on this list.
 *   ESTATE   the estate's own Head of Security (§2): `SiteEscalation::estateHeadsOfSecurity()`
 *            — this estate, an active assignment, an active user, and the role or a copy
 *            of it this estate made (by lineage, never by name: an estate is told to copy
 *            the shared role to change it). Read directly rather than filtered out of the
 *            committee list, because a text must not depend on where the role sorts in it.
 *
 * The mail path (`allContactsFor()`) is not touched. Which ADDRESSES it uses is
 * changing under s14; which PHONES this uses is a separate question with its own answer.
 *
 * ONE NUMBER, ONE TEXT. "PPV1 Head of Security" is one of the site's four emergency
 * numbers (Q-M09), and the estate's own Head of Security is very likely that same phone.
 * A number already on the site's list, or already given by the other Head of Security,
 * is not texted a second time — it is written down as `deduplicated`, with the site
 * number that covered it, and counted on the alert so a screen can say so. Compared in
 * E.164, because "1-876-845-9422" and "876 845 9422" are one phone.
 *
 * AT THE SAME MOMENT AS EVERY OTHER LEG, AND BEHIND NONE OF THEM. `PanicFanOut` calls
 * this beside the site's and the household's texts. All it does synchronously is read
 * two short lists and queue ONE job on the panic queue; a provider that is slow, broken
 * or absent cannot reach the console, a push, a mail or another text.
 *
 * ONLY A CONNECTED PROVIDER IS ASKED. With `SmsSettings::canSend()` false the job still
 * writes one row per Head of Security, through the log driver — `recorded`: written
 * down, delivered to nobody — so the record is complete and nothing claims a text.
 *
 * NOT FROM THE DEMONSTRATION ESTATE, and not for a simulated alert: `PanicSms`'s rule.
 */
final class HeadsOfSecuritySms
{
    /** Gemini's Head of Security for the site (§1.2 item 4). */
    public const GEMINI = 'gemini';

    /** The estate's own Head of Security (§1.2 item 5). */
    public const ESTATE = 'estate';

    /** What a staff row in `alert_sms_dispatches` records as its `role`, by group. */
    public const ROLES = [
        self::GEMINI => 'gemini_head_of_security',
        self::ESTATE => 'estate_head_of_security',
    ];

    /** What it records as its `label`: the office, never the person. */
    public const LABELS = [
        self::GEMINI => 'Gemini Head of Security',
        self::ESTATE => 'Estate Head of Security',
    ];

    /** Why a Head of Security was not texted: a site number is the same phone. */
    public const SAME_AS_SITE_NUMBER = 'same_as_site_number';

    /** Why a Head of Security was not texted: the other Head of Security gave the same phone. */
    public const SAME_AS_ANOTHER_HEAD = 'same_as_another_head';

    public function __construct(
        private readonly SiteEscalation $escalation,
        private readonly SiteEmergencyNumbers $numbers,
    ) {}

    /**
     * Plan the texts, write the counts onto the alert, and queue the job.
     *
     * NULL MEANS THE LEG FAILED, and is kept apart from a plan of zeros for the reason
     * `HouseholdContactsSms::raise()` gives: a failure must not be recorded as "no Head of
     * Security on file".
     *
     * @return array<string, array{heads: int, texting: int, no_phone: int, deduplicated: int}>|null
     */
    public function raise(DuressAlert $alert): ?array
    {
        try {
            // A drill texts no Head of Security (M69 SEC-2; M73 §0.2): it wakes nobody outside its officers.
            if ($alert->is_simulated || $alert->is_drill || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
                $this->write($alert, 0, 0, 0, 0);

                return self::summarise([]);
            }

            $plan = $this->plan($alert);
            $summary = self::summarise($plan);

            $texting = array_sum(array_column($summary, 'texting'));
            $noPhone = array_sum(array_column($summary, 'no_phone'));
            $deduplicated = array_sum(array_column($summary, 'deduplicated'));

            if ($texting + $deduplicated === 0) {
                // Nothing to wait for: said as a 0, never left as a null the screen waits on.
                $this->write($alert, 0, 0, $noPhone, 0);

                return $summary;
            }

            // Written BEFORE the job is queued: on a synchronous queue the job finishes
            // inside `dispatch()`, and a write after it would undo its final answer.
            $this->write($alert, $texting, null, $noPhone, $deduplicated);

            /*
             * THE CENTRAL QUEUE, AND THE PANIC QUEUE ON IT — `PanicSms`'s two reasons.
             * THE JOB CARRIES AN ALERT ID AND NOTHING ELSE: no phone number in a payload.
             */
            $job = CentralQueue::pin(new SendHeadsOfSecuritySms((int) $alert->id));
            $urgentQueue = config('push.urgent_queue');

            if (is_string($urgentQueue) && $urgentQueue !== '') {
                $job->onQueue($urgentQueue);
            }

            dispatch($job);

            return $summary;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            // FINISHED, WITH NOTHING DELIVERED — a null would read "still texting" for ever.
            rescue(fn () => $this->write(
                $alert,
                (int) ($alert->getAttribute('staff_sms_recipients') ?? 0),
                0,
                (int) ($alert->getAttribute('staff_sms_no_phone') ?? 0),
                (int) ($alert->getAttribute('staff_sms_deduplicated') ?? 0),
            ), report: false);

            return null;
        }
    }

    /**
     * Every Head of Security this alert concerns, in the order they are texted, with the
     * decision about each: text them, or not — and why not.
     *
     * THE FULL NUMBER GOES TO THE JOB AND NOWHERE ELSE. It is handed to the gateway and
     * masked onto the ledger; it is never in a payload, a log or a central copy.
     *
     * @return list<array{group: string, user_id: int, phone: string|null, e164: string|null, duplicate: string|null, site_number_id: int|null}>
     */
    public function plan(DuressAlert $alert): array
    {
        $tenantId = (string) $alert->tenant_id;

        if ($tenantId === '') {
            return [];
        }

        /*
         * THE SITE'S OWN NUMBERS, by their diallable form. Only a routable one can be
         * the same phone; an unroutable site number is texted to nobody, so it cannot
         * stand in for a Head of Security.
         */
        /** @var array<string, int> $site */
        $site = [];

        foreach ($this->numbers->forSite($tenantId) as $number) {
            if ($number->isRoutable()) {
                $site[(string) $number->e164] ??= (int) $number->id;
            }
        }

        $groups = [
            self::GEMINI => $this->escalation->headsOfSecurity($tenantId),
            self::ESTATE => $this->escalation->estateHeadsOfSecurity($tenantId),
        ];

        $plan = [];
        $seenUsers = [];
        $seenNumbers = [];

        foreach ($groups as $group => $users) {
            foreach ($users as $user) {
                $id = (int) $user->getKey();

                if (isset($seenUsers[$id])) {
                    continue;
                }

                $seenUsers[$id] = true;

                $phone = trim((string) $user->getAttribute('contact_phone'));
                $e164 = $phone === '' ? null : JamaicanNumber::e164($phone);

                $duplicate = null;
                $siteNumberId = null;

                if ($e164 !== null) {
                    if (isset($site[$e164])) {
                        $duplicate = self::SAME_AS_SITE_NUMBER;
                        $siteNumberId = $site[$e164];
                    } elseif (isset($seenNumbers[$e164])) {
                        $duplicate = self::SAME_AS_ANOTHER_HEAD;
                    } else {
                        $seenNumbers[$e164] = true;
                    }
                }

                $plan[] = [
                    'group' => $group,
                    'user_id' => $id,
                    'phone' => $phone === '' ? null : $phone,
                    'e164' => $e164,
                    'duplicate' => $duplicate,
                    'site_number_id' => $siteNumberId,
                ];
            }
        }

        return $plan;
    }

    /**
     * The plan, counted per group — what the timing recorder and the alert are told.
     *
     * A phone that is on file but cannot be dialled is still `texting`: it gets a row,
     * `unroutable`, exactly as a site number typed wrong does, because that is how
     * somebody finds out.
     *
     * @param  list<array{group: string, user_id: int, phone: string|null, e164: string|null, duplicate: string|null, site_number_id: int|null}>  $plan
     * @return array<string, array{heads: int, texting: int, no_phone: int, deduplicated: int}>
     */
    public static function summarise(array $plan): array
    {
        $summary = [];

        foreach ([self::GEMINI, self::ESTATE] as $group) {
            $rows = array_values(array_filter($plan, static fn (array $row): bool => $row['group'] === $group));

            $summary[$group] = [
                'heads' => count($rows),
                'texting' => count(array_filter($rows, static fn (array $row): bool => $row['phone'] !== null && $row['duplicate'] === null)),
                'no_phone' => count(array_filter($rows, static fn (array $row): bool => $row['phone'] === null)),
                'deduplicated' => count(array_filter($rows, static fn (array $row): bool => $row['duplicate'] !== null)),
            ];
        }

        return $summary;
    }

    /**
     * The four counts, onto the alert, quietly — `PanicSms::write()`'s reasons.
     */
    private function write(DuressAlert $alert, int $recipients, ?int $delivered, int $noPhone, int $deduplicated): void
    {
        rescue(static fn () => $alert->forceFill([
            'staff_sms_recipients' => $recipients,
            'staff_sms_delivered' => $delivered,
            'staff_sms_no_phone' => $noPhone,
            'staff_sms_deduplicated' => $deduplicated,
        ])->saveQuietly(), report: false);
    }
}
