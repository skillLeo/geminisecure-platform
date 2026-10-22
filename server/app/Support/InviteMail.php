<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Platform\MailSettings;
use App\Support\Seeded\SeededRecipients;
use Illuminate\Notifications\Notification as MailNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE ONE WAY AN INVITATION'S EMAIL GOES OUT, AND THE ONE ANSWER TO "DID IT?" (SPEC l.26, G10).
 *
 * Stage 1 wrote this rule for resident invites (`ResidentInvites::deliver`), and committee, staff and
 * onboarding invitations did something else: `Invitations` sent with `notify`, inside the database
 * transaction, with no idea whether the relay took it — so a refusal was a server error page, and the screen
 * said "sent" whenever it was not one. The rule now lives here, word for word, and every invitation — resident,
 * committee, staff, onboarding contact — is delivered through it, so the two cannot drift:
 *
 *   not set up   production on a mailer that delivers nowhere (`MailReadiness::UNDELIVERABLE`): nothing is
 *                tried, and the answer says who can set it up. `MAIL_UNDELIVERABLE_ACKNOWLEDGED` excuses nothing
 *                here — acknowledged still means nothing leaves
 *   sent         the Director's saved relay is put in front of `.env` (`MailSettings::apply`) and the message is
 *                sent SYNCHRONOUSLY on the plain mail channel, on demand; returning means the relay accepted it
 *   not sent     anything the relay or the transport threw, in words (`MailSettings::explain`), never a
 *                credential; the log gets the same sentence and the caller's ids, never the exception's own text
 *   sample       the address belongs only to a person a seeder invented (M35 §5), so the sending layer took it off
 *                the message and nothing reached the mail server: `SAMPLE_PERSON`, never "sent"
 *
 * `null` is "the mail server accepted it". Anything else is the sentence to record and show.
 */
final class InviteMail
{
    /** Stage 1's own sentence (`ResidentInvites::NOT_SET_UP`), so every invitation says it the same way. */
    public const NOT_SET_UP = 'Mail is not set up on this platform yet, so nothing was emailed. The Gemini Director can set it up under Platform settings → Mail, then send it again.';

    /** What an invitation to a seeded person says (M35 §5): the platform never emails one, and nothing was sent. */
    public const SAMPLE_PERSON = 'This address belongs only to a person the sample data invented, not a real one, so the platform does not email it. Nothing was sent.';

    /**
     * @param  array<string, scalar|null>  $logContext  ids that say which invitation this was — never an address or a token
     */
    public static function deliver(string $to, MailNotification $mail, array $logContext = []): ?string
    {
        // A mailer that delivers nowhere is not a send: on production, say so rather than "sent".
        if (app()->environment('production') && in_array((string) config('mail.default'), MailReadiness::UNDELIVERABLE, true)) {
            return self::NOT_SET_UP;
        }

        try {
            app(MailSettings::class)->apply();

            // A message the sending layer stopped raises nothing (`SeededRecipients::screenMail`), so it is asked.
            [, $dropped] = SeededRecipients::whileWatching(static fn () => Notification::route('mail', $to)->notifyNow($mail));

            return $dropped === [] ? null : self::SAMPLE_PERSON;
        } catch (Throwable $e) {
            $reason = Str::limit(app(MailSettings::class)->explain($e), 250, '…');
            Log::warning('An invitation was not delivered.', $logContext + ['reason' => $reason]);

            return $reason;
        }
    }
}
