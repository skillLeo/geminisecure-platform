<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\AlertEmailDispatch;
use App\Models\DuressAlert;
use App\Services\Platform\OperatorEstates;
use Throwable;

/**
 * WHAT THE EMAILS TO A HOUSEHOLD'S CONTACTS ACTUALLY DID (M12 §2.5) — `PanicSmsOutcome`'s rules for the mail leg.
 *
 * "Nothing may tell a resident in an emergency that a text went out when none did" — nor an email. So the answer is
 * counted from `alert_email_dispatches` rows (written after the attempt) and from nothing else:
 *
 *   recipients  how many contacts this alert is emailing (the raise's count); null while the fan-out has not reached
 *               the leg, or on an alert older than it
 *   sent        rows the mail server ACCEPTED. Never "received": the inbox is downstream of anything we can know
 *   failed      rows every attempt was refused for, and contacts whose address was gone by the time it was sent
 *   finished    a row for every recipient
 *
 * Null on an officer's duress, which emails no household.
 */
final class PanicEmailOutcome
{
    /** @return array{recipients: int|null, sent: int, failed: int, finished: bool, members_sent: int, contacts_sent: int}|null */
    public function of(DuressAlert $alert): ?array
    {
        if (! HouseholdContactsSms::appliesTo($alert)) {
            return null;
        }

        $raw = $alert->getAttribute('household_email_recipients');
        $recipients = $raw === null ? null : (int) $raw;
        [$sent, $failed, $members] = $this->counts($alert);

        return [
            'recipients' => $recipients,
            'sent' => $sent,
            'failed' => $failed,
            'finished' => $recipients !== null && $sent + $failed >= $recipients,

            // Who the accepted mail went to (M18C §4.3): the household's own members, and its emergency contacts.
            'members_sent' => $members,
            'contacts_sent' => $sent - $members,
        ];
    }

    /**
     * The household's mail in one line, for the dispatcher's alert screen, beside the texts' line.
     *
     *   "2 emailed"                  the mail server accepted both
     *   "1 of 2 emailed"             one refused after every retry
     *   "2 being emailed"            not finished yet
     *   "none with an email address" the household gave no address
     */
    public function householdLine(DuressAlert $alert): ?string
    {
        $outcome = $this->of($alert);

        if ($outcome === null) {
            return null;
        }

        if ($outcome['recipients'] === null) {
            return 'not known yet';
        }

        if ($outcome['recipients'] === 0) {
            return $alert->is_simulated || OperatorEstates::isDemonstration((string) $alert->tenant_id)
                ? 'not emailed — a simulated or demonstration alert emails nobody'
                : 'none with an email address on file';
        }

        $n = $outcome['recipients'];

        return match (true) {
            $outcome['sent'] > 0 && $outcome['sent'] === $n => $outcome['sent'].' emailed',
            $outcome['sent'] > 0 => $outcome['sent'].' of '.$n.' emailed'.($outcome['finished'] ? '' : ' so far'),
            ! $outcome['finished'] => $n.' being emailed — none confirmed yet',
            default => 'not emailed — the mail server refused '.($n === 1 ? 'it' : 'them'),
        };
    }

    /**
     * Rescued to "nothing sent": a ledger the platform could not read must not become a claim.
     *
     * @return array{0: int, 1: int, 2: int} sent, not sent, and how many of the sent were to members
     */
    private function counts(DuressAlert $alert): array
    {
        try {
            $rows = AlertEmailDispatch::query()
                ->where('duress_alert_id', (int) $alert->id)
                ->where('audience', AlertEmailDispatch::HOUSEHOLD)
                ->get(['status', 'household_member_id']);

            return [
                $rows->filter(static fn ($r): bool => $r->status === AlertEmailDispatch::SENT)->count(),
                $rows->filter(static fn ($r): bool => $r->status !== AlertEmailDispatch::SENT)->count(),
                $rows->filter(static fn ($r): bool => $r->status === AlertEmailDispatch::SENT && $r->household_member_id !== null)->count(),
            ];
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);

            return [0, 0, 0];
        }
    }
}
