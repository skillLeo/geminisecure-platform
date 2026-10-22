<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\DispatchMessage;
use App\Models\Guard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Messages between a guard and dispatch (13 D2). Board guard-app-04 screen 17.
 *
 * What a guard reads: broadcasts to their estate, messages dispatch addressed to
 * them, and what they sent. Never another guard's conversation with dispatch.
 *
 * READ STATE IS PER GUARD, PER PHONE (M4 §3.4). It used to be one column,
 * `dispatch_messages.read_at`, shared by every guard at the estate and set when ANY
 * of them replied — so one guard's reply marked a broadcast read for all the others.
 * `read_at` and `unread` here are now the CALLER's: a row in `dispatch_message_reads`
 * for this message, this guard and the handset bound to them. A message is marked read
 * by `POST /messages/read` (the app sends it when a conversation is shown), and
 * replying marks the guard's own line with dispatch read — never a broadcast, and
 * never for anybody else. Nothing was backfilled: the old shared mark cannot say whose
 * it was, so every message starts unread once for each guard.
 */
class MessagesController extends Controller
{
    public function index(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['since' => ['nullable', 'date']]);

        $rows = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->when($data['since'] ?? null, fn ($q, string $since) => $q->where('sent_at', '>', Carbon::parse($since)))
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get();

        $reads = $this->readsFor($guard, $rows->pluck('id')->all());

        return response()->json([
            'items' => $rows->map(fn (object $m): array => $this->shape($m, $reads))->values()->all(),
            'unread' => $rows->filter(static fn (object $m): bool => $m->direction !== DispatchMessage::INBOUND && ! isset($reads[(int) $m->id]))->count(),
        ]);
    }

    /**
     * Mark messages read FOR THE CALLER, on the handset bound to them. Idempotent: the
     * first read of a message is the one kept, however many times it is sent.
     *
     * An id the caller may not see — another guard's line with dispatch, another
     * estate's broadcast, a number nobody has — is left out of the answer and nothing
     * is written for it. The three are indistinguishable, and none is an error: a
     * queued mark-read replayed after a message has gone must not wedge the queue.
     */
    public function read(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $visible = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->whereIn('id', array_map('intval', $data['ids']))
            ->where('direction', '!=', DispatchMessage::INBOUND)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->orderBy('id')
            ->pluck('direction', 'id');

        $this->markRead($guard, $visible->all(), $context);

        return response()->json([
            'ids' => $visible->keys()->map(static fn ($id): int => (int) $id)->values()->all(),
            'unread' => $this->unreadCount($guard, $context->tenantId),
        ]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $now = Carbon::now();

        $id = DB::connection('mysql')->table('dispatch_messages')->insertGetId([
            'tenant_id' => $context->tenantId,
            'direction' => DispatchMessage::INBOUND,
            'guard_id' => $guard->id,
            'body' => $data['body'],
            'sent_at' => $context->serverTime,
            'device_time' => $context->deviceTime,
            'idempotency_key' => $request->header('Idempotency-Key'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /*
         * Opening the thread to write is reading it — THIS guard's own line with
         * dispatch, for THIS guard. A broadcast is a different conversation and is not
         * touched: replying to dispatch used to mark every broadcast read for every
         * guard at the estate, which is the bug M4 §3.4 names.
         */
        $theirs = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $context->tenantId)
            ->where('guard_id', $guard->id)
            ->where('direction', '!=', DispatchMessage::INBOUND)
            ->where('direction', '!=', DispatchMessage::BROADCAST)
            ->orderByDesc('sent_at')
            ->limit(100)
            ->pluck('direction', 'id');

        $this->markRead($guard, $theirs->all(), $context);

        $row = DB::connection('mysql')->table('dispatch_messages')->where('id', $id)->first();

        return response()->json([
            ...$this->shape($row, []),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $context->deviceTime?->toIso8601String(),
        ], 201);
    }

    /**
     * @param  array<int, string>  $reads  message id => when THIS guard read it on THIS handset
     * @return array<string, mixed>
     */
    private function shape(object $m, array $reads): array
    {
        $readAt = $m->direction === DispatchMessage::INBOUND ? null : ($reads[(int) $m->id] ?? null);

        return [
            'id' => (int) $m->id,
            'direction' => (string) $m->direction,
            'from' => $m->direction === DispatchMessage::INBOUND ? 'you' : 'dispatch',
            'body' => (string) $m->body,
            'sent_at' => Carbon::parse((string) $m->sent_at)->toIso8601String(),
            'read_at' => $readAt === null ? null : Carbon::parse($readAt)->toIso8601String(),
        ];
    }

    /** The handset a read belongs to: the one bound to the guard now. Empty before a UID was ever recorded. */
    private function handset(Guard $guard): string
    {
        return (string) ($guard->device_uid ?? '');
    }

    /**
     * @param  list<int|string>  $messageIds
     * @return array<int, string>
     */
    private function readsFor(Guard $guard, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        return DB::connection('mysql')->table('dispatch_message_reads')
            ->where('guard_id', $guard->id)
            ->where('device_uid', $this->handset($guard))
            ->whereIn('dispatch_message_id', $messageIds)
            ->pluck('read_at', 'dispatch_message_id')
            ->mapWithKeys(static fn ($at, $id): array => [(int) $id => (string) $at])
            ->all();
    }

    /**
     * @param  array<int|string, string>  $messages  message id => direction, already known to be the caller's to read
     */
    private function markRead(Guard $guard, array $messages, DeviceContext $context): void
    {
        if ($messages === []) {
            return;
        }

        $now = Carbon::now();

        // The first read is the one kept: a replayed or repeated mark never moves it.
        DB::connection('mysql')->table('dispatch_message_reads')->insertOrIgnore(array_map(fn (int|string $id): array => [
            'dispatch_message_id' => (int) $id,
            'guard_id' => $guard->id,
            'device_uid' => $this->handset($guard),
            'read_at' => $context->serverTime,
            'device_time' => $context->deviceTime,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_keys($messages)));

        /*
         * The old column still means something on a message addressed to ONE guard:
         * when its addressee first read it. It is never written for a broadcast again —
         * one column cannot say which of forty guards has read it.
         */
        $direct = array_keys(array_filter($messages, static fn (string $direction): bool => $direction !== DispatchMessage::BROADCAST));

        if ($direct !== []) {
            DB::connection('mysql')->table('dispatch_messages')
                ->whereIn('id', $direct)
                ->where('guard_id', $guard->id)
                ->whereNull('read_at')
                ->update(['read_at' => $context->serverTime]);
        }
    }

    /** Unread for the caller, over the same window `index` answers: the newest hundred they can see. */
    private function unreadCount(Guard $guard, string $tenantId): int
    {
        $ids = DB::connection('mysql')->table('dispatch_messages')
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('direction', DispatchMessage::BROADCAST)->orWhere('guard_id', $guard->id))
            ->orderByDesc('sent_at')
            ->limit(100)
            ->get(['id', 'direction'])
            ->filter(static fn (object $m): bool => $m->direction !== DispatchMessage::INBOUND)
            ->pluck('id')
            ->all();

        return count($ids) - count($this->readsFor($guard, $ids));
    }
}
