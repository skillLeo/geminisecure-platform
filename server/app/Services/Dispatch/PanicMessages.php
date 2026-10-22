<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Api\ApiError;
use App\Live\Events\AlertLive;
use App\Live\LiveBus;
use App\Models\DuressAlert;
use App\Models\DuressAlertMessage;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "MESSAGE DISPATCH…" — THE RESIDENT AND THE CONTROL ROOM, WRITING TO EACH OTHER WHILE A PANIC IS OPEN (board
 * resident-app-06, its "Live channel with dispatch"; M50 §4.2 #2).
 *
 *   the resident    writes from the panic screen (`duress.messages.store`), through the phone's queue: with no
 *                   signal it waits on the phone and goes the moment there is one, never dropped; `client_key` makes
 *                   a message sent twice one row
 *   the control     replies from the alert's own page on the Gemini console (DispatchController::reply); the reply
 *   room            is on the resident's screen at once — the live step `alert.message` makes the panic screen read
 *                   its alert again — and pushed as an urgent panic update, which no switch and no quiet hours hold
 *
 * DELIVERED, NEVER GUESSED. `seen_at` is stamped when the other side was SHOWN a message: the console drew the alert's
 * page with it, or the phone read its alert back. Each side sees "Sent" until then, and "Delivered" after.
 *
 * ONLY A RESIDENT'S PANIC, ONLY WHILE IT IS OPEN. A guard's duress has the officer's own channel (DispatchMessaging);
 * once an alert is resolved, closed as a false alarm or cancelled, nobody is reading this thread, and saying a message
 * went somewhere it did not is the one thing an emergency screen must never do — so a message to a closed alert is
 * refused, in words that say what to do instead.
 */
class PanicMessages
{
    public const MAX_BODY = 500;

    /** The statuses no one is answering any more. */
    private const CLOSED = ['resolved', 'false_alarm'];

    public const CLOSED_FOR_RESIDENT = 'This alert is closed, so the control room is no longer reading messages on it. If you still need help, press the panic button again or call 119.';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PushNotifier $push,
    ) {}

    /**
     * The thread as the resident's phone draws it, oldest first.
     *
     * @return list<array{id: int, from: string, name: string|null, body: string, at: string, seen_at: string|null, client_key: string|null}>
     */
    public function forResident(DuressAlert $alert): array
    {
        if ($alert->raised_by_account_id === null) {
            return [];
        }

        // Raised in this very request: nobody has had the chance to write on it, so the panic press — the most urgent
        // write there is — asks the database nothing more for its thread (AppApiWriteBudgetTest holds it to that).
        // A press the phone sends again is handed the alert it already raised, read back, and so gets its thread.
        if ($alert->wasRecentlyCreated) {
            return [];
        }

        return $this->thread($alert)->map(static fn (DuressAlertMessage $m): array => [
            'id' => (int) $m->id,
            'from' => $m->author === DuressAlertMessage::RESIDENT ? 'you' : 'dispatch',
            'name' => $m->author === DuressAlertMessage::DISPATCH ? $m->author_name : null,
            'body' => (string) $m->body,
            'at' => $m->created_at->toIso8601String(),
            'seen_at' => $m->seen_at?->toIso8601String(),
            'client_key' => $m->author === DuressAlertMessage::RESIDENT ? $m->client_key : null,
        ])->values()->all();
    }

    /**
     * The thread as the console draws it.
     *
     * @return list<array{id: int, from: string, name: string|null, body: string, at: string, seen_at: string|null}>
     */
    public function forConsole(DuressAlert $alert): array
    {
        return $this->thread($alert)->map(static fn (DuressAlertMessage $m): array => [
            'id' => (int) $m->id,
            'from' => $m->author,
            'name' => $m->author_name,
            'body' => (string) $m->body,
            'at' => $m->created_at->toIso8601String(),
            'seen_at' => $m->seen_at?->toIso8601String(),
        ])->values()->all();
    }

    /** The resident's message. Idempotent on `client_key`. Refused on a closed alert (409 `alert_closed`). */
    public function fromResident(DuressAlert $alert, ResidentAccount $account, string $body, ?string $clientKey): DuressAlertMessage
    {
        $text = self::clean($body);

        if ($text === '') {
            throw ApiError::unprocessable('message_empty', 'Write a message first.');
        }

        if ($clientKey !== null && ($already = $this->byClientKey($alert, $clientKey)) !== null) {
            return $already;
        }

        if (in_array($alert->status, self::CLOSED, true)) {
            throw ApiError::conflict('alert_closed', self::CLOSED_FOR_RESIDENT);
        }

        try {
            $message = DuressAlertMessage::query()->create([
                'duress_alert_id' => $alert->id,
                'tenant_id' => (string) $alert->tenant_id,
                'author' => DuressAlertMessage::RESIDENT,
                'resident_account_id' => (int) $account->id,
                'author_name' => $account->full_name !== null ? mb_substr((string) $account->full_name, 0, 80) : null,
                'body' => $text,
                'client_key' => $clientKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same message from the queue and a retry at the same moment: the first one's row.
            return $this->byClientKey($alert, (string) $clientKey) ?? throw ApiError::conflict('message_conflict', 'That message is already being sent. Pull down to see it.');
        }

        $this->tell($alert);

        rescue(fn () => $this->audit->record(
            action: 'panic.message_from_resident',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            after: ['message_id' => (int) $message->id, 'length' => mb_strlen($text)],
            tenantId: (string) $alert->tenant_id,
        ), report: false);

        return $message;
    }

    /**
     * The control room's reply. Only to a resident's panic, only while it is open — a refusal is a DomainException
     * whose message the console shows under the box.
     */
    public function fromDispatch(DuressAlert $alert, User $dispatcher, string $body): DuressAlertMessage
    {
        $text = self::clean($body);

        if ($alert->raised_by_account_id === null) {
            throw new DomainException('This alert was not raised from the Resident App, so there is no resident to message. Use the officer message box.');
        }

        if ($text === '') {
            throw new DomainException('Write the reply first.');
        }

        if (in_array($alert->status, self::CLOSED, true)) {
            throw new DomainException('This alert is closed. The resident\'s panic screen has closed too, so a reply would not be seen.');
        }

        $name = trim((string) strtok((string) ($dispatcher->name ?? ''), ' '));

        $message = DuressAlertMessage::query()->create([
            'duress_alert_id' => $alert->id,
            'tenant_id' => (string) $alert->tenant_id,
            'author' => DuressAlertMessage::DISPATCH,
            'user_id' => (int) $dispatcher->id,
            'author_name' => $name === '' ? null : mb_substr($name, 0, 80),
            'body' => $text,
        ]);

        $this->tell($alert);

        // On the lock screen too: an urgent panic update, which no category switch and no quiet hours hold back.
        $this->push->toAccount((string) $alert->tenant_id, (int) $alert->raised_by_account_id, new PushMessage(
            type: 'panic_update',
            title: 'Message from the control room',
            body: $text,
            id: (int) $alert->id,
            kind: 'message',
        ));

        rescue(fn () => $this->audit->record(
            action: 'panic.message_from_dispatch',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            after: ['message_id' => (int) $message->id, 'length' => mb_strlen($text)],
            tenantId: (string) $alert->tenant_id,
            actor: $dispatcher,
        ), report: false);

        return $message;
    }

    /** The phone read its alert back: every reply from the control room on it has now been shown to the resident. */
    public function seenByResident(DuressAlert $alert): void
    {
        $this->seen($alert, DuressAlertMessage::DISPATCH);
    }

    /** The console drew the alert's page: every message from the resident on it has now been shown to the control room. */
    public function seenByDispatch(DuressAlert $alert): void
    {
        $this->seen($alert, DuressAlertMessage::RESIDENT);
    }

    /** @return \Illuminate\Support\Collection<int, DuressAlertMessage> */
    private function thread(DuressAlert $alert)
    {
        return DuressAlertMessage::query()->where('duress_alert_id', $alert->id)->orderBy('id')->limit(200)->get();
    }

    private function byClientKey(DuressAlert $alert, string $clientKey): ?DuressAlertMessage
    {
        return DuressAlertMessage::query()->where('duress_alert_id', $alert->id)->where('client_key', $clientKey)->first();
    }

    private function seen(DuressAlert $alert, string $author): void
    {
        $changed = DuressAlertMessage::query()->where('duress_alert_id', $alert->id)->where('author', $author)->whereNull('seen_at')
            ->update(['seen_at' => Carbon::now()]);

        // "Delivered" on the other side's screen as it happens — only when something changed, so a read never echoes.
        if ($changed > 0) {
            $this->tell($alert);
        }
    }

    /** Every screen showing this alert reads it again: the resident's panic screen, its console page, the queue. */
    private function tell(DuressAlert $alert): void
    {
        rescue(static fn () => DB::connection($alert->getConnectionName())->transaction(static fn () => LiveBus::recordAll(AlertLive::for($alert, 'message'))), report: true);
    }

    private static function clean(string $body): string
    {
        $one = trim((string) preg_replace("/[ \t]+/u", ' ', str_replace(["\r\n", "\r"], "\n", $body)));

        return mb_substr($one, 0, self::MAX_BODY);
    }
}
