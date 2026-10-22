<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Invoice;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\StatutoryFiling;
use App\Models\User;
use App\Services\Dispatch\ShiftAutoClose;
use App\Services\Dispatch\ShiftClock;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Platform\MailFailures;
use App\Services\Platform\OperatorEstates;
use App\Services\Support\LiveChat;
use App\Support\PlatformCalendar;
use App\Support\PlatformMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Gemini Console's notification centre — board super-admin-01's bell (12 §2,
 * item 41): "read/unread model, feeds from existing events".
 *
 * DERIVED, NEVER STORED. Every item is a row in the table that owns the fact —
 * an alert still open, a request still pending, a licence about to lapse, an
 * incident nobody has closed, an invoice past due, a return owed — and an item
 * stops appearing the moment its record is dealt with. Only the read mark is
 * kept (`platform_notification_reads`).
 *
 * GATED PER ITEM, AND SCOPED. A notification is a summary of a record, so a
 * role that may not open the record does not get the summary; and a Head of
 * Security scoped to assigned sites is told only about the sites they cover.
 */
final class PlatformNotifications
{
    /** A licence inside this many days of lapsing needs somebody now. */
    private const LICENCE_WINDOW_DAYS = 30;

    private const PER_KIND = 20;

    /**
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public function attention(User $viewer): array
    {
        $items = [];

        /*
         * SCOPE AND THE DEMONSTRATION ESTATE, in one call on every read below
         * (M5 §3).
         *
         * The bell is the console's quietest life-safety surface and its most
         * easily missed one: a panic raised in the demonstration estate by an
         * app-store reviewer arrived here marked `urgent`, named the estate, and
         * linked straight to the response screen — the same row a real panic
         * produces. `OperatorEstates::narrow()` is used rather than the old
         * `when($scoped, …)` pair because the two halves of the question must
         * travel together: every reader here had the scope half and none of them
         * had the other, which is exactly what happens when a rule is copied by
         * hand into eight queries.
         */
        /*
         * ONE PARAMETER, deliberately. `tap()` invokes its callback as
         * `$callback($query, true)` — the `when()` it delegates to passes the
         * condition through — so a second typed parameter here would be handed
         * a bool and this file declares strict_types. Every reader below is
         * keyed on a plain `tenant_id`; the one that is not builds its own
         * closure at the call site.
         */
        $narrow = static function ($query) use ($viewer) {
            OperatorEstates::narrow($query, $viewer);

            return $query;
        };

        if ($viewer->can('gemini.dispatch.view')) {
            foreach (DuressAlert::query()->active()->with('estate')->tap($narrow)->limit(self::PER_KIND)->get() as $alert) {
                $items[] = [
                    'key' => 'alert:'.$alert->id.':'.$alert->status,
                    'icon' => 'alert',
                    'urgent' => true,
                    // A drill says so first (M10 §1.3): the bell is where it is least expected.
                    'title' => $alert->drillPrefix().ucfirst($alert->kind).' alert at '.($alert->estate->name ?? $alert->tenant_id),
                    'detail' => 'Status: '.str_replace('_', ' ', $alert->status).($alert->raised_by_name === null ? '' : ' · raised by '.$alert->raised_by_name),
                    'href' => '/dispatch/alerts/'.$alert->id,
                    'at' => $alert->server_time,
                ];
            }

            foreach (GuardRequest::query()->pending()->with(['officer', 'estate'])->tap($narrow)->orderByDesc('created_at')->limit(self::PER_KIND)->get() as $request) {
                $items[] = [
                    'key' => 'request:'.$request->id,
                    'icon' => 'inbox',
                    'urgent' => false,
                    'title' => ucfirst($request->kind).' request waiting: '.($request->officer->full_name ?? 'a guard'),
                    'detail' => $request->subject,
                    'href' => '/dispatch/requests',
                    'at' => $request->created_at,
                ];
            }

            // A resident waiting on an answer on Live chat (M50 §4.3) — keyed on when they started waiting, so the
            // next unanswered question after an answer is a new notification, not one already read.
            $chats = app(LiveChat::class);
            foreach ($chats->waiting($viewer, self::PER_KIND) as $chat) {
                $items[] = [
                    'key' => 'chat:'.$chat->id.':'.$chat->waiting_since?->getTimestamp(),
                    'icon' => 'inbox',
                    'urgent' => false,
                    'title' => 'Live chat waiting: '.$chats->residentName($chat),
                    'detail' => (string) ($chat->estate->name ?? $chat->tenant_id),
                    'href' => '/dispatch/chat?chat='.$chat->id,
                    'at' => $chat->waiting_since,
                ];
            }
        }

        if ($viewer->can('gemini.guard_workforce.view')) {
            $lapsing = Guard::query()
                ->tap($narrow)
                ->whereNotIn('status', ['inactive'])
                ->whereNotNull('psra_expires_on')
                ->whereDate('psra_expires_on', '<=', PlatformCalendar::today()->addDays(self::LICENCE_WINDOW_DAYS))
                ->orderBy('psra_expires_on')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($lapsing as $guard) {
                $expires = $guard->psra_expires_on;
                $lapsed = $expires !== null && $expires->lessThan(PlatformCalendar::today());

                $items[] = [
                    // The expiry is in the key, so a renewed licence that later
                    // approaches its NEW date is a new notification, not a read one.
                    'key' => 'licence:'.$guard->id.':'.$expires?->toDateString(),
                    'icon' => 'licence',
                    'urgent' => $lapsed,
                    'title' => $lapsed ? 'PSRA licence lapsed: '.$guard->full_name : 'PSRA licence lapses soon: '.$guard->full_name,
                    'detail' => ($lapsed ? 'Expired ' : 'Expires ').$expires?->format('M j, Y'),
                    'href' => '/guards/'.$guard->id,
                    'at' => $expires?->copy()->startOfDay(),
                ];
            }

            /*
             * "Every use ... is reported to the Head of Security" (M4 §3.4). A shift
             * started on a supervisor's PIN, because the phone could not confirm who
             * was holding it. Derived from the flag on the shift like everything else
             * here, scoped like everything else here, and it leaves the list on its
             * own after `REPORT_DAYS` — reading it does not unflag the shift.
             */
            $overridden = Shift::query()->with(['officer', 'estate'])
                ->tap($narrow)
                ->whereNotNull('overridden_at')
                ->where('overridden_at', '>=', Carbon::now()->subDays(SupervisorOverride::REPORT_DAYS))
                ->orderByDesc('overridden_at')
                ->limit(self::PER_KIND)
                ->get();

            $vouchers = User::query()->whereIn('id', $overridden->pluck('overridden_by')->filter()->unique()->all())->pluck('name', 'id');

            foreach ($overridden as $shift) {
                $items[] = [
                    'key' => 'override:'.$shift->id,
                    'icon' => 'licence',
                    'urgent' => (bool) $shift->mock_location_flag,
                    'title' => 'Supervisor override at clock-in: '.($shift->officer->full_name ?? 'a guard'),
                    'detail' => ($shift->estate->name ?? $shift->tenant_id)
                        .' · vouched for by '.($vouchers[$shift->overridden_by] ?? 'a supervisor')
                        .' · '.match ($shift->override_reason) {
                            SupervisorOverride::REASON_NO_BIOMETRICS => 'phone has no fingerprint or face set up',
                            SupervisorOverride::REASON_OUTSIDE_GEOFENCE => 'outside the geofence'.($shift->geofence_distance_m === null ? '' : ', '.$shift->geofence_distance_m.' m from the post'),
                            SupervisorOverride::REASON_POSITION_UNCONFIRMED => 'position could not be established'.($shift->clock_in_accuracy_m === null ? '' : ' (±'.$shift->clock_in_accuracy_m.' m)'),
                            default => 'fingerprint or face did not match',
                        }
                        .($shift->mock_location_flag ? ' · MOCKED LOCATION reported' : ''),
                    'href' => $shift->guard_id === null ? '/guards' : '/guards/'.$shift->guard_id,
                    'at' => $shift->overridden_at,
                ];
            }

            /*
             * A CLOCK-IN TO REVIEW (M12 §1.2, §1.4): a start with something a supervisor must look at — a mocked
             * location, a queued start judged outside when it arrived, no geofence configured, a phone clock that
             * disagreed or was changed. Derived from `review_reasons` like the override above, scoped the same way,
             * gone on its own after REPORT_DAYS. A start that was ALSO overridden is already listed above. Urgent
             * only for a mocked location, which is the one that may mean somebody is not where they say.
             */
            $toReview = Shift::query()->with(['officer', 'estate', 'post'])
                ->tap($narrow)
                ->whereNotNull('review_reasons')
                ->whereNull('overridden_at')
                ->where('actual_start', '>=', Carbon::now()->subDays(SupervisorOverride::REPORT_DAYS))
                ->orderByDesc('actual_start')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($toReview as $shift) {
                $reasons = $shift->review_reasons ?? [];

                $items[] = [
                    'key' => 'clockreview:'.$shift->id,
                    'icon' => 'licence',
                    'urgent' => in_array(ShiftClock::REVIEW_MOCK_LOCATION, $reasons, true),
                    'title' => 'Clock-in to review: '.($shift->officer->full_name ?? 'a guard'),
                    'detail' => ($shift->estate->name ?? $shift->tenant_id).' · '.($shift->post?->label() ?? 'a post').' · '.ShiftClock::reviewWords($reasons, $shift->clock_offset_s),
                    'href' => $shift->guard_id === null ? '/guards' : '/guards/'.$shift->guard_id,
                    'at' => $shift->actual_start,
                ];
            }

            /*
             * AN ATTENDANCE EXCEPTION (M6 §2.1 part 4). A shift the platform closed
             * itself, because nobody clocked out and it was hours past its rostered
             * end. Derived from `auto_closed_at` exactly as the override item above is
             * derived from `overridden_at`, scoped the same way, and it leaves on its
             * own after `shifts.auto_close_report_days` — reading it does not unflag
             * the shift, and nothing here corrects the row.
             *
             * NOT URGENT. It is a pay-and-coverage question for a supervisor to settle,
             * not an emergency; marking it urgent would put it above open panic alerts
             * in this list, which is the one thing the ordering must never do.
             */
            $autoClosed = Shift::query()->with(['officer', 'estate'])
                ->tap($narrow)
                ->whereNotNull('auto_closed_at')
                ->where('auto_closed_at', '>=', Carbon::now()->subDays((int) config('shifts.auto_close_report_days', 14)))
                ->orderByDesc('auto_closed_at')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($autoClosed as $shift) {
                $hours = (int) $shift->auto_close_hours;

                $items[] = [
                    'key' => 'autoclose:'.$shift->id,
                    'icon' => 'licence',
                    'urgent' => false,
                    'title' => 'Attendance exception: no clock-out from '.($shift->officer->full_name ?? 'a guard'),
                    'detail' => ($shift->estate->name ?? $shift->tenant_id)

                        // The time that is on the row: the clock-on, when it came after the rostered end (bug hunt B100).
                        .(ShiftAutoClose::closedAtClockOn($shift)
                            ? ' · clocked on after its rostered end, so closed at the time it was clocked on, '.$hours.' hour'.($hours === 1 ? '' : 's').' after it'
                            : ' · closed at its rostered end, '.$hours.' hour'.($hours === 1 ? '' : 's').' after it')
                        .' · confirm the hours actually worked before this shift is paid',
                    'href' => $shift->guard_id === null ? '/guards' : '/guards/'.$shift->guard_id,
                    'at' => $shift->auto_closed_at,
                ];
            }

            $open = SecurityIncident::query()->with('estate')
                ->tap($narrow)
                ->where('status', SecurityIncident::OPEN)
                ->orderByDesc('occurred_at')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($open as $incident) {
                $items[] = [
                    'key' => 'incident:'.$incident->id,
                    'icon' => 'incident',
                    'urgent' => $incident->severity === 'high',
                    'title' => 'Open incident at '.($incident->estate->name ?? $incident->tenant_id),
                    'detail' => $incident->kind,
                    'href' => '/guards/incidents/'.$incident->id,
                    'at' => $incident->occurred_at,
                ];
            }
        }

        /*
         * THE TWO MONEY KINDS ASK `PlatformMoney` (M10 §5), the one place the rest of the console
         * asks too. The answer is the same permission this always checked; what changes is that
         * the bell and the dashboard beside it can no longer come to different conclusions about
         * who may see a past-due invoice.
         */
        if (PlatformMoney::maySeeRevenue($viewer)) {
            /*
             * Past due AND STILL OWED (B080): an invoice its credit notes have
             * taken to nothing is not past due, whatever its status says — a
             * credit note never touches the invoice. From the day after the due
             * date, on Gemini's calendar, as `Invoice::isOverdue()` has it.
             */
            $overdue = DB::connection('mysql')->table('invoices')
                ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
                ->tap(static fn ($query) => OperatorEstates::exclude($query, 'invoices.tenant_id'))
                ->whereIn('invoices.status', ['issued', 'overdue'])
                ->whereDate('invoices.due_on', '<', PlatformCalendar::today())
                ->whereRaw(Invoice::outstandingSql().' > 0')
                ->orderBy('invoices.due_on')
                ->limit(self::PER_KIND)
                ->get(['invoices.id', 'invoices.reference', 'invoices.due_on', 'tenants.name as client']);

            foreach ($overdue as $invoice) {
                $items[] = [
                    'key' => 'invoice:'.$invoice->id,
                    'icon' => 'billing',
                    'urgent' => false,
                    'title' => 'Invoice past due: '.$invoice->client,
                    'detail' => $invoice->reference.' · due '.Carbon::parse((string) $invoice->due_on)->format('M j, Y'),
                    'href' => '/billing/invoices/'.$invoice->id,
                    'at' => Carbon::parse((string) $invoice->due_on),
                ];
            }
        }

        if (PlatformMoney::maySeePay($viewer)) {
            $owed = StatutoryFiling::query()
                ->where('status', '!=', StatutoryFiling::FILED)
                ->whereDate('period_end', '<=', PlatformCalendar::today())
                ->orderBy('due_on')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($owed as $filing) {
                $late = $filing->isOverdue(PlatformCalendar::today());

                $items[] = [
                    'key' => 'filing:'.$filing->id,
                    'icon' => 'payroll',
                    'urgent' => $late,
                    'title' => ($late ? 'Return overdue: ' : 'Return due: ').$filing->form_code.' '.$filing->period_label,
                    'detail' => 'Due '.$filing->due_on->format('M j, Y'),
                    'href' => '/payroll/filings',
                    'at' => $filing->due_on->copy()->startOfDay(),
                ];
            }
        }

        /*
         * EMAILS THE PLATFORM GAVE UP ON (M12 §5.1: "surface failures where an admin can see them rather than only in
         * the log"). One item for the last day, keyed by the newest failure so a fresh one is unread again — a
         * sign-in code among them is somebody who could not get into the app. It opens the list on Email settings.
         */
        if ($viewer->can('gemini.platform_settings.view')) {
            $mail = app(MailFailures::class)->lately();

            if ($mail['count'] > 0 && $mail['latest'] !== null) {
                $items[] = [
                    'key' => 'mail-failures:'.$mail['latest']->id,
                    'icon' => 'inbox',
                    'urgent' => false,
                    'title' => $mail['count'].' '.($mail['count'] === 1 ? 'email' : 'emails').' could not be sent in the last day',
                    'detail' => 'Latest: "'.MailFailures::withoutCodes((string) $mail['latest']->subject).'" to '.$mail['latest']->recipients,
                    'href' => '/settings/email',
                    'at' => Carbon::parse((string) $mail['latest']->failed_at),
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [(int) ! $a['urgent'], -($a['at']?->getTimestamp() ?? 0)]
            <=> [(int) ! $b['urgent'], -($b['at']?->getTimestamp() ?? 0)]);

        /*
         * The read marks of THESE items (M11 A2), not every mark this person ever left: the
         * table keeps a row per item read, for ever, and the bell is on the dashboard every
         * Gemini user opens. Only a key on the list below is ever looked up.
         */
        $keys = array_values(array_unique(array_column($items, 'key')));

        $read = $keys === [] ? collect() : DB::connection('mysql')
            ->table('platform_notification_reads')
            ->where('user_id', $viewer->getKey())
            ->whereIn('item_key', $keys)
            ->pluck('read_at', 'item_key');

        $unread = 0;
        $out = [];

        foreach ($items as $item) {
            $isRead = isset($read[$item['key']]);
            $unread += $isRead ? 0 : 1;

            $out[] = [
                'key' => $item['key'],
                'icon' => $item['icon'],
                'urgent' => $item['urgent'],
                'title' => $item['title'],
                'detail' => $item['detail'],
                'href' => $item['href'],
                'when' => $item['at'] instanceof Carbon ? $item['at']->format('M j, Y') : '',
                'is_read' => $isRead,
            ];
        }

        return ['items' => $out, 'unread' => $unread];
    }

    /**
     * Mark items seen, for this person only.
     *
     * @param  list<mixed>  $keys
     */
    public function markRead(User $viewer, array $keys): int
    {
        $now = Carbon::now();
        $rows = [];

        foreach (array_unique($keys) as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                continue;
            }

            $rows[] = ['user_id' => $viewer->getKey(), 'item_key' => $key, 'read_at' => $now, 'updated_at' => $now, 'created_at' => $now];
        }

        /*
         * EVERY MARK IN ONE STATEMENT (M11 A2), on the table's own unique key (user, item).
         * "Mark all read" sends up to two hundred keys, and each was a look-up and then a write
         * of its own: four hundred queries for one click. The rows left behind are the ones the
         * look-up-then-write left — a new mark inserted, an existing one given this moment as
         * its `read_at`, `updated_at` and `created_at` — so nothing reading them can tell.
         */
        if ($rows !== []) {
            DB::connection('mysql')->table('platform_notification_reads')->upsert(
                $rows,
                ['user_id', 'item_key'],
                ['read_at', 'updated_at', 'created_at'],
            );
        }

        return count($rows);
    }
}
