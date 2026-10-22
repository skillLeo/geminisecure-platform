<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\User;
use App\Services\Gemini\ClientMessaging;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WHAT GEMINI SECURITY SAID TO THIS COMMITTEE MEMBER — the delivery half of
 * `client_messages` (Q-M40, D-M128).
 *
 * THE DEFECT THIS EXISTS TO CLOSE. A Gemini operator typed a message to a
 * client's committee on board super-admin-11, the platform wrote a row and an
 * audit entry, and that was the whole of it: no inbox, no mail, no notification.
 * The screen printed "unread" beside every message ever sent, for ever, because
 * `client_messages.read_at` was rendered and never written by anything. The
 * messages are the ones that decide disputes — a guard suspended for a lapsed
 * licence, an invoice falling due, an incident overnight — and an operator who
 * believes the treasurer was told stops there.
 *
 * THE FIX IS DELIVERY, NOT BETTER WORDING. The wording was corrected first,
 * because it was untrue rather than undecided; the client then ruled for the
 * inbox (option (a)), which is the only one of the three options on the table
 * that can carry an honest read mark. An email can say "sent"; it cannot say
 * "read", and a receipt nobody can stand behind is how this defect started.
 *
 * WHERE IT LIVES, AND WHY IT IS NOT A SIDEBAR ITEM. The Estate Console sidebar
 * is ten items in four groups and the boards are the contract (`EstateNavigation`).
 * This rides the surface that already exists for "something is waiting on you":
 * the notification centre behind the dashboard bell, which no board draws and
 * which is already per-viewer. The bell carries the signal and a link; this screen
 * carries the correspondence itself.
 *
 * ADDRESSED, NOT PERMISSIONED. Every read here is keyed on `recipient_id = this
 * viewer` AND `tenant_id = this estate`, and there is no module permission in
 * front of it. A message is addressed to a named person; gating it behind, say,
 * Governance would mean a Treasurer could not read a letter written to the
 * Treasurer. There is nothing here a viewer could widen: an id in a URL is never
 * consulted, so somebody else's correspondence is not one parameter away.
 *
 * "READ" MEANS THE NAMED RECIPIENT WAS SHOWN IT IN FULL. `markOpened()` is called
 * by the screen that renders the body, for the rows it actually rendered, and only
 * the FIRST time — a later visit does not move the timestamp, because the question
 * the column answers is "when did they first have this in front of them", which is
 * the question asked when a client says they were never told.
 */
class CommitteeInbox
{
    /** How many are kept on the screen. Correspondence with one committee, not a mailbox. */
    public const LIMIT = 50;

    /**
     * Everything the inbox screen draws, newest first.
     *
     * @return list<array{id: int, subject: string, body: string, category: string, from: string, sent: string, opened: string|null}>
     */
    public function forViewer(User $viewer, string $tenantId): array
    {
        return $this->rows($viewer, $tenantId)
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'subject' => (string) $row->subject,
                'body' => (string) $row->body,
                'category' => ClientMessaging::categoryLabel((string) $row->category),
                'from' => (string) $row->sent_by_name,

                /*
                 * ON THE ESTATE'S OWN CLOCK, BY IANA NAME (M4 §3.1). The row is
                 * stored UTC; a committee member reads it at the hour their estate
                 * keeps, never the server's and never a fixed offset.
                 */
                // "21 Sep 2026, 3:14 PM": Law 5 as decided (M62 A5, DR-039), with no clock tag: the console is on
                // the estate's own clock.
                'sent' => SiteTime::local(Carbon::parse((string) $row->sent_at, 'UTC'), $tenantId)->format('j M Y, g:i A'),

                'opened' => $row->read_at === null
                    ? null
                    : SiteTime::local(Carbon::parse((string) $row->read_at, 'UTC'), $tenantId)->format('j M Y, g:i A'),
            ])
            ->all();
    }

    /**
     * The ones this viewer has not opened yet — what the bell is derived from.
     *
     * @return list<array{id: int, subject: string, from: string, at: Carbon}>
     */
    public function unopened(User $viewer, string $tenantId): array
    {
        return $this->rows($viewer, $tenantId)
            ->filter(static fn (object $row): bool => $row->read_at === null)
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'subject' => (string) $row->subject,
                'from' => (string) $row->sent_by_name,
                'at' => Carbon::parse((string) $row->sent_at, 'UTC'),
            ])
            ->values()
            ->all();
    }

    /**
     * Stamp the messages this viewer has just been shown, in full, on their screen.
     *
     * THREE CONDITIONS, ALL OF THEM LOAD-BEARING. The row must belong to THIS
     * estate, must be addressed to THIS viewer, and must not already carry a
     * timestamp. The first two mean the column can only ever record the person it
     * claims to; the third means it records the first time rather than the last.
     *
     * @param  list<int>  $ids  the message ids the screen rendered
     * @return int how many were stamped by this call
     */
    public function markOpened(User $viewer, string $tenantId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::connection('mysql')
            ->table('client_messages')
            ->whereIn('id', $ids)
            ->where('tenant_id', $tenantId)
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    /**
     * The raw rows, read once and shaped by the callers above.
     *
     * CENTRAL, from inside an estate. `client_messages` travels FROM the platform
     * TO one estate and lives in `gs_platform` so that a Gemini operator can see
     * what was said to every client from one screen; the estate reads its own by
     * `tenant_id`, exactly as the migration's own note describes. Nothing here
     * opens another estate's database, and nothing here can see another estate's
     * correspondence.
     *
     * @return Collection<int, \stdClass> the query builder's own rows, which are exactly this
     */
    private function rows(User $viewer, string $tenantId): Collection
    {
        return DB::connection('mysql')
            ->table('client_messages')
            ->where('tenant_id', $tenantId)
            ->where('recipient_id', $viewer->getKey())
            ->orderByDesc('sent_at')
            ->limit(self::LIMIT)
            ->get();
    }
}
