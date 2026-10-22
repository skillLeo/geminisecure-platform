<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Live\Events\ApprovalLive;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Audit\AuditLogger;
use App\Services\Dispatch\GateLog;
use App\Services\Estate\Residents;
use App\Services\Passes\OfflinePassVerifier;
use App\Services\Passes\VisitorPasses;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushAudience;
use App\Services\ResidentApp\HouseholdEntries;
use App\Services\ResidentApp\RevokeResidentAccess;
use App\Services\Restriction\RestrictionPolicy;
use App\Support\SiteTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The gate: verify, entry, exit, override, search, activity, walk-up approvals (13 D2).
 *
 * Boards guard-app-02 (waiting on resident), -07 (verify, override), -08 (walk-up).
 *
 * INVARIANT 2 IS THIS FILE'S WHOLE BOUNDARY. Everything a guard learns about a
 * household here is its name, its unit, and `access_restricted` — a boolean.
 * No response is assembled from a charge, an invoice or a payment, and the
 * guard contract test holds every response below to its catalogue allowlist.
 *
 * VERIFY DOES NOT ADMIT. A verdict is shown; the admission is a separate act
 * (`/gate/entry`), because a guard shown "valid" can still turn somebody away,
 * and a single-use pass is consumed only when the person actually comes in.
 *
 * OFFLINE, THE HANDSET DECIDES AND THE SERVER RECONCILES. A pass verified on the
 * handset with no signal is admitted there. When the entry syncs, the server
 * records it — the admission happened — and says in `reconciliation` whether the
 * pass had been cancelled or used meanwhile, so the app can tell the guard and
 * dispatch sees it. An ONLINE entry on a pass that is not admissible is refused:
 * the guard had the facts, and letting somebody in against them is an override.
 */
class GateController extends Controller
{
    /** How long a walk-up visitor waits for the household before the estate's policy applies. */
    public const APPROVAL_WAIT_SECONDS = 180;

    /**
     * How many units a search returns at most. The officer has a visitor in front of them and
     * reads the first few; `total` tells them how many the estate actually has, so twenty is a
     * page rather than a claim.
     */
    public const SEARCH_LIMIT = 20;

    /** A pass's category, in the restriction policy's words. */
    private const RESTRICTION_CATEGORY = [
        'single' => 'guest',
        'recurring' => 'visitor',
        'contractor' => 'contractor',
        'delivery' => 'delivery',
        VisitorPasses::RESIDENT => 'resident',
    ];

    /** A walk-up visitor, in the restriction policy's words: a guest, as a single-visit pass would have made them. */
    private const WALK_UP_CATEGORY = 'guest';

    public function __construct(
        private readonly VisitorPasses $passes,
        private readonly RestrictionPolicy $policy,
        private readonly GateLog $log,
        private readonly AuditLogger $audit,
    ) {}

    public function verify(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate(['pass' => ['required', 'string', 'max:2048']]);

        $result = $this->passes->verifyOnline($context->tenantId, $data['pass'], $context->serverTime);
        $pass = $result['pass'];
        $household = $pass === null ? null : $this->householdOf($pass);
        $restricted = null;
        $verdict = $result['verdict'];

        if ($verdict === 'valid' && $pass !== null) {
            $restricted = false;

            if ($household !== null) {
                $decision = $this->policy->decide($household, self::RESTRICTION_CATEGORY[$pass->category] ?? 'guest');
                $restricted = ! $decision['admitted'];
            }

            if ($restricted) {
                $verdict = 'restricted';
            }
        }

        [$tone, $headline, $detail] = match ($verdict) {
            'valid' => ['green', 'Admit', $pass?->single_use ? 'Single-visit pass. Recording the entry uses it.' : 'Recurring pass, valid until '.($pass === null ? '' : SiteTime::local($pass->valid_to, $context->tenantId)->format('M j, g:i A')).'.'], // words a guard reads: the SITE'S clock (M4 §3.1)
            'restricted' => ['amber', 'Access restricted', 'Contact management.'],
            // Q-M44: the office ended this person's resident access. Red like any refusal,
            // but its own headline, because "do not admit on this pass" would leave the
            // officer with somebody at the barrier and no idea what to do with them.
            'revoked_resident' => ['red', RevokeResidentAccess::GATE_HEADLINE, $result['reason']],
            default => ['red', 'Do not admit on this pass', $result['reason']],
        };

        return response()->json([
            'verdict' => $verdict,
            'tone' => $tone,
            'headline' => $headline,
            'detail' => $detail,
            'admit_allowed' => $verdict === 'valid',
            'pass' => $pass === null ? null : $this->passShape($pass),
            'unit' => $pass?->unit?->reference,
            'household' => $household?->name,
            'access_restricted' => $restricted,
            'server_time' => $context->serverTime->toIso8601String(),
        ]);
    }

    public function entry(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'pass_id' => ['nullable', 'string', 'max:64'],
            'approval_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:120'],
            'verified_offline' => ['nullable', 'boolean'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $offline = (bool) ($data['verified_offline'] ?? false);
        $postId = $this->postFor($guard, $data['post_id'] ?? null, $context);
        $subject = trim((string) ($data['subject'] ?? ''));
        $basis = GateLog::GUARD_DECISION;
        $reconciliation = null;
        $restricted = null;
        $pass = null;
        $approval = null;

        if (($data['pass_id'] ?? null) !== null) {
            $pass = VisitorPass::query()->with('unit')->where('pass_id', $data['pass_id'])->first();
            $basis = $offline ? 'QR pass · verified offline' : 'QR pass';

            if ($pass === null) {
                if (! $offline) {
                    throw ApiError::notFound('not_found', 'No such pass at this estate. Verify it first.');
                }

                $reconciliation = 'pass_unknown';
            } else {
                $household = $this->householdOf($pass);
                $restricted = $household === null ? false : ! $this->policy->decide($household, self::RESTRICTION_CATEGORY[$pass->category] ?? 'guest')['admitted'];

                /*
                 * THE WINDOW, BOTH ENDS, ON THE RIGHT CLOCK (bug hunt B061). `valid_from` was
                 * never asked, so a single-use pass for tomorrow entered online today was
                 * `consumed` — used up, logged as an ordinary admission, audited nowhere — and
                 * tomorrow its visitor was turned away as "already used", although
                 * `gate.verify` had said `not_yet_valid` about it. And expiry read the
                 * HANDSET's clock even online, so an old X-Device-Time carried an expired pass
                 * through.
                 *
                 *   online    the server's clock: the guard is asking now.
                 *   offline   the handset's clock — the admission happened then — but never
                 *             LATER than the moment it reached the server, so a handset
                 *             running fast cannot move an early admission into the window.
                 *
                 * With verify's own two minutes each side (`CLOCK_TOLERANCE_SECONDS`), so the
                 * verdict the guard was shown and the entry that follows it agree at the edges.
                 */
                $at = $offline && $context->deviceTime !== null && $context->deviceTime->lessThan($context->serverTime)
                    ? $context->deviceTime
                    : $context->serverTime;
                $slack = OfflinePassVerifier::CLOCK_TOLERANCE_SECONDS;

                $reconciliation = match (true) {
                    $pass->status === VisitorPass::CANCELLED => 'pass_cancelled',
                    $pass->status === VisitorPass::USED && $pass->single_use => 'pass_already_used',
                    $pass->valid_from->getTimestamp() > $at->getTimestamp() + $slack => 'pass_not_yet_valid',
                    $pass->valid_to->getTimestamp() < $at->getTimestamp() - $slack => 'pass_expired',
                    $restricted => 'household_restricted',
                    default => $pass->single_use ? 'consumed' : 'valid',
                };

                if (! $offline && ! in_array($reconciliation, ['consumed', 'valid'], true)) {
                    throw ApiError::conflict('pass_not_admissible', 'This pass cannot admit anybody now ('.str_replace('_', ' ', $reconciliation).'). Admitting them is an override, with a reason.');
                }

                /*
                 * AN EARLY ADMISSION DOES NOT USE THE PASS UP. It is recorded, and audited
                 * below as one the server would have refused, but the visit that happens
                 * inside the window is the one the resident issued the pass for.
                 */
                if ($reconciliation !== 'pass_not_yet_valid') {
                    $this->passes->consume($pass, $context->serverTime);
                }
                $subject = $subject !== '' ? $subject : $pass->visitor_name.' · '.$pass->unit->reference;
            }
        } elseif (($data['approval_id'] ?? null) !== null) {
            $approval = DB::connection('tenant')->table('gate_approval_requests')->where('id', $data['approval_id'])->first();

            if ($approval === null) {
                throw ApiError::notFound('not_found', 'No such walk-up request.');
            }

            if ($approval->status !== 'approved') {
                throw ApiError::conflict('approval_not_granted', 'The household has not approved this visitor. Apply the estate\'s policy, or override with a reason.');
            }

            $unit = Unit::query()->with('household')->find($approval->unit_id);

            /*
             * THE ARREARS RESTRICTION APPLIES TO A WALK-UP AS TO A PASS (M21 §1.7). An approved walk-up for a restricted
             * household was admitted as "Pre-approved" with no word said. Online it is refused, and admitting the
             * visitor is an override, with a reason — as it is for the guest pass the same household would have sent.
             * Offline it is recorded and flagged, as an offline pass admission is.
             */
            $restricted = $this->walkUpRestricted($unit?->household);
            if ($restricted && ! $offline) {
                throw ApiError::conflict('household_restricted', RestrictionPolicy::GATE_SENTENCE.'. Admitting this visitor is an override, with a reason.');
            }
            if ($restricted) {
                $reconciliation = 'household_restricted';
            }

            $basis = 'Pre-approved';
            $subject = $subject !== '' ? $subject : $approval->visitor_name.' · '.($unit->reference ?? '');
        }

        if ($subject === '') {
            throw ApiError::unprocessable('subject_required', 'Say who came in — a name, a plate, a company — when there is no pass or approval.');
        }

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'admit',
            category: $data['category'],
            subject: $subject,
            basis: $basis,
            guardId: $guard->id,
            postId: $postId,
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        $this->recordReconciliation($event, $context, $guard, $pass, $reconciliation, $offline);

        /*
         * THE HOUSEHOLD'S SIDE OF THE ADMISSION (M11 §2, DESIGN.md §13.3 (1), D-169). Their visitor is in: the moment
         * goes on the pass — a recurring one as much as a single-use one — or on the walk-up they approved, and every
         * member's phone hears it while its Visitors screen is open. Once: an idempotent retry of this entry wrote no
         * new log row above, and tells nobody anything twice.
         */
        if ($event->wasRecentlyCreated) {
            if ($pass !== null) {
                // The gate by the post's own name ("Main Gate"), as the household's row says it (M47 §5.4) — the log
                // keeps the long label the guards know it by.
                $this->passes->admitted($pass, $context->serverTime, $postId === null ? null : Post::query()->whereKey($postId)->value('name'), $guard->full_name);
            }

            if ($approval !== null) {
                DB::connection('tenant')->transaction(function () use ($approval, $context): void {
                    DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval->id)->update(['admitted_at' => $context->serverTime, 'updated_at' => $context->serverTime]);
                    ApprovalLive::tell((int) $approval->id, $approval->household_id === null ? null : (int) $approval->household_id, 'admitted');
                });
            }
        }

        return response()->json([
            ...$this->eventShape($event, $context),
            'reconciliation' => $reconciliation,
            'access_restricted' => $restricted,
        ], 201);
    }

    /**
     * AN ADMISSION THE SERVER WOULD HAVE REFUSED, WRITTEN DOWN (review, M6 §1).
     *
     * Online, a pass that is cancelled, expired, already used or belongs to a restricted
     * household admits nobody: the guard is refused `409 pass_not_admissible` and told that
     * letting them in is an override, with a reason. Offline the handset could not know any
     * of that, so the entry is accepted and reconciled — and the reconciliation was returned
     * to the PHONE and then thrown away. `gate_events` has no column for it, `GateLog` takes
     * no argument for it, and the row it writes is indistinguishable from an ordinary valid
     * admission. So the one act the override verdict exists to make visible — somebody let in
     * against the system's advice — left no trace at all whenever `verified_offline: true`
     * was sent, which is a flag the server cannot check and any handset can assert.
     *
     * The audit log is where it goes. It is append-only at the database (triggers, not only
     * grants), it outlives the gate row, and it is the record an estate reads when a resident
     * asks how somebody got in on a pass they had cancelled. Nothing about the response, the
     * catalogue or the gate event changes: this is added evidence, not a refusal.
     *
     * `valid` and `consumed` are the ordinary outcomes and are not audited — four hundred a
     * week of those would bury the two that matter, the same reason `ShiftClock` audits only
     * a mocked location or a manual start.
     */
    private function recordReconciliation(GateEvent $event, DeviceContext $context, Guard $guard, ?VisitorPass $pass, ?string $reconciliation, bool $offline): void
    {
        if ($reconciliation === null || in_array($reconciliation, ['valid', 'consumed'], true)) {
            return;
        }

        $this->audit->record(
            action: 'gate.admitted_on_unadmissible_pass',
            entityType: 'GateEvent',
            entityId: (string) $event->id,
            before: [],
            after: [
                'reconciliation' => $reconciliation,
                'pass_id' => $pass?->pass_id,
                'pass_status' => $pass?->status,
                'verified_offline_claimed' => $offline,
                'subject' => $event->subject,
                'basis' => $event->basis,
                'guard' => $guard->full_name,
                'employee_number' => $guard->employee_number,
                'post' => $event->post_name,
                'device_time' => $event->device_time?->toIso8601String(),
                'occurred_at' => $event->occurred_at->toIso8601String(),
            ],
            tenantId: $context->tenantId,
        );
    }

    public function exit(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:120'],
            'pass_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'exit',
            category: $data['category'],
            subject: $data['subject'],
            basis: ($data['pass_id'] ?? null) !== null ? 'QR pass' : GateLog::GUARD_DECISION,
            guardId: $guard->id,
            postId: $postId = $this->postFor($guard, $data['post_id'] ?? null, $context),
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        // A Helper or a Dependent going out on their own e-Pass, recorded on the pass: the household is told, by the
        // estate's rule (8 Oct 2026). Once — a retry of this exit wrote no new row.
        if ($event->wasRecentlyCreated && ($data['pass_id'] ?? null) !== null) {
            $pass = VisitorPass::query()->where('pass_id', (string) $data['pass_id'])->first();
            if ($pass !== null) {
                app(HouseholdEntries::class)->came($pass, $context->serverTime, $postId === null ? null : Post::query()->whereKey($postId)->value('name'), 'out');
            }
        }

        return response()->json($this->eventShape($event, $context), 201);
    }

    public function override(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:160'],
            'pass_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'override',
            category: $data['category'],
            subject: $data['subject'],
            basis: 'Override — '.trim($data['reason']),
            guardId: $guard->id,
            postId: $this->postFor($guard, $data['post_id'] ?? null, $context),
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json($this->eventShape($event, $context), 201);
    }

    /**
     * Find a unit by its reference or a household by name — board guard-app-07's search.
     *
     * `access_restricted` AS A BOOLEAN AND NOTHING ELSE ABOUT MONEY (13 D2). The
     * rows are built from the unit, the household's name, its primary resident's
     * name and today's expected visitors. None of those can carry a figure.
     */
    public function search(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate([
            'unit' => ['nullable', 'string', 'min:1', 'max:32', 'required_without:name'],
            'name' => ['nullable', 'string', 'min:2', 'max:80', 'required_without:unit'],
            'phase' => ['nullable', 'string', 'max:64'],
        ]);

        /*
         * THE PHASE FILTER AND THE COUNT ARE BOTH THE SERVER'S (23 September 2026).
         *
         * The app used to take the twenty rows this endpoint returns and filter THEM by phase,
         * which reads as a phase filter and is not one: on an estate of 2,000 lots, "Phase 5"
         * applied to twenty Phase 1 rows shows an empty screen at a gate with a visitor waiting.
         * The filter now narrows the query, and `total` says how many the whole estate matched,
         * so the screen can say it is showing the first twenty of a hundred rather than imply
         * there are twenty.
         */
        $matching = Household::query()
            ->when($data['unit'] ?? null, fn ($q, string $unit) => $q->whereHas('unit', fn ($u) => $u->where('reference', 'like', '%'.$this->like($unit).'%')))
            // M32 F4: a household by any of its people (name, email, phone however punctuated) and by its vehicles' plates.
            ->when($data['name'] ?? null, function ($q, string $name): void {
                $plate = Residents::plateCondition($name);

                $q->where(function ($w) use ($name, $plate): void {
                    $w->where('name', 'like', '%'.$this->like($name).'%')
                        ->orWhereHas('residents', fn ($r) => Residents::matchResident($r, $name));

                    if ($plate !== null) {
                        $w->orWhereExists(fn ($v) => $v->select(DB::raw(1))->from('household_vehicles')
                            ->whereColumn('household_vehicles.household_id', 'households.id')
                            ->whereRaw($plate[0], $plate[1]));
                    }
                });
            })
            ->when($data['phase'] ?? null, fn ($q, string $phase) => $q->whereHas('unit', fn ($u) => $u->where('block', $phase)));

        $total = (clone $matching)->count();
        $households = $matching->with(['unit', 'residents'])->orderBy('name')->limit(self::SEARCH_LIMIT)->get();

        $now = $context->serverTime;
        $expected = VisitorPass::query()
            ->whereIn('unit_id', $households->pluck('unit_id'))
            ->where('status', VisitorPass::ACTIVE)
            // "Expected today" ends when the SITE'S day ends, not UTC's (M4 §3.1).
            ->where('valid_from', '<=', SiteTime::dayBounds(null, $context->tenantId)[1])
            ->where('valid_to', '>=', $now)
            ->orderBy('valid_from')
            ->get()
            ->groupBy('unit_id');

        /*
         * WHO AT THIS LOT IS NO LONGER A RESIDENT (Q-M44).
         *
         * The register entry STAYS when the estate office ends somebody's app access — it
         * is the community's record and a revocation is not a deletion — so without this
         * the card would go on naming a former owner as the primary resident and a guard
         * would admit them on it. One sentence, in the card's own standing-note slot, and
         * it says where to send them: the Visitor Entrance.
         */
        $revoked = RevokeResidentAccess::revokedNamesByUnit($context->tenantId, $households->pluck('unit_id')->map(static fn ($id): int => (int) $id)->all());

        $items = $households->map(fn (Household $h): array => [
            'unit' => (string) $h->unit?->reference,
            'household' => $h->name,
            'primary_resident' => $h->residents->firstWhere('is_primary', true)?->full_name,
            'access_restricted' => (bool) $h->access_restricted,
            'notice' => RevokeResidentAccess::gateNotice($revoked[(int) $h->unit_id] ?? [], (string) $h->unit?->reference),
            'expected_visitors' => ($expected->get($h->unit_id) ?? collect())->map(static fn (VisitorPass $p): array => [
                'visitor_name' => $p->visitor_name,
                'category' => $p->category,
                'valid_to' => $p->valid_to->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();

        /*
         * EVERY LOOKUP IS LOGGED (M21 §1.7). A guard can search every household on the estate by lot or by name; the
         * estate's audit log keeps who asked, from which post, what was typed and how many households matched — never
         * which ones, and nothing about their standing. The officer is named as a Guard App handset. Every search the
         * phone sends is one entry, because each is a real question put to the register.
         */
        $guard = $context->guardOrFail();
        $this->audit->record(
            action: 'gate.lookup',
            entityType: 'Household',
            after: [
                'by' => isset($data['unit']) ? 'lot' : 'name',
                'typed' => (string) ($data['unit'] ?? $data['name'] ?? ''),
                'phase' => $data['phase'] ?? null,
                'matched' => $total,
                'guard' => $guard->full_name,
                'employee_number' => $guard->employee_number,
                'post' => $guard->post?->name,
            ],
            tenantId: $context->tenantId,
        );

        return response()->json(['total' => $total, 'limit' => self::SEARCH_LIMIT, 'items' => $items]);
    }

    public function activity(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        // Today's log is the SITE'S today (M4 §3.1): midnight at the gate, as the UTC moment it is.
        $today = SiteTime::today($context->tenantId);

        $events = GateEvent::query()->where('tenant_id', $context->tenantId)
            ->where('occurred_at', '>=', $today)
            ->orderByDesc('occurred_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        $counts = GateEvent::query()->where('tenant_id', $context->tenantId)
            ->where('occurred_at', '>=', $today)
            ->selectRaw('verdict, COUNT(*) AS n')
            ->groupBy('verdict')
            ->pluck('n', 'verdict');

        return response()->json([
            'date' => $today->toDateString(),
            'counts' => [
                'admitted' => (int) ($counts['admit'] ?? 0),
                'exited' => (int) ($counts['exit'] ?? 0),
                'overridden' => (int) ($counts['override'] ?? 0),
                'denied' => (int) ($counts['deny'] ?? 0),
            ],
            'items' => $events->map(fn (GateEvent $e): array => [
                'id' => $e->id,
                'verdict' => $e->verdict,
                'category' => $e->category,
                'subject' => $e->subject,
                'basis' => $e->basis,
                'pass_based' => $this->log->isPassBased((string) $e->basis),
                'guard_name' => $e->guard_name,
                'post_name' => $e->post_name,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'device_time' => $e->device_time?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Board guard-app-08: record a walk-up visitor and ask the household. */
    public function requestApproval(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        /*
         * NOTHING IS OPTIONAL (M18C): the ID and what the visit is for, always; the plate, or the guard's explicit
         * "Arriving on foot or by taxi — no vehicle". One or the other, never neither.
         */
        $noVehicle = $request->boolean('no_vehicle');

        $data = $request->validate([
            'unit' => ['required', 'string', 'max:32'],
            'visitor_name' => ['required', 'string', 'max:160'],
            'id_type' => ['required', 'string', 'max:40'],
            'id_number' => ['required', 'string', 'max:40'],
            'purpose' => ['required', 'string', 'min:2', 'max:160'],
            'vehicle_plate' => $noVehicle ? ['prohibited'] : ['required', 'string', 'min:2', 'max:16'],
            'no_vehicle' => ['sometimes', 'boolean'],
        ], [
            'vehicle_plate.required' => 'Enter the plate, or tick “Arriving on foot or by taxi — no vehicle”.',
            'vehicle_plate.prohibited' => 'Untick “Arriving on foot or by taxi” to give a plate.',
        ]);

        $data['no_vehicle'] = $noVehicle;

        $unit = Unit::query()->with('household')->where('reference', $data['unit'])->first();

        if ($unit === null) {
            throw ApiError::notFound('not_found', 'No unit '.$data['unit'].' at this estate. Search by name.');
        }

        $post = $guard->post_id === null ? null : Post::query()->find($guard->post_id);
        $now = $context->serverTime;

        // With its live event (M11 Part C, DESIGN.md §2.9 I15): the household's open app shows the request at once.
        $id = DB::connection('tenant')->transaction(fn (): int => $this->insertApproval($request, $context, $guard, $unit, $post, $data, $now));

        // The household's phones, even closed (M4 §3.2). Queued, and it never throws: the request stands either way.
        app(PushAnnouncer::class)->approvalRequested($context->tenantId, (int) $unit->id, (int) $id, $data['visitor_name'], $post?->label(), self::APPROVAL_WAIT_SECONDS, $request->boolean('simulated'));

        /*
         * WHETHER ANYBODY CAN ANSWER. Only a Member's access level answers the gate, so a household whose only
         * app users are Dependents or Helpers is asked by nobody — and the guard is told now, not after the clock.
         */
        return response()->json([
            ...$this->approvalShape((int) $id),
            'household_can_answer' => app(PushAudience::class)->canAnswer($context->tenantId, (int) $unit->id),
        ], 201);
    }

    /**
     * The walk-up request's row, and its `approval.requested` (see requestApproval()).
     *
     * @param  array<string, mixed>  $data
     */
    private function insertApproval(Request $request, DeviceContext $context, Guard $guard, Unit $unit, ?Post $post, array $data, Carbon $now): int
    {
        $id = (int) DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
            'unit_id' => $unit->id,
            'household_id' => $unit->household?->id,
            'guard_id' => $guard->id,
            'guard_name' => $guard->full_name,
            'post_id' => $post?->id,
            // As the estate names it, so a resident told "Gate 5" knows which gate (M26).
            'post_name' => $post?->label(),
            'visitor_name' => $data['visitor_name'],
            'id_type' => $data['id_type'] ?? null,
            'id_number' => $data['id_number'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'no_vehicle' => (bool) ($data['no_vehicle'] ?? false),
            'status' => 'pending',
            'requested_at' => $now,
            'respond_by' => $now->copy()->addSeconds(self::APPROVAL_WAIT_SECONDS),
            'device_time' => $context->deviceTime,
            'is_simulated' => $request->boolean('simulated'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        ApprovalLive::tell($id, $unit->household?->id === null ? null : (int) $unit->household->id, 'requested');

        return $id;
    }

    /**
     * A walk-up nobody answered in time is expired where it is read (here, and PassesController::respond), and the
     * guard waiting at the gate and the household both hear it, once: only the read that expires it records the event
     * (M11 Part C, DESIGN.md §2.9 I17).
     */
    public static function expireIfDue(int $id): void
    {
        DB::connection('tenant')->transaction(static function () use ($id): void {
            $expired = DB::connection('tenant')->table('gate_approval_requests')
                ->where('id', $id)->where('status', 'pending')->where('respond_by', '<', Carbon::now())
                ->update(['status' => 'expired', 'updated_at' => Carbon::now()]);

            if ($expired > 0) {
                $household = DB::connection('tenant')->table('gate_approval_requests')->where('id', $id)->value('household_id');
                ApprovalLive::tell($id, $household === null ? null : (int) $household, 'expired');
            }
        });
    }

    public function approval(int $approval, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        if (! DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->exists()) {
            throw ApiError::notFound('not_found', 'No such walk-up request.');
        }

        return response()->json($this->approvalShape($approval));
    }

    /** @return array<string, mixed> */
    private function approvalShape(int $id): array
    {
        self::expireIfDue($id);

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $id)->first();
        $unit = Unit::query()->with('household')->find($row->unit_id);
        $restricted = $this->walkUpRestricted($unit?->household);

        return [
            'approval_id' => (int) $row->id,
            'status' => (string) $row->status,
            'unit' => (string) $unit?->reference,
            'household' => $unit?->household?->name,
            'visitor_name' => (string) $row->visitor_name,
            'requested_at' => Carbon::parse((string) $row->requested_at)->toIso8601String(),
            'respond_by' => Carbon::parse((string) $row->respond_by)->toIso8601String(),
            'responded_at' => $row->responded_at === null ? null : Carbon::parse((string) $row->responded_at)->toIso8601String(),
            'responded_by_name' => $row->responded_by_name,
            // As at a pass: the one boolean, and never why (M21 §1.7).
            'access_restricted' => $restricted,
            'guidance' => match (true) {
                $row->status === 'approved' && $restricted => 'Approved by the household, but its visitors are held at the gate: contact management. Admitting them is an override, with a reason.',
                $row->status === 'approved' => 'Approved by the household. Record the entry with this approval.',
                $row->status === 'denied' => 'The household declined. Do not admit.',
                $row->status === 'expired' => 'No answer from the household. Apply the estate\'s policy for unannounced visitors.',
                default => 'Waiting on the household.',
            },
        ];
    }

    /**
     * WHETHER THIS HOUSEHOLD'S WALK-UP VISITOR IS HELD AT THE GATE (M21 §1.7): the estate's arrears restriction, read
     * exactly as for a guest pass (`RestrictionPolicy`, a payment plan being met included). A household's own "yes"
     * in the app does not lift it — it is the same visitor a guest pass would have brought.
     */
    private function walkUpRestricted(?Household $household): bool
    {
        return $household !== null && ! $this->policy->decide($household, self::WALK_UP_CATEGORY)['admitted'];
    }

    /** @return array<string, mixed> */
    private function eventShape(GateEvent $event, DeviceContext $context): array
    {
        return [
            'id' => $event->id,
            'verdict' => $event->verdict,
            'basis' => $event->basis,
            'pass_based' => $this->log->isPassBased((string) $event->basis),
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $event->device_time?->toIso8601String(),
            'clock_skewed' => $this->log->clockSkewed($event->device_time, $event->occurred_at),
        ];
    }

    /** @return array<string, mixed> */
    private function passShape(VisitorPass $pass): array
    {
        return [
            'pass_id' => $pass->pass_id,
            'category' => $pass->category,
            'visitor_name' => $pass->visitor_name,
            'vehicle_plate' => $pass->vehicle_plate,
            'purpose' => $pass->purpose,
            'valid_from' => $pass->valid_from->toIso8601String(),
            'valid_to' => $pass->valid_to->toIso8601String(),
            'single_use' => $pass->single_use,
            'status' => $pass->status,
        ];
    }

    private function householdOf(VisitorPass $pass): ?Household
    {
        return $pass->household_id === null
            ? $pass->unit->household
            : Household::query()->find($pass->household_id);
    }

    private function postFor(Guard $guard, ?int $postId, DeviceContext $context): ?int
    {
        $postId ??= $guard->post_id;

        if ($postId !== null && ! Post::query()->whereKey($postId)->where('tenant_id', $context->tenantId)->exists()) {
            throw ApiError::forbidden('wrong_site', 'That post is not at your estate.');
        }

        return $postId;
    }

    private function like(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
