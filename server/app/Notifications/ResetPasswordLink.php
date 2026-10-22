<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Support\ConsoleHome;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The password reset email — both doors.
 *
 * THE LINK LANDS ON THE USER'S OWN DOOR. A committee member's estate is on its
 * own hostname in production and under /estate/{tenant} locally, and the card
 * they reset on should be the one they sign in on — "Phoenix Park Village ·
 * Powered by Gemini Security", not the internal console's mark. Gemini staff
 * land on the central door. `ConsoleHome` already knows both shapes.
 *
 * THIRTY MINUTES AND ONCE. The broker hashes the token, expires it after the
 * `expire` in config/auth.php, and deletes it on use; this message says so in
 * words, because a link that stops working is less alarming when it said it
 * would.
 *
 * NOTHING HERE SAYS WHETHER THE ADDRESS WAS KNOWN. The message only ever
 * reaches an address that holds an account; the screen that sent it says the
 * same sentence either way.
 */
class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = $this->url($notifiable);

        return (new MailMessage)
            ->subject('Reset your GeminiSecure password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Somebody asked to reset the password on this account. If it was you, use the button below.')
            ->action('Choose a new password', $url)
            ->line('The link works once, for thirty minutes. After that, ask for a new one from the sign-in page.')
            ->line('If you did not ask for this, nothing has changed and you can ignore this email.');
    }

    /** The reset page on the user's own door, with the token and the address the token is bound to. */
    public function url(User $user): string
    {
        $query = http_build_query(['email' => $user->email]);

        $tenantId = $user->accessibleEstateIds()[0] ?? null;

        if ($user->console->value === 'estate' && $tenantId !== null) {
            return ConsoleHome::estateUrl((string) $tenantId).'/reset-password/'.$this->token.'?'.$query;
        }

        return url('/reset-password/'.$this->token).'?'.$query;
    }
}
