<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Dispatch\ClockInFence;
use App\Services\Gemini\DutyBoard;
use App\Services\Gemini\IncidentLog;
use App\Services\Gemini\Roster;
use App\Services\Gemini\Schedule;
use App\Services\Gemini\SecurityOperations;
use App\Services\Gemini\ShiftClaims;
use App\Services\Gemini\SiteGeography;
use App\Services\Gemini\StandingOrders;
use App\Services\Platform\OperatorEstates;
use App\Support\CalendarDate;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Inertia\Response;

/**
 * Security operations — board screens super-admin-24 to 27.
 *
 * The operational view across every client: the week's rota, the standing
 * orders in force, what the posts are reporting, and the incident log. All four
 * read gs_platform only.
 *
 * Nothing is decided here. The controller reads the request, hands the viewer
 * to the service and returns what comes back, because who may see which estate
 * is a rule that has to be enforced in SQL — a controller that filtered rows
 * after the fact would leave the rest reachable by paging or deep-linking.
 */
class OperationsController extends Controller
{
    /**
     * Publishing an order set changes what guards are legally instructed to do
     * at a gate, so it is Guard workforce create, and revising one update.
     */
    private const NO_ORDER_WRITE = 'Publishing or revising standing orders needs Guard workforce create and update access. You are able to read the library.';

    /** Company-wide orders, read by a role that covers only some clients. */
    private const ORDERS_NOT_YOURS = 'Company-wide orders apply at every client, so they are revised by a role that covers every client. You can write the orders for a post you cover.';

    /** Why a role that reads the incident log may not add to it or close one. */
    private const NO_INCIDENT_WRITE = 'Logging or closing an incident needs Guard workforce create and update access. You are able to read this log.';

    public function roster(Request $request, SecurityOperations $operations, Roster $roster): Response
    {
        /*
         * A day that is on the calendar, or today (bug hunt B014). The check was the
         * shape alone, so 2026-13-45 reached Carbon::parse and answered 500, and
         * 2026-02-30 drew 2 March as though it had been asked for. A date that is not
         * a day is no day asked for: the board opens as it does with no ?date= at all.
         */
        $on = CalendarDate::from($request->query('date'));
        $requested = $on !== null;
        // "Today" and "this week" are the operations room's, on the site clock — not UTC's (M4 §3.1).
        $date = $on ?? SiteTime::today()->toDateString();

        // The week drawn (3 Oct): the one asked for by ?week=, else the week of the day opened, else this week.
        $week = CalendarDate::from($request->query('week')) ?? $on;

        return inertia('Gemini/Operations/Roster', [
            ...$operations->roster($request->user(), $week),

            /*
             * The forward roster (12 §2, Wave 5). An OPEN shift is a real row
             * with no officer on it — a post needing somebody is a fact this
             * console has to hold, and a placeholder guard would instead show
             * an empty gate as manned.
             */
            'day' => $roster->day($date, $request->user()),

            // Officers' claims on open shifts, whatever day each is on (26 Sep, G3): answered here.
            'claimsWaiting' => app(ShiftClaims::class)->waiting($request->user()),

            // The schedule's own shift panel, for every client (M35 §2, §3): Post open shift, Change guard and Remove
            // open it here, with its rules, instead of a second form with rules of its own.
            'shiftPanels' => app(Schedule::class)->panels($request->user()),

            /*
             * WHEN THE DAY VIEW IS DRAWN. Only when a day was asked for — each
             * heading on the week grid is a link to its own day — or when the
             * day has an open shift somebody must act on. Drawn on every load
             * it pushed the board's own week grid down the page for a reader
             * who came to read the week.
             */
            'dayRequested' => $requested,

            // The seven dates behind the grid's headings come with the week, from the roster above. Not the posts and
            // officers it once listed (M35): the inline form and the Assign select they fed are the shift panel now,
            // which is handed each client's gates above and asks for who is free itself.
            'canWrite' => $request->user()->can('gemini.guard_workforce.update'),
            'writeBlockedReason' => 'Posting a shift puts a client\'s gate on the rota, so it needs Guard workforce update access. You are able to read the roster.',
        ]);
    }

    /** Post an open shift — a post, a window, and nobody yet. */
    /**
     * Sites & geofences (M11 §6): where each post and checkpoint of a client stands, on a map — and, for a role that
     * may update the workforce, where they are placed from. What is set here is what the guard's checkpoint map, the
     * dispatch map and the estate's Security map draw, and the geofence a guard clocks in within.
     */
    public function sites(Request $request, SiteGeography $geography): Response
    {
        $estate = $request->query('estate');

        return inertia('Gemini/Operations/Sites', [
            ...$geography->board($request->user(), is_string($estate) && $estate !== '' ? $estate : null),
            'canPlace' => $request->user()->can('gemini.guard_workforce.update'),
            'radius' => ['min' => SiteGeography::MIN_RADIUS_M, 'max' => SiteGeography::MAX_RADIUS_M],
            // A checkpoint's circle is a tag on a wall, not a gate's geofence (M21 §1.1).
            'checkpointRadius' => ['min' => SiteGeography::MIN_RADIUS_M, 'max' => SiteGeography::MAX_CHECKPOINT_RADIUS_M, 'default' => PatrolCheckpoint::DEFAULT_RADIUS_M],
            // A gate or a relief (M27): a patrol is a shift's duty at the gate it starts from, not a post.
            'postTypes' => array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(SiteGeography::NEW_POST_TYPES), array_values(SiteGeography::NEW_POST_TYPES)),
            // The rule a clock-in is judged by, said on the page (M12 §1): the margin and the "not established" line.
            'fenceRule' => ['good_accuracy_m' => ClockInFence::GOOD_ACCURACY_M, 'poor_accuracy_m' => ClockInFence::POOR_ACCURACY_M, 'max_margin_m' => ClockInFence::MAX_MARGIN_M, 'margin_per_accuracy' => ClockInFence::MARGIN_PER_ACCURACY],
        ]);
    }

    public function placePost(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:'.SiteGeography::MIN_RADIUS_M, 'max:'.SiteGeography::MAX_RADIUS_M],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $geography->placePost($request->user(), $post, (float) $data['lat'], (float) $data['lng'], (int) $data['radius_m'], (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['place' => $refused->getMessage()]);
        }

        return back()->with('success', 'Post placed. Guards rostered to it clock in within '.$data['radius_m'].' m of it from now on. The move is in the audit log with your reason.');
    }

    /** A new post at an estate, placed as it is made (M12 §1.1). */
    public function addPost(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        // A gate or a relief (M27: a patrol is a shift's duty at a gate, not a post of its own), with its other names.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(SiteGeography::NEW_POST_TYPES))],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:'.SiteGeography::MIN_RADIUS_M, 'max:'.SiteGeography::MAX_RADIUS_M],
            'also_called' => ['nullable', 'string', 'max:'.Post::ALSO_CALLED_MAX],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $post = $geography->addPost($request->user(), $estate, (string) $data['name'], (string) $data['type'], (float) $data['lat'], (float) $data['lng'], (int) $data['radius_m'], (string) $data['reason'], $data['also_called'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['add' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $post->label().' is added and placed. It can be rostered now, and guards on it clock in within '.$data['radius_m'].' m of it.');
    }

    /** The estate's own fence and its supervisor's number (M12 §1). */
    public function placeEstate(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'min:'.SiteGeography::MIN_RADIUS_M, 'max:'.SiteGeography::MAX_RADIUS_M],
            'supervisor_phone' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $geography->placeEstate($request->user(), $estate, [
                'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                'lng' => isset($data['lng']) ? (float) $data['lng'] : null,
                'radius_m' => isset($data['radius_m']) ? (int) $data['radius_m'] : null,
                'supervisor_phone' => $data['supervisor_phone'] ?? null,
            ], (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['site' => $refused->getMessage()]);
        }

        return back()->with('success', 'Saved. A post with no position of its own is now judged against this, and the audit log keeps your reason.');
    }

    public function placeCheckpoint(Request $request, int $checkpoint, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $lat = $data['lat'] ?? null;
        $lng = $data['lng'] ?? null;

        try {
            $geography->placeCheckpoint($request->user(), $checkpoint, $lat === null ? null : (float) $lat, $lng === null ? null : (float) $lng);
        } catch (DomainException $refused) {
            return back()->withErrors(['place' => $refused->getMessage()]);
        }

        return back()->with('success', $lat === null ? 'Checkpoint taken off the map.' : 'Checkpoint placed.');
    }

    /** A new checkpoint on a patrol route, placed as it is made, with a tag of its own (M21 §1.1). */
    public function addCheckpoint(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'min:'.SiteGeography::MIN_RADIUS_M, 'max:'.SiteGeography::MAX_CHECKPOINT_RADIUS_M],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $checkpoint = $geography->addCheckpoint($request->user(), $post, (string) $data['label'], (float) $data['lat'], (float) $data['lng'], (int) $data['radius_m'], (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['checkpoint' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $checkpoint->label.' is checkpoint '.$checkpoint->sequence.' on the route. Print its tag, fix it where the checkpoint is, and the guard on the route scans it on every round.');
    }

    /** A checkpoint taken off its route, with the reason: out of the tour, its tag refused from now (M22). */
    public function retireCheckpoint(Request $request, int $checkpoint, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $retired = $geography->retireCheckpoint($request->user(), $checkpoint, (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['retire' => $refused->getMessage()]);
        }

        return back()->with('success', $retired->label.' is off the route. Its tag is refused from now: take it down. The other checkpoints on the route are numbered again; print their tags again if the numbers matter on the wall.');
    }

    /** A checkpoint's tag, printable: its QR, its name, its place on the route and its code (M21 §1.1). */
    public function checkpointTag(Request $request, int $checkpoint, SiteGeography $geography): View
    {
        $tag = $geography->tag($request->user(), $checkpoint) ?? abort(404);

        return view('documents.checkpoint-tag', [...$tag, 'tag' => $tag, 'large' => $request->query('size') === 'large']);
    }

    /** Every tag on the estate's patrol, one to a page, to print at once (M26 §3.5, M27 §4.2). */
    public function routeTags(Request $request, string $estate, SiteGeography $geography): View
    {
        $tags = $geography->routeTags($request->user(), $estate) ?? abort(404);

        return view('documents.checkpoint-tags', [...$tags, 'large' => $request->query('size') === 'large']);
    }

    /** One checkpoint on the estate's own patrol route, with its phase, placed now or later (M27 §4.1). */
    public function addRouteCheckpoint(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'phase' => ['nullable', 'string', 'max:32'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_m' => ['nullable', 'integer', 'min:'.SiteGeography::MIN_RADIUS_M, 'max:'.SiteGeography::MAX_CHECKPOINT_RADIUS_M],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $checkpoint = $geography->addRouteCheckpoint(
                $request->user(),
                $estate,
                (string) $data['label'],
                $data['phase'] ?? null,
                isset($data['lat']) ? (float) $data['lat'] : null,
                isset($data['lng']) ? (float) $data['lng'] : null,
                (int) ($data['radius_m'] ?? PatrolCheckpoint::DEFAULT_RADIUS_M),
                (string) $data['reason'],
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['checkpoint' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $checkpoint->label.' is checkpoint '.$checkpoint->sequence.' on the patrol route'.($checkpoint->latitude === null ? ', not placed yet: print its tag, mount it, and place it from where it hangs.' : '. Print its tag and fix it where the checkpoint is.'));
    }

    /** Many checkpoints at once, one name to a line, with no position yet (M27 §4.1). */
    public function addRouteCheckpoints(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'string', 'max:8000'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $made = $geography->addRouteCheckpoints($request->user(), $estate, (string) $data['lines'], (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['checkpoints' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', count($made).' checkpoint'.(count($made) === 1 ? ' is' : 's are').' on the patrol route, not placed yet. Print every tag at once, mount them, and place each from the installer\'s message.');
    }

    /** Many checkpoints placed at once, from the installers' messages, as the Director confirmed them (M27 §4.3). */
    public function placeRouteCheckpoints(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'places' => ['required', 'array', 'min:1', 'max:'.SiteGeography::MAX_CHECKPOINTS_AT_ONCE],
            'places.*.id' => ['required', 'integer'],
            'places.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'places.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $placed = $geography->placeRouteCheckpoints($request->user(), $estate, array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']], $data['places']), (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['placeMany' => $refused->getMessage()]);
        }

        return back()->with('success', $placed.' checkpoint'.($placed === 1 ? ' is' : 's are').' placed. A scan there now counts only within its radius of where its tag hangs.');
    }

    /** A checkpoint one place earlier or later on its route (M27 §4.4). */
    public function moveCheckpoint(Request $request, int $checkpoint, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'string', 'in:up,down']]);

        try {
            $moved = $geography->moveCheckpoint($request->user(), $checkpoint, (string) $data['direction']);
        } catch (DomainException $refused) {
            return back()->withErrors(['move' => $refused->getMessage()]);
        }

        return back()->with('success', $moved->label.' is checkpoint '.$moved->sequence.' now. Tags already printed show their old number: print them again if the number matters on the wall. The codes have not changed.');
    }

    /** How long after one checkpoint the next is due on this estate's patrol (M27 §4.4). */
    public function patrolInterval(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'minutes' => ['nullable', 'integer', 'min:'.SiteGeography::MIN_PATROL_INTERVAL, 'max:'.SiteGeography::MAX_PATROL_INTERVAL],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $minutes = $geography->setPatrolInterval($request->user(), $estate, isset($data['minutes']) ? (int) $data['minutes'] : null, (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['interval' => $refused->getMessage()]);
        }

        return back()->with('success', 'On this estate\'s patrol the next checkpoint is due '.$minutes.' minutes after the last. Past that and '.PatrolCheckpoint::GRACE_MINUTES.' minutes\' grace, the officer shows "Missed checkpoint" on Patrol monitoring.');
    }

    /** The other names the estate uses for a post: "Gate 5, Phase 5 Gate" (M26). */
    public function postAlsoCalled(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate([
            'also_called' => ['nullable', 'string', 'max:'.Post::ALSO_CALLED_MAX],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);

        try {
            $saved = $geography->setAlsoCalled($request->user(), $post, $data['also_called'] ?? null, (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['names' => $refused->getMessage()]);
        }

        return back()->with('success', $saved->also_called === null
            ? $saved->name.' is shown by its name alone now.'
            : 'Every screen now shows '.$saved->label().'.');
    }

    /** A post that does not exist, taken away: deleted, or retired when it has history (M26). */
    public function removePost(Request $request, int $post, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        try {
            $removed = $geography->removePost($request->user(), $post, (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['remove' => $refused->getMessage()]);
        }

        return back()->with('success', SiteGeography::removedSentence($removed));
    }

    /** TRACK-01: whether this client's own console sees its guards on the map (off unless turned on here). */
    public function estateSeesGuards(Request $request, string $estate, SiteGeography $geography): RedirectResponse
    {
        $data = $request->validate(['sees' => ['required', 'boolean']]);
        $sees = (bool) $data['sees'];

        try {
            $geography->setEstateSeesGuards($request->user(), $estate, $sees);
        } catch (DomainException $refused) {
            return back()->withErrors(['sees' => $refused->getMessage()]);
        }

        return back()->with('success', $sees
            ? 'This client\'s Security page now shows its guards on the map.'
            : 'This client\'s Security page shows "guards on site" only, and no positions.');
    }

    public function postShift(Request $request, Roster $roster): RedirectResponse
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:190'],
            // M27: the post stood, or a patrol that starts and clocks in there.
            'duty' => ['nullable', 'string', 'in:'.implode(',', Shift::DUTIES)],
        ]);

        $post = Post::query()->find($data['post_id']);

        if ($post === null || ! $request->user()->canAccessEstate((string) $post->tenant_id)) {
            return back()->withErrors(['post_id' => 'That post is not one this role can roster.']);
        }

        try {
            $shift = $roster->postOpenShift($post, $data['starts_at'], $data['ends_at'], $request->user(), $data['reason'] ?? null, $data['duty'] ?? Shift::DUTY_POST);
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $shift->postLabel().' is on the rota for '.$shift->rostered_start->format('M j, g:i A').SiteTime::label($shift->rostered_start).', open until somebody is assigned.');
    }

    /** Put an officer on an open shift. */
    public function assignShift(Request $request, int $shift, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['guard_id' => ['required', 'integer']]);

        $row = Shift::query()->find($shift);
        $guard = Guard::query()->find($data['guard_id']);

        /*
         * THE OFFICER HAS TO BE ONE THIS ROLE CAN SEE, not only the shift (bug hunt B071).
         * Only the shift's estate was asked, so a Head of Security confined to Estate A
         * could post the id of Estate B's officer — somebody no screen of theirs lists —
         * and the success message then told them that officer's name; guard ids are
         * sequential, so cycling them read out a roster the role may not see. Refused in
         * the same words as an id that does not exist, so the answer teaches nothing. An
         * unposted officer is one every role may see, as the directory already shows them,
         * and `assign()` says why they cannot stand an estate's shift.
         */
        if ($row === null || $guard === null
            || ! $request->user()->canAccessEstate((string) $row->tenant_id)
            || ($guard->tenant_id !== null && ! $request->user()->canAccessEstate((string) $guard->tenant_id))) {
            return back()->withErrors(['guard_id' => 'That shift or that officer is not one this role can roster.']);
        }

        try {
            $roster->assign($row, $guard, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['guard_id' => $refused->getMessage()]);
        }

        return back()->with('success', $guard->full_name.' is on that shift.');
    }

    /** Who is on duty now and when each shift ends, who has not clocked in, who starts soon, what is open, who is away (C4). */
    public function onDuty(Request $request, DutyBoard $board): Response
    {
        return inertia('Gemini/Operations/OnDuty', [
            ...$board->for($request->user()),
            'scoped' => $request->user()->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /** Approve an officer's claim: they are put on the shift, and every other claim on it is declined (G3). */
    public function approveClaim(Request $request, int $claim, ShiftClaims $claims): RedirectResponse
    {
        try {
            $shift = $claims->approve($claim, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['claim' => $refused->getMessage()]);
        }

        return back()->with('success', ($shift->officer->full_name ?? 'The officer').' is on that shift, and has been told. Any other claims on it were declined.');
    }

    /** Decline one claim; the shift stays open for the others (G3). */
    public function declineClaim(Request $request, int $claim, ShiftClaims $claims): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        try {
            $claims->decline($claim, $request->user(), $data['note'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['claim' => $refused->getMessage()]);
        }

        return back()->with('success', 'Claim declined. The officer has been told, and the shift is still open.');
    }

    /**
     * The posts this viewer may roster.
     *
     * Each with `today` — the date it is now at the post's estate, the earliest its
     * standing orders may take effect (bug hunt B106).
     *
     * @return list<array<string, mixed>>
     */
    private function postsFor(Request $request): array
    {
        $user = $request->user();

        return Post::query()
            ->where('is_active', true)
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()),
            )
            ->with('estate')
            ->orderBy('name')
            ->get()
            ->map(static fn (Post $post): array => [
                'id' => $post->id,
                // As the estate names it (M26).
                'label' => $post->label().' — '.($post->estate->name ?? 'Unassigned'),
                'today' => StandingOrders::today((string) $post->tenant_id),
            ])
            ->all();
    }

    /** The written orders in force at every post — board screen 25. */
    public function standingOrders(Request $request, SecurityOperations $operations): Response
    {
        $user = $request->user();

        return inertia('Gemini/Operations/StandingOrders', [
            ...$operations->standingOrders($user),

            // Publishing a set (12 §2, item 28). A site-scoped role writes only
            // the orders for a post it covers; the form offers what it may.
            'canCreate' => $user->can('gemini.guard_workforce.create'),
            'categories' => $user->widestScope() === AccessScope::AssignedSites
                ? ['post_specific' => StandingOrders::CATEGORIES['post_specific']]
                : StandingOrders::CATEGORIES,
            'posts' => $this->postsFor($request),

            /*
             * The earliest a set may take effect, on the calendar the service judges it by
             * (bug hunt B106): this date for company-wide orders, and each post's own
             * `today` for its orders. The form used the browser's UTC date, which from
             * 19:00 in Kingston is already tomorrow.
             */
            'today' => StandingOrders::today(null),

            'writeDisabledReason' => self::NO_ORDER_WRITE,
        ]);
    }

    /** One order set, its versions and its acknowledgements. 404 outside scope. */
    public function standingOrder(Request $request, int $set, StandingOrders $orders): Response
    {
        $detail = $orders->detail($set, $request->user());

        abort_if($detail === null, 404);

        return inertia('Gemini/Operations/StandingOrder', [
            'set' => $detail,
            'canUpdate' => $request->user()->can('gemini.guard_workforce.update') && $detail['writable'],
            'writeDisabledReason' => $detail['writable'] ? self::NO_ORDER_WRITE : self::ORDERS_NOT_YOURS,
        ]);
    }

    /** Board 25's "New order set" — published at version 1. */
    public function createOrders(Request $request, StandingOrders $orders): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:general,emergency,post_specific'],
            'post_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:140'],
            'summary' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
            'effective_on' => ['required', 'date'],
        ]);

        try {
            $id = $orders->create($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()])->withInput();
        }

        return redirect()->to('/guards/standing-orders/'.$id)
            ->with('success', 'Published as version 1. Guards on a post acknowledge it from their handsets.');
    }

    /** Publish the next version of a set. */
    public function reviseOrders(Request $request, int $set, StandingOrders $orders): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:190'],
            'effective_on' => ['required', 'date'],
            'change_note' => ['required', 'string', 'max:300'],
        ]);

        try {
            $version = $orders->revise($set, $data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Version '.$version.' published. Every guard on post acknowledges it afresh — an acknowledgement of the last version does not carry over.');
    }

    /** Record a review that changes nothing. */
    public function reviewOrders(Request $request, int $set, StandingOrders $orders): RedirectResponse
    {
        try {
            $orders->markReviewed($set, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()]);
        }

        return back()->with('success', 'Reviewed today. The text is unchanged, so no acknowledgement is reset.');
    }

    /** What the gates are reporting, across every client — board screen 26. */
    public function gateActivity(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/GateActivity', $operations->gateActivity($request->user()));
    }

    /** What has gone wrong on a Gemini post — board screen 27. */
    public function incidents(Request $request, SecurityOperations $operations): Response
    {
        $user = $request->user();
        $scoped = $user->widestScope() === AccessScope::AssignedSites;

        // M32 F5: newest first unless the headings ask otherwise, a page at a time.
        $board = $operations->incidents($user, SecurityOperations::incidentsTable($request));

        return inertia('Gemini/Operations/Incidents', [
            ...$board,

            // Category B (13 C3): a guard reports an incident from the post (13 D2).
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn('mysql', 'security_incidents', array_column($board['incidents'], 'id'))),

            // The intake (12 §2, item 27): the clients and officers this role covers.
            'canLog' => $user->can('gemini.guard_workforce.create'),
            // The incident intake's client picker. Not the demonstration estate
            // (M5 §3): an incident logged against it is a real report filed
            // about a place that does not exist.
            'clients' => OperatorEstates::estates()
                ->when($scoped, static fn ($estates) => $estates->whereIn('id', $user->accessibleEstateIds()))
                ->sortBy('name')
                ->map(static fn (Tenant $estate): array => ['id' => (string) $estate->getTenantKey(), 'name' => (string) $estate->name])
                ->values()
                ->all(),
            'officers' => Guard::query()
                ->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number', 'tenant_id'])
                ->map(static fn (Guard $guard): array => [
                    'id' => $guard->id,
                    'label' => $guard->full_name.' · '.$guard->employee_number,
                    'tenant_id' => $guard->tenant_id,
                ])
                ->all(),
            'severities' => IncidentLog::SEVERITIES,
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }

    /** One incident — board 27's "View". 404 outside the viewer's scope. */
    public function incident(Request $request, int $incident, IncidentLog $log): Response
    {
        $detail = $log->detail($incident, $request->user());

        abort_if($detail === null, 404);

        return inertia('Gemini/Operations/Incident', [
            'incident' => $detail,
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn('mysql', 'security_incidents', [$incident])),
            'canResolve' => $request->user()->can('gemini.guard_workforce.update'),
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }

    /** Board 27's "Log incident" — a structured intake. */
    public function logIncident(Request $request, IncidentLog $log): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'],
            'guard_id' => ['nullable', 'integer'],
            'occurred_at' => ['required', 'date'],
            'kind' => ['required', 'string', 'max:160'],
            'severity' => ['required', 'string', 'in:low,med,high'],
            'detail' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $incident = $log->log($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['incident' => $refused->getMessage()])->withInput();
        }

        return redirect()->to('/guards/incidents/'.$incident->id)
            ->with('success', 'Incident logged. It stays open until what was done about it is recorded.');
    }

    /** Close an incident with what was done about it. */
    public function resolveIncident(Request $request, int $incident, IncidentLog $log): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:4000']]);

        $row = SecurityIncident::query()->find($incident);

        if ($row === null) {
            abort(404);
        }

        try {
            $log->resolve($row, (string) $data['resolution'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['resolution' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Incident closed with what was done. A closed incident is not rewritten.');
    }
}
