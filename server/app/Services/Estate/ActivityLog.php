<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\DunningNotice;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What has happened in this estate, and what still needs somebody (12 §2).
 *
 * TWO READS, ONE RULE. Neither the feed nor the notification centre keeps a
 * table of its own: a payment, an incident, a verification, a closed ticket, a
 * booking and a notice are each already a row in the table that owns that fact,
 * and a claim waiting for review is a row in `unit_claims`. Copying any of them
 * into an activity or notifications table would make a sixth copy that drifts —
 * a claim approved on board 34 would leave a notification standing that says it
 * still needs review, and the reader would believe the notification.
 *
 * WHAT IS STORED IS THE READ MARK, and only that. See the migration.
 */
class ActivityLog
{
    /** The dashboard panel's five. */
    public const DASHBOARD_ROWS = 5;

    /** One page of the full log. */
    public const PAGE = 25;

    /**
     * The estate's activity, newest first, merged from the tables that own it.
     *
     * The dashboard takes the first five of this; the full log pages through
     * it. `$perCategory` is how deep each table is read before the merge — the
     * dashboard needs one apiece, a page of twenty-five needs more, and reading
     * every row of six tables to print twenty-five is how a dashboard becomes
     * slow on the estate that has been running longest.
     *
     * THE MONEY IN IT IS GATED THE WAY THE BELL IS (M10 §5). Every estate role
     * reads this feed, and until now every one of them was told "$6,200 ·
     * receipt R-4471" under every payment. Given the viewer, it applies the
     * rule `attention()` already keeps — a row is a summary of a record, and a
     * role that may not read the record may not read the summary:
     *
     *   - A PAYMENT ROW NEEDS `estate.payments.view`. The Property Manager, the
     *     Secretary and the Head of Security hold none, and "Lot 47 paid" is a
     *     household's financial position (D-010) with or without the figure.
     *   - ITS AMOUNT NEEDS THE ESTATE'S POSITION, `Dues::maySeeEstatePosition()`
     *     on Payments. The Admin Assistant issues receipts and keeps the row and
     *     its receipt number; a running list of what each household paid is
     *     the estate's takings, and the figure goes.
     *   - A DUNNING ROW NEEDS THE ESTATE'S POSITION, not only the ledger.
     *     "Final notice sent — Lot 47" says a household is in arrears as plainly
     *     as a balance does, and a feed of them is the arrears register one
     *     reminder at a time — the list the register itself no longer gives the
     *     Admin Assistant. `Dues::maySeeEstatePosition()` includes the ledger's
     *     own `view`, so no role that lacked the row before gains it.
     *   - AN INCIDENT ROW NEEDS `estate.security.view` (B049). Incidents are the
     *     Security module's content (M10 §2), and the Secretary, the Treasurer
     *     and the Admin Assistant are refused that module's screen.
     *   - A VERIFICATION ROW NEEDS `estate.residents.view`, and a closed ticket
     *     or a booking needs `estate.facilities.view` — the same rule for the
     *     three categories left, each gated on the module whose screen shows
     *     the record. Every seeded role holds both, so no seeded reader loses a
     *     row; a role a Community Super Admin builds without them (M10 §4) was
     *     told a resident's name, and which lot booked what, from modules it
     *     is refused. The empty states on both screens say "each only to a role
     *     that may read the record it comes from", and this is what makes that
     *     true of every row rather than of two categories.
     *
     * NULL MEANS NOBODY IN PARTICULAR, and the whole feed — the shape every
     * existing caller and suite already reads. Both screens pass their viewer.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function feed(int $limit = self::DASHBOARD_ROWS, int $offset = 0, ?User $viewer = null): array
    {
        $depth = $limit + $offset + 5;
        $rows = [];

        $maySeePayments = $viewer === null || $viewer->can('estate.payments.view');
        $maySeeAmounts = $viewer === null || Dues::maySeeEstatePosition($viewer, 'payments');
        $maySeeDunning = $viewer === null || Dues::maySeeEstatePosition($viewer);
        $maySeeIncidents = $viewer === null || $viewer->can('estate.security.view');
        $maySeeResidents = $viewer === null || $viewer->can('estate.residents.view');
        $maySeeFacilities = $viewer === null || $viewer->can('estate.facilities.view');

        /*
         * NEWEST RECORDED FIRST WHEN THEY SHARE A MOMENT, AND SAID SO (M11 A2). A dues receipt
         * run gives hundreds of payments one `received_at` — 1,836 at Phoenix Park on 3 September —
         * and without a tie-break which of them came first was whatever the sort produced, which
         * differed with the depth: the dashboard's newest ten and the log's first ten were
         * different payments. `id` makes it one answer at every depth, the one the receipts
         * register already gives, and `payments_received_at_index` reads it in this order.
         */
        $payments = $maySeePayments
            ? Payment::query()->with('unit')->orderByDesc('received_at')->orderByDesc('id')->limit($depth)->get()
            : [];

        foreach ($payments as $payment) {
            $receipt = $payment->receipt_no === '' ? '' : 'receipt '.$payment->receipt_no;

            $rows[] = [
                'at' => $payment->received_at,
                'icon' => 'payment',
                'amber' => false,
                'title' => 'Payment received · '.($payment->unit->reference ?? 'unit'),

                /*
                 * THE RECEIPT, NOT THE SUM, where the sum is withheld. The
                 * number says a payment was recorded and is how the office
                 * finds it; how much a household paid is on its own ledger.
                 */
                'detail' => $maySeeAmounts
                    // To the cent, in the console's one form of money (Law 5; Hassam's batch 1 item 10).
                    ? MoneyFormatter::fromMinor($payment->amount_minor, (string) ($payment->currency ?: MoneyFormatter::DEFAULT_CURRENCY)).($receipt === '' ? '' : ' · '.$receipt)
                    : ($receipt === '' ? 'Receipt issued' : ucfirst($receipt)),
            ];
        }

        /*
         * Central table, filtered to this estate — the same reach board 26's
         * cross-tenant feed has in the other direction. See D-035.
         *
         * AND ONLY FOR A ROLE THAT HOLDS SECURITY (B049). This row was written
         * before M10 §2 gave the estate a Security module, when no estate
         * module owned an incident and every role read them. Incidents are that
         * module's content now, and the Secretary, the Treasurer and the Admin
         * Assistant hold '-' on it: "New incident report — Suspicious person"
         * is a summary of a record they are refused, and the rule above holds
         * for it exactly as it does for a payment.
         */
        $incidents = $maySeeIncidents
            ? SecurityIncident::query()
                ->where('tenant_id', (string) tenant()->getTenantKey())
                ->orderByDesc('occurred_at')
                ->limit($depth)
                ->get()
            : [];

        foreach ($incidents as $incident) {
            $rows[] = [
                'at' => $incident->occurred_at,
                'icon' => 'incident',
                'amber' => true,
                'title' => 'New incident report',
                'detail' => $incident->kind,
            ];
        }

        // The same tie-break as the payments above, for residents verified together (M11 A2).
        $verified = $maySeeResidents
            ? Resident::query()
                ->with('household.unit')
                ->whereNotNull('verified_at')
                ->orderByDesc('verified_at')
                ->orderByDesc('id')
                ->limit($depth)
                ->get()
            : [];

        foreach ($verified as $resident) {
            $rows[] = [
                'at' => $resident->verified_at,
                'icon' => 'residents',
                'amber' => false,
                'title' => 'New resident verified · '.($resident->household?->unit->reference ?? 'unit'),
                'detail' => $resident->full_name,
            ];
        }

        // Tickets closed together: the order `maintenance_tickets_closed_reported_index` reads (M11 A2).
        $closed = $maySeeFacilities
            ? MaintenanceTicket::query()
                ->with('unit')
                ->whereNotNull('closed_at')
                ->orderByDesc('closed_at')
                ->orderByDesc('reported_at')
                ->orderByDesc('id')
                ->limit($depth)
                ->get()
            : [];

        foreach ($closed as $ticket) {
            $rows[] = [
                'at' => $ticket->closed_at,
                'icon' => 'maintenance',
                'amber' => false,
                'title' => 'Maintenance ticket closed · '.($ticket->unit->reference ?? 'unit'),
                'detail' => trim((string) ($ticket->resolution ?? 'Resolved')),
            ];
        }

        $bookings = $maySeeFacilities
            ? AmenityBooking::query()
                ->with(['amenity', 'unit'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($depth)
                ->get()
            : [];

        foreach ($bookings as $booking) {
            $rows[] = [
                'at' => $booking->created_at ?? $booking->starts_at,
                'icon' => 'facility',
                'amber' => false,
                'title' => ($booking->amenity->name ?? 'Amenity').' booked · '.$booking->starts_at->format('D, M j'),
                'detail' => (string) ($booking->unit->reference ?? 'unit'),
            ];
        }

        /*
         * Dunning is on the FULL log and not on the dashboard's five, and that
         * is the board's own shape rather than an omission: the panel it draws
         * carries five categories and no reminder among them. On a log that
         * exists to answer "what did the estate do in September", a notice sent
         * to a household is exactly the kind of act somebody comes looking for.
         */
        if ($limit > self::DASHBOARD_ROWS && $maySeeDunning) {
            // A reminder run sends its notices at one moment: newest recorded first (M11 A2).
            $notices = DunningNotice::query()
                ->with('unit')
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->limit($depth)
                ->get();

            foreach ($notices as $notice) {
                $rows[] = [
                    'at' => $notice->sent_at,
                    'icon' => 'dunning',
                    'amber' => false,
                    'title' => $notice->template_label.' sent · '.($notice->unit->reference ?? 'unit'),
                    'detail' => $notice->channelLabel(),
                ];
            }
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['at'] instanceof Carbon));

        usort($rows, static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        $page = array_slice($rows, $offset, $limit);

        return [
            'rows' => array_map(fn (array $row): array => [
                'icon' => $row['icon'],
                'amber' => $row['amber'],
                'title' => $row['title'],
                'detail' => $row['detail'],
                'when' => $this->relative($row['at']),
                // On the estate's clock and saying so (M19A §4.2), whichever column the moment came from.
                'at' => SiteTime::fmt(SiteTime::local($row['at']), 'M j, Y g:i A'),

                // The dashboard panel prints one line under the title; the full
                // log prints the detail and the time in their own columns.
                'subtitle' => trim($row['detail'].' · '.$this->relative($row['at']), ' ·'),
            ], $page),
            'has_more' => count($rows) > $offset + $limit,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the notification centre */
    /* ------------------------------------------------------------------ */

    /**
     * What still needs somebody, and whether this viewer has seen it.
     *
     * DERIVED, NEVER STORED. An item that resolves stops being derived: a claim
     * approved on board 34 is gone from here the next time the bell is drawn,
     * with nothing to go back and tidy.
     *
     * GATED PER ITEM. A notification is a summary of a record, and a role that
     * may not read the record may not read the summary either — telling a
     * Property Manager that three households are ninety days late is telling
     * them what D-010 says they may not be told.
     *
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public function attention(User $viewer): array
    {
        $items = [];

        if ($viewer->can('estate.residents.view')) {
            $claims = UnitClaim::query()->where('status', UnitClaim::PENDING)->orderByDesc('created_at')->limit(20)->get();

            foreach ($claims as $claim) {
                $items[] = [
                    'key' => 'claim:'.$claim->id,
                    'icon' => 'residents',
                    'title' => 'Unit claim needs review',
                    'detail' => 'A self-claimed unit did not match the register exactly.',
                    'at' => $claim->created_at,
                ];
            }
        }

        if ($viewer->can('estate.facilities.view')) {
            $overdue = MaintenanceTicket::query()
                ->with('unit')
                ->whereNull('closed_at')
                ->orderBy('reported_at')
                ->orderBy('id')
                ->limit(50)
                ->get()
                ->filter(static fn (MaintenanceTicket $ticket): bool => $ticket->isOverdue(Carbon::now()))
                ->take(20);

            foreach ($overdue as $ticket) {
                $items[] = [
                    'key' => 'ticket:'.$ticket->id,
                    'icon' => 'maintenance',
                    'title' => $ticket->label().' is past its target',
                    'detail' => $ticket->title.' · '.$ticket->location_label,
                    'at' => $ticket->reported_at,
                ];
            }
        }

        /*
         * MESSAGES FROM GEMINI SECURITY, ADDRESSED TO THIS PERSON (Q-M40, D-M128).
         *
         * NO PERMISSION GATE, and it is the one item here that has none. Every
         * other item is a summary of an estate record, so the rule is "a role that
         * may not read the record may not read the summary". This is not a summary
         * of a record; it is correspondence written to this viewer by name. There
         * is no module it belongs to, and putting it behind one would mean a
         * Treasurer never learning that a letter to the Treasurer had arrived.
         *
         * IT LEAVES THE LIST WHEN IT IS OPENED, not when it is "marked seen" — the
         * derived-never-stored rule, with `client_messages.read_at` as the record
         * that owns the fact. Reading the message IS the resolution, which is why
         * this item, alone among them, carries a link.
         */
        /*
         * ASKED OF TENANCY, NOT ASSUMED. Every other read in this method goes
         * through the `tenant` CONNECTION and never needs to know which estate it
         * is; `client_messages` is central and keyed by `tenant_id`, so this one
         * does. A caller that has the estate's connection configured without
         * tenancy initialised — which is how several suites drive this service —
         * gets the rest of the bell rather than a fatal, and nothing is silently
         * wrong, because there is no estate to address a message to.
         */
        $estateId = tenancy()->initialized ? (string) tenant()->getTenantKey() : '';

        foreach ($estateId === '' ? [] : app(CommitteeInbox::class)->unopened($viewer, $estateId) as $message) {
            $items[] = [
                'key' => 'client-message:'.$message['id'],
                'icon' => 'notice',
                'title' => 'Message from Gemini Security: '.$message['subject'],
                'detail' => 'Written to you by '.$message['from'].'. Open it to read it.',
                'href' => $this->estatePath('/messages', $estateId),
                'at' => $message['at'],
            ];
        }

        if ($viewer->can('estate.facilities.approve')) {
            $awaiting = AmenityBooking::query()
                ->with(['amenity', 'unit'])
                ->where('deposit_state', AmenityBooking::DEPOSIT_HELD)
                ->where('ends_at', '<', Carbon::now())
                ->orderBy('ends_at')
                ->orderBy('id')
                ->limit(20)
                ->get();

            foreach ($awaiting as $booking) {
                $items[] = [
                    'key' => 'deposit:'.$booking->id,
                    'icon' => 'facility',
                    'title' => 'A deposit is still held after the booking',
                    'detail' => ($booking->amenity->name ?? 'Amenity').' · '.($booking->unit->reference ?? 'unit'),
                    'at' => $booking->ends_at,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        $read = DB::connection('tenant')
            ->table('notification_reads')
            ->where('user_id', $viewer->getKey())
            ->pluck('read_at', 'item_key');

        $unread = 0;
        $out = [];

        foreach ($items as $item) {
            $isRead = isset($read[$item['key']]);
            $unread += $isRead ? 0 : 1;

            $out[] = [
                'key' => $item['key'],
                'icon' => $item['icon'],
                'title' => $item['title'],
                'detail' => $item['detail'],
                'when' => $item['at'] instanceof Carbon ? $this->relative($item['at']) : '',
                'is_read' => $isRead,

                /*
                 * Optional, and absent on almost everything. Most items here are a
                 * summary of a record the viewer is already looking at somewhere,
                 * and a link would only be a second route to it. A message from
                 * Gemini Security has nowhere else to be read, so it carries one.
                 */
                'href' => $item['href'] ?? null,
            ];
        }

        return ['items' => $out, 'unread' => $unread];
    }

    /**
     * An estate path in whichever shape this environment serves — the dashboard's
     * own two lines, because a bell item's link has to work under both.
     *
     * Production gives each estate its own hostname, so the path is bare; local
     * serves them all from one host with the estate in the path.
     */
    private function estatePath(string $path, string $estateId): string
    {
        return app()->isLocal() && $estateId !== ''
            ? '/estate/'.$estateId.$path
            : $path;
    }

    /**
     * Mark what this viewer is looking at as seen. Per viewer, never per estate.
     *
     * @param  list<mixed>  $keys
     */
    public function markRead(User $viewer, array $keys): int
    {
        $now = Carbon::now();
        $marked = 0;

        foreach (array_unique($keys) as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                continue;
            }

            DB::connection('tenant')->table('notification_reads')->updateOrInsert(
                ['user_id' => $viewer->getKey(), 'item_key' => $key],
                ['read_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );

            $marked++;
        }

        return $marked;
    }

    /* ------------------------------------------------------------------ */
    /* formatting */
    /* ------------------------------------------------------------------ */

    /**
     * The board's own relative-time style — "2 min ago", "1 hour ago" — never
     * Carbon's spelled-out default, so a dashboard reads in the same words the
     * board draws.
     */
    public function relative(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(Carbon::now());
        $hours = intdiv($minutes, 60);
        $days = intdiv($minutes, 1440);

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 60 => $minutes.' min ago',
            $minutes < 1440 => $hours.' hour'.($hours === 1 ? '' : 's').' ago',
            $minutes < 10_080 => $days.' day'.($days === 1 ? '' : 's').' ago',
            default => $at->format('M j, Y'),
        };
    }
}
