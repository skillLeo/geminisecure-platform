<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Support\TableQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * EVERY EMAIL SENT, AND WHAT BECAME OF IT — the Gemini console's "Recent emails" (M18G §7).
 *
 *   accepted   Laravel's `MessageSent`: the mail server took it. Never "delivered" — a bounce comes back later, to the
 *              sending mailbox, and this relay reports none to the platform (CLIENT_ACTIONS: a provider that does).
 *   failed     ResilientSmtpTransport gave up on it (`MailFailures::record` writes here too), with the reason.
 *   not_sent   addressed to a person a seeder invented, and never handed to the mail server (M35 §5,
 *              `SeededRecipients`). The recipient column names the ROW — "residents #92 at phoenixparkvillage1" — and
 *              never the address.
 *
 * One row per recipient. The type is the notification's name when a notification sent it ("Resident sign-in code"),
 * else "Email". The subject has any code dotted out (`MailFailures::withoutCodes`); the body is never kept. Nothing
 * here may stop a send: every write is rescued.
 */
final class MailLog
{
    /** How long a row is kept. */
    public const KEEP_DAYS = 60;

    public static function sent(MessageSent $event): void
    {
        $notification = $event->data['__laravel_notification'] ?? null;
        self::write($event->message, is_string($notification) ? $notification : null, 'accepted', null, null);
    }

    public static function failed(RawMessage $message, string $reason, string $relay): void
    {
        self::write($message, null, 'failed', $reason, $relay);
    }

    /** An email NOT handed to the mail server: its one recipient was a seeded person, named by row (M35 §5). */
    public static function notSent(RawMessage $message, string $type, string $row, ?string $tenant): void
    {
        try {
            $now = Carbon::now();

            DB::connection('mysql')->table('mail_log')->insert([
                'sent_at' => $now,
                'recipient' => mb_substr($row, 0, 254),
                'type' => mb_substr($type, 0, 80),
                'subject' => $message instanceof Email ? mb_substr(MailFailures::withoutCodes((string) $message->getSubject()), 0, 200) : '',
                'outcome' => 'not_sent',
                'reason' => 'Not sent: addressed to a person a seeder invented, not a real one.',
                'relay' => null,
                'tenant_id' => $tenant,
                'created_at' => $now,
            ]);
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }

    /**
     * What the log's table orders by (M32 F5).
     *
     * @return array<string, string>
     */
    public static function sorts(): array
    {
        return ['at' => 'sent_at', 'recipient' => 'recipient', 'type' => 'type', 'outcome' => 'outcome'];
    }

    /** The log as the address asks for it (M32 F5): newest first, `q` the address searched for. */
    public static function table(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sorts(), 'at', 'desc');
    }

    /**
     * EVERY EMAIL OF THE SIXTY DAYS KEPT, A PAGE AT A TIME (M32 F5). The screens used to draw the latest hundred, and
     * the hundred-and-first send — the one somebody is asking about — was on neither of them.
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    public static function page(TableQuery $table): array
    {
        $query = self::matching($table->search);
        $table->order($query, 'id');
        $page = $table->paginateRows($query);

        return [
            'rows' => array_values($page->getCollection()->map(self::row(...))->all()),
            'table' => $table->meta($page),
        ];
    }

    /** The log, to an address containing `$search` when given. */
    private static function matching(?string $search): Builder
    {
        $term = mb_strtolower(trim((string) $search));

        return DB::connection('mysql')->table('mail_log')
            ->when($term !== '', static fn ($q) => $q->where('recipient', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->select(['id', 'sent_at', 'recipient', 'type', 'subject', 'outcome', 'reason']);
    }

    /** @return array{id: int, at: string, recipient: string, type: string, subject: string, outcome: string, reason: string|null} */
    private static function row(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'at' => Carbon::parse((string) $r->sent_at)->format('D j M Y, g:i:s A').' UTC',
            'recipient' => (string) $r->recipient,
            'type' => (string) $r->type,
            'subject' => MailFailures::withoutCodes((string) $r->subject),
            'outcome' => (string) $r->outcome,
            'reason' => $r->reason === null ? null : (string) $r->reason,
        ];
    }

    public static function prune(): int
    {
        return DB::connection('mysql')->table('mail_log')->where('sent_at', '<', Carbon::now()->subDays(self::KEEP_DAYS))->delete();
    }

    private static function write(RawMessage $message, ?string $notification, string $outcome, ?string $reason, ?string $relay): void
    {
        try {
            if (! $message instanceof Email) {
                return;
            }

            $recipients = array_map(static fn (Address $a): string => mb_strtolower($a->getAddress()), [...$message->getTo(), ...$message->getCc(), ...$message->getBcc()]);
            $now = Carbon::now();
            $type = self::typeOf($notification);
            $subject = mb_substr(MailFailures::withoutCodes((string) $message->getSubject()), 0, 200);
            $tenant = function_exists('tenant') && tenant() !== null ? (string) tenant()->getTenantKey() : null;

            foreach (array_unique($recipients) as $to) {
                DB::connection('mysql')->table('mail_log')->insert([
                    'sent_at' => $now,
                    'recipient' => mb_substr($to, 0, 254),
                    'type' => $type,
                    'subject' => $subject,
                    'outcome' => $outcome,
                    'reason' => $reason === null ? null : mb_substr($reason, 0, 400),
                    'relay' => $relay === null ? null : mb_substr($relay, 0, 120),
                    'tenant_id' => $tenant,
                    'created_at' => $now,
                ]);
            }
        } catch (Throwable $e) {
            rescue(static fn () => report($e), report: false);
        }
    }

    /** "ResidentSignInCode" → "Resident sign-in code". */
    public static function typeOf(?string $notification): string
    {
        if ($notification === null || $notification === '') {
            return 'Email';
        }

        $words = trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', class_basename($notification)));

        return mb_substr(ucfirst(mb_strtolower($words)), 0, 80);
    }
}
