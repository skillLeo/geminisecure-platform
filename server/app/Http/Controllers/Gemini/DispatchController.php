<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\AlertProgress;
use App\Services\Dispatch\AlertProgressRefused;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Dispatch\DispatchMessaging;
use App\Services\Dispatch\LiveMap;
use App\Services\Dispatch\PanicAudio;
use App\Services\Dispatch\PanicEmailOutcome;
use App\Services\Dispatch\PanicMessages;
use App\Services\Dispatch\PanicPeople;
use App\Services\Dispatch\PanicSmsOutcome;
use App\Services\Dispatch\PatrolMonitor;
use App\Services\Dispatch\PostCoverage;
use App\Services\Dispatch\RequestInbox;
use App\Services\Platform\OperatorEstates;
use App\Services\Ring\RingReach;
use App\Services\Ring\RingReport;
use App\Services\Tracking\GuardSignal;
use App\Support\AlertClassification;
use App\Support\CalendarDate;
use App\Support\PlatformMoney;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispatch â€” Super Admin screens 14 and 17.
 *
 * Two life-safety surfaces:
 *
 *   14  the active alerts queue, board `super-admin-14`
 *   17  the panic alert response screen, board `super-admin-17`
 *
 * Both read gs_platform and nothing else. A dispatcher watches every estate at
 * once, which is only possible because dispatch records are central; opening an
 * estate database from here would turn one screen into a fan-out across every
 * client and break the isolation model that makes the screen legal in the
 * first place.
 *
 * The queue is deliberately a MIXED feed, as the board draws it: a resident's
 * panic button, a guard-reported incident and a workforce compliance flag sit
 * in one list, each carrying the source tag that says where it came from. The
 * alternative â€” three separate screens â€” is not what was approved, and a
 * dispatcher does not have three screens' worth of attention during an
 * incident.
 *
 * NO MONETARY FIGURE APPEARS ANYWHERE HERE, and none of the tables these
 * methods read holds one. That is invariant 2 standing on structure rather
 * than on remembering to leave a column out.
 */
class DispatchController extends Controller
{
    /** The queue's filter tabs, in the order the board draws them. */
    private const TABS = [
        'all' => 'All alerts',
        'panic' => 'Panic alerts',
        'incidents' => 'Incidents',
        'resolved' => 'Resolved',
    ];

    /** Statuses that mean nobody is waiting on this any more. */
    private const SETTLED = ['resolved', 'false_alarm'];

    /**
     * Compliance flags sort below every live alert.
     *
     * DuressAlert::priority() returns 1 to 4. A lapsed licence matters, but it
     * never outranks a person asking for help, so it takes a rank below the
     * lowest an alert can hold rather than being interleaved by timestamp.
     */
    private const COMPLIANCE_PRIORITY = 5;

    /**
     * Dispatch live map â€” board super-admin-12.
     *
     * The screen polls this method every five seconds, which is what its
     * status pill claims, so it stays cheap: four indexed queries and no
     * per-row lookups.
     *
     * `estateIds` is sent so the screen can also open the private alert
     * channel for each estate it is showing. That channel carries a
     * notification and nothing else â€” never a position, because there is no
     * position anywhere in this module to carry.
     */
    public function map(Request $request, LiveMap $map): Response
    {
        $viewer = $request->user();

        return inertia('Gemini/Dispatch/Map', [
            'sections' => $this->sectionTabs('map'),
            // The site card's plan name is the client's tier, and the tier is Billing's (M10 §5).
            ...$map->forViewer($viewer, PlatformMoney::maySeeRevenue($viewer)),
            'estateIds' => $this->visibleEstateIds($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,

            /*
             * Every alert on this map originates on a handset, and until the
             * Guard App exists the simulator is what produces them. Asked of the
             * alerts a viewer can actually see rather than of the table, so a
             * site-scoped dispatcher is told about their own estates' data.
             */
            'sourceBadge' => SourceBadge::guard($this->anySimulatedAlert($viewer)),
        ]);
    }

    /**
     * Post coverage board â€” board super-admin-13.
     *
     * ON THE LIVE STREAM (13 C1), like the other dispatch screens. It was left
     * unpolled on the reasoning that a roster changes when a supervisor changes
     * it â€” but coverage does not turn on the roster alone: a post is covered once
     * somebody clocks in across it, and that happens on a handset, at a gate, at
     * 18:58. The screen now subscribes to the estates it shows and re-reads when
     * a guard clocks on or off, and polls only while the channel is down.
     */
    public function coverage(Request $request, PostCoverage $coverage): Response
    {
        $viewer = $request->user();

        // A chosen day, or today. A malformed ?date= lands on today rather than
        // an error: this parameter selects a view, it does not write anything.
        // A real calendar day, not only its shape: 2026-13-45 threw a 500, and
        // 2026-02-30 became 2 March (bug hunt B014).
        $date = CalendarDate::from($request->query('date'));
        $on = $date === null ? null : Carbon::parse($date);

        return inertia('Gemini/Dispatch/Coverage', [
            'sections' => $this->sectionTabs('coverage'),
            ...$coverage->forViewer($viewer, $on),
            'estateIds' => $this->visibleEstateIds($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * Guard alertness and patrol monitoring â€” board super-admin-15.
     *
     * The one question is whether every guard who is supposed to be on duty
     * right now is still demonstrably awake and reporting, and SILENCE IS THE
     * FINDING: a guard who has stopped scanning has either sat down, been hurt
     * or lost their handset, and all three need a dispatcher. So this is a
     * life-safety surface like the queue, polled and streamed rather than left
     * to a reload.
     *
     * `estateIds` is sent so the screen can open the private alert channel for
     * each estate it is watching, exactly as the live map does. That channel
     * carries a notification and nothing else.
     *
     * NO POSITION LEAVES THIS METHOD. PatrolMonitor deliberately holds none, so
     * there is no coordinate anywhere in this response that could be broadcast
     * as where a guard is standing.
     */
    public function alertness(Request $request, PatrolMonitor $monitor): Response
    {
        $viewer = $request->user();

        return inertia('Gemini/Dispatch/Alertness', [
            'sections' => $this->sectionTabs('patrol'),
            ...$monitor->forViewer($viewer),
            'estateIds' => $this->visibleEstateIds($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,

            /*
             * Every scan and every alertness challenge on this screen was
             * answered on a handset. The alert channel is what would tell a
             * dispatcher something has gone wrong here, so its origin is what
             * this badge reports.
             */
            'sourceBadge' => SourceBadge::guard($this->anySimulatedAlert($viewer)),
        ]);
    }

    /**
     * Requests inbox â€” board super-admin-16.
     *
     * Leave, equipment and the message log in one place, because a dispatcher's
     * question is "what is waiting on me", not "what kind of thing is waiting
     * on me".
     *
     * Not polled, and deliberately not. A leave request is not a life-safety
     * event: it arrived hours ago and will still be there on the next reload,
     * and a five-second refresh on a queue a dispatcher is reading would move
     * rows out from under them mid-decision.
     */
    public function requests(Request $request, RequestInbox $inbox): Response
    {
        $board = $inbox->forViewer($request->user());

        return inertia('Gemini/Dispatch/Requests', [
            'sections' => $this->sectionTabs('requests'),
            ...$board,

            // Category B (13 C3): leave and equipment requests are raised on a handset.
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn(
                'mysql',
                'guard_requests',
                [...array_column($board['leave'], 'id'), ...array_column($board['equipment'], 'id')],
            )),
        ]);
    }

    /**
     * Requests already decided â€” board 16's "Request history" (12 Â§2, Wave 4).
     *
     * The inbox holds what is waiting; this holds what was decided, by whom and
     * when, scoped the same way the inbox is. Behind the same `view` gate: a
     * dispatcher who may read the queue may read what became of it.
     */
    public function requestHistory(Request $request, RequestInbox $inbox): Response
    {
        /*
         * `before` is a cursor the previous answer handed out, not a page number: a decision
         * taken while somebody was reading page one used to push a row down an OFFSET and
         * serve it to them twice. Anything that is not a cursor this list issued is read as
         * "from the newest" â€” a stale bookmark shows the top of the list, never a refusal.
         */
        $history = $inbox->history($request->user(), $request->query('before') === null ? null : (string) $request->query('before'));

        return inertia('Gemini/Dispatch/RequestHistory', [
            'sections' => $this->sectionTabs('requests'),
            ...$history,
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn('mysql', 'guard_requests', array_column($history['rows'], 'id'))),
        ]);
    }

    /**
     * Approve or deny one request. A real state change, audited.
     *
     * Two dispatchers can be on this queue at once, and the second one must not
     * silently overwrite the first â€” so a request already decided comes back as
     * a message naming what happened to it rather than as a 500 or, worse, as a
     * quiet second write.
     */
    public function decide(Request $request, int $guardRequest, RequestInbox $inbox): RedirectResponse
    {
        try {
            $inbox->decide($request->user(), $guardRequest, $request->all());
        } catch (RuntimeException $refused) {
            return back()->withErrors(['decision' => $refused->getMessage()]);
        }

        return back();
    }

    /**
     * Active alerts queue â€” board super-admin-14.
     *
     * The screen polls, so this method is also the poll's endpoint. It stays
     * cheap for that reason: two indexed queries and no per-row lookups.
     */
    public function alerts(Request $request): Response
    {
        $viewer = $request->user();

        /*
         * The tab lives in the URL, not in component state.
         *
         * That is what makes it survive the screen's own three-second poll: a
         * partial reload re-runs this method, and a filter held only in Vue
         * would be silently discarded on the first refresh. It also makes a
         * filtered queue linkable, which matters when one dispatcher hands an
         * incident to another.
         *
         * Validated against the known set, so a hand-typed ?tab= produces the
         * default queue rather than an empty screen.
         */
        $requested = $request->query('tab');
        $tab = is_string($requested) && array_key_exists($requested, self::TABS) ? $requested : 'all';

        $rows = array_merge(
            $this->alertRows($viewer, $tab),
            $this->complianceRows($viewer, $tab),
        );

        /*
         * Ordered by what is being asked for, then by recency.
         *
         * Sorted here rather than in SQL because the ordering spans two tables
         * and one domain rule â€” panic first â€” that no column expresses.
         */
        usort($rows, function (array $a, array $b): int {
            return [$a['priority'], $b['at']] <=> [$b['priority'], $a['at']];
        });

        $estateIds = $this->visibleEstateIds($viewer);

        return inertia('Gemini/Dispatch/Alerts', [
            'sections' => $this->sectionTabs('alerts'),
            'tabs' => $this->filterTabs($tab),
            'tab' => $tab,

            /*
             * NO SIGNAL (guard live tracking, 12 Oct 2026): a guard on duty whose phone has sent nothing for longer than
             * their estate's tolerance, "No signal from <name> at <site>, last seen HH:MM", in the queue with the
             * others, cleared by itself when a position arrives (GuardSignal). On every tab: it is an operational
             * problem now, whatever the filter.
             */
            'noSignal' => app(GuardSignal::class)->open($estateIds),

            /*
             * The estates whose alert channel this screen may listen on.
             *
             * THE QUEUE ITSELF WAS THE LAST DISPATCH SCREEN WITHOUT A SOCKET,
             * which is the wrong way round: the map and the alertness board both
             * pushed, and the one screen whose entire job is to show a panic the
             * moment it arrives sat on a three-second poll. Scoped exactly as the
             * queue is, so a site-scoped dispatcher subscribes to the estates
             * they can already see and no others.
             */
            'estateIds' => $estateIds,

            /*
             * Asked of the rows in the queue rather than of the table, because
             * this screen is filtered by tab: a reviewer looking at "Resolved"
             * should be told about the alerts in front of them, not about a
             * simulated one sitting in another tab.
             */
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulated($rows)),
            // The sort keys were only ever for the sort. They do not travel to
            // the browser, where a second copy of the ordering rule could
            // start disagreeing with this one.
            'alerts' => array_map(
                static fn (array $row): array => Arr::except($row, ['priority', 'at', 'is_simulated']),
                $rows,
            ),
        ]);
    }

    /**
     * Panic alert response â€” board super-admin-17.
     *
     * A dispatcher holding this open must see the status change under them â€”
     * a guard acknowledging on the ground, the alert resolving â€” without
     * reloading, so the props below are all re-fetched by the screen's poll.
     */
    public function alert(Request $request, DuressAlert $alert, DeliveryReport $deliveries, AlertProgress $progress): Response
    {
        $viewer = $request->user();

        // 404, not 403. A site-scoped dispatcher should not learn that an
        // alert exists at an estate they do not cover.
        abort_unless($viewer->canAccessEstate($alert->tenant_id), 404);

        $alert->load(['raisedByGuard.post', 'estate', 'respondingGuard']);

        /*
         * WHO MAY BE SENT (M10 §1.3): exactly the officers this panic was pushed
         * to, in name order. Not a ranking, for the reason `onDuty` below is not
         * one. A dispatcher who could pick somebody the fan-out never rang would
         * be sending an officer who has not been told there is anything to go to.
         */
        $candidates = $progress->candidates($alert)
            ->map(fn (Guard $guard): array => [
                'guard_id' => (int) $guard->id,
                'name' => (string) $guard->full_name,
                'post' => $guard->post?->label() ?? 'No post',
            ])
            ->values()
            ->all();

        /*
         * WHO IS ON DUTY HERE â€” A LIST, NOT A RANKING (M10 Â§0).
         *
         * A distance ranking briefly lived here and the client has ruled it out:
         * a panic goes to EVERYONE who can help, at once, and no officer is
         * singled out as nearest. There is no guard position modelled anywhere,
         * no distance computed, and nothing on this screen implies one.
         *
         * The list survives because the message box needs an addressee and the
         * dispatcher needs to see who is actually clocked on. It is ordered by
         * name, which is an order rather than a claim.
         */
        $onDuty = $this->onDutyOfficers($alert);

        $responder = $this->responder($alert);
        $respondingGuard = $responder?->full_name;
        $who = $this->raisedBy($alert);
        [$statusLabel] = $this->statusPill($alert);

        return inertia('Gemini/Dispatch/Alert', [
            /*
             * One estate, and only this alert's own.
             *
             * The queue and the map watch every estate a dispatcher covers; a
             * single alert's response screen is about one incident at one
             * community, and subscribing it to the rest would push it a refresh
             * every time anything happened anywhere.
             */
            'estateIds' => [$alert->tenant_id],

            /*
             * This alert's own origin, and nothing wider. A response screen is
             * about one incident: whether some other alert in the queue was
             * simulated says nothing about whether THIS person needs help.
             */
            'sourceBadge' => SourceBadge::guard((bool) $alert->is_simulated),

            /*
             * The officers clocked on here, so the dispatcher can see who is
             * actually available and address the message box to one of them.
             *
             * NOT a ranking, and the screen says so: the client has ruled that a
             * panic reaches everyone who can help rather than the closest
             * officer (M10 Â§0), so no order here carries a claim about who is
             * nearer to anything.
             */
            'onDuty' => $onDuty,

            /*
             * WHO WAS TOLD, AND HOW FAST (M10 §1.3). Every one of §1.2's seven
             * destinations, what it was handed by which channel, and how many
             * milliseconds after the fan-out began — HANDED OFF, never delivered, and
             * the screen says so under the list. A destination nothing addresses is
             * listed as not reached with the reason, never left out.
             *
             * Re-fetched by the poll with the rest: the rows are written the moment
             * the last leg goes, which can be a few milliseconds after this screen
             * was first opened from the queue.
             */
            'deliveries' => $deliveries->forAlert($alert),

            /*
             * THE RING (the guard ring §9.3): round by round, what each phone was sent and what
             * the phones themselves said — sent, call-style ready and partly ready apart, shown
             * on the phone, with the alarm sound on — and never that a phone "rang". Re-fetched
             * by the poll with the rest.
             */
            'rings' => app(RingReport::class)->forAlert($alert),

            'alert' => [
                /*
                 * A DRILL SAYS SO IN THE HEADLINE (M10 §1.3), the first thing on the
                 * screen, so that nobody reads the rest of it as a person in danger.
                 */
                'headline' => $alert->drillPrefix().$this->headline($alert),
                'is_drill' => (bool) $alert->is_drill,
                'who' => $who,
                'subtitle' => $this->join([
                    $who,
                    $alert->estate->name ?? 'Unknown estate',

                    /*
                     * The queue no longer carries this estate's alerts (M5 Â§3),
                     * but the response screen is still reachable by id â€” and it
                     * should be, so somebody at Gemini can check the
                     * demonstration estate works before a reviewer finds out it
                     * does not. What must not happen is that they read it as a
                     * real person in trouble, so the screen says which it is.
                     */
                    OperatorEstates::isDemonstration($alert->tenant_id) ? OperatorEstates::LABEL : null,

                    $alert->unit_reference,
                ]),
                'status_label' => $statusLabel,
                'fired' => $alert->server_time->diffForHumans(),

                // The instant as well (B112): the screen counts on from it while it is open,
                // where the age above is only true at the moment the page was drawn.
                'fired_at' => $alert->server_time->toIso8601String(),

                /*
                 * The guard on post, which is what `responder()` has always
                 * answered and all this screen may claim. It is NOT the nearest
                 * officer and the label beside it does not say so â€” no guard
                 * position is modelled anywhere in this platform, and the client
                 * has ruled the whole idea out (M10 Â§0).
                 */
                'responder' => $respondingGuard ?? 'None on post',

                'location_source' => $this->locationSource($alert),
                'message_target' => $respondingGuard ?? 'the responding guard',
                'unit' => $alert->unit_reference ?? 'Not supplied',

                /*
                 * Three of the board's five resident rows are answered by this
                 * console with "not here". They are answered honestly rather
                 * than filled in, because household composition and emergency
                 * contacts live in the estate's own database and medical notes
                 * are not held centrally at all. Inventing a value would make
                 * a dispatcher trust a figure this console cannot see.
                 */
                'household' => 'In the estate database',
                'medical_notes' => 'Never held centrally',
                'emergency_contact' => 'In the estate database',

                /*
                 * BUT THE NUMBERS ARE HERE (M18C §4.2): the resident, every household member and every emergency
                 * contact, each with name, relationship and number, ready to tap and dial — read from the estate
                 * the way the panic's own mail found them. Null on an officer's duress.
                 */
                'people' => app(PanicPeople::class)->of($alert),

                /*
                 * WHAT THE HOUSEHOLD'S OWN CONTACTS WERE SENT (M10 §1.2 item 7) — a
                 * fact this console CAN see, because it is in the central ledger:
                 * how many texts a provider accepted, never who the contacts are.
                 * "2 texted", or "not texted: no SMS provider connected". Null on an
                 * officer's duress, which texts no household, and the row is left out.
                 */
                'household_contacts' => app(PanicSmsOutcome::class)->householdLine($alert),

                // And what they were EMAILED (M12 §2.5): "2 emailed", from `alert_email_dispatches`. Null on a duress.
                'household_emails' => app(PanicEmailOutcome::class)->householdLine($alert),

                /*
                 * AND WHAT BOTH HEADS OF SECURITY WERE TEXTED (M10 §1.2 items 4 and 5),
                 * from the same ledger: "2 texted", "not texted: no phone on file". On
                 * every alert, a guard's duress included.
                 */
                'heads_of_security_texts' => app(PanicSmsOutcome::class)->staffLine($alert),
            ],
            'timeline' => $this->timeline($alert, $candidates),
            'actions' => $this->actions($alert, $viewer, $candidates),

            /*
             * THE RESIDENT'S LIVE CHANNEL (M50 §4.2 #2): what the resident wrote from their panic screen, and the
             * control room's replies. Null on an officer's duress, which has the officer box instead. Drawing this
             * page IS the control room being shown the resident's messages: they are marked delivered here, and the
             * resident's screen says so at once.
             */
            'residentThread' => $this->residentThread($alert, $viewer),

            /*
             * THE RESIDENT'S LIVE AUDIO (M50 §4.2 #1): whether pieces are coming in, how much there is, and where the
             * player asks for more. Null on an officer's duress.
             */
            'residentAudio' => ($summary = app(PanicAudio::class)->summary($alert)) === null ? null : [
                ...$summary,
                'list_url' => '/dispatch/alerts/'.$alert->id.'/audio',
                'live_seconds' => PanicAudio::LIVE_SECONDS,
            ],
        ]);
    }

    /** The pieces of the resident's live audio after `?after=` (M50 §4.2 #1), for the alert page's player. */
    public function audioList(Request $request, DuressAlert $alert, PanicAudio $audio): JsonResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $after = (int) $request->query('after', '-1');

        return response()->json(['pieces' => $audio->after($alert, $after), 'summary' => $audio->summary($alert)])
            ->header('Cache-Control', 'no-store');
    }

    /** One piece's bytes, from the private disk. */
    public function audioPiece(Request $request, DuressAlert $alert, int $seq, PanicAudio $audio): StreamedResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        return $audio->stream($alert, $seq) ?? abort(404);
    }

    /**
     * @return array{messages: list<array<string, mixed>>, canReply: bool, closed: bool, max: int}|null
     */
    private function residentThread(DuressAlert $alert, User $viewer): ?array
    {
        if ($alert->raised_by_account_id === null) {
            return null;
        }

        $messages = app(PanicMessages::class);
        $messages->seenByDispatch($alert);

        return [
            'messages' => $messages->forConsole($alert),
            'canReply' => $viewer->can('gemini.dispatch.update'),
            'closed' => in_array($alert->status, ['resolved', 'false_alarm'], true),
            'max' => PanicMessages::MAX_BODY,
        ];
    }

    /**
     * The control room replies to the resident's panic (M50 §4.2 #2). On the resident's screen at once, and pushed.
     * `update`, like acknowledge: it puts words in front of a frightened person in the control room's name.
     */
    public function reply(Request $request, DuressAlert $alert, PanicMessages $messages): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $data = $request->validate(
            ['body' => ['required', 'string', 'max:2000']],
            ['body.required' => 'Write the reply first.', 'body.max' => 'Keep the reply under 500 characters.'],
        );

        try {
            $messages->fromDispatch($alert, $request->user(), (string) $data['body']);
        } catch (DomainException $refused) {
            return back()->withErrors(['reply' => $refused->getMessage()]);
        }

        return back()->with('success', 'Sent to the resident. It is on their panic screen now.');
    }

    /**
     * Acknowledge an alert on the console's behalf.
     *
     * A real state change, audited. The guard's own acknowledgement will
     * arrive from the Guard App when it ships; until then a dispatcher who has
     * reached the guard by radio can record it here rather than leaving the
     * queue lying about who is responding.
     */
    public function acknowledge(Request $request, DuressAlert $alert, AlertIntake $intake): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $intake->acknowledge($alert, $request->user());

        return back();
    }

    /**
     * Close an alert out. A real state change, audited.
     *
     * THE CLASSIFICATION IS A CHOICE FROM A LIST (AlertClassification), made in
     * the console's resolve dialog, and the note is what the list cannot say.
     * Two classifications close the alert as a false alarm; "Genuine — other"
     * needs a note, because the list alone would say nothing about it.
     *
     * Every refusal is a sentence the dialog shows in place, under the field it
     * is about. An over-long note used to be refused with no message at all
     * (B115), and the alert simply stayed open.
     */
    public function resolve(Request $request, DuressAlert $alert, AlertIntake $intake): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $data = $request->validate(
            [
                'classification' => ['required', 'string', Rule::in(AlertClassification::keys())],
                'note' => ['nullable', 'string', 'max:'.AlertClassification::NOTE_MAX],
            ],
            [
                'classification.required' => 'Choose how this alert is classified.',
                'classification.in' => 'Choose one of the listed classifications.',
                'note.max' => 'The note can be up to '.AlertClassification::NOTE_MAX.' characters. Shorten it and resolve again.',
            ],
        );

        $classification = (string) $data['classification'];
        $note = trim((string) ($data['note'] ?? ''));

        if (AlertClassification::noteRequired($classification) && $note === '') {
            return back()->withErrors(['note' => 'Say briefly what happened. "Other emergency" needs a note, because the list does not say it.']);
        }

        $intake->resolve(
            $alert,
            $request->user(),
            $note === '' ? null : $note,
            AlertClassification::isFalseAlarm($classification),
            $classification,
        );

        return back();
    }

    /**
     * Send one officer to this alert (M10 §1.3). A real state change, audited.
     *
     * THE OFFICER IS NAMED BY ID AND CHECKED AGAINST THE ALERT'S OWN REACH, for
     * `messageGuard()`'s reason: a posted id is a posted id, and an officer at
     * another client — or one the panic never reached — is refused by
     * `AlertProgress` with a sentence rather than sent.
     *
     * The flash says what is TRUE: the dispatch is on the record and a push was
     * queued to the officer's handset if they have one registered. It does not
     * claim the officer has it; only a radio answer can say that.
     */
    public function dispatchOfficer(Request $request, DuressAlert $alert, AlertProgress $progress): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $data = $request->validate([
            'guard_id' => ['required', 'integer', 'min:1'],
        ]);

        $officer = Guard::query()->find((int) $data['guard_id']);

        if ($officer === null) {
            return back()->withErrors(['progress' => 'No such officer on this platform.']);
        }

        try {
            $progress->dispatch($alert, $officer, $request->user());
        } catch (AlertProgressRefused $refused) {
            return back()->withErrors(['progress' => $refused->getMessage()]);
        }

        return back()->with('success', $officer->full_name.' is dispatched and it is on the record. Their handset is pushed the alert if one is registered; confirm by radio that they have it.');
    }

    /**
     * "Mark on the way" — a radio call, recorded on the officer's behalf.
     *
     * Kept as recorded BY THIS DISPATCHER (`en_route_recorded_by`, and the audit
     * entry), never as though the officer's handset had said it: the two are
     * different evidence, and the timeline says which one it was.
     */
    public function enRoute(Request $request, DuressAlert $alert, AlertProgress $progress): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        try {
            $progress->enRoute($alert, $request->user());
        } catch (AlertProgressRefused $refused) {
            return back()->withErrors(['progress' => $refused->getMessage()]);
        }

        return back();
    }

    /** "Mark on scene" — the same, one step further. */
    public function onScene(Request $request, DuressAlert $alert, AlertProgress $progress): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        try {
            $progress->onScene($alert, $request->user());
        } catch (AlertProgressRefused $refused) {
            return back()->withErrors(['progress' => $refused->getMessage()]);
        }

        return back();
    }

    /**
     * Tell one officer to go â€” the console half of the handset's own thread.
     *
     * THE GUARD IS NAMED BY ID AND CHECKED AGAINST THIS ALERT'S ESTATE, not
     * taken on trust from the form. The ranked candidate list this screen draws
     * is the officers on duty here, but a posted id is a posted id: an officer
     * from another client would be a real person receiving an instruction about
     * an incident at an estate they are not standing, and the service refuses
     * that rather than the form hoping nobody tries.
     *
     * `back()`, because the message belongs on the screen the dispatcher is
     * already reading. The thread itself is the handset's; this console records
     * that the instruction was given, which after an incident is the question
     * that gets asked.
     */
    public function messageGuard(Request $request, DuressAlert $alert, DispatchMessaging $messaging): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $data = $request->validate([
            'guard_id' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:'.DispatchMessaging::MAX_BODY],
        ]);

        $guard = Guard::query()->find((int) $data['guard_id']);

        if ($guard === null) {
            return back()->withErrors(['body' => 'No such officer on this platform.']);
        }

        try {
            $messaging->toGuard($guard, (string) $data['body'], $request->user(), $alert);
        } catch (DomainException $refused) {
            return back()->withErrors(['body' => $refused->getMessage()]);
        }

        return back()->with('success', 'Sent to '.$guard->full_name.'. It is on their handset and on the record.');
    }

    /* ---------------------------------------------------------------- rows */

    /**
     * Alert rows for the queue.
     *
     * @return list<array<string, mixed>>
     */
    private function alertRows(User $viewer, string $tab): array
    {
        $query = $this->scoped(DuressAlert::query(), $viewer)
            ->with(['raisedByGuard.post', 'estate']);

        /*
         * PANICS BY `DuressAlert::isPanic()`, NOT BY KIND (bug hunt B086). A resident's press
         * labelled "Medical" or "Fire" is a panic — it was filed under Incidents, where a
         * dispatcher on the Panic alerts tab never saw it.
         */
        match ($tab) {
            'panic' => $query->panics()->whereNotIn('status', self::SETTLED),
            'incidents' => $query->incidents()->whereNotIn('status', self::SETTLED),
            'resolved' => $query->whereIn('status', self::SETTLED),
            default => $query->whereNotIn('status', self::SETTLED),
        };

        return $query->orderByDesc('server_time')->get()
            ->map(function (DuressAlert $alert): array {
                $fromGuard = $alert->guard_id !== null;
                [$statusLabel, $statusClass] = $this->statusPill($alert);
                $urgent = $alert->isPanic() && $alert->status === 'open';

                return [
                    'priority' => $alert->priority(),
                    'at' => $alert->server_time->format('Y-m-d H:i:s'),

                    /*
                     * Carried on the row so the screen's source badge can be
                     * computed from what is actually in the queue rather than
                     * from a second query against the whole table â€” the queue is
                     * filtered by tab, and those are different questions.
                     * Stripped below with the sort keys before the rows travel.
                     */
                    'is_simulated' => (bool) $alert->is_simulated,

                    'key' => 'alert-'.$alert->id,
                    'href' => '/dispatch/alerts/'.$alert->id,
                    'icon' => $this->alertIcon($alert),

                    // Both queue glyphs bake their own pair of stroke weights.
                    // The prop is sent anyway so the template never has to know
                    // which icons honour it.
                    'icon_stroke' => 1.7,
                    'urgent' => $urgent,
                    // Who, then what: "Jane Brown: panic button". A colon, never an em dash (M53 Law 3).
                    'title' => $alert->drillPrefix().$this->raisedBy($alert).': '.lcfirst($alert->kindLabel()),
                    'tag' => $fromGuard ? 'Guard-reported' : 'Resident',

                    // The red variant is the board's, and it is reserved for a
                    // resident: a person who is not trained, not on duty and
                    // not carrying a radio.
                    'tag_class' => $fromGuard ? 'source-tag' : 'source-tag resident',
                    'meta' => $this->join([
                        $alert->estate->name ?? 'Unknown estate',
                        $alert->unit_reference ?? $alert->raisedByGuard?->post?->label(),

                        // What it reached — moves live on `alert.reach` (M10 §1.2).
                        $this->reachLabel($alert),

                        /*
                         * Offline capture, clock disagreement and simulated
                         * origin are stated on the row, never quietly
                         * reconciled. Each one changes how much a dispatcher
                         * should trust the timestamp beside it.
                         */
                        $alert->captured_offline ? 'Captured offline' : null,
                        $alert->clock_skewed ? 'Device clock disagrees' : null,
                        $alert->is_simulated ? 'Simulated' : null,
                        // Drill mode (M53 §3A.1) is a person's press that told nobody; panic:walk's drill told everyone.
                        $alert->wakesNobody() ? 'Drill mode: nobody was alerted' : ($alert->is_drill ? 'Drill: a practice run, everyone was alerted' : null),

                        $alert->server_time->diffForHumans(),
                    ]),
                    'status' => $statusLabel,
                    'status_class' => $statusClass,
                ];
            })
            ->all();
    }

    /**
     * Workforce compliance rows â€” a guard on the roster with a lapsed licence.
     *
     * Real central data, and a genuine dispatch concern: an unlicensed guard
     * standing a post is an operational exposure, not a filing problem. It
     * belongs to neither the panic nor the incident tab, which is exactly
     * where the board puts it â€” under "All alerts", and under "Resolved" once
     * the guard has been taken off duty.
     *
     * @return list<array<string, mixed>>
     */
    private function complianceRows(User $viewer, string $tab): array
    {
        if (! in_array($tab, ['all', 'resolved'], true)) {
            return [];
        }

        // Lapsed on the officer's own site calendar, as licenceState() says (bug hunt B008) — not
        // before the server's UTC date, which from 19:00 in Kingston flagged a licence still good that day.
        $query = Guard::query()
            ->with(['estate', 'post'])
            ->whereLicenceDaysLeft('<', 0);

        // Scope and the demonstration estate in one call, as everywhere on this
        // screen. The reviewer's guard account carries an invented PSRA number
        // with an invented expiry, and chasing it is somebody's wasted morning.
        OperatorEstates::narrow($query, $viewer);

        if ($tab === 'resolved') {
            $query->whereIn('status', ['suspended', 'inactive']);
        }

        return $query->orderBy('psra_expires_on')->get()
            ->map(function (Guard $guard): array {
                $settled = in_array($guard->status, ['suspended', 'inactive'], true);
                $expiry = $guard->psra_expires_on;

                return [
                    'priority' => self::COMPLIANCE_PRIORITY,
                    'at' => $expiry?->format('Y-m-d H:i:s') ?? '',

                    'key' => 'guard-'.$guard->id,
                    'href' => '/guards/'.$guard->id,
                    'icon' => 'shield',

                    // The board draws the shield a notch lighter than the two
                    // alert glyphs beside it.
                    'icon_stroke' => 1.6,
                    'urgent' => false,
                    'title' => $guard->full_name.': PSRA compliance',
                    'tag' => 'Guard workforce',
                    'tag_class' => 'source-tag',
                    'meta' => $this->join([
                        $guard->estate->name ?? 'Unassigned',
                        $guard->post?->label(),
                        'Licence expired '.($expiry?->format('M j') ?? 'date unknown'),
                        $guard->updated_at === null ? null : SiteTime::local($guard->updated_at, $guard->tenant_id === null ? null : (string) $guard->tenant_id)->format('M j'),
                    ]),
                    'status' => $settled
                        ? 'Resolved: '.lcfirst($guard->statusLabel())
                        : 'Unacknowledged',
                    'status_class' => $settled ? 'resolved' : 'unack',
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------- detail */

    /**
     * The response timeline â€” every state this alert has actually reached,
     * plus the ones it has not, drawn as pending.
     *
     * Nothing here is narrated that the row does not record. The board draws a
     * device-side detail ("panic button held 1.5s") that no column holds, so
     * this says what the row can prove instead of inventing telemetry.
     *
     * @param  list<array{guard_id: int, name: string, post: string}>  $candidates
     * @return list<array{title: string, meta: string, pending: bool}>
     */
    private function timeline(DuressAlert $alert, array $candidates): array
    {
        $acknowledgedBy = $alert->acknowledged_by === null
            ? null
            : User::query()->whereKey($alert->acknowledged_by)->value('name');

        /*
         * EVERY TIME ON THIS TIMELINE IS THE ESTATE'S OWN (M4 Â§3.1). The four moments are stored
         * as true UTC instants, so their raw digits name the wrong hour â€” and a panic timeline
         * that reads five hours out is the worst place on the platform for it. `DuressAlert`
         * keeps a plain `datetime` cast because the API sends these as UTC instants; the zone
         * is applied here, where a dispatcher reads them.
         */
        $onSiteClock = static fn (?Carbon $at): ?string => $at === null
            ? null
            : SiteTime::stamp($at, (string) $alert->tenant_id, 'g:i:s A');

        return [
            [
                'title' => 'Alert fired',
                'meta' => $this->join([
                    $alert->guard_id !== null
                        ? 'Raised from a guard handset'
                        : 'Raised from a resident device',

                    // Said plainly on the surface that matters most. A
                    // dispatcher must never mistake the simulator for a person.
                    $alert->is_simulated ? 'simulated device' : null,
                    $alert->wakesNobody()
                        ? 'a DRILL: the resident\'s account was in drill mode, so no officer was rung and nobody was mailed or texted'
                        : ($alert->is_drill ? 'a DRILL raised to test who a panic reaches, not by a person' : null),

                    $onSiteClock($alert->device_time) ?? 'Device clock not supplied',
                ]),
                'pending' => false,
            ],
            [
                'title' => 'Received by dispatch',
                'meta' => $this->join([
                    $alert->captured_offline
                        ? 'Captured offline and synced on reconnect'
                        : 'Routed to the gatehouse and this console',
                    $alert->clock_skewed
                        ? 'device clock disagrees with the server and is recorded as sent'
                        : null,
                    $onSiteClock($alert->server_time),
                ]),
                'pending' => false,
            ],

            /*
             * THE RING, STEP BY STEP (the guard ring §9.3): each round that went, the answers
             * the officers gave on it, and the moment every phone was told to stop — or the
             * next round still due. Nothing here says a phone "rang".
             */
            ...app(RingReport::class)->timeline($alert),

            $alert->acknowledged_at !== null
                ? [
                    'title' => 'Acknowledged'.($acknowledgedBy === null ? '' : ' by '.$acknowledgedBy),
                    'meta' => (string) $onSiteClock($alert->acknowledged_at),
                    'pending' => false,
                ]
                : [
                    /*
                     * THE CONTROL ROOM'S STEP, AND NOBODY ELSE'S. This read "Awaiting
                     * guard acknowledgement · <guard> has not yet confirmed response",
                     * a step no action anywhere could complete: an acknowledgement is
                     * recorded by a console user, and what a guard confirms is that
                     * they are on the way — which is now a step of its own, below.
                     */
                    'title' => 'Awaiting acknowledgement',
                    'meta' => in_array($alert->status, self::SETTLED, true)
                        ? 'Nobody in the control room took this alert before it closed'
                        : 'Nobody in the control room has taken this alert yet',
                    'pending' => true,
                ],
            ...$this->responseSteps($alert, $candidates, $onSiteClock),
            $alert->resolved_at !== null
                ? [
                    'title' => $alert->status === 'false_alarm' ? 'Resolved — false alarm' : 'Resolved',
                    'meta' => $this->join([
                        AlertClassification::label($alert->resolution_classification),
                        $alert->resolution_note,
                        $onSiteClock($alert->resolved_at),
                    ]),
                    'pending' => false,
                ]
                : [
                    'title' => 'Awaiting resolution',
                    'meta' => 'The alert stays open until dispatch resolves it',
                    'pending' => true,
                ],
        ];
    }

    /**
     * DISPATCHED, EN ROUTE, ON SCENE — the three steps between the control room
     * taking an alert and closing it (M10 §1.3), each drawn from its own column.
     *
     * EACH SAYS WHO MADE IT. A dispatch names the dispatcher who sent the
     * officer. En route and on scene name either the officer's own handset or
     * the dispatcher who recorded it after a radio call — different evidence,
     * and after an incident "who said he was there" is the question.
     *
     * A STEP NOT RECORDED IS DRAWN AS NOT RECORDED. An officer who ran straight
     * to the lot is on scene with no "on the way", and the timeline says that
     * rather than inventing the missing moment.
     *
     * @param  list<array{guard_id: int, name: string, post: string}>  $candidates
     * @param  callable(?Carbon): ?string  $onSiteClock
     * @return list<array{title: string, meta: string, pending: bool}>
     */
    private function responseSteps(DuressAlert $alert, array $candidates, callable $onSiteClock): array
    {
        $officer = $alert->respondingGuard?->full_name;
        $settled = in_array($alert->status, self::SETTLED, true);

        $ids = array_values(array_filter([
            $alert->getAttribute('dispatched_by'),
            $alert->getAttribute('en_route_recorded_by'),
            $alert->getAttribute('on_scene_recorded_by'),
        ], static fn ($id): bool => $id !== null));

        /** @var array<int, string> $names */
        $names = $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        $nameOf = static fn ($id): string => $names[(int) $id] ?? 'a console user no longer on the platform';

        $dispatched = $alert->dispatched_at !== null
            ? [
                'title' => 'Dispatched '.($officer ?? 'an officer'),
                'meta' => $this->join(['Sent by '.$nameOf($alert->getAttribute('dispatched_by')), $onSiteClock($alert->dispatched_at)]),
                'pending' => false,
            ]
            : [
                'title' => 'No officer dispatched',
                'meta' => match (true) {
                    $officer !== null => $officer.' took this alert from their own handset without being sent',
                    $settled => 'Nobody was sent before this alert closed',
                    $candidates === [] => 'This alert reached no officer on shift here, so there is nobody to send',
                    default => 'Send one of the officers it reached, under Actions',
                },
                'pending' => true,
            ];

        $step = function (string $at, string $by, string $done, ?string $later) use ($alert, $officer, $settled, $nameOf, $onSiteClock): array {
            if ($alert->getAttribute($at) !== null) {
                $recorder = $alert->getAttribute($by);

                return [
                    'title' => ($officer ?? 'The officer').' '.$done,
                    'meta' => $this->join([
                        $recorder === null
                            ? 'From '.($officer === null ? 'the officer' : $officer."'s").' own handset'
                            : 'Recorded by '.$nameOf($recorder).' after a radio call',
                        $onSiteClock($alert->getAttribute($at)),
                    ]),
                    'pending' => false,
                ];
            }

            return [
                'title' => 'Not yet '.$done,
                'meta' => match (true) {
                    $later !== null && $alert->getAttribute($later) !== null => 'Not recorded: '.($officer ?? 'the officer').' was marked on scene without it',
                    $settled => 'Not recorded before this alert closed',
                    $officer === null => 'Nobody has been sent yet',
                    default => 'No word yet that '.$officer.' is '.$done,
                },
                'pending' => true,
            ];
        };

        return [
            $dispatched,
            $step('en_route_at', 'en_route_recorded_by', 'on the way', 'on_scene_at'),
            // The Emergency list's words for the two states (Hassam, 11 Oct): On the way, On scene.
            $step('on_scene_at', 'on_scene_recorded_by', 'on scene', null),
        ];
    }

    /**
     * What the action buttons may do, and why not when they may not.
     *
     * One of the board's has no state to change: there is no escalation record
     * at all. The screen renders it disabled with the reason attached rather
     * than accepting a click and doing nothing with it. "Dispatch another guard"
     * used to be the other — it only re-addressed the message box — and is now
     * a real dispatch, beside the two steps a radio call records (M10 §1.3).
     *
     * @param  list<array{guard_id: int, name: string, post: string}>  $candidates
     * @return array<string, mixed>
     */
    private function actions(DuressAlert $alert, User $viewer, array $candidates): array
    {
        $mayAct = $viewer->can('gemini.dispatch.update');
        $settled = in_array($alert->status, self::SETTLED, true);
        $acknowledged = $alert->acknowledged_at !== null;

        $officer = $alert->respondingGuard?->full_name;
        $first = $officer === null ? null : Str::before($officer, ' ');
        $clock = static fn (?Carbon $at): string => $at === null ? '' : SiteTime::stamp($at, (string) $alert->tenant_id, 'g:i A');
        $responding = $alert->en_route_at !== null || $alert->on_scene_at !== null;

        return [
            /*
             * WHAT THE SCREEN NEEDS TO PUT EACH ACTION AT ITS LEVEL (M96, M98): whether this person may ever act, whether
             * the alert is closed, and which steps are already recorded. A recorded step is a badge with its time, never
             * a dead button; a closed alert, or a role that may only watch, shows the result instead of the buttons.
             */
            'may_act' => $mayAct,
            'settled' => $settled,
            'acknowledged' => $acknowledged,
            'dispatched' => $alert->dispatched_at !== null,
            'responding' => $responding,
            'en_route' => $alert->en_route_at !== null,
            'on_scene' => $alert->on_scene_at !== null,
            // Each recorded step's badge is its state and when (M96 §3, the Emergency list): "On scene · 10:50 PM".
            'officer' => $officer,
            'acknowledged_time' => $clock($alert->acknowledged_at),
            'dispatched_time' => $clock($alert->dispatched_at),
            'en_route_time' => $clock($alert->en_route_at),
            'on_scene_time' => $clock($alert->on_scene_at),

            /*
             * SEND AN OFFICER (M10 §1.3). The pick list is the alert's own reach —
             * the officers the panic was pushed to — and one alert takes one
             * dispatched officer: once somebody is sent, or has said from their
             * own handset that they are coming, the button says who and stops.
             * More help is a message to another officer, which the box beside
             * this panel still sends.
             */
            'dispatch_url' => '/dispatch/alerts/'.$alert->id.'/dispatch',
            'dispatch_candidates' => $candidates,
            'can_dispatch' => $mayAct && ! $settled && $alert->dispatched_at === null && ! $responding && $candidates !== [],
            'dispatch_label' => match (true) {
                $alert->dispatched_at !== null => 'Dispatched '.($officer ?? 'an officer'),
                $responding => ($officer ?? 'An officer').' is responding',
                default => 'Dispatch an officer',
            },
            'dispatch_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not send an officer to an incident',
                $settled => 'This alert is closed, so nobody can be sent to it now',
                $alert->dispatched_at !== null => ($officer ?? 'An officer').' was dispatched at '.$clock($alert->dispatched_at),
                $responding => ($officer ?? 'An officer').' took this alert from their own handset, so there is nobody left to dispatch',
                $candidates === [] => 'This alert reached no officer on shift at this estate, so there is nobody to send. Reach whoever is posted here by radio.',
                default => null,
            },

            /*
             * A RADIO CALL, RECORDED ON THE OFFICER'S BEHALF. Only once somebody
             * has been sent — "on the way" is said of a named officer — and only
             * forward: each button says what is already recorded and stops.
             */
            'en_route_url' => '/dispatch/alerts/'.$alert->id.'/en-route',
            // The button is the verb of the Emergency list's state (Hassam, 11 Oct): Mark on the way, never "en route".
            'en_route_label' => $first === null ? 'Mark on the way' : 'Mark '.$first.' on the way',
            'can_en_route' => $mayAct && ! $settled && $officer !== null && ! $responding,
            'en_route_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is closed, so there is nothing left to record',
                $officer === null => 'Dispatch an officer first: on the way is recorded for the officer who was sent',
                $alert->en_route_at !== null => 'Recorded as on the way at '.$clock($alert->en_route_at),
                $alert->on_scene_at !== null => 'Already on scene, and the response only moves forward',
                default => 'Record a radio call: it is kept as recorded by you, on '.$officer."'s behalf",
            },

            'on_scene_url' => '/dispatch/alerts/'.$alert->id.'/on-scene',
            'on_scene_label' => $first === null ? 'Mark on scene' : 'Mark '.$first.' on scene',
            'can_on_scene' => $mayAct && ! $settled && $officer !== null && $alert->on_scene_at === null,
            'on_scene_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is closed, so there is nothing left to record',
                $officer === null => 'Dispatch an officer first: on scene is recorded for the officer who was sent',
                $alert->on_scene_at !== null => 'Recorded as on scene at '.$clock($alert->on_scene_at),
                default => 'Record a radio call: it is kept as recorded by you, on '.$officer."'s behalf",
            },

            /*
             * THE MESSAGE CHANNEL, WHICH IS NO LONGER A CAPTION.
             *
             * The box beside this panel was disabled and read "Messaging opens
             * when the Guard App ships". Both halves had stopped being true: the
             * app shipped, and `MessagesController::index()` has served
             * `direction = broadcast OR guard_id = me` since it was written, so
             * an outbound row reaches the officer's handset and counts unread.
             * Only the write was missing.
             *
             * Gated on the same `update` as acknowledging, because sending an
             * officer to an incident is an act on the alert and not a reading
             * of it â€” and closed alerts take no new instructions, for the same
             * reason they take no new acknowledgement.
             */
            'message_url' => '/dispatch/alerts/'.$alert->id.'/message',
            // Replying to the resident who raised it (M50 §4.2 #2): the "Messages with the resident" box.
            'reply_url' => '/dispatch/alerts/'.$alert->id.'/reply',
            'can_message' => $mayAct && ! $settled,
            'message_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not send an officer to an incident',
                $settled => 'This alert is closed, so there is nothing left to instruct anyone to do',
                default => null,
            },

            'acknowledge_url' => '/dispatch/alerts/'.$alert->id.'/acknowledge',
            /*
             * THE CONTROL ROOM'S OWN ACT, and the label no longer says otherwise.
             * It read "Acknowledge on Marcus's behalf", naming the guard on post, as
             * though acknowledging were an officer confirming they would respond —
             * which is what the resident was then pushed ("someone is responding").
             * An officer responding is now recorded as what it is: dispatched, on
             * the way, at the scene (M10 §1.3).
             */
            'acknowledge_label' => 'Acknowledge alert',
            'can_acknowledge' => $mayAct && ! $acknowledged && ! $settled,
            'acknowledge_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is already closed',
                // The estate's clock, like the timeline above (M4 Â§3.1).
                $acknowledged => 'Already acknowledged at '.SiteTime::stamp($alert->acknowledged_at, (string) $alert->tenant_id, 'g:i A'),
                default => null,
            },

            'resolve_url' => '/dispatch/alerts/'.$alert->id.'/resolve',
            // The resolve dialog's list, and the note's limit, from the one place the server checks them.
            'resolve_classifications' => AlertClassification::forForm(),
            'resolve_note_max' => AlertClassification::NOTE_MAX,
            'can_resolve' => $mayAct && ! $settled,
            'resolve_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is already closed',
                default => null,
            },
        ];
    }

    /**
     * The officers clocked on at this estate, in name order.
     *
     * A LIST, NOT A RANKING. The client has ruled that a panic reaches everyone
     * who can help rather than the closest officer (M10 Â§0), so nothing here
     * measures a distance or nominates a nearest. The order is alphabetical â€”
     * an order rather than a claim â€” and the screen presents it as "on duty
     * now" rather than as a recommendation.
     *
     * The same four conditions the live map uses, so the two screens cannot
     * disagree about who is on duty: the shift covers now, it was actually
     * started, it has not ended, and the officer is active.
     *
     * @return list<array{guard_id: int, name: string, post: string, shift_id: int}>
     */
    private function onDutyOfficers(DuressAlert $alert): array
    {
        return Shift::query()
            ->with(['officer', 'post'])
            ->where('tenant_id', $alert->tenant_id)
            ->covering(Carbon::now())
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereHas('officer', fn (Builder $q) => $q->where('status', 'active'))
            ->get()
            ->filter(fn (Shift $shift): bool => $shift->officer !== null)
            ->sortBy(fn (Shift $shift): string => (string) $shift->officer->full_name)
            ->map(fn (Shift $shift): array => [
                'guard_id' => (int) $shift->officer->id,
                'name' => (string) $shift->officer->full_name,
                'post' => $shift->post?->label() ?? 'No post',
                'shift_id' => (int) $shift->id,
            ])
            ->values()
            ->all();
    }

    /**
     * The guard this alert is being asked of.
     *
     * NOT computed by distance â€” no guard position is modelled anywhere in
     * this system, so nothing here could honestly answer "nearest". This is
     * the guard standing a post at the estate, which is the guard a dispatcher
     * would actually raise. It is NOT the officer responding: that is
     * `respondingGuard`, set only when somebody is dispatched or takes the
     * alert from their own handset (M10 §1.3).
     */
    private function responder(DuressAlert $alert): ?Guard
    {
        if ($alert->raisedByGuard !== null) {
            return $alert->raisedByGuard;
        }

        return Guard::query()
            ->with('post')
            ->where('tenant_id', $alert->tenant_id)
            ->where('status', 'active')
            ->whereNotNull('post_id')
            ->orderBy('full_name')
            ->first();
    }

    /** The board's phrasing for the screen heading and the hero. */
    private function headline(DuressAlert $alert): string
    {
        return match ($alert->kind) {
            'panic' => 'Panic alert',
            'duress' => 'Guard duress',
            'medical' => 'Medical alert',
            'fire' => 'Fire alert',
            'intrusion' => 'Intrusion alert',
            default => $alert->kindLabel(),
        };
    }

    /**
     * How well this alert's position is known.
     *
     * A refused location permission degrades accuracy and says so here; it
     * never removes panic (invariant 12), which is why "Not supplied" is a
     * displayed answer rather than an error.
     */
    private function locationSource(DuressAlert $alert): string
    {
        $gps = $alert->latitude !== null && $alert->longitude !== null;
        $unit = $alert->unit_reference !== null;

        return match (true) {
            $gps && $unit => 'GPS + unit fix',
            $gps => 'GPS fix',
            $unit => 'Unit reference',
            default => 'Not supplied',
        };
    }

    private function raisedBy(DuressAlert $alert): string
    {
        return $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown';
    }

    /**
     * Which of the board's two alert glyphs this kind is drawn with.
     *
     * The triangle is the board's mark for someone in danger; the ring is its
     * mark for an incident. Every panic (`DuressAlert::isPanic()` — a labelled
     * press included) and every fire take the triangle.
     */
    private function alertIcon(DuressAlert $alert): string
    {
        return $alert->isPanic() || $alert->kind === 'fire' ? 'panic' : 'alert';
    }

    /**
     * The status pill: label, and the board class that colours it.
     *
     * @return array{0: string, 1: string}
     */
    private function statusPill(DuressAlert $alert): array
    {
        return match ($alert->status) {
            'open' => ['Unacknowledged', 'unack'],
            'acknowledged' => ['Acknowledged', 'responding'],
            'responding' => ['Responding', 'responding'],
            // The Emergency list (Hassam, 11 Oct): a false alarm is its own state, never "Resolved: false alarm".
            'false_alarm' => ['False alarm', 'resolved'],
            default => ['Resolved', 'resolved'],
        };
    }

    /* ---------------------------------------------------------------- nav */

    /**
     * The dispatch section tabs.
     *
     * Every section has a screen, so every tab is a link. The pages used to
     * carry an inert branch for a section with no screen â€” the live map, once â€”
     * and it is gone with the last such section: a branch no data can reach is
     * a reason kept in reserve for a screen that exists.
     *
     * LIVE CHAT IS THE ONE TAB THE BOARDS DO NOT DRAW (M50 §4.3): the Resident App's Help & support draws "Live chat",
     * and somebody has to answer it — the control room, who are already here around the clock. Public so its own
     * controller (Gemini\LiveChatController) draws the same strip.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    public static function sectionTabs(string $current): array
    {
        $sections = [
            ['key' => 'map', 'label' => 'Live map', 'href' => '/dispatch/map'],
            ['key' => 'coverage', 'label' => 'Coverage board', 'href' => '/dispatch/coverage'],
            ['key' => 'alerts', 'label' => 'Alerts', 'href' => '/dispatch/alerts'],
            ['key' => 'patrol', 'label' => 'Patrol monitoring', 'href' => '/dispatch/alertness'],
            ['key' => 'requests', 'label' => 'Requests', 'href' => '/dispatch/requests'],
            ['key' => 'chat', 'label' => 'Live chat', 'href' => '/dispatch/chat'],
        ];

        return array_map(
            static fn (array $section): array => [
                'label' => $section['label'],
                'href' => $section['href'],
                'active' => $section['key'] === $current,
            ],
            $sections,
        );
    }

    /**
     * The estates this viewer may watch.
     *
     * Sent to the live screens so each can open the private alert channel for
     * every estate it is showing â€” and for no estate it is not.
     *
     * THE DEMONSTRATION ESTATE IS NOT ONE OF THEM (M5 Â§3). This method used to
     * return every tenant on the platform, and alerts broadcast on
     * `estate.{id}.alerts` with no global channel â€” so the estate an App Store
     * reviewer signs in to was subscribed by every open console, and a reviewer
     * pressing the panic button in the Guard App pushed a duress alert into a
     * live control room, unflagged, for a dispatcher to respond to. A fake
     * panic costs a real person's attention and can cost a real response.
     *
     * Excluding it HERE closes the socket as well as the queue: a channel
     * nobody subscribes to carries nothing, so the alert is still recorded, the
     * reviewer's app still behaves exactly as a resident's would, and nothing
     * reaches Gemini.
     *
     * @return list<string>
     */
    private function visibleEstateIds(User $viewer): array
    {
        $query = Tenant::query();

        OperatorEstates::narrow($query, $viewer, 'id');

        /** @var list<string> $ids */
        $ids = $query->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * Whether any alert this viewer can see was produced by the simulator.
     *
     * SCOPED THE SAME WAY EVERY OTHER READ ON THIS CONTROLLER IS. A site-scoped
     * dispatcher's badge answers about their own estates: telling them their
     * data is simulated because some other client's is would be a caveat
     * attached to the wrong figures.
     *
     * Bounded to a fortnight, because the badge annotates what is on the screen
     * and none of these screens looks further back than that. A simulated alert
     * from six months ago says nothing about tonight.
     */
    private function anySimulatedAlert(User $viewer): bool
    {
        $query = DuressAlert::query()
            ->where('is_simulated', true)
            ->where('server_time', '>=', now()->subDays(14))
            ->whereIn('tenant_id', $viewer->accessibleEstateIds());

        // Never let the demonstration estate caveat a real dispatcher's figures:
        // the badge annotates the rows on the screen, and its rows are not there.
        OperatorEstates::exclude($query);

        return $query->exists();
    }

    /**
     * The queue's filter tabs. Each is a real link and the filter is applied
     * in SQL, so the tab survives a reload, a bookmark and the screen's poll.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function filterTabs(string $current): array
    {
        $tabs = [];

        foreach (self::TABS as $key => $label) {
            $tabs[] = [
                'label' => $label,
                'href' => $key === 'all' ? '/dispatch/alerts' : '/dispatch/alerts?tab='.$key,
                'active' => $key === $current,
            ];
        }

        return $tabs;
    }

    /**
     * How many officers' phones this alert reached, for the queue row (M10 §1.2).
     *
     * The alert is broadcast BEFORE the fan-out runs, so a row drawn the moment it
     * arrives has no count yet — "Alerting officers…" — and `alert.reach` makes the
     * queue re-read the server the moment it has one. A count that never came is not
     * left saying "alerting" for ever: after a minute it says it was not recorded.
     * Nothing for a simulated alert, which the row already calls simulated and which
     * rings no phone.
     */
    private function reachLabel(DuressAlert $alert): ?string
    {
        if ($alert->is_simulated) {
            return null;
        }

        $handsets = $alert->getAttribute('alerted_handsets');

        if ($handsets === null) {
            return $alert->server_time->greaterThan(now()->subMinute())
                ? 'Alerting officers…'
                : 'Officers\' phones reached: not recorded';
        }

        if ((int) $handsets === 0) {
            // The raise reached no phone, and the ring then rang every officer of the estate at once (M18 §1.6).
            $rang = RingReach::rang($alert);

            return $rang !== null && $rang > 0
                ? 'No phone on duty at the raise · every officer rung: '.$rang.' phone'.($rang === 1 ? '' : 's')
                : 'No officer\'s phone reached';
        }

        return 'Officers\' phones alerted: '.(int) $handsets
            .($alert->getAttribute('alerted_via') === 'rostered' ? ' (rostered today, none clocked on)' : '');
    }

    /* -------------------------------------------------------------- utils */

    /**
     * The board's meta separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * Narrows to the estates this viewer may see.
     *
     * Head of Security watches assigned sites only. A dispatcher watches all â€”
     * all the estates GEMINI RUNS, which since M5 Â§3 is not the same thing as
     * all the tenants in the table. `OperatorEstates::narrow()` applies the
     * viewer's scope and drops the demonstration estate in one call, and it is
     * one call on purpose: the half of the rule a new reader remembers to copy
     * should be the half that carries the other.
     *
     * @param  Builder<DuressAlert>  $query
     * @return Builder<DuressAlert>
     */
    private function scoped(Builder $query, User $viewer): Builder
    {
        OperatorEstates::narrow($query, $viewer);

        return $query;
    }
}
