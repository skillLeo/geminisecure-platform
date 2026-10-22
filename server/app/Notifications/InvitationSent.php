<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\Tenant;
use App\Support\ConsoleHome;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The invitation email.
 *
 * Sent to an address, not a user — there is no user yet; that is what an
 * invitation is. The link lands on the estate's own door, where the card names
 * the community the person is being asked to join, and says who asked, what
 * role, and that the link stands for fourteen days.
 */
class InvitationSent extends Notification
{
    /** How long after an estate is made its own address may still be waiting for a certificate (issued within five minutes). */
    public const NEW_ADDRESS_MINUTES = 60;

    public function __construct(public readonly Invitation $invitation) {}

    /**
     * Whether this invitation's link opens on an estate address so new that a browser may not trust it yet.
     *
     * Onboarding a client emails its contact at once (DESIGN §11 row 5), and the link opens on the new estate's
     * own hostname, whose certificate is issued within five minutes (`gs-estate-hosts`). A contact who clicks
     * straight away would meet a browser's security warning on a page that asks for a password, and could
     * reasonably take the email for phishing. So an invitation to an estate made within the hour says, in the
     * email, what the warning is and to wait rather than continue past it — and the office is told the same.
     * Local development serves estates on one host by path, so there is no new address there.
     */
    public static function opensOnNewAddress(Invitation $invitation): bool
    {
        if ($invitation->tenant_id === null || app()->isLocal()) {
            return false;
        }

        $created = Tenant::query()->whereKey($invitation->tenant_id)->value('created_at');

        return $created !== null && Carbon::parse((string) $created)->greaterThan(Carbon::now()->subMinutes(self::NEW_ADDRESS_MINUTES));
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['inviter', 'role']);
        $estate = $invitation->tenant_id === null ? null : Tenant::query()->find($invitation->tenant_id);
        $where = $estate === null ? 'the Gemini Console' : (string) $estate->name;

        $mail = (new MailMessage)
            ->subject('You have been invited to '.$where.' on GeminiSecure')
            ->greeting('Hello,')
            ->line(sprintf(
                '%s has invited you to %s as %s.',
                $invitation->inviter->name,
                $where,
                (string) ($invitation->role->label ?? $invitation->role->name),
            ))
            ->action('Accept the invitation', $this->url())
            ->line('The link stands for fourteen days, until '.$invitation->expires_at->format('F j, Y').'. Choose a password when you arrive.');

        if (self::opensOnNewAddress($invitation)) {
            $mail->line(sprintf(
                '%s\'s address is brand new, and its security certificate is issued within a few minutes. If your browser warns that the connection is not private, do not continue: wait a few minutes and open the link again.',
                $where,
            ));
        }

        return $mail->line('If you were not expecting this, ignore it — no account exists until you accept.');
    }

    /** The acceptance page, on the estate's own door or the central one. */
    public function url(): string
    {
        if ($this->invitation->tenant_id !== null) {
            return ConsoleHome::estateUrl($this->invitation->tenant_id).'/invitations/'.$this->invitation->token;
        }

        return url('/invitations/'.$this->invitation->token);
    }
}
