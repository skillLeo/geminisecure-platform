<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Document;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\TicketActivity;
use App\Models\User;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Notices;
use App\Services\Push\PushAnnouncer;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance tickets, notices and meetings, from the resident's side (13 D3).
 * Boards resident-app-15, -16 (tickets), -17 (notices), -18 (meetings).
 *
 * A TICKET IS THE ESTATE'S QUEUE. Reported here, it is the same row board 17 works
 * and board 18 tracks, through `Maintenance::report` — the SLA clock starts at the
 * resident's report, not when somebody in the office opens it.
 *
 * AN RSVP IS NOT ATTENDANCE. It tells the secretary who to expect. Quorum is
 * counted from the register taken at the meeting (board 36), and nothing here
 * writes to that register.
 */
class CommunityController extends Controller
{
    private const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'video/mp4', 'video/quicktime'];

    public function __construct(private readonly ResidentAccounts $accounts) {}

    public function tickets(Request $request, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $before = self::cursor($request);

        // THE WHOLE HISTORY, A PAGE AT A TIME (M4 §3.5 "a list of all requests"): the newest hundred, and `next_before`
        // reaches the hundred before them. One more row than the page is read, so "is there anything older?" costs nothing.
        $page = MaintenanceTicket::query()->with(['activity' => static fn ($q) => $q->orderBy('occurred_at'), 'vendor'])
            ->where('unit_id', $unit->id)
            ->when($before !== null, static fn ($q) => self::olderThan($q, 'reported_at', $before))
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->limit(self::TICKETS_PAGE + 1)
            ->get();

        $items = $page->take(self::TICKETS_PAGE)->values();

        // How many photos are on each — ONE query for the page, like every other list here. Asked per
        // row it was a hundred round trips an answer, and "All requests" walked back five pages was
        // five hundred, on a handset over a mobile connection.
        $media = self::mediaCounts($items->map(static fn (MaintenanceTicket $t): int => (int) $t->id)->all());
        $roles = self::staffRoles(...$items->all());

        return response()->json([
            'items' => $items->map(fn (MaintenanceTicket $t): array => $this->ticketShape($t, $media[(int) $t->id] ?? 0, $roles))->all(),
            'next_before' => $page->count() > self::TICKETS_PAGE ? self::cursorOf($items->last()->reported_at, (int) $items->last()->id) : null,
        ]);
    }

    public function reportTicket(Request $request, DeviceContext $context, Maintenance $maintenance, PushAnnouncer $announcer): JsonResponse
    {
        $home = $this->accounts->home($context);

        /*
         * WHAT IT IS AND WHAT IS WRONG ARE REQUIRED (M18C: nothing is optional) — the kind, and the resident's own words.
         * Where, when to come, and whether the office may go in are ADDITIONAL (§3.3): the unit is the place when no
         * other is given, and the rest is extra help the app asks for under its own heading. They were once lines the
         * phone appended to the description (M4 §3.5); a request carrying that old shape still carries a description.
         */
        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:140'],
            'category' => ['required', 'string', 'in:plumbing,electrical,security,landscaping,roads,water,waste,amenity,other'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'access_permitted' => ['nullable', 'boolean'],
            'preferred_time' => ['nullable', 'string', 'max:80'],
            // Together or not at all: half a coordinate is not a place.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'happening_now' => ['nullable', 'boolean'],
        ]);

        $name = (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident');

        $ticket = $maintenance->report(
            title: $data['title'],
            locationLabel: $data['location'] ?? $home['unit']->reference,
            priority: $data['priority'] ?? MaintenanceTicket::MEDIUM,
            unit: $home['unit'],
            category: $data['category'] ?? null,
            description: $data['description'] ?? null,
            reportedByName: $name,
        );

        $preferred = trim((string) ($data['preferred_time'] ?? ''));
        $happeningNow = (bool) ($data['happening_now'] ?? false);

        $ticket->forceFill([
            'is_simulated' => $request->boolean('simulated'),
            'access_permitted' => array_key_exists('access_permitted', $data) && $data['access_permitted'] !== null ? (bool) $data['access_permitted'] : null,
            'preferred_time' => $preferred === '' ? null : $preferred,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'location_accuracy_m' => $data['accuracy_m'] ?? null,
            'happening_now' => $happeningNow,
        ])->save();

        /*
         * NOTIFICATION IS NOT OWNERSHIP (M4 §3.5). The guards on duty at this site are
         * told; the ticket is still the estate office's, `submitted`, unassigned, with
         * the resident named as the reporter. Nothing below writes to the ticket.
         */
        if ($happeningNow && ! $ticket->is_simulated) {
            $announcer->residentReportedNow($ticket, $context->tenantId);
        }

        // Asked the same way as the list, for the one row — a report just filed has no photos on it yet
        // (they are captured behind it, `tickets.media`), and this says so rather than assuming it.
        $media = self::mediaCounts([(int) $ticket->id]);
        $ticket->load(['activity', 'vendor']);

        return response()->json($this->ticketShape($ticket, $media[(int) $ticket->id] ?? 0, self::staffRoles($ticket)), 201);
    }

    /**
     * A MESSAGE TO THE ESTATE OFFICE ABOUT ONE REQUEST (M47 §11b: "Add a comment … it must actually send"). It goes on
     * the ticket's own history as a note, in the resident's words and under their name — where the office works the
     * job (the console's ticket history lists every note) — and is never a separate inbox to be forgotten. Any request
     * of the household's, open or closed.
     */
    public function comment(Request $request, int $ticket, DeviceContext $context, Maintenance $maintenance): JsonResponse
    {
        [$home, $record] = $this->ownTicket($ticket, $context);

        $data = $request->validate(['body' => ['required', 'string', 'max:500']], ['body.required' => 'Write your message first.']);
        $body = trim((string) $data['body']);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write your message first.']);
        }

        $maintenance->record($record, TicketActivity::NOTE, note: $body, actorKind: TicketActivity::RESIDENT, actorName: $this->residentName($home));
        $record->refresh()->load(['activity' => static fn ($q) => $q->orderBy('occurred_at'), 'vendor']);

        return response()->json($this->ticketShape($record, self::mediaCounts([(int) $record->id])[(int) $record->id] ?? 0, self::staffRoles($record)), 201);
    }

    /**
     * The role each member of staff on these tickets' histories holds, by user — "Property Manager", "Dispatcher" — in
     * ONE query, read once for a whole page. A role's label is what every screen prints; its first role by the seeded
     * order where somebody holds several.
     *
     * @return array<int, string>
     */
    private static function staffRoles(MaintenanceTicket ...$tickets): array
    {
        $ids = [];

        foreach ($tickets as $ticket) {
            foreach ($ticket->activity as $entry) {
                if ($entry->actor_kind === TicketActivity::STAFF && $entry->actor_id !== null) {
                    $ids[(int) $entry->actor_id] = true;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $roles = [];

        foreach (DB::connection('mysql')->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', array_keys($ids))
            ->orderBy('roles.sort')->orderBy('roles.id')
            ->get(['model_has_roles.model_id', 'roles.label', 'roles.name']) as $row) {
            $roles[(int) $row->model_id] ??= trim((string) ($row->label ?? '')) !== '' ? (string) $row->label : Str::headline(Str::afterLast((string) $row->name, '.'));
        }

        return $roles;
    }

    /*
     * THE HOUSEHOLD ANSWERS "IS IT FIXED?" (the client's report R8, 26 Sep). The estate office marks a job completed;
     * until now only the office could say anything after that, and the "the household agrees" stage the timeline has
     * always drawn had no way to be reached. Both answers are the household's own request only, and only once the
     * office has said it is finished:
     *
     *   tickets.confirm_fixed   completed → verified, with the resident's name on the stage
     *   tickets.still_a_problem completed or verified → back in the queue, with what is still wrong, named as the
     *                           resident; the first closure stays in the history, and the deadline still runs from
     *                           the original report (Maintenance::reopen)
     */
    public function confirmFixed(int $ticket, DeviceContext $context, Maintenance $maintenance): JsonResponse
    {
        [$home, $record] = $this->ownTicket($ticket, $context);

        if ($record->status !== MaintenanceTicket::COMPLETED) {
            throw ApiError::conflict('not_completed', 'The estate office has not marked this as finished yet, so there is nothing to confirm.');
        }

        $maintenance->verify($record, $this->residentName($home));
        $record->refresh()->load(['activity', 'vendor']);

        return response()->json($this->ticketShape($record, self::mediaCounts([(int) $record->id])[(int) $record->id] ?? 0, self::staffRoles($record)));
    }

    public function stillAProblem(Request $request, int $ticket, DeviceContext $context, Maintenance $maintenance): JsonResponse
    {
        [$home, $record] = $this->ownTicket($ticket, $context);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        if (! in_array($record->status, [MaintenanceTicket::COMPLETED, MaintenanceTicket::VERIFIED], true)) {
            throw ApiError::conflict('not_completed', 'This request is still open with the estate office, so it cannot be reopened. Add a photo or call the office if something has changed.');
        }

        $maintenance->reopen($record, trim($data['reason']), residentName: $this->residentName($home));
        $record->refresh()->load(['activity', 'vendor']);

        return response()->json($this->ticketShape($record, self::mediaCounts([(int) $record->id])[(int) $record->id] ?? 0, self::staffRoles($record)));
    }

    /**
     * @return array{0: array<string, mixed>, 1: MaintenanceTicket}
     */
    private function ownTicket(int $ticket, DeviceContext $context): array
    {
        $home = $this->accounts->home($context);
        $record = MaintenanceTicket::query()->whereKey($ticket)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No ticket of your household\'s with that id.');
        }

        return [$home, $record];
    }

    /** @param  array<string, mixed>  $home */
    private function residentName(array $home): string
    {
        return (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident');
    }

    public function ticketMedia(Request $request, int $ticket, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = MaintenanceTicket::query()->whereKey($ticket)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No ticket of your household\'s with that id.');
        }

        $request->validate(['file' => ['required', 'file', 'max:51200', 'mimetypes:'.implode(',', self::MEDIA_TYPES)]]);

        $file = $request->file('file');
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $sha256 = hash('sha256', $bytes);

        /*
         * THIS PATH CARRIES NO ESTATE IN IT, AND IT IS ONLY SAFE BECAUSE THE DISK DOES.
         *
         * `Storage::disk('local')` is rooted per tenant by tenancy's `FilesystemTenancyBootstrapper`
         * (config/tenancy.php: `bootstrappers`, `filesystem.disks` lists `local`, and
         * `filesystem.root_override.local`), so while an estate's tenancy is open this writes under
         * that estate's own storage path and nowhere else. A ticket id is an auto-increment in each
         * estate's OWN database, so estate A's ticket 55 and estate B's ticket 55 both exist — if
         * that bootstrapper were ever removed from the list, or `local` dropped from `filesystem.disks`,
         * these two estates' residents' photos would land in one shared `ticket-media/55/` folder.
         * `Services\Documents\Documents::keep` does not depend on it: it writes
         * `estates/{tenant}/documents/…` and names the estate itself. If you change either, change both.
         */
        $path = 'ticket-media/'.$record->id.'/'.$sha256.'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');

        Storage::disk('local')->put($path, $bytes);

        $id = DB::connection('tenant')->table('ticket_media')->insertGetId([
            'maintenance_ticket_id' => $record->id,
            'path' => $path,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
            'uploaded_by_account_id' => $home['account']->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json([
            'media_id' => $id,
            'ticket_id' => $record->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
        ], 201);
    }

    public function notices(Request $request, DeviceContext $context, Notices $notices): JsonResponse
    {
        $home = $this->accounts->home($context);

        $read = $home['resident'] === null ? collect() : DB::connection('tenant')->table('notice_reads')
            ->where('resident_id', $home['resident']->id)->pluck('read_at', 'notice_id');

        // THE WHOLE HISTORY, A PAGE AT A TIME — the same `before` / `next_before` the other two lists take.
        // The first page is the hundred it always was; nothing else about this answer changed.
        $before = self::cursor($request);
        $page = $this->visibleNotices($home['unit']->block, $before);
        $visible = $page->take(self::NOTICES_PAGE)->values();
        $last = $visible->last();
        $nextBefore = $page->count() > self::NOTICES_PAGE && $last !== null ? self::cursorOf($last->published_at, (int) $last->id) : null;

        // "Seen by 212 of 450 households" and the files put with a notice (M4 §3.5) — both asked once for the whole
        // list, of the service the console's own seen bar is counted by, never per row and never counted a second way.
        $reach = $notices->householdReach($visible);
        $files = $notices->attachmentsFor($visible->pluck('id')->map(static fn ($id): int => (int) $id)->all());

        $items = $visible
            ->map(static fn (Notice $n): array => [
                'id' => $n->id,
                'kind' => $n->kind,
                'title' => $n->title,
                'body' => $n->body,
                'author_name' => $n->author_name,
                'posted_as_role' => $n->posted_as_role,
                'audience' => $n->audience_scope === Notice::ONE_PHASE ? (string) $n->audience_phase : 'Whole estate',
                'published_at' => SiteTime::iso($n->published_at),
                'read_at' => isset($read[$n->id]) ? SiteTime::iso(Carbon::parse((string) $read[$n->id], 'UTC')) : null,
                'seen_households' => $reach[$n->id]['seen'] ?? 0,
                'audience_households' => $reach[$n->id]['audience'] ?? 0,
                'attachments' => array_map(self::attachmentShape(...), $files[$n->id] ?? []),
            ])->all();

        return response()->json([
            'items' => $items,
            // Unread AMONG THE NOTICES IN THIS ANSWER, as it always was — never a figure for the whole history.
            'unread' => count(array_filter($items, static fn (array $i): bool => $i['read_at'] === null)),
            'next_before' => $nextBefore,
        ]);
    }

    /**
     * A file the office put with a notice — what a phone may know about it.
     *
     * A NAME, A TYPE, A SIZE AND AN ID. Never where it is kept: the phone fetches
     * it from `notices.attachment` with its own token, so there is no address in
     * this answer that would open for anybody else.
     *
     * @return array{id: int, name: string, content_type: string, bytes: int}
     */
    private static function attachmentShape(Document $document): array
    {
        return [
            'id' => (int) $document->id,
            'name' => $document->filename,
            'content_type' => $document->contentType(),
            'bytes' => (int) $document->bytes,
        ];
    }

    /**
     * One attachment's bytes, to the household the notice was published to — and to nobody else.
     *
     * THE NOTICE IS FOUND THE WAY THE LIST FINDS IT, so a draft, a notice dated
     * ahead, another phase's notice and an id nobody has are one answer: 404.
     * So is an attachment that belongs to a different notice, or is not ready.
     *
     * READ HERE, NOT STREAMED LATER. The estate's disk is only the estate's while
     * its tenancy is open, and `ResolveDevice` closes it as soon as this returns —
     * a stream that ran afterwards would look for the file on the platform's disk.
     */
    public function noticeAttachment(int $notice, int $attachment, DeviceContext $context, Notices $notices): Response
    {
        $home = $this->accounts->home($context);
        $record = $this->noticeFor($home['unit']->block, $notice);
        $document = $record === null ? null : $notices->attachment($record, $attachment);

        if ($document === null || ! Storage::disk('local')->exists((string) $document->path)) {
            throw ApiError::notFound('not_found', 'No attachment for your household with that id.');
        }

        $bytes = (string) Storage::disk('local')->get((string) $document->path);

        return response($bytes, 200, [
            'Content-Type' => $document->contentType(),
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $document->filename).'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function readNotice(int $notice, DeviceContext $context, Notices $notices): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = $this->noticeFor($home['unit']->block, $notice);

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No notice for your household with that id.');
        }

        if ($home['resident'] === null) {
            throw ApiError::forbidden('account_pending', 'This account is not linked to a resident on the register yet.');
        }

        $notices->markRead($record, $home['resident']);

        $at = DB::connection('tenant')->table('notice_reads')->where('notice_id', $record->id)->where('resident_id', $home['resident']->id)->value('read_at');

        // The count AFTER this read, so the line under the notice is right at once and not a refresh later.
        $reach = $notices->householdReach([$record])[$record->id] ?? ['seen' => 0, 'audience' => 0];

        return response()->json([
            'notice_id' => $record->id,
            'read_at' => SiteTime::iso(Carbon::parse((string) $at, 'UTC')),
            'seen_households' => $reach['seen'],
            'audience_households' => $reach['audience'],
        ]);
    }

    public function meetings(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $rsvps = DB::connection('tenant')->table('meeting_rsvps')->where('unit_id', $home['unit']->id)->pluck('response', 'meeting_id');
        $before = self::cursor($request);

        // THE WHOLE HISTORY, A PAGE AT A TIME (M4 §3.5 "a list of all meetings"): everything ahead and the most recent
        // past, newest first — and `next_before` reaches the meetings before those. One more row than the page is read.
        $page = $this->meetingsFor($home['unit']->block)
            ->when($before !== null, static fn ($q) => self::olderThan($q, 'starts_at', $before))
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::MEETINGS_PAGE + 1)
            ->get();

        $last = $page->take(self::MEETINGS_PAGE)->last();
        $nextBefore = $page->count() > self::MEETINGS_PAGE && $last !== null ? self::cursorOf($last->starts_at, (int) $last->id) : null;

        $items = $page->take(self::MEETINGS_PAGE)->values()
            ->map(static fn (Meeting $m): array => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'starts_at' => SiteTime::iso($m->starts_at), // typed on the estate's clock, stored and sent as the UTC moment it means (M4 §3.1)
                /*
                 * WHERE TO BE (M10 §8). `venue` and `virtual_link` were already
                 * sent and were always null, because no form on the estate
                 * console could set either — so the app had a place to draw a
                 * location and nothing to draw in it.
                 *
                 * `held_as` is sent as well as the two so the handset can say
                 * "Zoom" rather than inferring it from the shape of a URL, and
                 * so a meeting moved offline stops claiming to be online even if
                 * an old link is still on the row. `place` is the composed line,
                 * built once on the server by `Meeting::placeLine()` so the
                 * console, the notice and the app cannot word it three ways.
                 */
                'held_as' => $m->held_as,
                // "Zoom", or for "Other online" the platform the secretary named ("Webex") — 26 Sep.
                'platform' => $m->isOnline() ? $m->platformLabel() : null,
                'place' => $m->placeLine(),
                'venue' => $m->venue,
                'virtual_link' => $m->virtual_link,
                'status' => self::meetingStatus($m),
                // Postponed with no new date yet (M56 §2.3): `starts_at` is the day it was first called for, not a day to
                // come, so the app says "New date to be set". A postponement with its new date arrives as `scheduled`.
                'date_to_be_set' => $m->status === Meeting::POSTPONED,
                // Why it was postponed or cancelled, as the office told every household (M56 §1.2, §2.1).
                'reason' => match ($m->status) {
                    Meeting::CANCELLED => $m->cancel_reason,
                    Meeting::POSTPONED => $m->postpone_reason,
                    default => null,
                },
                'description' => $m->description,
                'recording_enabled' => (bool) $m->recording_enabled,
                'agenda' => $m->agenda->map(static fn ($item): array => [
                    'start_time' => $item->getAttribute('start_time'),
                    'text' => (string) $item->getAttribute('text'),
                ])->values()->all(),
                // PUBLISHED minutes only (M21 §1.4): a draft the secretary is still writing is not yet the record.
                'minutes_available' => $m->minutes?->published_at !== null,
                'my_rsvp' => $rsvps[$m->id] ?? null,
            ])->all();

        return response()->json(['items' => $items, 'next_before' => $nextBefore]);
    }

    /**
     * WHETHER A MEETING HAS BEEN HELD (M21 §1.4, found on the emulator). Nothing writes `held` to a meeting — the
     * console reads it from the clock — so every past meeting went to the app as `scheduled`, and a meeting held last
     * week offered "Join meeting" and said "ask your estate office whether it is still running". Six hours after its
     * start a published meeting is `held`: the same horizon after which the app stops offering its link.
     */
    private static function meetingStatus(Meeting $m): string
    {
        return $m->status === Meeting::SCHEDULED && $m->starts_at->lessThan(Carbon::now()->subHours(6)) ? Meeting::HELD : (string) $m->status;
    }

    /**
     * A MEETING'S MINUTES, ONCE PUBLISHED (M21 §1.4) — for the households the meeting was for, under the list's own
     * visibility rule. The text as the office published it, when, and who recorded it; nothing about who attended.
     */
    public function minutes(int $meeting, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = $this->meetingsFor($home['unit']->block)->whereKey($meeting)->first();
        $minutes = $record?->minutes;

        if ($record === null || $minutes === null || $minutes->published_at === null) {
            throw ApiError::notFound('not_found', 'No minutes are published for that meeting.');
        }

        return response()->json([
            'meeting_id' => $record->id,
            'title' => $record->title,
            'held_on' => SiteTime::local($record->starts_at)->toDateString(),
            'body' => (string) $minutes->body,
            'recorded_by' => $minutes->recorded_by_name,
            'published_at' => SiteTime::iso($minutes->published_at),
        ]);
    }

    public function rsvp(Request $request, int $meeting, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $request->validate(['response' => ['required', 'string', 'in:attending,apologies,not_attending']]);

        // Asked for BY ID under the list's own visibility rule — not looked for in the first page of it, which a long
        // history would push an old meeting out of (it would then be "not found" instead of "closed").
        $record = $this->meetingsFor($home['unit']->block)->whereKey($meeting)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No meeting for your household with that id.');
        }

        if ($record->status === Meeting::POSTPONED) {
            throw ApiError::conflict('meeting_closed', 'This meeting is postponed. Replies open again when it has a new date.');
        }

        if ($record->status !== Meeting::SCHEDULED || $record->starts_at->isPast()) {
            throw ApiError::conflict('meeting_closed', 'This meeting has already started or is no longer scheduled.');
        }

        $now = Carbon::now();

        DB::connection('tenant')->table('meeting_rsvps')->updateOrInsert(
            ['meeting_id' => $record->id, 'unit_id' => $home['unit']->id],
            [
                'resident_id' => $home['resident']?->id,
                'responded_by_name' => $home['resident']->full_name ?? $home['account']->full_name,
                'response' => $data['response'],
                'responded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return response()->json([
            'meeting_id' => $record->id,
            'response' => $data['response'],
            'responded_at' => SiteTime::iso($now),
            'households_attending' => DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $record->id)->where('response', 'attending')->count(),
        ]);
    }

    /**
     * The notices a household in this phase may see — the RULE, with no page on it.
     *
     * Published, dated today or earlier, and for the whole estate or this
     * household's phase. ONE notice is asked for BY ID through this same rule
     * (`noticeFor`) and never looked for inside a page of it: a page stops at a
     * hundred, and an estate that posts often would push an older notice out of
     * it — its file would then be refused as "not yours" to the household it
     * was published to, which is the one thing this refusal must never mean.
     * That stays true now the list pages: the rule is what a notice is asked
     * against, and the page is only how much of it is handed over at a time.
     *
     * @return Builder<Notice>
     */
    private function noticesFor(?string $phase): Builder
    {
        return Notice::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now())
            ->where(static fn ($q) => $q->where('audience_scope', Notice::ESTATE_WIDE)->orWhere(static fn ($p) => $p->where('audience_scope', Notice::ONE_PHASE)->where('audience_phase', $phase)));
    }

    /** One notice under that rule, or null — another phase's, a draft and an id nobody has are one answer. */
    private function noticeFor(?string $phase, int $notice): ?Notice
    {
        return $this->noticesFor($phase)->whereKey($notice)->first();
    }

    /**
     * One page of that rule, newest first — and ONE ROW MORE, so "is there anything older?" costs
     * nothing (`tickets` and `meetings` read their pages the same way).
     *
     * Two notices published in the same second are separated by their id, in the same direction as
     * the order, which is what keeps a row from being skipped or served twice across the join.
     *
     * @param  array{0: string, 1: int}|null  $before
     * @return Collection<int, Notice>
     */
    private function visibleNotices(?string $phase, ?array $before = null): Collection
    {
        return $this->noticesFor($phase)
            ->when($before !== null, static fn ($q) => self::olderThan($q, 'published_at', $before))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::NOTICES_PAGE + 1)
            ->get();
    }

    /**
     * The meetings a household in this phase may see — the RULE, with no window and no page on it.
     *
     * Published, not the committee's own, not a draft, and for the whole estate
     * or this household's phase. It used to stop at six months back; a resident
     * asked where last year's AGM went (M4 §3.5), so the list now pages instead.
     *
     * @return Builder<Meeting>
     */
    private function meetingsFor(?string $phase): Builder
    {
        return Meeting::query()->with(['agenda', 'minutes'])
            ->whereNotNull('published_at')
            ->where('type', '!=', Meeting::COMMITTEE)
            ->where('status', '!=', Meeting::DRAFT)
            ->where(static fn ($q) => $q->where('audience_scope', '!=', Meeting::PHASE_SUBSET)->orWhere('phase', $phase));
    }

    /* ── A long list, a page at a time ───────────────────────────────────────
     *
     * `before` / `next_before`, the way `sync.pull` hands on `since` / `next_since`:
     * the answer names where the next one starts, and the phone sends that back
     * untouched. The cursor is the last row's own time AND id, so two rows stamped
     * in the same second are neither skipped nor served twice. It is opaque on
     * purpose — a phone has no business building one.
     */

    private const TICKETS_PAGE = 100;

    private const MEETINGS_PAGE = 50;

    // The hundred this list always answered with, now with a way past it.
    private const NOTICES_PAGE = 100;

    /** @return array{0: string, 1: int}|null */
    private static function cursor(Request $request): ?array
    {
        $raw = $request->query('before');

        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_string($raw) && strlen($raw) <= 120 ? base64_decode(strtr($raw, '-_', '+/'), true) : false;

        if ($decoded === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d{1,18})$/', $decoded, $found) !== 1) {
            throw ApiError::unprocessable('cursor_invalid', 'That is not a `next_before` this list handed out. Ask for the list again from the start.');
        }

        return [$found[1], (int) $found[2]];
    }

    private static function cursorOf(\DateTimeInterface $at, int $id): string
    {
        // The cursor is compared with the STORED time, which is UTC — and a model hands these back on the
        // estate's clock (`SiteDateTime`), so the digits are taken in UTC, not in the zone it is carried in (M4 §3.1).
        return rtrim(strtr(base64_encode(Carbon::instance($at)->utc()->format('Y-m-d H:i:s').'|'.$id), '+/', '-_'), '=');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{0: string, 1: int}  $before
     * @return Builder<TModel>
     */
    private static function olderThan(Builder $query, string $column, array $before): Builder
    {
        return $query->where(static fn ($q) => $q
            ->where($column, '<', $before[0])
            ->orWhere(static fn ($same) => $same->where($column, $before[0])->where('id', '<', $before[1])));
    }

    /**
     * How many photos are on each of these tickets — one query for the whole page.
     *
     * A ticket with no photos has no row here and is read back as 0, which is why every caller
     * supplies a default rather than expecting a key.
     *
     * @param  list<int>  $ticketIds
     * @return array<int, int> keyed by ticket id
     */
    private static function mediaCounts(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $found = [];

        $rows = DB::connection('tenant')->table('ticket_media')
            ->whereIn('maintenance_ticket_id', $ticketIds)
            ->groupBy('maintenance_ticket_id')
            ->selectRaw('maintenance_ticket_id, COUNT(*) AS media')
            ->get();

        foreach ($rows as $row) {
            $found[(int) $row->maintenance_ticket_id] = (int) $row->media;
        }

        return $found;
    }

    /**
     * @param  array<int, string>  $roles  staff roles by user (`staffRoles`)
     * @return array<string, mixed>
     */
    private function ticketShape(MaintenanceTicket $t, int $mediaCount, array $roles = []): array
    {
        $vendor = $t->relationLoaded('vendor') ? $t->vendor : null;
        $phone = static fn (?string $number): ?string => trim((string) $number) === '' ? null : trim((string) $number);

        return [
            'id' => $t->id,
            'number' => $t->number,
            'title' => $t->title,
            'category' => $t->category,
            'location' => $t->location_label,
            'description' => $t->description,

            // M4 §3.5: named fields, not lines appended to the description. Null on a
            // ticket that did not say — including every one filed before they existed.
            'access_permitted' => $t->access_permitted,
            'preferred_time' => $t->preferred_time,
            'priority' => $t->priority,
            'status' => $t->status,
            // The console's own words (M96, MaintenanceTicket::STATE_LABELS), so a security report in "My requests"
            // reads a plain word rather than every client carrying its own translation of the same seven statuses.
            'status_label' => MaintenanceTicket::STATE_LABELS[$t->status] ?? $t->status,
            // ONE TIME RULE (M4 §3.1): every time is a UTC instant; the app shows it in the estate's `timezone`.
            'reported_at' => SiteTime::iso($t->reported_at),
            'technician_name' => $t->technician_name,
            'eta_starts_at' => SiteTime::iso($t->eta_starts_at), // the visit window the office typed, as the moment it means
            'eta_ends_at' => SiteTime::iso($t->eta_ends_at),
            'resolution' => $t->resolution,
            'closed_at' => SiteTime::iso($t->closed_at),
            'media_count' => $mediaCount, // counted for the whole page at once (mediaCounts), never per row
            // M47 §11a: the company the estate gave the job to, and a number to call — the technician's, else the company's.
            'vendor' => $vendor === null ? null : ['name' => (string) $vendor->name, 'phone' => $phone($t->technician_phone) ?? $phone($vendor->contact_phone)],
            'timeline' => $t->activity->map(static fn (TicketActivity $a): array => [
                'event' => $a->event,
                'status' => $a->to_status,
                'note' => $a->note,
                'occurred_at' => SiteTime::iso($a->occurred_at),
                // Who did it (M47 §11.4, §12.5): "Property Manager", "Dispatcher Renae Cross", or the resident themselves.
                'by' => $a->actor_kind === TicketActivity::SYSTEM || trim((string) $a->actor_name) === '' ? null : [
                    'kind' => (string) $a->actor_kind,
                    'name' => trim((string) $a->actor_name),
                    'role' => $a->actor_kind === TicketActivity::STAFF && $a->actor_id !== null ? ($roles[(int) $a->actor_id] ?? null) : null,
                ],
            ])->values()->all(),
        ];
    }
}
