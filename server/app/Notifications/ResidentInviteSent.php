<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invite to the estate's Resident App, for one person at one address.
 *
 * Sent NOW, not queued, by `ResidentInvites`: whether the mail server accepted it is the answer the
 * screen gives the person who pressed the button, and a queued send cannot give it. The email says
 * what to do in the order it is done — install, sign in with THIS address — because the invitee has
 * never seen the app, and "sign in" with a different address would find nothing.
 *
 * NO FORM TO FILL IN (stage 2, W-ACCEPT): the app recognises the invited address when its one-time code is
 * proven, and puts the person with their household. The estate office confirms them with one tap, or, when
 * the estate trusts its primary residents' invites and a primary sent this, the app opens at once (`trusted`).
 *
 * IT NAMES THE ESTATE AND THE INVITER, NEVER THE HOME (DESIGN INV-14). Whoever holds this address has not
 * yet proven it, and must not learn where somebody lives from an email.
 *
 * It carries no token and no link that signs anybody in. Signing in is the one-time code the app
 * emails to the address; an invite that opened an account by itself would be a credential sitting in
 * an inbox. Its one link (M18G §5) only OPENS the app with this address typed in for them.
 */
class ResidentInviteSent extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $estateName,
        public readonly string $memberName,
        public readonly string $invitedBy,
        public readonly string $email,
        public readonly string $lapsesOn,
        public readonly ?string $appUrl = null,
        public readonly bool $trusted = false,
        /** M18G §5: the signed "Open the app" link — the app opens at sign-in with THIS address filled in. */
        public readonly ?string $openUrl = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->invitedBy.' invited you to '.$this->estateName.' on GeminiSecure')
            ->greeting('Hello '.$this->memberName.',')
            ->line($this->invitedBy.' added you to their household at '.$this->estateName.' on GeminiSecure — the estate\'s app for visitor passes, notices, dues and the emergency button.')
            ->line('To start:')
            ->line('1. Install the GeminiSecure Resident app on your phone.'.($this->appUrl === null ? ' The estate office can tell you where to get it.' : ''));

        if ($this->appUrl !== null) {
            $mail->action('Get the Resident App', $this->appUrl);
        }

        // The address typed from memory is where a sign-in goes wrong ("…@gmail.comm"): this link types it for them.
        if ($this->openUrl !== null) {
            $mail->line('2. Once it is installed, open it from here — it opens with your address, '.$this->email.', already filled in:')
                ->action('Open the Resident App', $this->openUrl)
                ->line('Or open the app yourself, choose '.$this->estateName.' and sign in with '.$this->email.'. The app emails you a one-time code; there is no password.');
        } else {
            $mail->line('2. Choose '.$this->estateName.' and sign in with this email address, '.$this->email.'. The app emails you a one-time code; there is no password.');
        }

        return $mail
            ->line($this->trusted
                ? '3. That\'s all — the app opens on your household straight away.'
                : '3. The app recognises this address and puts you with your household — there is no form to fill in. The estate office confirms you, usually with one tap, and the app opens fully.')
            ->line('This invite is for '.$this->email.' only and lapses on '.$this->lapsesOn.'. If you were not expecting it, you can ignore this email — nothing happens unless you sign in.');
    }
}
