<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\DuressAlert;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\PanicEmailOutcome;
use App\Services\Dispatch\PanicPeople;
use App\Services\Gemini\IncidentLog;
use App\Services\Tracking\EstateBounds;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The estate's own picture of its security — M10 §2.
 *
 * WHY THIS DID NOT EXIST. Panic alerts, incidents, gate events and guard shifts
 * all live in the CENTRAL database, because a dispatcher watches every estate at
 * once and that is only possible if the records are central. The consequence
 * nobody had addressed is that the community whose gate it is could not see its
 * own security at all: every one of those surfaces was a Gemini console screen,
 * and the estate console had none.
 *
 * SO THIS READS CENTRAL TABLES, SCOPED TO ONE TENANT, AND THAT IS NOT A BREACH
 * OF ISOLATION. It is the opposite. Every query below is filtered on the
 * tenant key of the estate whose console is open — the estate is reading its
 * OWN gate log, its OWN alerts, its OWN incidents. Nothing here can reach
 * another estate's rows, and `EstateSecurityIsolationTest` proves it by asking
 * for a second estate's data through the same methods.
 *
 * WHAT THE ESTATE IS NOT ENTITLED TO, and what is therefore absent rather than
 * merely un-rendered:
 *
 *   - guard PAY, of any kind. Guards are Gemini Security Limited's employees.
 *   - guard EMPLOYMENT records: licence numbers, employee numbers, discipline,
 *     which handset they carry, which other estates they work.
 *   - any other estate's anything.
 *
 * What the estate IS entitled to, per the data-ownership matrix, is the presence
 * it is paying for — "guards on site: N" and the alert states — plus the events
 * at its own gate. So `guardsOnSite()` returns a COUNT and the officers' names,
 * because a committee member watching an alert needs to know who is on the
 * ground; it returns nothing else about them at all.
 */
class EstateSecurity
{
    /** How many rows each list carries. A console screen, not an export. */
    public const PAGE = 25;

    /** Alerts nobody is waiting on any more. */
    private const SETTLED = ['resolved', 'false_alarm'];

    /*
     * WHAT AN INCIDENT'S KIND READS AS (M83 item 4, SEC-7). The Guard App sends the officer's own words ("Attempted
     * entry"), printed as typed with a capital; Phoenix Park's two sample incidents carry codes, which read as words.
     */
    private const INCIDENT_KINDS = [
        'suspicious' => 'Suspicious activity',
        'property' => 'Estate property',
    ];

    /**
     * An incident's kind in words, the one rule for every screen that shows one (the estate's Security page and Gemini's
     * incident log alike, M98): a code reads as its word, an officer's own words as typed with a capital.
     */
    public static function incidentKindLabel(string $kind): string
    {
        return self::INCIDENT_KINDS[$kind] ?? mb_strtoupper(mb_substr($kind, 0, 1)).mb_substr($kind, 1);
    }

    /** A lot or a name nobody recorded says so (SEC-7: never "Not supplied" or "Unknown"). */
    private const NO_LOT = 'Lot not recorded';

    private const NO_NAME = 'Name not recorded';

    /** @var array<string, list<array{0: float, 1: float}>|null> each estate's boundary, read once however many lists ask */
    private array $bounds = [];

    /**
     * Why a row's position cannot be drawn on the estate's map ("36 km outside the estate's boundary"), or null when it
     * can, or has no position (EstateBounds; Hassam, 12 Oct: one bad coordinate must not stretch the whole view).
     */
    private function offEstate(string $estateId, mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        if (! array_key_exists($estateId, $this->bounds)) {
            $this->bounds[$estateId] = EstateBounds::of([$estateId])[$estateId] ?? null;
        }

        return EstateBounds::notConfirmed($this->bounds[$estateId], (float) $lat, (float) $lng);
    }

    /*
     * WHAT A GATE VERDICT MEANS ON THIS SCREEN, in the gate API's own words (B088).
     *
     * `GateLog::record()` stores the verdict the Guard App sent — `admit`, `override`,
     * `exit`, `deny` (the legacy `GateEventController` accepts the same four). This screen
     * compared every row to `admitted`, a word only the demonstration seed ever wrote, so on
     * real data every admission, override and exit read "Refused" in red and the refusal
     * count was every event at the gate.
     *
     * An OVERRIDE is an admission — the officer let the person in against the system's
     * advice and said why — and says so. An EXIT is neither admitted nor refused: somebody
     * left. `admitted` and `denied` are PhoenixDemoSeed's words from before this was put
     * right; `gate_events` is append-only, so those rows can only be read correctly, never
     * rewritten.
     */
    private const OUTCOMES = [
        GateEvent::ADMIT => ['admitted', 'Admitted'],
        GateEvent::OVERRIDE => ['admitted', 'Admitted on override'],
        GateEvent::EXIT => ['exit', 'Left'],
        GateEvent::DENY => ['refused', 'Refused'],
        'admitted' => ['admitted', 'Admitted'],
        'denied' => ['refused', 'Refused'],
    ];

    /**
     * Everything the Security screen draws: three tables — the alerts, the incidents and the gate's log — each ordered
     * by the server, paged, and kept in the address under its own words (`alerts_sort`, `incidents_page`, `gate_per`)
     * so the three never take each other's place (M32 F5).
     *
     * @return array<string, mixed>
     */
    public function board(string $estateId, ?Request $request = null): array
    {
        $request ??= new Request;

        $alerts = TableQuery::from($request, self::alertSorts(), 'state', 'asc', self::PAGE, 'alerts_');
        $incidents = TableQuery::from($request, self::incidentSorts(), 'occurred', 'desc', self::PAGE, 'incidents_');
        $gate = TableQuery::from($request, self::gateSorts(), 'occurred', 'desc', self::PAGE, 'gate_');

        $alertPage = $this->alertsPage($estateId, $alerts);
        $incidentPage = $this->incidentsPage($estateId, $incidents);
        $gatePage = $this->gatePage($estateId, $gate);

        return [
            'alerts' => $alertPage['rows'],
            'alertsTable' => $alertPage['table'],
            'incidents' => $incidentPage['rows'],
            'incidentsTable' => $incidentPage['table'],
            'gate' => $gatePage['rows'],
            'gateTable' => $gatePage['table'],
            'presence' => $this->guardsOnSite($estateId),
            'counts' => $this->counts($estateId),
            'geo' => $this->geo($estateId),
        ];
    }

    /**
     * What the alerts table orders by (M32 F5). `state`, the default, is the list's own order: open ones first and then
     * newest, because a panic from an hour ago that nobody has closed matters more than one resolved five minutes ago.
     *
     * @return array<string, string|\Closure>
     */
    public static function alertSorts(): array
    {
        $flip = static fn (string $dir): string => $dir === 'asc' ? 'desc' : 'asc';

        return [
            'state' => static fn ($query, string $dir) => $query
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END '.$dir, self::SETTLED)
                ->orderBy('server_time', $flip($dir)),
            'raised' => 'server_time',
            'unit' => 'unit_reference',
            'kind' => 'kind',
            'who' => 'raised_by_name',
        ];
    }

    /** @return array<string, string|\Closure> */
    public static function incidentSorts(): array
    {
        return [
            'occurred' => 'occurred_at',
            'kind' => 'kind',
            // The ladder's own order (`IncidentLog::SEVERITIES`), lowest first ascending.
            'severity' => static fn ($query, string $dir) => $query->orderByRaw("FIELD(severity, 'low', 'med', 'high') ".$dir),
            'reported_by' => static fn ($query, string $dir) => $query->orderByRaw('COALESCE(guard_name, logged_by_name) '.$dir),
            'status' => 'status',
        ];
    }

    /** @return array<string, string> */
    public static function gateSorts(): array
    {
        return [
            'occurred' => 'occurred_at',
            'subject' => 'subject',
            'outcome' => 'verdict',
            'post' => 'post_name',
            'guard' => 'guard_name',
        ];
    }

    /**
     * THE ESTATE ON A MAP (M11 §6): its gates and posts where they stand (with the geofence a guard clocks in
     * within) and its patrol checkpoints. The open alerts and incidents with a position are pinned from the rows
     * `alerts()` and `incidents()` already read (their `lat`/`lng`), so the map costs the page two reads, not four.
     * What has no position is named as not yet placed.
     *
     * THE GUARDS ON DUTY AND THE OPEN PANICS ARE NOT HERE: they are the map's own feed, `EstateSecurityMap` (12 Oct
     * 2026), which copies each guard field an estate may see by name and draws positions only where the estate's
     * contract allows (TRACK-01). This is the estate's fixed ground: posts, fences, checkpoints, its boundary.
     *
     * @return array{posts: list<array<string, mixed>>, checkpoints: list<array<string, mixed>>, unplaced: list<array{id: string, title: string}>, unconfirmed: list<array{id: string, title: string, why: string}>, site: array<string, mixed>|null, boundary: array<string, mixed>|null}
     */
    public function geo(string $estateId): array
    {
        $posts = Post::query()->where('tenant_id', $estateId)->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'also_called', 'latitude', 'longitude', 'geofence_radius_m']);
        $checkpoints = DB::connection('mysql')->table('patrol_checkpoints')->where('tenant_id', $estateId)->where('is_active', true)
            ->orderBy('sequence')->get(['id', 'label', 'sequence', 'latitude', 'longitude']);

        // The estate's own fence (M12 §1): what a clock-in at a post with no position is judged against. Read-only here.
        $site = $this->site($estateId);
        $boundary = $site?->boundary === null ? null : json_decode((string) $site->boundary, true);
        $boundary = is_array($boundary) && count($boundary) >= 3 ? array_values($boundary) : null;
        $this->bounds[$estateId] = $boundary;

        // A gate or checkpoint stored outside the estate's boundary is named, never plotted (EstateBounds; Hassam, 12 Oct).
        $notConfirmed = static fn (mixed $lat, mixed $lng): ?string => EstateBounds::notConfirmed($boundary, (float) $lat, (float) $lng);
        [$placed, $offPosts] = $posts->filter(static fn (Post $p): bool => $p->isPlaced())
            ->partition(static fn (Post $p): bool => $notConfirmed($p->latitude, $p->longitude) === null);
        [$marked, $offCheckpoints] = $checkpoints->filter(static fn (object $c): bool => $c->latitude !== null && $c->longitude !== null)
            ->partition(static fn (object $c): bool => $notConfirmed($c->latitude, $c->longitude) === null);

        return [
            // Each post by the estate's own names (M26).
            'posts' => $placed->map(static fn (Post $p): array => ['id' => 'post-'.$p->id, 'lat' => (float) $p->latitude, 'lng' => (float) $p->longitude, 'radius_m' => (int) ($p->geofence_radius_m ?? 10), 'title' => $p->label()])
                ->values()->all(),

            'site' => $site !== null && $site->latitude !== null && $site->longitude !== null && $site->geofence_radius_m !== null
                ? ['lat' => (float) $site->latitude, 'lng' => (float) $site->longitude, 'radius_m' => (int) $site->geofence_radius_m]
                : null,

            // The estate's boundary as its site plan draws it (M57.5 §4.5), and where it came from; null without one.
            'boundary' => $boundary === null ? null : ['points' => $boundary, 'source' => (string) ($site->boundary_source ?? '')],

            'checkpoints' => $marked->map(static fn (object $c): array => ['id' => 'cp-'.$c->id, 'lat' => (float) $c->latitude, 'lng' => (float) $c->longitude, 'title' => $c->sequence.'. '.$c->label])
                ->values()->all(),

            'unplaced' => [
                ...$posts->reject(static fn (Post $p): bool => $p->isPlaced())->map(static fn (Post $p): array => ['id' => 'post-'.$p->id, 'title' => $p->label()])->values()->all(),
                ...$checkpoints->reject(static fn (object $c): bool => $c->latitude !== null && $c->longitude !== null)->map(static fn (object $c): array => ['id' => 'cp-'.$c->id, 'title' => (string) $c->label])->values()->all(),
            ],

            'unconfirmed' => [
                ...$offPosts->map(static fn (Post $p): array => ['id' => 'post-'.$p->id, 'title' => $p->label(), 'why' => (string) $notConfirmed($p->latitude, $p->longitude)])->values()->all(),
                ...$offCheckpoints->map(static fn (object $c): array => ['id' => 'cp-'.$c->id, 'title' => $c->sequence.'. '.$c->label, 'why' => (string) $notConfirmed($c->latitude, $c->longitude)])->values()->all(),
            ],
        ];
    }

    /**
     * Live and recent alerts at this estate.
     *
     * Open ones first and then by time, because a panic from an hour ago that
     * nobody has closed matters more than one resolved five minutes ago.
     *
     * @return list<array<string, mixed>>
     */
    public function alerts(string $estateId): array
    {
        return $this->alertsPage($estateId, TableQuery::from(new Request, self::alertSorts(), 'state', 'asc', self::PAGE, 'alerts_'))['rows'];
    }

    /**
     * A page of the alerts, in the order the table asks for (M32 F5).
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    private function alertsPage(string $estateId, TableQuery $table): array
    {
        $query = DuressAlert::query()->where('tenant_id', $estateId);
        $table->order($query, 'id');
        $page = $table->paginate($query);

        $rows = $page->getCollection()
            ->map(fn (DuressAlert $alert): array => [
                'id' => $alert->id,
                'kind' => $alert->kind,
                'kind_label' => $alert->kindLabel(),

                /*
                 * THE LOT IS THE POINT. The client's own words: "they must know
                 * there is an emergency at a specific lot." A committee member
                 * reading this needs the address before anything else.
                 */
                'unit' => $alert->unit_reference ?? self::NO_LOT,
                'who' => $alert->raised_by_name ?? self::NO_NAME,
                'status' => $alert->status,
                'open' => ! in_array($alert->status, self::SETTLED, true),
                'at' => SiteTime::fmt(SiteTime::local($alert->server_time, $estateId), 'D j M, g:i A'),
                'ago' => $alert->server_time->diffForHumans(),

                // The instant (B108): the screen keeps itself current now, and counts the
                // age on from this rather than showing `ago` as it was when the page was drawn.
                'raised_at' => $alert->server_time->toIso8601String(),
                'acknowledged' => $alert->acknowledged_at !== null,

                // A drill says so on the estate's own list too (M10 §1.3).
                'is_drill' => (bool) $alert->is_drill,

                // Where it was raised, when it came with a position: the map pins it beside the lot (M11 §6), unless it
                // lies outside the estate's boundary, and then the page names it and says how far (EstateBounds).
                'lat' => $alert->latitude === null ? null : (float) $alert->latitude,
                'lng' => $alert->longitude === null ? null : (float) $alert->longitude,
                'off_estate' => $this->offEstate($estateId, $alert->latitude, $alert->longitude),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'table' => $table->meta($page)];
    }

    /**
     * ONE ALERT, READ END TO END (the client's report E4, 26 Sep: "I am unable to click any of the rows").
     *
     * Still a read and only a read: acknowledging, sending an officer and closing an alert happen in Gemini's
     * control room. What the estate is told is what it is entitled to — who raised it and from which lot, where, and
     * each step the response reached with its time on the estate's clock: acknowledged, an officer sent (by name,
     * as "on site now" names them), on the way, at the scene, resolved and what was found. And who was TOLD, in
     * counts: security phones alerted, and texts the provider ACCEPTED out of those tried — never "sent" for a text
     * nobody accepted. WHO ACKNOWLEDGED IT is named (M12 §4.3: "who acknowledged it and when"), as Gemini's own
     * alert screen names them; E4 had said only "the control room". Another estate's alert is the same null as one
     * that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function alert(string $estateId, int $id): ?array
    {
        $alert = DuressAlert::query()->with('respondingGuard')->where('tenant_id', $estateId)->whereKey($id)->first();

        if (! $alert instanceof DuressAlert) {
            return null;
        }

        $at = static fn (?Carbon $moment): ?string => $moment === null ? null : SiteTime::fmt(SiteTime::local($moment, $estateId), 'D j M, g:i:s A');
        $responder = $alert->respondingGuard?->full_name;
        $acknowledgedBy = $alert->acknowledged_by === null ? null : User::query()->whereKey($alert->acknowledged_by)->value('name');

        $steps = array_values(array_filter([
            ['key' => 'raised', 'label' => 'Raised', 'at' => $at($alert->server_time), 'detail' => ($alert->raised_by_name ?? self::NO_NAME).($alert->captured_offline ? ' · saved on the phone with no signal and sent when it came back' : '')],
            // Each step in the Emergency list's word for its state (Hassam, 11 Oct): one word per idea, as dispatch says it.
            ['key' => 'acknowledged', 'label' => 'Acknowledged', 'at' => $at($alert->acknowledged_at), 'detail' => is_string($acknowledgedBy) && $acknowledgedBy !== '' ? 'By '.$acknowledgedBy : null],
            ['key' => 'dispatched', 'label' => 'Dispatched', 'at' => $at($alert->dispatched_at), 'detail' => $responder],
            ['key' => 'en_route', 'label' => 'On the way', 'at' => $at($alert->en_route_at), 'detail' => $responder],
            ['key' => 'on_scene', 'label' => 'On scene', 'at' => $at($alert->on_scene_at), 'detail' => $responder],
            ['key' => 'resolved', 'label' => $alert->status === 'false_alarm' ? 'False alarm' : 'Resolved', 'at' => $at($alert->resolved_at), 'detail' => $alert->resolution_note],
        ], static fn (array $step): bool => $step['at'] !== null));

        $told = array_values(array_filter([
            $alert->alerted_handsets === null ? null : $alert->alerted_handsets.' security '.($alert->alerted_handsets === 1 ? 'phone' : 'phones').' alerted',
            $alert->sms_recipients ? ($alert->sms_delivered ?? 0).' of '.$alert->sms_recipients.' estate emergency numbers texted (accepted by the provider)' : null,
            $alert->household_sms_recipients ? ($alert->household_sms_delivered ?? 0).' of '.$alert->household_sms_recipients.' household emergency contacts texted (accepted by the provider)' : null,
            // M12 §2.5: the same contacts by email — "sent" only when the mail server accepted it.
            $alert->household_email_recipients ? 'Household and emergency contacts: '.app(PanicEmailOutcome::class)->householdLine($alert) : null,
            $alert->staff_sms_recipients ? ($alert->staff_sms_delivered ?? 0).' of '.$alert->staff_sms_recipients.' Heads of Security texted (accepted by the provider)' : null,
            $alert->escalated_contacts ? $alert->escalated_contacts.' escalation '.($alert->escalated_contacts === 1 ? 'contact' : 'contacts').' e-mailed' : null,
        ]));

        return [
            'id' => (int) $alert->id,
            'kind_label' => $alert->kindLabel(),
            'is_drill' => (bool) $alert->is_drill,
            'unit' => $alert->unit_reference ?? self::NO_LOT,
            'who' => $alert->raised_by_name ?? self::NO_NAME,
            'open' => ! in_array($alert->status, self::SETTLED, true),
            // Where the alert stands, in the Emergency list's words (Hassam, 11 Oct), the same words dispatch uses.
            'status' => match (true) {
                $alert->status === 'false_alarm' => 'False alarm',
                $alert->status === 'resolved' => 'Resolved',
                $alert->on_scene_at !== null => 'On scene',
                $alert->en_route_at !== null => 'On the way',
                $alert->dispatched_at !== null => 'Dispatched',
                $alert->acknowledged_at !== null => 'Acknowledged',
                default => 'Unacknowledged',
            },
            'lat' => $alert->latitude === null ? null : (float) $alert->latitude,
            'lng' => $alert->longitude === null ? null : (float) $alert->longitude,
            'steps' => $steps,
            'told' => $told,

            // Every number, ready to dial (M18C §4.2): the resident, the household, the emergency contacts.
            'people' => app(PanicPeople::class)->of($alert),
        ];
    }

    /**
     * Incidents logged at this estate.
     *
     * @return list<array<string, mixed>>
     */
    public function incidents(string $estateId): array
    {
        return $this->incidentsPage($estateId, TableQuery::from(new Request, self::incidentSorts(), 'occurred', 'desc', self::PAGE, 'incidents_'))['rows'];
    }

    /**
     * ONE INCIDENT, READ END TO END (Batch 1 item 10, 12 Oct 2026: the list's rows had nowhere to open to). Still a
     * read and only a read: an incident is logged and resolved on the Guard App and the Gemini console; this is what
     * the estate is entitled to about its own gate. What, when, how serious, what was done, and who was on post, by
     * name only (never their employee number, licence or record). Another estate's incident is the same null as one
     * that does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function incident(string $estateId, int $id): ?array
    {
        $incident = SecurityIncident::query()->where('tenant_id', $estateId)->whereKey($id)->first();

        if (! $incident instanceof SecurityIncident) {
            return null;
        }

        return [
            'id' => $incident->id,
            'kind_label' => self::incidentKindLabel((string) $incident->kind),
            'detail' => $incident->detail,
            'severity' => $incident->severity,
            'severity_label' => IncidentLog::SEVERITIES[$incident->severity] ?? 'Low',
            'closed' => $incident->closed_at !== null,
            'status_label' => $incident->closed_at !== null ? 'Closed' : 'Action required',
            'occurred_at' => SiteTime::fmt(SiteTime::local($incident->occurred_at, $estateId), 'D j M, g:i A'),
            'guard' => $incident->guard_name ?? 'No guard recorded',
            'logged_by' => $incident->logged_by_name ?? 'Not recorded',
            'logged_at' => $incident->created_at === null ? null : SiteTime::fmt(SiteTime::local($incident->created_at, $estateId), 'D j M, g:i A'),
            'resolution' => $incident->resolution,
            'closed_at' => $incident->closed_at === null ? null : SiteTime::fmt(SiteTime::local($incident->closed_at, $estateId), 'D j M, g:i A'),
        ];
    }

    /**
     * A page of the incidents, in the order the table asks for (M32 F5).
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    private function incidentsPage(string $estateId, TableQuery $table): array
    {
        $query = SecurityIncident::query()->where('tenant_id', $estateId);
        $table->order($query, 'id');
        $page = $table->paginate($query);

        $rows = $page->getCollection()
            ->map(fn (SecurityIncident $incident): array => [
                'id' => $incident->id,
                'kind' => $incident->kind,
                'kind_label' => self::incidentKindLabel((string) $incident->kind),
                'detail' => $incident->detail,
                'severity' => $incident->severity,
                'status' => $incident->status,
                // The vocabulary's words (M98 §3.5): an incident nobody has closed waits on somebody, so Action required.
                'status_label' => $incident->closed_at !== null ? 'Closed' : 'Action required',
                'at' => SiteTime::fmt(SiteTime::local($incident->occurred_at, $estateId), 'D j M, g:i A'),

                /*
                 * The officer's NAME and nothing else about them. An estate is
                 * entitled to know who reported an incident at its gate; it is
                 * not entitled to their employee number, their licence or their
                 * record, and none of those is selected.
                 */
                'reported_by' => $incident->guard_name ?? $incident->logged_by_name ?? 'The estate office',
                'closed' => $incident->closed_at !== null,

                // Where it was reported, when it came with a position (M11 §6), unless it lies outside the estate's
                // boundary (EstateBounds).
                'lat' => $incident->latitude === null ? null : (float) $incident->latitude,
                'lng' => $incident->longitude === null ? null : (float) $incident->longitude,
                'off_estate' => $this->offEstate($estateId, $incident->latitude, $incident->longitude),
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'table' => $table->meta($page)];
    }

    /**
     * Who came through the gate, most recent first.
     *
     * @return list<array<string, mixed>>
     */
    public function gateActivity(string $estateId): array
    {
        return $this->gatePage($estateId, TableQuery::from(new Request, self::gateSorts(), 'occurred', 'desc', self::PAGE, 'gate_'))['rows'];
    }

    /**
     * A page of the gate's log, in the order the table asks for (M32 F5).
     *
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    private function gatePage(string $estateId, TableQuery $table): array
    {
        $query = GateEvent::query()->where('tenant_id', $estateId);
        $table->order($query, 'id');
        $page = $table->paginate($query);

        $rows = $page->getCollection()
            ->map(fn (GateEvent $event): array => [
                'id' => $event->id,
                'subject' => $event->subject,
                'verdict' => $event->verdict,
                ...$this->outcome($event->verdict),
                'category' => $event->category,
                'basis' => $event->basis,
                'post' => $event->post_name ?? 'Gate',
                'guard' => $event->guard_name ?? 'Unattended',
                'at' => SiteTime::fmt(SiteTime::local($event->occurred_at, $estateId), 'D j M, g:i A'),
                'simulated' => (bool) $event->is_simulated,
            ])
            ->values()
            ->all();

        return ['rows' => $rows, 'table' => $table->meta($page)];
    }

    /**
     * The presence the estate is paying for, and nothing more.
     *
     * A COUNT AND A LIST OF NAMES. The data-ownership matrix gives the community
     * "guards on site: N" and alert states — so a committee member watching a
     * panic can see somebody is on the ground and who to expect. It does not
     * give them the officer's employment record, and this method selects none of
     * it: no employee number, no PSRA licence, no rate, no handset, no other
     * estate they work.
     *
     * @return array{count: int, officers: list<array{name: string, post: string}>}
     */
    public function guardsOnSite(string $estateId): array
    {
        $shifts = $this->onSite($estateId);

        return [
            'count' => $shifts->count(),
            // No clock-in time (Hassam, 12 Oct): the estate sees who is on duty and where they are posted, not when they started.
            'officers' => $shifts->map(fn (Shift $shift): array => [
                'name' => (string) $shift->officer->full_name,
                'post' => $shift->post?->label() ?? 'No post',
            ])->values()->all(),
        ];
    }

    /** @var array<string, Collection<int, Shift>> */
    private array $onSite = [];

    /**
     * The guards on duty at this estate now, by name: clocked in and not out (12 Oct 2026: "a guard appears when they
     * clock in and disappears when they clock out"), on a shift begun within the last day. The map's own rule
     * (`EstateSecurityMap::onDuty`), so the count above the page and the guards on its map always agree; it was the
     * rostered window, which dropped a guard still on duty past the end of their roster.
     *
     * @return Collection<int, Shift>
     */
    private function onSite(string $estateId): Collection
    {
        return $this->onSite[$estateId] ??= EstateSecurityMap::onDuty($estateId, Carbon::now());
    }

    /**
     * The same guards on duty, for the page's map to draw without reading them a second time.
     *
     * @return Collection<int, Shift>
     */
    public function onDuty(string $estateId): Collection
    {
        return $this->onSite($estateId);
    }

    /** @var array<string, object|null> */
    private array $site = [];

    /**
     * The estate's site row, read once a page: its fence and boundary, for the ground and for the map
     * (`EstateSecurityMap`).
     */
    public function site(string $estateId): ?object
    {
        if (! array_key_exists($estateId, $this->site)) {
            $this->site[$estateId] = DB::connection('mysql')->table('estate_sites')->where('tenant_id', $estateId)
                ->first(['latitude', 'longitude', 'geofence_radius_m', 'boundary', 'boundary_source']);
        }

        return $this->site[$estateId];
    }

    /**
     * The four figures above the lists.
     *
     * @return array<string, int>
     */
    public function counts(string $estateId): array
    {
        $since = Carbon::now()->subDays(30);

        return [
            'open_alerts' => DuressAlert::query()
                ->where('tenant_id', $estateId)
                ->whereNotIn('status', self::SETTLED)
                ->count(),

            'open_incidents' => SecurityIncident::query()
                ->where('tenant_id', $estateId)
                ->whereNull('closed_at')
                ->count(),

            'admitted_30d' => GateEvent::query()
                ->where('tenant_id', $estateId)
                ->whereIn('verdict', self::verdictsMeaning('admitted'))
                ->where('occurred_at', '>=', $since)
                ->count(),

            // A refusal is a denial, and only that: an exit is not somebody turned away.
            'refused_30d' => GateEvent::query()
                ->where('tenant_id', $estateId)
                ->whereIn('verdict', self::verdictsMeaning('refused'))
                ->where('occurred_at', '>=', $since)
                ->count(),
        ];
    }

    /**
     * What one verdict means here: `admitted`, `exit` or `refused`, and the word the row shows.
     * A verdict this screen does not know is shown as written and claimed as neither.
     *
     * @return array{outcome: string, label: string, admitted: bool}
     */
    private function outcome(string $verdict): array
    {
        [$outcome, $label] = self::OUTCOMES[$verdict] ?? ['other', ucfirst($verdict)];

        return ['outcome' => $outcome, 'label' => $label, 'admitted' => $outcome === 'admitted'];
    }

    /**
     * Every stored verdict that means this outcome.
     *
     * @return list<string>
     */
    private static function verdictsMeaning(string $outcome): array
    {
        return array_keys(array_filter(self::OUTCOMES, static fn (array $meaning): bool => $meaning[0] === $outcome));
    }
}
