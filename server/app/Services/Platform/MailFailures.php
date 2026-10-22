<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\AlertEmailDispatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * THE EMAILS THE PLATFORM GAVE UP ON, WHERE AN ADMIN SEES THEM (M12 §5.1).
 *
 * Written by ResilientSmtpTransport after its last attempt, read by Platform settings → Email and by the bell. What
 * the message was (its subject), who it was for (masked — "so***@gm***.com"), how many attempts, when, through which
 * relay, and why in words an operator can act on (MailSettings::explain). Never the message body, never a code.
 */
final class MailFailures
{
    /** How far back the bell looks. */
    public const BELL_HOURS = 24;

    public function __construct(private readonly MailSettings $mail) {}

    /** The recorder the transport is given. It must never throw: a failed record cannot hide the failed send. */
    public function record(RawMessage $message, ?Envelope $envelope, int $attempts, Throwable $why, string $relay): void
    {
        try {
            DB::connection('mysql')->table('mail_failures')->insert([
                'failed_at' => Carbon::now(),
                'subject' => mb_substr(self::withoutCodes($message instanceof Email ? (string) $message->getSubject() : '(no subject)'), 0, 200),
                'recipients' => mb_substr(implode(', ', $this->recipients($message, $envelope)), 0, 400),
                'attempts' => max(1, min(255, $attempts)),
                'reason' => mb_substr($this->reason($why), 0, 400),
                'relay' => mb_substr($relay, 0, 120),
                'tenant_id' => function_exists('tenant') && tenant() !== null ? (string) tenant()->getTenantKey() : null,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        // And on the list of every email sent (M18G §7), as failed, with the same reason.
        MailLog::failed($message, $this->reason($why), $relay);
    }

    /**
     * The latest failures, newest first, as the Email settings page lists them.
     *
     * @return list<array{at: string, subject: string, recipients: string, attempts: int, reason: string, relay: string}>
     */
    public function recent(int $limit = 50): array
    {
        return DB::connection('mysql')->table('mail_failures')
            ->orderByDesc('failed_at')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'at' => Carbon::parse((string) $row->failed_at)->format('D j M Y, g:i A').' UTC',
                // Read through the same filter: a row written before it existed may still carry a code.
                'subject' => self::withoutCodes((string) $row->subject),
                'recipients' => (string) $row->recipients,
                'attempts' => (int) $row->attempts,
                'reason' => (string) $row->reason,
                'relay' => (string) $row->relay,
            ])
            ->values()
            ->all();
    }

    /** @return array{count: int, latest: object|null} failures in the bell's window */
    public function lately(): array
    {
        $since = Carbon::now()->subHours(self::BELL_HOURS);
        $query = DB::connection('mysql')->table('mail_failures')->where('failed_at', '>=', $since);

        return ['count' => (clone $query)->count(), 'latest' => $query->orderByDesc('failed_at')->orderByDesc('id')->first()];
    }

    /**
     * A SUBJECT WITH NO CODE IN IT (M18G, found on production). A sign-in or verification email carries its code in the
     * subject so it reads in the notification — and a failed one's subject was kept here whole, putting a one-time code
     * on an admin's screen. The code is dead by then (a failed send retires it), but a code is never shown: six digits
     * standing on their own — every code the platform sends is six — become dots. An invoice number or an amount keeps
     * its digits.
     */
    public static function withoutCodes(string $subject): string
    {
        return (string) preg_replace('/(?<![\w.,$\/-])\d{6}(?![\w.,\/-])/u', '••••••', $subject);
    }

    /** @return list<string> */
    private function recipients(RawMessage $message, ?Envelope $envelope): array
    {
        $addresses = $envelope?->getRecipients() ?? ($message instanceof Email ? [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()] : []);

        return array_values(array_unique(array_map(static fn ($address): string => AlertEmailDispatch::mask($address->getAddress()), $addresses)));
    }

    /** In plain words first, then the class of what failed — with every address in it masked. */
    private function reason(Throwable $why): string
    {
        $first = strtok($why->getMessage(), "\n") ?: $why->getMessage();
        $masked = (string) preg_replace_callback('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', static fn (array $m): string => AlertEmailDispatch::mask($m[0]), $first);

        return $this->mail->explain($why).' ('.class_basename($why).': '.mb_substr($masked, 0, 160).')';
    }
}
