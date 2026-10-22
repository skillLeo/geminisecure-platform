<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * THE MAIL AN ALERT GOES OUT ON — TO A PROVEN ADDRESS, OR TO NOBODY (M10 §1.4).
 *
 * "Store a verified flag with the timestamp. An unverified address is never used for
 * alerting." An alert notification names this class in `via()` instead of `mail`, and
 * this is the only place its recipient is decided: the notifiable's `alerts` route,
 * which for a console user is `User::alertEmail()` — the contact address, once proven,
 * and null until then. Null sends nothing. There is no fallback to the sign-in address.
 *
 * WHY A CHANNEL, AND NOT THE OTHER TWO WAYS.
 *
 *   NOT `User::routeNotificationForMail()`. That is where EVERY mail to a user goes,
 *     and most of them must keep going where they went: a password reset is sent to
 *     the address the account signs in with, and a person locked out of an unproven
 *     alert address must still be able to reset their password. Changing the global
 *     route to fix alerts would break the door to fix the alarm.
 *
 *   NOT `Notification::route('mail', $address)` AT EACH CALLER. It would work at the
 *     callers that remembered it, and the rule would live in every one of them. The
 *     next caller to write `Notification::send($users, new PanicRaisedAtSite(...))`
 *     would mail sign-in addresses nobody proved, silently. Here the rule travels with
 *     the NOTIFICATION: whoever sends an alert, however they send it, it can only go to
 *     a proven address. The user also stays the notifiable, so the queued job, the test
 *     fakes and any later channel on the same alert still know who it was for.
 *
 * THE CALLERS STILL SKIP THE UNPROVEN, and must: `PanicFanOut` counts the people it
 * mailed and tells the resident that number, so it asks `alertEmail()` itself and
 * never hands this channel somebody it would then refuse. What this adds is that a
 * caller that FORGOT cannot get it wrong, and that the address is read again at the
 * moment of sending — in the worker, for a queued alert — so an address that stopped
 * being proven between the panic and the send is not used.
 *
 * ONE ADDRESS PER MAIL. The route is a single string, so one mail never carries two
 * people's addresses in its To line: a committee member does not learn the Gemini Head
 * of Security's mailbox from a panic.
 *
 * `send()` is written out rather than handed to the parent, because the parent refuses
 * to send unless the `mail` route answers — which is the sign-in address, the very
 * address this channel exists not to consult.
 */
final class AlertMailChannel extends MailChannel
{
    /** The route a notifiable answers for an alert: `User::routeNotificationForAlerts()`. */
    public const ROUTE = 'alerts';

    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification): ?SentMessage
    {
        if (self::addressOf($notifiable, $notification) === null) {
            return null;
        }

        /** @phpstan-ignore-next-line every alert notification defines toMail() */
        $message = $notification->toMail($notifiable);

        /*
         * A MAILABLE ADDRESSES ITSELF, and would carry whatever `to` it was built with
         * straight past the proven address. An alert is a `MailMessage` or it is a
         * mistake worth failing loudly on — in the worker, never on a panic's request.
         */
        if ($message instanceof Mailable || ! $message instanceof MailMessage) {
            throw new LogicException(
                get_class($notification).' must build a MailMessage: an alert is only ever addressed by AlertMailChannel, to a proven address.'
            );
        }

        // The mailer the message names, or — while it names none, which leaves the property
        // null — the application's default: `Mail\Factory::mailer()` takes null for that.
        return $this->mailer->mailer($message->mailer)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message),
        );
    }

    /**
     * The one proven address, as the To line.
     *
     * @param  mixed  $notifiable
     * @param  Notification  $notification
     * @param  MailMessage  $message
     * @return list<string>
     */
    protected function getRecipients($notifiable, $notification, $message): array
    {
        $address = self::addressOf($notifiable, $notification);

        return $address === null ? [] : [$address];
    }

    /**
     * The address an alert to this notifiable may use, or null — never the sign-in address.
     *
     * NEVER AN ON-DEMAND ROUTE. `Notification::route('alerts', $address)` would hand this
     * channel an address nobody proved, which is the one thing it exists to refuse; an
     * alert is sent to an ACCOUNT, and the account says where.
     */
    public static function addressOf(mixed $notifiable, Notification $notification): ?string
    {
        if ($notifiable instanceof AnonymousNotifiable
            || ! is_object($notifiable)
            || ! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $address = $notifiable->routeNotificationFor(self::ROUTE, $notification);

        return is_string($address) && trim($address) !== '' ? trim($address) : null;
    }
}
