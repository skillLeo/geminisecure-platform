<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Api\ApiError;
use App\Live\Channels;
use App\Live\Events\RecordLive;
use App\Models\ResidentAccount;
use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Platform\OperatorEstates;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "LIVE CHAT" (board resident-app-39, M50 §4.3): A RESIDENT WRITES TO GEMINI SECURITY, AND THE CONTROL ROOM ANSWERS.
 *
 *   the resident    writes from Help & support → Live chat (`support.chat.messages.store`), through the phone's queue:
 *                   with no signal a message waits on the phone and goes the moment there is one; `client_key` makes a
 *                   message sent twice one row. Their next message opens a resolved conversation again.
 *   the control     answers from the Gemini console, Dispatch → Live chat (Gemini\LiveChatController), on the estates
 *   room            it covers; a conversation waiting on an answer is in the console's bell. The answer is on the
 *                   resident's screen at once (the live `chat.changed`) and pushed (`support_reply`), and either side's
 *                   "Delivered" is stamped only when the other side was shown the message.
 *
 * "TYPICALLY REPLIES IN …" IS MEASURED, NEVER PROMISED: the median wait for the control room's first answer over the
 * last thirty days, once there are enough answers to say it (`typicalReplyMinutes`). Until then the app says who
 * answers, not how fast.
 *
 * NOT FOR EMERGENCIES, and the screen says so: a panic is the panic button, and this inbox is answered after it.
 */
class LiveChat
{
    public const MAX_BODY = 1000;

    /** The waits a typical reply time is taken from, and how many it takes before one is said. */
    public const TYPICAL_DAYS = 30;

    public const TYPICAL_MIN_ANSWERS = 5;

    /** Who answers, in the words the phone and the console both use. */
    public const ANSWERED_BY = 'Gemini Security';

    public const DEMONSTRATION = 'This is a demonstration community, so nobody reads its Live chat. In a real community, Gemini Security\'s control room answers here.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PushNotifier $push,
    ) {}

    /* ------------------------------------------------------------------ the resident's side */

    /**
     * The conversation as the resident's phone draws it, oldest first — and every answer in it is now delivered.
     *
     * @return array{id: int|null, open: bool, messages: list<array<string, mixed>>, typical_reply_minutes: int|null, answered_by: string}
     */
    public function forResident(ResidentAccount $account): array
    {
        $chat = $this->chatOf($account);

        if ($chat !== null) {
            $this->seen($chat, SupportChatMessage::STAFF);
        }

        return [
            'id' => $chat?->id,
            'open' => $chat === null || $chat->status === SupportChat::OPEN,
            'messages' => $chat === null ? [] : $this->thread($chat)->map(static fn (SupportChatMessage $m): array => [
                'id' => (int) $m->id,
                'from' => $m->author === SupportChatMessage::RESIDENT ? 'you' : 'support',
                'name' => $m->author === SupportChatMessage::STAFF ? $m->author_name : null,
                'body' => (string) $m->body,
                'at' => $m->created_at->toIso8601String(),
                'seen_at' => $m->seen_at?->toIso8601String(),
                'client_key' => $m->author === SupportChatMessage::RESIDENT ? $m->client_key : null,
            ])->values()->all(),
            'typical_reply_minutes' => $this->typicalReplyMinutes(),
            'answered_by' => self::ANSWERED_BY,
        ];
    }

    /** The resident's message. Idempotent on `client_key`; it opens a resolved conversation again. */
    public function fromResident(ResidentAccount $account, string $body, ?string $clientKey): SupportChatMessage
    {
        $text = self::clean($body);
        $tenant = (string) $account->tenant_id;

        if ($text === '') {
            throw ApiError::unprocessable('message_empty', 'Write a message first.');
        }

        if (OperatorEstates::isDemonstration($tenant)) {
            throw ApiError::conflict('demonstration_community', self::DEMONSTRATION);
        }

        $chat = $this->chatOf($account);

        if ($chat !== null && $clientKey !== null && ($already = $this->byClientKey($chat, $clientKey)) !== null) {
            return $already;
        }

        $now = Carbon::now();

        try {
            $message = DB::connection('mysql')->transaction(function () use ($account, $chat, $tenant, $text, $clientKey, $now): SupportChatMessage {
                $chat ??= SupportChat::query()->firstOrCreate(['tenant_id' => $tenant, 'resident_account_id' => (int) $account->id], ['status' => SupportChat::OPEN]);

                $message = SupportChatMessage::query()->create([
                    'support_chat_id' => $chat->id,
                    'author' => SupportChatMessage::RESIDENT,
                    'author_name' => $account->full_name !== null ? mb_substr((string) $account->full_name, 0, 80) : null,
                    'body' => $text,
                    'client_key' => $clientKey,
                ]);

                // Open again, newest in the console's list, and waiting on an answer from this message if it was not already.
                $chat->forceFill([
                    'status' => SupportChat::OPEN,
                    'last_message_at' => $now,
                    'waiting_since' => $chat->waiting_since ?? $now,
                    'resolved_at' => null,
                    'resolved_by' => null,
                ])->save();

                $this->tell($chat);

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // The same message from the queue and a retry at the same moment: the first one's row.
            $chat = $this->chatOf($account);

            return ($chat !== null && $clientKey !== null ? $this->byClientKey($chat, $clientKey) : null)
                ?? throw ApiError::conflict('message_conflict', 'That message is already being sent. Pull down to see it.');
        }

        return $message;
    }

    /* ------------------------------------------------------------------ the control room's side */

    /**
     * The conversations a member of staff may answer — the estates they cover, never the demonstration one — those
     * waiting on an answer first, the longest-waiting at the top, then the rest, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(User $viewer, int $limit = 60): array
    {
        $query = SupportChat::query()->with(['account', 'estate'])->whereNotNull('last_message_at');
        OperatorEstates::narrow($query, $viewer);

        $chats = $query->orderByRaw('waiting_since IS NULL')->orderBy('waiting_since')->orderByDesc('last_message_at')->limit($limit)->get();
        $last = SupportChatMessage::query()->whereIn('id', SupportChatMessage::query()->selectRaw('MAX(id)')->whereIn('support_chat_id', $chats->pluck('id'))->groupBy('support_chat_id'))
            ->get()->keyBy('support_chat_id');
        $unread = SupportChatMessage::query()->whereIn('support_chat_id', $chats->pluck('id'))->where('author', SupportChatMessage::RESIDENT)->whereNull('seen_at')
            ->selectRaw('support_chat_id, COUNT(*) AS n')->groupBy('support_chat_id')->pluck('n', 'support_chat_id');

        return $chats->map(fn (SupportChat $c): array => [
            'id' => (int) $c->id,
            'resident' => $this->residentName($c),
            'estate' => (string) ($c->estate->name ?? $c->tenant_id),
            'status' => $c->status,
            'waiting_since' => $c->waiting_since?->toIso8601String(),
            'last_message_at' => $c->last_message_at?->toIso8601String(),
            'last' => isset($last[$c->id]) ? ['from' => $last[$c->id]->author, 'body' => mb_substr((string) $last[$c->id]->body, 0, 120)] : null,
            'unread' => (int) ($unread[$c->id] ?? 0),
        ])->values()->all();
    }

    /** One conversation this member of staff may answer, or null when it is not on an estate they cover. */
    public function find(User $viewer, int $id): ?SupportChat
    {
        $query = SupportChat::query()->with(['account', 'estate'])->whereKey($id);
        OperatorEstates::narrow($query, $viewer);

        return $query->first();
    }

    /**
     * The conversation as the console draws it — and every message from the resident in it is now delivered.
     *
     * @return array<string, mixed>
     */
    public function forConsole(SupportChat $chat): array
    {
        $this->seen($chat, SupportChatMessage::RESIDENT);

        return [
            'id' => (int) $chat->id,
            'resident' => $this->residentName($chat),
            'signs_in_with' => (string) ($chat->account->destination ?? ''),
            'estate' => (string) ($chat->estate->name ?? $chat->tenant_id),
            'status' => $chat->status,
            'waiting_since' => $chat->waiting_since?->toIso8601String(),
            'messages' => $this->thread($chat)->map(static fn (SupportChatMessage $m): array => [
                'id' => (int) $m->id,
                'from' => $m->author,
                'name' => $m->author_name,
                'body' => (string) $m->body,
                'at' => $m->created_at->toIso8601String(),
                'seen_at' => $m->seen_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** The control room's answer: on the resident's screen at once, and pushed. A refusal is a DomainException's words. */
    public function fromStaff(SupportChat $chat, User $staff, string $body): SupportChatMessage
    {
        $text = self::clean($body);

        if ($text === '') {
            throw new DomainException('Write the answer first.');
        }

        $name = trim((string) strtok((string) ($staff->name ?? ''), ' '));

        $message = DB::connection('mysql')->transaction(function () use ($chat, $staff, $text, $name): SupportChatMessage {
            $message = SupportChatMessage::query()->create([
                'support_chat_id' => $chat->id,
                'author' => SupportChatMessage::STAFF,
                'user_id' => (int) $staff->id,
                'author_name' => $name === '' ? null : mb_substr($name, 0, 80),
                'body' => $text,
            ]);

            // Answered: nothing is waiting any more, and the resident's messages have been read by the one answering.
            $chat->forceFill(['last_message_at' => Carbon::now(), 'waiting_since' => null])->save();
            SupportChatMessage::query()->where('support_chat_id', $chat->id)->where('author', SupportChatMessage::RESIDENT)->whereNull('seen_at')->update(['seen_at' => Carbon::now()]);

            $this->tell($chat);

            return $message;
        });

        // On the lock screen too. Not urgent — Live chat is not for emergencies — and never muted: it is the answer they asked for.
        $this->push->toAccount((string) $chat->tenant_id, (int) $chat->resident_account_id, new PushMessage(
            type: 'support_reply',
            title: 'Gemini Security replied',
            body: $text,
            id: (int) $chat->id,
        ));

        rescue(fn () => $this->audit->record(
            action: 'support.chat_answered',
            entityType: 'SupportChat',
            entityId: (string) $chat->id,
            after: ['message_id' => (int) $message->id, 'length' => mb_strlen($text)],
            tenantId: (string) $chat->tenant_id,
            actor: $staff,
        ), report: false);

        return $message;
    }

    /** Resolved: off the waiting list. The resident's next message opens it again. */
    public function resolve(SupportChat $chat, User $staff): void
    {
        if ($chat->status === SupportChat::RESOLVED) {
            return;
        }

        DB::connection('mysql')->transaction(function () use ($chat, $staff): void {
            $chat->forceFill(['status' => SupportChat::RESOLVED, 'waiting_since' => null, 'resolved_at' => Carbon::now(), 'resolved_by' => (int) $staff->id])->save();
            $this->tell($chat);
        });

        rescue(fn () => $this->audit->record(
            action: 'support.chat_resolved',
            entityType: 'SupportChat',
            entityId: (string) $chat->id,
            tenantId: (string) $chat->tenant_id,
            actor: $staff,
        ), report: false);
    }

    /**
     * How long the control room typically takes to answer, in whole minutes rounded up — the median wait, over the
     * last thirty days, from a resident's first unanswered message to the answer — or null with fewer than five answers
     * to measure: then the app says who answers, never a speed nobody has measured.
     */
    public function typicalReplyMinutes(): ?int
    {
        $since = Carbon::now()->subDays(self::TYPICAL_DAYS);
        $waits = [];

        SupportChatMessage::query()
            ->whereIn('support_chat_id', SupportChatMessage::query()->select('support_chat_id')->where('author', SupportChatMessage::STAFF)->where('created_at', '>=', $since))
            ->where('created_at', '>=', $since->copy()->subDay())
            ->orderBy('support_chat_id')->orderBy('id')
            ->get(['support_chat_id', 'author', 'created_at'])
            ->groupBy('support_chat_id')
            ->each(static function (Collection $thread) use (&$waits, $since): void {
                $asked = null;
                foreach ($thread as $m) {
                    if ($m->author === SupportChatMessage::RESIDENT) {
                        $asked ??= $m->created_at;
                    } elseif ($asked !== null) {
                        if ($m->created_at->greaterThanOrEqualTo($since)) {
                            $waits[] = max(0, $asked->diffInSeconds($m->created_at));
                        }
                        $asked = null;
                    }
                }
            });

        if (count($waits) < self::TYPICAL_MIN_ANSWERS) {
            return null;
        }

        sort($waits);
        $middle = intdiv(count($waits), 2);
        $median = count($waits) % 2 === 1 ? $waits[$middle] : ($waits[$middle - 1] + $waits[$middle]) / 2;

        return max(1, (int) ceil($median / 60));
    }

    /** Conversations waiting on an answer, for the console's bell: the longest-waiting first. */
    public function waiting(User $viewer, int $limit): Collection
    {
        $query = SupportChat::query()->with(['account', 'estate'])->whereNotNull('waiting_since')->where('status', SupportChat::OPEN);
        OperatorEstates::narrow($query, $viewer);

        return $query->orderBy('waiting_since')->limit($limit)->get();
    }

    public function residentName(SupportChat $chat): string
    {
        return (string) ($chat->account->full_name ?? '') !== '' ? (string) $chat->account->full_name : 'A resident';
    }

    /* ------------------------------------------------------------------ internals */

    private function chatOf(ResidentAccount $account): ?SupportChat
    {
        return SupportChat::query()->where('tenant_id', (string) $account->tenant_id)->where('resident_account_id', (int) $account->id)->first();
    }

    /** @return Collection<int, SupportChatMessage> the newest two hundred, oldest first */
    private function thread(SupportChat $chat): Collection
    {
        return SupportChatMessage::query()->where('support_chat_id', $chat->id)->orderByDesc('id')->limit(200)->get()->reverse()->values();
    }

    private function byClientKey(SupportChat $chat, string $clientKey): ?SupportChatMessage
    {
        return SupportChatMessage::query()->where('support_chat_id', $chat->id)->where('client_key', $clientKey)->first();
    }

    private function seen(SupportChat $chat, string $author): void
    {
        $changed = SupportChatMessage::query()->where('support_chat_id', $chat->id)->where('author', $author)->whereNull('seen_at')
            ->update(['seen_at' => Carbon::now()]);

        // "Delivered" on the other side's screen as it happens — only when something changed, so a read never echoes.
        if ($changed > 0) {
            $this->tell($chat);
        }
    }

    /** The console's Live chat tab and bell, and the resident's own Live chat screen, read the conversation again. */
    private function tell(SupportChat $chat): void
    {
        $tenant = (string) $chat->tenant_id;

        rescue(static fn () => RecordLive::tell('chat', (int) $chat->id, $tenant, static fn (): array => [
            ...Channels::gemini('dispatch', $tenant),
            Channels::resident($tenant, (int) $chat->resident_account_id),
        ]), report: true);
    }

    private static function clean(string $body): string
    {
        $one = trim((string) preg_replace("/[ \t]+/u", ' ', str_replace(["\r\n", "\r"], "\n", $body)));

        return mb_substr($one, 0, self::MAX_BODY);
    }
}
