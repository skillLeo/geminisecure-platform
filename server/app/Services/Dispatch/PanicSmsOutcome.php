<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\AlertSmsDispatch;
use App\Models\DuressAlert;
use App\Services\Platform\OperatorEstates;
use App\Services\Platform\SmsSettings;
use App\Services\ResidentApp\LogSmsGateway;
use App\Services\ResidentApp\SmsResult;
use Throwable;

/**
 * WHAT THE TEXTS FOR ONE ALERT ACTUALLY DID — M10 §1, in the client's own words:
 * "nothing may tell a resident in an emergency that a text went out when none did."
 *
 * WHY A SEPARATE ANSWER, WHEN `sms_recipients` AND `sms_delivered` ALREADY EXIST.
 * Those two are counts, and a count invites a sentence. `sms_recipients: 4` is how
 * many numbers were ON FILE — the job has not run when the handset is answered —
 * and a panic screen that turned it into "4 emergency contacts texted" would be
 * the exact lie this product refuses to tell, on the one screen where it matters
 * most. Nothing about the old fields stops that sentence being written. This
 * does, by saying in so many words what happened, from the only two places that
 * know:
 *
 *   `alert_sms_dispatches`  one row per number, written AFTER the attempt, in the
 *                           sender's own words — `sent`, `recorded`, `failed`,
 *                           `unroutable`. A row is a fact, never an intention.
 *   `SmsSettings::canSend()` whether a provider is connected at all. The log
 *                           driver writes a message down and delivers it to
 *                           nobody, and a settings row that merely exists proves
 *                           nothing.
 *
 * THE FIVE FIELDS, AND WHAT EACH MAY BE READ AS:
 *
 *   provider_connected  a provider could send a text right now. False on this
 *                       platform today.
 *   attempted           numbers handed to a REAL provider for this alert. A
 *                       `recorded` row went to the log driver and an
 *                       `unroutable` one went nowhere, so neither is counted: an
 *                       attempt is a provider being asked, not a line in a log.
 *   delivered           numbers a provider ACCEPTED (`SmsResult::SENT`, and
 *                       nothing else). The furthest the platform can honestly go
 *                       — the carrier and the handset are downstream.
 *   finished            the texting has run to the end. Until then `attempted`
 *                       and `delivered` are "so far", never "in total".
 *   note                the one sentence to show. Built here so the resident's
 *                       screen, the guard's and anything later cannot word the
 *                       same fact three ways, and so no screen has to compose a
 *                       claim out of numbers.
 *
 * NEVER A HARD-CODED CLAIM. Every branch of `note()` is reached from rows and the
 * provider check; there is no sentence here that says a text went out unless a
 * row says a provider accepted one.
 *
 * AND THE HOUSEHOLD'S OWN CONTACTS (M10 §1.2 item 7), ADDITIVELY. The five fields
 * above describe the ESTATE's emergency numbers exactly as they always have, from
 * the site's rows only. `household` sits beside them — the resident's nominated
 * contacts, counted by the same two rules from the household's rows — and is null
 * on an officer's duress, which texts no household. `note` becomes the one
 * sentence about BOTH, and is unchanged word for word whenever the household leg
 * has nobody to text.
 */
final class PanicSmsOutcome
{
    /** The sentence for an alert raised while no provider is connected. */
    public const NO_PROVIDER = 'No text was sent: no SMS provider is connected.';

    /**
     * The same fact, when the household had contacts that would have been texted.
     *
     * One sentence rather than two, because two would be the same "no provider"
     * said twice to somebody who has just pressed a panic button — and it NAMES the
     * contacts, because a resident who remembers adding a relative must not be
     * left to assume that relative was told (D-238: never "she" or "he").
     */
    public const NO_PROVIDER_FOR_EITHER = 'No text was sent to this estate\'s emergency numbers or to your emergency contacts: no SMS provider is connected.';

    /** How the household's contacts are named to the resident, in every sentence. */
    private const YOUR_CONTACTS = 'your emergency contacts';

    /** How the estate's numbers are named when the household is named beside them. */
    private const THE_ESTATES_NUMBERS = 'this estate\'s emergency numbers';

    public function __construct(private readonly SmsSettings $settings) {}

    /**
     * @return array{provider_connected: bool, attempted: int, delivered: int, finished: bool, note: string, household: array{attempted: int, delivered: int, finished: bool}|null}
     */
    public function of(DuressAlert $alert): array
    {
        [$statuses, $householdStatuses] = $this->statuses($alert);

        $delivered = self::deliveredIn($statuses);
        $attempted = self::attemptedIn($statuses);

        $connected = $this->settings->canSend();

        /*
         * FINISHED IS THE JOB'S OWN WORD. `sms_delivered` is written when the job
         * ends (or at once, as 0, when there was nothing to send) and is null until
         * then — the same null-is-not-zero rule `alerted_handsets` follows.
         */
        $finished = $alert->getAttribute('sms_delivered') !== null;

        $household = HouseholdContactsSms::appliesTo($alert) ? [
            'attempted' => self::attemptedIn($householdStatuses),
            'delivered' => self::deliveredIn($householdStatuses),
            'finished' => $alert->getAttribute('household_sms_delivered') !== null,
        ] : null;

        $note = $this->note($statuses, $attempted, $delivered, $connected, $finished);

        /*
         * THE HOUSEHOLD IS NAMED ONLY WHEN IT HAD SOMEBODY TO TEXT. With no contacts
         * on file there is nothing true to add, and the sentence the apps already show
         * stays exactly as it was. With contacts, both audiences are named in turn —
         * the estate's sentence rewritten to say whose numbers it is about, because
         * "No text was sent for this alert" beside "the provider accepted 2 texts to
         * your emergency contacts" would contradict itself.
         */
        if ($household !== null && (int) ($alert->getAttribute('household_sms_recipients') ?? 0) > 0) {
            $note = ! $connected && $delivered === 0 && $household['delivered'] === 0
                ? self::NO_PROVIDER_FOR_EITHER
                : $this->sentence(self::THE_ESTATES_NUMBERS, $statuses, $attempted, $delivered, $connected, $finished)
                    .' '.$this->sentence(self::YOUR_CONTACTS, $householdStatuses, $household['attempted'], $household['delivered'], $connected, $household['finished']);
        }

        return [
            'provider_connected' => $connected,
            'attempted' => $attempted,
            'delivered' => $delivered,
            'finished' => $finished,
            'note' => $note,

            // Added LAST and never instead of anything above (M10 §1.2 item 7).
            'household' => $household,
        ];
    }

    /**
     * The household's leg in one line, for the dispatcher's alert screen.
     *
     *   "2 texted"                                  a provider accepted two
     *   "not texted: no SMS provider connected"    the platform today
     *   "none on file"                              the household nominated nobody
     *
     * NULL ON AN OFFICER'S DURESS, which texts no household — the screen leaves the
     * row out rather than printing a sentence about people who were never in scope.
     *
     * "TEXTED" MEANS A PROVIDER ACCEPTED IT, and nothing weaker: the count is the
     * household's `sent` rows and no other. Never "received" — the carrier and the
     * handset are downstream of anything this platform can know.
     */
    public function householdLine(DuressAlert $alert): ?string
    {
        if (! HouseholdContactsSms::appliesTo($alert)) {
            return null;
        }

        $recipients = $alert->getAttribute('household_sms_recipients');

        if ($recipients === null) {
            // The fan-out has not reached this leg yet, or the alert predates it.
            return 'not known yet';
        }

        $recipients = (int) $recipients;

        if ($recipients === 0) {
            return $alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)
                ? 'not texted: a simulated or demonstration alert texts nobody'
                : 'none on file';
        }

        [, $rows] = $this->statuses($alert);

        $delivered = self::deliveredIn($rows);
        $overCap = (int) ($alert->getAttribute('household_sms_over_cap') ?? 0);

        $line = match (true) {
            $delivered > 0 => $delivered === $recipients ? $delivered.' texted' : $delivered.' of '.$recipients.' texted',
            ! $this->settings->canSend() => 'not texted: no SMS provider connected',
            $alert->getAttribute('household_sms_delivered') === null => $recipients.' being texted: none confirmed yet',
            self::attemptedIn($rows) > 0 => 'not texted: the SMS provider refused '.($recipients === 1 ? 'it' : 'them'),
            in_array(SmsResult::RECORDED, array_column($rows, 'status'), true) => 'not texted: no SMS provider was connected',
            in_array(SmsResult::SEEDED, array_column($rows, 'status'), true) => 'not texted: sample data, not a person',
            $rows !== [] => 'not texted: no number on file can be dialled',
            default => 'not texted',
        };

        // THE CAP, SAID WHERE IT BIT, so nobody reads five as "everybody on file".
        return $overCap > 0
            ? $line.'; '.$overCap.' more on file not texted (a panic texts at most '.HouseholdContactsSms::CAP.')'
            : $line;
    }

    /**
     * The one sentence, from the facts and nothing else.
     *
     * IN THIS ORDER, because the first true thing is the one that matters. A text a
     * provider accepted is reported even if the provider has since been
     * disconnected — it went. Otherwise no provider means no text, whatever else is
     * true; and only with a provider does "still sending" or "refused" arise.
     *
     * @param  list<array{status: string, driver: string}>  $statuses
     */
    private function note(array $statuses, int $attempted, int $delivered, bool $connected, bool $finished): string
    {
        if ($delivered > 0) {
            return sprintf(
                'The SMS provider accepted %d of %d %s to this estate\'s emergency numbers.',
                $delivered,
                max($attempted, $delivered),
                max($attempted, $delivered) === 1 ? 'text' : 'texts',
            );
        }

        if (! $connected) {
            return self::NO_PROVIDER;
        }

        if (! $finished) {
            return 'Texting this estate\'s emergency numbers: no text has been confirmed yet.';
        }

        if ($attempted > 0) {
            return 'No text was delivered: the SMS provider did not accept any of them.';
        }

        if (in_array(SmsResult::RECORDED, array_column($statuses, 'status'), true)) {
            return 'No text was sent: no SMS provider was connected when this alert was raised.';
        }

        if ($statuses !== []) {
            return 'No text was sent: none of this estate\'s emergency numbers can be dialled.';
        }

        return 'No text was sent for this alert.';
    }

    /**
     * One audience's sentence, NAMING that audience — for when both are said together.
     *
     * The same order and the same facts as `note()`, so the two can never disagree
     * about what happened; only the words differ, because each sentence has to say
     * whose texts it is about.
     *
     * @param  list<array{status: string, driver: string}>  $statuses
     */
    private function sentence(string $to, array $statuses, int $attempted, int $delivered, bool $connected, bool $finished): string
    {
        if ($delivered > 0) {
            return sprintf(
                'The SMS provider accepted %d of %d %s to %s.',
                $delivered,
                max($attempted, $delivered),
                max($attempted, $delivered) === 1 ? 'text' : 'texts',
                $to,
            );
        }

        return match (true) {
            ! $connected => 'No text was sent to '.$to.': no SMS provider is connected.',
            ! $finished => 'Texting '.$to.': no text has been confirmed yet.',
            $attempted > 0 => 'No text to '.$to.' was delivered: the SMS provider did not accept any of them.',
            in_array(SmsResult::RECORDED, array_column($statuses, 'status'), true) => 'No text was sent to '.$to.': no SMS provider was connected when this alert was raised.',
            $statuses !== [] => 'No text was sent to '.$to.': none of the numbers can be dialled.',
            default => 'No text was sent to '.$to.'.',
        };
    }

    /**
     * What a provider ACCEPTED — `SmsResult::SENT` and nothing else.
     *
     * @param  list<array{status: string, driver: string}>  $statuses
     */
    private static function deliveredIn(array $statuses): int
    {
        return count(array_filter($statuses, static fn (array $row): bool => $row['status'] === SmsResult::SENT));
    }

    /**
     * What a REAL provider was asked to send. A `recorded` row went to the log driver
     * and an `unroutable` one went nowhere, so neither is an attempt.
     *
     * @param  list<array{status: string, driver: string}>  $statuses
     */
    private static function attemptedIn(array $statuses): int
    {
        return count(array_filter(
            $statuses,
            static fn (array $row): bool => $row['status'] === SmsResult::SENT
                || ($row['status'] === SmsResult::FAILED && $row['driver'] !== LogSmsGateway::DRIVER),
        ));
    }

    /**
     * The two Heads of Security's texts in one line, for the dispatcher's alert screen
     * (M10 §1.2 items 4 and 5), next to the household's.
     *
     *   "2 texted"                                  a provider accepted both
     *   "not texted: no SMS provider connected"    the platform today
     *   "not texted: no phone on file"             nobody gave a number on §1.4's page
     *   "none on file"                              the site has no Head of Security
     *
     * EVERY ALERT HAS THIS LINE, a guard's duress included — the Heads of Security are
     * told about both. "TEXTED" MEANS A PROVIDER ACCEPTED IT: the staff rows' `sent`,
     * never a `deduplicated` one, which was deliberately not sent twice and is said as
     * such rather than counted.
     */
    public function staffLine(DuressAlert $alert): string
    {
        $recipients = $alert->getAttribute('staff_sms_recipients');

        if ($recipients === null) {
            // The fan-out has not reached this leg yet, or the alert predates it.
            return 'not known yet';
        }

        if ($alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)) {
            return 'not texted: a simulated or demonstration alert texts nobody';
        }

        $recipients = (int) $recipients;
        $noPhone = (int) ($alert->getAttribute('staff_sms_no_phone') ?? 0);
        $deduplicated = (int) ($alert->getAttribute('staff_sms_deduplicated') ?? 0);

        if ($recipients + $noPhone + $deduplicated === 0) {
            return 'none on file';
        }

        $rows = array_values(array_filter(
            $this->statuses($alert)[2],
            static fn (array $row): bool => $row['status'] !== AlertSmsDispatch::DEDUPLICATED,
        ));

        $delivered = self::deliveredIn($rows);

        $line = match (true) {
            $recipients === 0 && $deduplicated === 0 => 'not texted: no phone on file',
            $recipients === 0 => 'not texted separately',
            $delivered > 0 => $delivered === $recipients ? $delivered.' texted' : $delivered.' of '.$recipients.' texted',
            ! $this->settings->canSend() => 'not texted: no SMS provider connected',
            $alert->getAttribute('staff_sms_delivered') === null => $recipients.' being texted: none confirmed yet',
            self::attemptedIn($rows) > 0 => 'not texted: the SMS provider refused '.($recipients === 1 ? 'it' : 'them'),
            in_array(SmsResult::RECORDED, array_column($rows, 'status'), true) => 'not texted: no SMS provider was connected',
            in_array(SmsResult::SEEDED, array_column($rows, 'status'), true) => 'not texted: sample data, not a person',
            $rows !== [] => 'not texted: no number on file can be dialled',
            default => 'not texted',
        };

        // What the count leaves out, said where it matters.
        $also = array_filter([
            $deduplicated === 0 ? null : $deduplicated.' not texted twice: the same number is already being texted',
            $noPhone === 0 || ($recipients === 0 && $deduplicated === 0) ? null : $noPhone.' with no phone on file',
        ]);

        return $also === [] ? $line : $line.'; '.implode('; ', $also);
    }

    /**
     * Every row for this alert, as status and driver — the SITE's, the HOUSEHOLD's
     * and the STAFF's, apart, so no audience's count can include another's.
     *
     * RESCUED TO "NO ROWS", which reads as "no text was sent" — never as a
     * delivery. A ledger the platform could not read must not become a claim.
     *
     * @return array{0: list<array{status: string, driver: string}>, 1: list<array{status: string, driver: string}>, 2: list<array{status: string, driver: string}>}
     */
    private function statuses(DuressAlert $alert): array
    {
        try {
            $rows = AlertSmsDispatch::query()
                ->where('duress_alert_id', (int) $alert->id)
                ->get(['audience', 'status', 'driver']);

            $of = static fn (string $audience): array => $rows
                ->filter(static fn (AlertSmsDispatch $row): bool => (string) $row->audience === $audience)
                ->map(static fn (AlertSmsDispatch $row): array => ['status' => (string) $row->status, 'driver' => (string) $row->driver])
                ->values()
                ->all();

            return [$of(AlertSmsDispatch::SITE), $of(AlertSmsDispatch::HOUSEHOLD), $of(AlertSmsDispatch::STAFF)];
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return [[], [], []];
        }
    }
}
