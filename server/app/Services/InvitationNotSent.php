<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invitation;
use App\Support\InviteMail;
use DomainException;

/**
 * An invitation that is saved but whose email did not reach the mail server — thrown AFTER the row is safe.
 *
 * The screen used to say "Invitation sent" whatever happened: a relay that refused the message was a server
 * error page, and a resend that failed had already killed the link the person held. Now the caller is told
 * what is true — the invitation stands (or, on a resend, the earlier link is exactly as it was) and the email
 * did not go, with the reason in words (`MailSettings::explain`, never a credential).
 *
 * ON A RESEND, "THE LINK THEY ALREADY HAVE" IS SAID ONLY WHEN THEY HAVE ONE. A failed resend keeps the stored
 * link, but that link reached the person only if an email carrying it went: when every try so far was refused
 * (the relay was down for the first send too) they hold nothing, and telling the office "the link they already
 * have still works" would be the very claim this class exists to stop. So what the caller says about the
 * earlier emails decides the sentence (`$earlier`): one went, none went, or it was never recorded (a row
 * written before delivery was recorded). A lapsed link is said to have lapsed.
 *
 * THE WORDS FIT EVERY SCREEN THAT SHOWS THEM — Users & roles, Platform admins and a client's page, whose button
 * reads "Resend invitation" and which has no list — so they say "Press Resend", never "from the list".
 */
final class InvitationNotSent extends DomainException
{
    /** An earlier email carrying the stored link went: the mail server took it (`sent_at`). */
    public const EARLIER_EMAILED = 'emailed';

    /** Every try so far was recorded, and none went: the person holds no link. */
    public const EARLIER_NONE = 'none';

    /** The row was written before delivery was recorded (`attempts` 0): whether anything went is not known. */
    public const EARLIER_UNRECORDED = 'unrecorded';

    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $reason,
        public readonly bool $resend,
        public readonly string $earlier = self::EARLIER_EMAILED,
    ) {
        $why = rtrim($reason, '.').'.';

        parent::__construct(match (true) {
            ! $resend => sprintf('The invitation to %s is saved, but the email did not go: %s Press Resend once mail is working.', $invitation->email, $why),
            $earlier === self::EARLIER_NONE => sprintf('Nothing was resent to %s: %s No email has gone to them yet, so they have no link to use. Press Resend once mail is working.', $invitation->email, $why),
            $invitation->expires_at->isPast() => sprintf('Nothing was resent to %s: %s The link they were sent lapsed on %s, so they have no working link until a resend goes.', $invitation->email, $why, $invitation->expires_at->format('F j')),
            $earlier === self::EARLIER_UNRECORDED => sprintf('Nothing was resent to %s: %s Whether the last email reached them was not recorded; if it did, its link still works until %s.', $invitation->email, $why, $invitation->expires_at->format('F j')),
            default => sprintf('Nothing was resent to %s: %s The link they already have still works until %s.', $invitation->email, $why, $invitation->expires_at->format('F j')),
        });
    }

    /** `not_set_up` when mail goes nowhere on this platform, `not_sent` when the relay refused or could not be reached. */
    public function state(): string
    {
        return $this->reason === InviteMail::NOT_SET_UP ? 'not_set_up' : 'not_sent';
    }
}
