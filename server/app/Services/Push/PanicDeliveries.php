<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery as Leg;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Models\User;
use App\Services\Dispatch\HeadsOfSecuritySms;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Dispatch\SiteEmergencyNumbers;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Platform\OperatorEstates;
use App\Services\Platform\SmsSettings;
use Illuminate\Support\Carbon;
use Throwable;
use WeakMap;

/**
 * HOW FAST EACH DESTINATION WAS HANDED A PANIC — measured in memory, written once (M10 §1.3).
 *
 * "Measure and record delivery time to each destination." `PanicFanOut` already timed
 * itself as a whole (`fanout_ms`). This times every LEG of an alert's announcement against
 * one clock, so that a Head of Security can be told the consoles had it at 3 ms, the guards
 * were pushed at 12 ms and their own mail queued at 140 ms — and so that `panic:walk` can
 * say which of §1.2's seven destinations were not reached at all, and why.
 *
 * WHAT IS MEASURED IS A HAND-OFF, NEVER AN ARRIVAL. The clock starts when the platform
 * begins telling anyone about the alert (`begin()`, just before the live broadcast, which
 * goes first), and each leg is stamped the moment the platform LET GO of it: the
 * broadcast accepted by the socket server, the push jobs written, the mail notification
 * queued, the text job queued. Expo, the carrier, the mail relay and the phone are all
 * downstream and none of them answers synchronously, so none of them is in these
 * numbers and no screen built on them may say "delivered".
 *
 * `fanout_ms` IS A DIFFERENT, SMALLER CLOCK and keeps its old meaning: the fan-out's own
 * share, from entering `PanicFanOut::forAlert()` to recording its outcome. It starts after
 * the broadcast, so a leg's `elapsed_ms` can be larger than it and still be right.
 *
 * NOTHING HERE MAY THROW OR DELAY THE PANIC.
 * ==========================================
 *   - DURING the fan-out, each leg costs one `hrtime` call and an array write. No query,
 *     no classification, no I/O: the facts are kept raw (a count, a list of the people
 *     mailed) and turned into rows only once every leg has gone.
 *   - AFTER every leg has gone, the rows are built and written in ONE insert — and, if the
 *     database refuses that statement, one row at a time, so a single row it will not take
 *     cannot cost the alert every other leg's record (bug hunt B091). That write
 *     is the platform's bookkeeping, not anybody being told, so it comes after the last
 *     leg — the texts — and never sits in front of any destination. (It does go before
 *     the reach broadcast, which tells nobody new, so a screen that re-reads the server
 *     on that broadcast finds the record written.)
 *   - Every public method swallows its own failure the way `PanicFanOut::record()` does,
 *     and reports it. A delivery record that failed to write is a gap in a report; a
 *     panic that failed because its report could not be written would be unforgivable.
 *   - It never row-locks. The table is append-only, and `SELECT … FOR UPDATE` needs the
 *     UPDATE privilege that append-only withholds.
 *
 * ONE PLACE DRIVES IT, IN §1.2's ORDER. `PushServiceProvider::announceAlert()` runs once
 * per committed alert: `begin()`, the live broadcast (stamped by `broadcast()`), the
 * fan-out (which stamps its own legs between `start()` and `fanOutFinished()`), then
 * `settle()`, which writes the lot, and last the reach broadcast — which is NOT a
 * destination and is never stamped. Nothing writes before `settle()`, so the record is
 * one statement and never lands between two legs.
 *
 * A FAN-OUT THAT DIED PART-WAY is still written: `settle()` runs whatever happened above
 * it, and what the fan-out did manage is worth writing down. An alert whose fan-out never
 * STARTED — a simulated one, which `PushAnnouncer` sends to nobody — writes nothing,
 * exactly as before, and `DeliveryReport` says why.
 *
 * KEYED ON THE ALERT OBJECT, IN A WEAKMAP. Every caller holds the same model instance
 * (the `created` event hands over `$this`), so the object is the key; and a WeakMap
 * forgets an entry when the model is garbage-collected, so an alert that is never settled
 * — a fan-out called directly, as some tests do — leaves nothing behind in a worker.
 *
 * NOT FINAL, and only for one reason: the test that proves a failure to record does not
 * break the panic replaces `insert()` with one that throws.
 */
class PanicDeliveries
{
    /**
     * Where a site emergency number's text is attributed, by the number's `role`.
     *
     * The numbers are PEOPLE, and §1.2 names people: the President and the property
     * office are the estate's committee, "PPV1 Head of Security" is the estate's own
     * Head of Security (§2 — a different person from Gemini's), and Gemini Dispatch is
     * dispatch. They are NOT the household's emergency contacts, and filing them under
     * that destination would count the estate's four as the resident's own people. The
     * household's contacts have a leg of their own (`householdLeg()`), read from what
     * that leg wrote onto the alert, and neither is ever counted as the other.
     *
     * @var array<string, string>
     */
    public const SMS_ROLE_DESTINATIONS = [
        'president' => Leg::ESTATE_CONSOLE,
        'property_office' => Leg::ESTATE_CONSOLE,
        'head_of_security' => Leg::ESTATE_HEAD_OF_SECURITY,
        'dispatch' => Leg::DISPATCHER_CONSOLE,
    ];

    /**
     * How a Head of Security's OWN text begins its note (M10 §1.2 items 4 and 5).
     *
     * The estate's Head of Security can have two SMS legs on one alert: the site number
     * with the `head_of_security` role, above, and the text to the phone they gave on
     * §1.4's page. Both are `estate_head_of_security` / `sms`; the note is what tells a
     * reader — and a test — which is which, so it says so in its first words.
     */
    public const OWN_PHONE = 'Their own phone — ';

    /**
     * The three destinations the alert mail is meant for, which always get a row — so a
     * site with no Head of Security on file says "nobody to tell" instead of saying
     * nothing.
     */
    private const MAIL_DESTINATIONS = [
        Leg::GEMINI_HEAD_OF_SECURITY,
        Leg::ESTATE_HEAD_OF_SECURITY,
        Leg::ESTATE_CONSOLE,
    ];

    /** @var WeakMap<DuressAlert, array<string, mixed>> */
    private WeakMap $clocks;

    public function __construct(private readonly SiteEmergencyNumbers $numbers)
    {
        $this->clocks = new WeakMap;
    }

    /* ------------------------------------------------------- the whole announcement */

    /**
     * The platform starts telling people about this alert. Every leg is timed from here.
     *
     * Called before the live broadcast, which goes first. Starting again for an alert
     * already begun is ignored: the earliest moment is the true one.
     */
    public function begin(DuressAlert $alert): void
    {
        $at = hrtime(true);

        if (! $this->has($alert, 'begun')) {
            $this->remember($alert, 'begun', ['at' => $at]);
        }
    }

    /**
     * The live broadcast was handed to the socket server — or refused. A stamp, never a write.
     */
    public function broadcast(DuressAlert $alert, bool $accepted): void
    {
        $this->stamp($alert, 'broadcast', ['accepted' => $accepted]);
    }

    /**
     * Every leg has gone: write the record, in one insert, and forget the alert. Writes
     * nothing if the fan-out never started (a simulated alert).
     */
    public function settle(DuressAlert $alert): void
    {
        $this->write($alert);
    }

    /* ------------------------------------------------------ the fan-out side */

    /**
     * The fan-out's own clock started, at this `hrtime` — the one `fanout_ms` is measured
     * from. It also marks that the fan-out RAN, which is what `settle()` writes on. For a
     * fan-out nobody `begin()`-ed (called directly), it is the clock the legs are timed on.
     */
    public function start(DuressAlert $alert, int $startedAt): void
    {
        $this->remember($alert, 'fanout', ['at' => $startedAt]);
    }

    /** The guards' push was handed off: this many officers were on the list, this many handsets queued. */
    public function pushed(DuressAlert $alert, int $officers, int $handsets): void
    {
        $this->stamp($alert, 'push', ['officers' => $officers, 'handsets' => $handsets]);
    }

    /**
     * The alert mail was handed off to exactly these people — possibly nobody.
     *
     * The users themselves are kept, not a count, so the rows can say which of §1.2's
     * destinations each one was; that is worked out AFTER every leg has gone, never here.
     *
     * `$contacts` IS WHO WAS MAILED AND NOBODY ELSE. `$unverified` is who the mail was
     * meant for and was not sent to, because they have no proven address (M10 §1.4);
     * they are filed under the same destinations, as a `skipped` row that says so, and
     * never counted among the people told.
     *
     * @param  iterable<int, User>  $contacts
     * @param  iterable<int, User>  $unverified
     */
    public function mailed(DuressAlert $alert, iterable $contacts, iterable $unverified = []): void
    {
        $this->stamp($alert, 'mail', ['ids' => self::ids($contacts), 'unverified_ids' => self::ids($unverified)]);
    }

    /** The alert mail was NOT handed off: `skipped` on purpose, or `failed`. */
    public function notMailed(DuressAlert $alert, string $outcome, string $why): void
    {
        $this->stamp($alert, 'mail', ['outcome' => $outcome, 'why' => $why]);
    }

    /** The texts were handed off — `PanicSms::raise()` answered with this many numbers. */
    public function texted(DuressAlert $alert, int $numbers): void
    {
        $this->stamp($alert, 'sms', ['numbers' => $numbers]);
    }

    /**
     * The household's own emergency contacts (§1.2 item 7) — `HouseholdContactsSms::raise()`
     * answered with this many contacts queued, or NULL when the leg failed.
     *
     * The count alone is kept here; whether it was an officer's duress, a household with
     * nobody on file, or a platform with no provider is read off the alert afterwards,
     * because the leg has already written those facts onto it.
     */
    public function householdTexted(DuressAlert $alert, ?int $contacts): void
    {
        $this->stamp($alert, 'household', ['contacts' => $contacts]);
    }

    /**
     * Both Heads of Security by text (§1.2 items 4 and 5) — `HeadsOfSecuritySms::raise()`
     * answered with its plan, counted per group, or NULL when the leg failed.
     *
     * @param  array<string, array{heads: int, texting: int, no_phone: int, deduplicated: int}>|null  $plan
     */
    public function headsTexted(DuressAlert $alert, ?array $plan): void
    {
        $this->stamp($alert, 'heads', ['plan' => $plan]);
    }

    /** Every leg of the fan-out has gone. A stamp: `settle()` is what writes. */
    public function fanOutFinished(DuressAlert $alert): void
    {
        $this->remember($alert, 'finished', ['at' => hrtime(true)]);
    }

    /* ------------------------------------------------------ the ring's rounds */

    /**
     * ONE ROUND OF THE RING, AS A LEG OF ITS OWN (the guard ring §4.5) — "Ring 2 (escalation):
     * 14 handsets of 11 officers…".
     *
     * NOT THROUGH THE WEAKMAP. The raise is one fan-out, stamped in memory and written once;
     * a round is a later job in another process with nothing in memory to settle. So this is
     * one direct insert, the same row shape plus `round`, and nothing the raise writes is
     * touched. `elapsed_ms` is measured from the alert's `server_time`, on the wall clock —
     * the round's hand-off after the raise, still never an arrival.
     *
     * A ROUND LEG NEVER MAKES ITS DESTINATION "REACHED" (G13): `DeliveryReport` counts reached
     * from the raise's legs only, and lists these under it. Never throws; a failure is reported.
     */
    public function round(DuressAlert $alert, int $round, string $destination, string $channel, int $recipients, string $outcome, string $note): void
    {
        try {
            $now = Carbon::now();

            $this->insert([[
                'duress_alert_id' => (int) $alert->getKey(),
                'tenant_id' => (string) $alert->tenant_id,
                'destination' => $destination,
                'round' => max(0, $round),
                'channel' => $channel,
                'recipients' => max(0, $recipients),
                'outcome' => $outcome,
                'elapsed_ms' => (int) max(0, min(4_294_967_295, $alert->server_time->diffInMilliseconds($now, false))),
                'note' => mb_strimwidth($note, 0, Leg::NOTE_LENGTH, '…'),
                'created_at' => $now,
            ]]);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }

    /* ---------------------------------------------------------------- write */

    /**
     * One insert, every leg. Protected so the failure test can make it throw.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insert(array $rows): void
    {
        Leg::query()->insert($rows);
    }

    private function write(DuressAlert $alert): void
    {
        try {
            $clock = $this->clocks[$alert] ?? null;

            // Forgotten BEFORE the write, so a write that throws is never retried on
            // the path of a panic.
            unset($this->clocks[$alert]);

            // No fan-out, nothing to time: a simulated alert is sent to nobody.
            if (! is_array($clock) || ! isset($clock['fanout'])) {
                return;
            }

            $rows = $this->rows($alert, $clock);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return;
        }

        if ($rows === []) {
            return;
        }

        try {
            $this->insert($rows);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            /*
             * ONE BAD ROW MUST NOT TAKE THE OTHERS WITH IT (bug hunt B091). The single
             * statement is refused whole, so a note too long for its column — or any
             * one row the database will not take — used to cost the alert its record
             * for all seven destinations. Each row is written on its own instead, and
             * each one that is still refused is reported: the record then has a gap
             * where that leg is, which `DeliveryReport` shows as a destination with no
             * row, rather than no record at all. Still after every leg has gone.
             */
            foreach ($rows as $row) {
                try {
                    $this->insert([$row]);
                } catch (Throwable $one) {
                    rescue(static fn () => report($one), report: false);
                }
            }
        }
    }

    /**
     * The raw facts, turned into §1.2's destinations. Runs once, after every hand-off.
     *
     * @param  array<string, mixed>  $clock
     * @return list<array<string, mixed>>
     */
    private function rows(DuressAlert $alert, array $clock): array
    {
        // From the moment the platform began telling anyone; the fan-out's own start
        // only when nothing `begin()`-ed the alert.
        $startedAt = (int) ($clock['begun']['at'] ?? $clock['fanout']['at']);
        $tenantId = (string) $alert->tenant_id;
        $demonstration = OperatorEstates::isDemonstration($tenantId);
        $now = Carbon::now();

        $rows = [];

        $row = static function (string $destination, string $channel, int $recipients, string $outcome, int $at, ?string $note) use ($alert, $tenantId, $startedAt, $now): array {
            return [
                'duress_alert_id' => (int) $alert->getKey(),
                'tenant_id' => $tenantId,
                'destination' => $destination,
                'channel' => $channel,
                'recipients' => max(0, $recipients),
                'outcome' => $outcome,

                // Never negative: every leg is stamped after `begin()`, and this is the
                // guard that keeps a column of hand-off times from ever saying otherwise.
                'elapsed_ms' => (int) max(0, round(($at - $startedAt) / 1_000_000)),

                // Never longer than the column (bug hunt B091): a sentence four times the
                // longest one written today is trimmed, visibly, rather than refused.
                'note' => $note === null ? null : mb_strimwidth($note, 0, Leg::NOTE_LENGTH, '…'),
                'created_at' => $now,
            ];
        };

        if (isset($clock['push'])) {
            $rows[] = $row(Leg::GUARDS_ON_SHIFT, Leg::PUSH, ...$this->pushLeg($clock['push']));
        }

        if (isset($clock['mail'])) {
            foreach ($this->mailLegs($clock['mail'], $tenantId) as [$destination, $recipients, $outcome, $note]) {
                $rows[] = $row($destination, Leg::EMAIL, $recipients, $outcome, (int) $clock['mail']['at'], $note);
            }
        }

        if (isset($clock['sms'])) {
            foreach ($this->smsLegs($tenantId, $clock['sms'], $demonstration || $alert->is_simulated) as [$destination, $recipients, $outcome, $note]) {
                $rows[] = $row($destination, Leg::SMS, $recipients, $outcome, (int) $clock['sms']['at'], $note);
            }
        }

        if (isset($clock['household'])) {
            [$recipients, $outcome, $note] = $this->householdLeg($alert, $clock['household'], $demonstration);
            $rows[] = $row(Leg::EMERGENCY_CONTACTS, Leg::SMS, $recipients, $outcome, (int) $clock['household']['at'], $note);
        }

        /*
         * AFTER the site's numbers, deliberately: a site number with the `head_of_security`
         * role is filed under the estate's Head of Security too, and it is the one that
         * went first — the Head of Security's own text is the second SMS leg there.
         */
        if (isset($clock['heads'])) {
            foreach ($this->headsLegs($alert, $clock['heads'], $demonstration) as [$destination, $recipients, $outcome, $note]) {
                $rows[] = $row($destination, Leg::SMS, $recipients, $outcome, (int) $clock['heads']['at'], $note);
            }
        }

        if (isset($clock['broadcast'])) {
            foreach ($this->broadcastLegs($clock['broadcast'], $demonstration) as [$destination, $outcome, $note]) {
                $rows[] = $row($destination, Leg::BROADCAST, 1, $outcome, (int) $clock['broadcast']['at'], $note);
            }
        }

        return $rows;
    }

    /**
     * The guards on shift.
     *
     * @param  array<string, mixed>  $push
     * @return array{0: int, 1: string, 2: int, 3: string}
     */
    private function pushLeg(array $push): array
    {
        $officers = (int) $push['officers'];
        $handsets = (int) $push['handsets'];
        $at = (int) $push['at'];

        if (! config('push.enabled', true)) {
            return [0, Leg::SKIPPED, $at, 'Push is switched off on this server, so no handset was sent anything.'];
        }

        if ($officers === 0) {
            return [0, Leg::NONE, $at, 'No officer is clocked on or rostered at this site today.'];
        }

        if ($handsets === 0) {
            return [0, Leg::NONE, $at, self::plural($officers, 'officer').' on shift or rostered today, but no handset of theirs could be pushed: none is registered and signed in, or queueing failed and was reported.'];
        }

        return [
            $handsets,
            $this->handOff(config('push.queue_connection')),
            $at,
            self::plural($handsets, 'handset').' of '.self::plural($officers, 'officer').' on shift or rostered today.',
        ];
    }

    /**
     * The alert mail, split into the destinations its recipients actually are.
     *
     * By ROLE, read once here — not by which list inside `SiteEscalation` a person came
     * from — so that when the escalation leg's routing changes (s14), whoever it mails
     * is still filed under who they are.
     *
     * @param  array<string, mixed>  $mail
     * @return list<array{0: string, 1: int, 2: string, 3: string|null}>
     */
    private function mailLegs(array $mail, string $tenantId): array
    {
        if (isset($mail['outcome'])) {
            return array_map(
                static fn (string $destination): array => [$destination, 0, (string) $mail['outcome'], (string) $mail['why']],
                self::MAIL_DESTINATIONS,
            );
        }

        /** @var list<int> $ids */
        $ids = $mail['ids'] ?? [];

        /** @var list<int> $unverifiedIds */
        $unverifiedIds = $mail['unverified_ids'] ?? [];

        // The roles that make somebody this estate's own Head of Security — the shared one
        // and this estate's copies of it — read once for both lists (bug hunt B139).
        $estateHeadRoles = SiteEscalation::estateHeadOfSecurityRoles($tenantId);

        $counts = self::countByDestination($ids, $estateHeadRoles);
        $skipped = self::countByDestination($unverifiedIds, $estateHeadRoles);

        $mailer = (string) config('mail.default');
        $nowhere = in_array($mailer, ['log', 'array'], true);
        $outcome = $this->handOff(config('queue.central_connection', 'central'));

        $legs = [];

        foreach (array_keys($counts + $skipped) as $destination) {
            $count = $counts[$destination] ?? 0;
            $unproven = $skipped[$destination] ?? 0;

            /*
             * "NOBODY TO TELL" ONLY WHEN THERE WAS NOBODY. A destination whose people are
             * all unproven had somebody to tell; what it lacks is an address the platform
             * may use, and saying "nobody" would hide the one fact that fixes it.
             */
            if ($count === 0 && $unproven === 0) {
                $legs[] = [$destination, 0, Leg::NONE, self::nobodyMailed($destination)];

                continue;
            }

            if ($count > 0) {
                $legs[] = $nowhere
                    ? [$destination, $count, Leg::SKIPPED, 'The mailer is set to `'.$mailer.'`: the mail to '.self::plural($count, 'person', 'people').' was written down and delivered to nobody.']
                    : [$destination, $count, $outcome, 'The alert mail to '.self::plural($count, 'person', 'people').', on the panic queue.'];
            }

            /*
             * THE PEOPLE IT COULD NOT BE SENT TO, as a row of their own (M10 §1.4): `skipped`,
             * how many, and why — so `panic:walk` prints the reason beside NOT REACHED and the
             * dispatcher's screen can say how many were missed. A count, never a name or an
             * address: the note column's rule, and the reader does not need either.
             */
            if ($unproven > 0) {
                $legs[] = [$destination, $unproven, Leg::SKIPPED, self::unverifiedNote($unproven)];
            }
        }

        return $legs;
    }

    /**
     * The sentence on a row of people the alert mail skipped. It carries
     * `Leg::ADDRESS_NOT_VERIFIED`, which is how the report recognises the row.
     */
    public static function unverifiedNote(int $count): string
    {
        return self::plural($count, 'person', 'people').' '.Leg::ADDRESS_NOT_VERIFIED.'. '
            .'An alert is only mailed to an address its owner has proven by a one-time code, '
            .'and the console asks for one before it opens again.';
    }

    /**
     * How many of these people each of §1.2's mail destinations holds — every one of the
     * three always present, so a site with nobody of a kind says so.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $estateHeadRoles  `SiteEscalation::estateHeadOfSecurityRoles()` for the alert's estate
     * @return array<string, int>
     */
    private static function countByDestination(array $ids, array $estateHeadRoles): array
    {
        $counts = array_fill_keys(self::MAIL_DESTINATIONS, 0);

        if ($ids === []) {
            return $counts;
        }

        foreach (User::query()->whereKey($ids)->with('roles')->get() as $user) {
            $destination = self::destinationOf($user, $estateHeadRoles);
            $counts[$destination] = ($counts[$destination] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  iterable<int, mixed>  $users
     * @return list<int>
     */
    private static function ids(iterable $users): array
    {
        $ids = [];

        foreach ($users as $user) {
            if ($user instanceof User) {
                $ids[] = (int) $user->getKey();
            }
        }

        return $ids;
    }

    /**
     * The site's emergency numbers, filed under the people they belong to.
     *
     * Read again here, after the texts were queued, rather than before — a read in
     * front of the hand-off would be a query standing between a panic and a text.
     *
     * @param  array<string, mixed>  $sms
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private function smsLegs(string $tenantId, array $sms, bool $demo): array
    {
        $numbers = $this->numbers->forSite($tenantId);

        if ($numbers->isEmpty()) {
            return [];
        }

        $noProvider = (string) (config('services.sms.driver') ?: 'log') === 'log';
        $handedOff = $this->handOff(config('queue.central_connection', 'central'));

        $groups = $numbers->groupBy(static fn (SiteEmergencyNumber $number): string => self::SMS_ROLE_DESTINATIONS[(string) $number->role] ?? Leg::ESTATE_CONSOLE);

        $legs = [];

        foreach ($groups as $destination => $group) {
            $count = $group->count();
            $labels = $group->pluck('label')->implode(', ');
            $dead = $group->reject(static fn (SiteEmergencyNumber $number): bool => $number->isRoutable())->count();

            $legs[] = match (true) {
                $demo => [(string) $destination, 0, Leg::SKIPPED, 'Demonstration estate or simulated alert: nobody real is texted about it.'],
                (int) $sms['numbers'] === 0 => [(string) $destination, 0, Leg::FAILED, 'The texts to '.$labels.' could not be queued; the error was reported.'],
                $noProvider => [(string) $destination, $count, Leg::SKIPPED, 'No SMS provider is connected: the text to '.$labels.' is written down and delivered to nobody.'],
                default => [(string) $destination, $count, $handedOff, 'Texted to '.$labels.' through the panic queue.'.($dead > 0 ? ' '.self::plural($dead, 'number').' cannot be dialled and will be recorded as unroutable.' : '')],
            };
        }

        return $legs;
    }

    /**
     * The household's own emergency contacts — §1.2 item 7, by SMS — in one row.
     *
     * ALWAYS A ROW, the mail destinations' rule: an officer's duress, a household with
     * nobody on file and a platform with no provider each say so in a sentence, rather
     * than the destination falling back to "no leg addresses this".
     *
     * THE RECIPIENTS ARE THE CONTACTS THE LEG IS TEXTING — at most five — never a count
     * of phones that received anything. Under the log driver they are still counted and
     * the outcome is `skipped`, exactly as the site's numbers are: the message is written
     * down and delivered to nobody. `SmsSettings::canSend()` is asked rather than the
     * driver's name, because it is the one question every surface asks and a saved
     * provider missing its token cannot send either.
     *
     * @param  array<string, mixed>  $household
     * @return array{0: int, 1: string, 2: string}
     */
    private function householdLeg(DuressAlert $alert, array $household, bool $demonstration): array
    {
        $queued = $household['contacts'];
        $recipients = (int) ($alert->getAttribute('household_sms_recipients') ?? 0);
        $overCap = (int) ($alert->getAttribute('household_sms_over_cap') ?? 0);

        // THE CAP, SAID WHERE IT BIT: five texted is not "everybody on file".
        $beyond = $overCap === 0 ? '' : ' '.self::plural($overCap, 'more contact').' on file '.($overCap === 1 ? 'is' : 'are')
            .' not texted: a panic texts at most '.HouseholdContactsSms::CAP.'.';

        return match (true) {
            ! HouseholdContactsSms::appliesTo($alert) => [0, Leg::NONE, 'An officer\'s duress texts no household: only a resident\'s panic reaches the household\'s own emergency contacts.'],
            $demonstration || $alert->is_simulated => [0, Leg::SKIPPED, 'Demonstration estate or simulated alert: nobody real is texted about it.'],
            $queued === null => [0, Leg::FAILED, 'The texts to the household\'s emergency contacts could not be queued; the error was reported.'],
            $alert->getAttribute('raised_by_account_id') === null => [0, Leg::NONE, 'This alert was not raised from a Resident App account, so there is no household whose contacts could be texted.'],
            $recipients === 0 => [0, Leg::NONE, 'The household that raised it has no emergency contacts on file.'],
            ! app(SmsSettings::class)->canSend() => [$recipients, Leg::SKIPPED, 'No SMS provider is connected: the text to '.self::plural($recipients, 'emergency contact').' is written down and delivered to nobody.'.$beyond],
            default => [$recipients, $this->handOff(config('queue.central_connection', 'central')), 'Texted to the household\'s '.self::plural($recipients, 'emergency contact').' through the panic queue.'.$beyond],
        };
    }

    /**
     * Both Heads of Security by text — one row each for §1.2 items 4 and 5.
     *
     * ALWAYS A ROW, the mail destinations' rule: a site with no Head of Security on file,
     * or one with nobody's phone on file, says so in a sentence. THE RECIPIENTS ARE THE
     * HEADS OF SECURITY BEING TEXTED, after the dedupe — never a count of phones that
     * rang. Under the log driver they are counted and `skipped`: written down, delivered
     * to nobody. A Head of Security whose number is already being texted for this alert
     * is not texted twice, and the note says so rather than letting the count look short.
     *
     * @param  array<string, mixed>  $heads
     * @return list<array{0: string, 1: int, 2: string, 3: string}>
     */
    private function headsLegs(DuressAlert $alert, array $heads, bool $demonstration): array
    {
        $plan = $heads['plan'];
        $connected = app(SmsSettings::class)->canSend();
        $handedOff = $this->handOff(config('queue.central_connection', 'central'));

        $groups = [
            Leg::GEMINI_HEAD_OF_SECURITY => [HeadsOfSecuritySms::GEMINI, 'Gemini Head of Security', 'Gemini Heads of Security', 'no Gemini Head of Security covering this site is on file to text.'],
            Leg::ESTATE_HEAD_OF_SECURITY => [HeadsOfSecuritySms::ESTATE, 'Head of Security of the estate\'s own', 'Heads of Security of the estate\'s own', 'the estate has no Head of Security of its own on file to text.'],
        ];

        $say = static fn (string $sentence): string => self::OWN_PHONE.$sentence;

        $legs = [];

        foreach ($groups as $destination => [$group, $one, $many, $nobody]) {
            $counts = is_array($plan) ? ($plan[$group] ?? null) : null;
            $onFile = (int) ($counts['heads'] ?? 0);
            $texting = (int) ($counts['texting'] ?? 0);
            $noPhone = (int) ($counts['no_phone'] ?? 0);
            $deduplicated = (int) ($counts['deduplicated'] ?? 0);

            $who = static fn (int $n): string => self::plural($n, $one, $many);

            $beyond = ($deduplicated === 0 ? '' : ' '.$who($deduplicated).' not texted twice: the same number is already being texted for this alert.')
                .($noPhone === 0 ? '' : ' '.$who($noPhone).' with no phone on file.');

            $legs[] = match (true) {
                $demonstration || $alert->is_simulated => [$destination, 0, Leg::SKIPPED, $say('demonstration estate or simulated alert: nobody real is texted about it.')],
                ! is_array($plan) => [$destination, 0, Leg::FAILED, $say('the texts to the Heads of Security could not be queued; the error was reported.')],
                $onFile === 0 => [$destination, 0, Leg::NONE, $say($nobody)],
                $texting === 0 && $deduplicated === 0 => [$destination, 0, Leg::NONE, $say($who($onFile).' on file, but no phone number to text.')],
                $texting === 0 => [$destination, 0, Leg::SKIPPED, $say('not texted separately.'.$beyond)],
                ! $connected => [$destination, $texting, Leg::SKIPPED, $say('no SMS provider is connected, so the text to '.$who($texting).' is written down and delivered to nobody.'.$beyond)],
                default => [$destination, $texting, $handedOff, $say('texted to '.$who($texting).' through the panic queue.'.$beyond)],
            };
        }

        return $legs;
    }

    /**
     * The live channel, which is one publish that three destinations listen to.
     *
     * THE DIRECTOR IS THE THIRD (M10 §1.2 item 3, "the Gemini Director — dashboard
     * alert"). The Gemini console's pop-up (`useConsolePanic`, mounted on the
     * console's shell) listens on this same `estate.{id}.alerts` channel on every
     * Gemini page, so this publish IS the hand-off to the Director's dashboard —
     * and until that pop-up existed, nothing addressed the Director and this list
     * said so. One row, `recipients` 1: one channel, never a count of whoever had
     * a console open, because the socket server does not report that back.
     *
     * @param  array<string, mixed>  $broadcast
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function broadcastLegs(array $broadcast, bool $demo): array
    {
        $driver = (string) config('broadcasting.default');
        $live = ! in_array($driver, ['', 'null', 'log'], true);
        $accepted = (bool) $broadcast['accepted'];

        return [
            match (true) {
                ! $accepted => [Leg::DISPATCHER_CONSOLE, Leg::FAILED, 'The live channel refused the alert and the error was reported. Dispatch screens find it on their next poll.'],
                $demo => [Leg::DISPATCHER_CONSOLE, Leg::SKIPPED, 'Demonstration estate: Gemini\'s consoles do not listen to its channel (M5 §3).'],
                ! $live => [Leg::DISPATCHER_CONSOLE, Leg::SKIPPED, 'No live channel is configured on this server (broadcasting is set to `'.($driver === '' ? 'null' : $driver).'`), so dispatch screens find the alert on their next poll instead.'],
                default => [Leg::DISPATCHER_CONSOLE, Leg::SENT, 'Published to the estate\'s live channel, which every dispatch screen covering this estate listens to.'],
            },
            match (true) {
                ! $accepted => [Leg::ESTATE_CONSOLE, Leg::FAILED, 'The live channel refused the alert, so no pop-up appeared. It is on the estate\'s Security screen.'],
                ! $live => [Leg::ESTATE_CONSOLE, Leg::SKIPPED, 'No live channel is configured on this server, so no pop-up can appear. The alert is on the estate\'s Security screen.'],
                default => [Leg::ESTATE_CONSOLE, Leg::SENT, 'Published to the estate\'s live channel, which raises the pop-up for everyone on the estate\'s console who may see its Security screen.'],
            },
            match (true) {
                ! $accepted => [Leg::DIRECTOR_DASHBOARD, Leg::FAILED, 'The live channel refused the alert and the error was reported, so no pop-up appeared on the Director\'s console. It is on the notification bell at their next page load.'],
                $demo => [Leg::DIRECTOR_DASHBOARD, Leg::SKIPPED, 'Demonstration estate: Gemini\'s consoles do not listen to its channel (M5 §3).'],
                ! $live => [Leg::DIRECTOR_DASHBOARD, Leg::SKIPPED, 'No live channel is configured on this server, so no pop-up can appear on the Director\'s console. It is on the notification bell at their next page load.'],
                default => [Leg::DIRECTOR_DASHBOARD, Leg::SENT, 'Published to the estate\'s live channel, which raises the pop-up and the blinking bar on any Gemini console screen a Director has open. Whether one was open is not something the channel reports.'],
            },
        ];
    }

    /* -------------------------------------------------------------- helpers */

    /**
     * Which of §1.2's destinations this person is.
     *
     * A person holding both Heads of Security (not possible today — one console each)
     * is filed under Gemini's, which is the stricter reading of who is accountable.
     *
     * THE ESTATE'S HEAD OF SECURITY BY LINEAGE (bug hunt B139): the shared role or this
     * estate's copy of it, exactly the people `SiteEscalation::estateHeadsOfSecurity()`
     * texts. By name alone, a Head of Security moved onto a copy was filed as "the
     * estate's console" and this destination said nobody was on the list.
     *
     * @param  list<int>  $estateHeadRoles
     */
    private static function destinationOf(User $user, array $estateHeadRoles): string
    {
        $roles = $user->roles->pluck('name')->all();
        $roleIds = $user->roles->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return match (true) {
            in_array(Role::HEAD_OF_SECURITY, $roles, true) => Leg::GEMINI_HEAD_OF_SECURITY,
            in_array(Role::DIRECTOR, $roles, true) => Leg::DIRECTOR_DASHBOARD,
            array_intersect($roleIds, $estateHeadRoles) !== [] => Leg::ESTATE_HEAD_OF_SECURITY,
            $user->console === Console::Gemini => Leg::DISPATCHER_CONSOLE,
            default => Leg::ESTATE_CONSOLE,
        };
    }

    private static function nobodyMailed(string $destination): string
    {
        return match ($destination) {
            Leg::GEMINI_HEAD_OF_SECURITY => 'No Gemini Head of Security covering this site was on the alert mail\'s list.',
            Leg::ESTATE_HEAD_OF_SECURITY => 'No Head of Security of the estate\'s own was on the alert mail\'s list.',
            default => 'No member of the estate\'s committee was on the alert mail\'s list.',
        };
    }

    /**
     * `queued` when a worker will hand it on, `sent` when the queue is synchronous and
     * the hand-off to the provider happened inside the fan-out itself.
     */
    private function handOff(mixed $connection): string
    {
        $name = is_string($connection) && $connection !== '' && config("queue.connections.{$connection}") !== null
            ? $connection
            : (string) config('queue.default');

        return config("queue.connections.{$name}.driver") === 'sync' ? Leg::SENT : Leg::QUEUED;
    }

    private static function plural(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }

    /** @param  array<string, mixed>  $fact */
    private function stamp(DuressAlert $alert, string $leg, array $fact): void
    {
        // The stamp is taken first, before anything else here can cost time.
        $at = hrtime(true);

        $this->remember($alert, $leg, [...$fact, 'at' => $at]);
    }

    /** @param  array<string, mixed>  $fact */
    private function remember(DuressAlert $alert, string $key, array $fact): void
    {
        try {
            $clock = $this->clocks[$alert] ?? [];
            $clock[$key] = $fact;
            $this->clocks[$alert] = $clock;
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }

    private function has(DuressAlert $alert, string $key): bool
    {
        try {
            return isset($this->clocks[$alert][$key]);
        } catch (Throwable) {
            return false;
        }
    }
}
