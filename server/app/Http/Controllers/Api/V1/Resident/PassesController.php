<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Api\V1\Guard\GateController;
use App\Http\Controllers\Controller;
use App\Live\Events\ApprovalLive;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Services\Passes\VisitorPasses;
use App\Services\ResidentApp\PassInvitation;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\Restriction\RestrictionPolicy;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Visitor passes, the resident's own e-pass, and answering a guard at the gate (13 D3).
 * Boards resident-app-05 (create), -06 (my passes, share), -07 (visitor approval), -08 (e-pass).
 *
 * THE PASS IS SIGNED WHEN IT IS ISSUED, and what is signed cannot be edited: the
 * window, the category and the single-use flag are in the payload a guard's
 * handset verifies offline. PATCH changes only what is not signed — who is coming,
 * their number, the purpose and the plate. A different window is a new pass.
 *
 * A RESTRICTED HOUSEHOLD IS TOLD, IN THE GATE'S WORDS. The resident issuing a
 * guest pass for a household in restriction is refused with the same sentence a
 * guard sees, RestrictionPolicy::GATE_SENTENCE, and nothing about why.
 * The figure is on their statement, which is theirs to read; the pass screen is
 * not where a balance is argued.
 */
class PassesController extends Controller
{
    /** A pass's category, in the restriction policy's words. */
    private const RESTRICTION_CATEGORY = ['single' => 'guest', 'recurring' => 'visitor', 'contractor' => 'contractor', 'delivery' => 'delivery'];

    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly VisitorPasses $passes,
        private readonly RestrictionPolicy $policy,
    ) {}

    public function index(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $request->validate(['status' => ['nullable', 'string', 'in:active,used,cancelled,expired']]);

        $items = VisitorPass::query()
            ->where('unit_id', $home['unit']->id)
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->where('valid_to', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('valid_from')
            ->limit(100)
            ->get()
            ->map(fn (VisitorPass $p): array => $this->shape($p))
            ->when($data['status'] ?? null, static fn ($c, string $status) => $c->where('status', $status))
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        /*
         * NOTHING IS OPTIONAL (M18C): name, purpose, the visitor's phone and the window, all required. The plate is the
         * one field that may genuinely not apply, and it is an explicit choice: a plate, or `no_vehicle` — "Arriving on
         * foot or by taxi — no vehicle". One or the other, never neither, and never both.
         */
        $noVehicle = $request->boolean('no_vehicle');

        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', VisitorPasses::CATEGORIES)],
            'visitor_name' => ['required', 'string', 'min:2', 'max:160'],
            'visitor_phone' => ['required', 'string', 'max:40', new PhoneNumber],
            // M12 §2.13: required — the guard sees what a visit is for before the visitor has to say it.
            'purpose' => ['required', 'string', 'min:2', 'max:160'],
            'vehicle_plate' => $noVehicle ? ['prohibited'] : ['required', 'string', 'min:2', 'max:16'],
            'no_vehicle' => ['sometimes', 'boolean'],
            // How the visitor arrives (M50 §6.3): Drive in with a plate; Walk in or Ride in with "no vehicle".
            'arrival' => ['sometimes', 'nullable', 'string', $noVehicle ? 'in:walk,ride' : 'in:drive'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
        ], [
            'purpose.required' => 'Say what the visit is for — e.g. Family visit, Plumber, Delivery. The guard sees it at the gate.',
            'purpose.min' => 'Say what the visit is for — e.g. Family visit, Plumber, Delivery. The guard sees it at the gate.',
            'vehicle_plate.required' => 'Enter the plate, or choose Walk in or Ride in.',
            'vehicle_plate.prohibited' => 'Choose Drive in to give a plate.',
            'arrival.in' => 'Drive in comes with a plate; Walk in and Ride in come without one.',
        ]);

        $data['visitor_phone'] = JamaicanNumber::normalise($data['visitor_phone']);
        $data['vehicle_plate'] = $noVehicle ? null : $data['vehicle_plate'];
        $data['no_vehicle'] = $noVehicle;
        $data['arrival'] = $data['arrival'] ?? null;

        if (! $this->policy->decide($home['household'], self::RESTRICTION_CATEGORY[$data['category']])['admitted']) {
            // The same words the guard reads at the gate (RestrictionPolicy::GATE_SENTENCE), not a second copy that could drift.
            throw ApiError::conflict('access_restricted', RestrictionPolicy::GATE_SENTENCE.'.');
        }

        // A pass whose window has already closed admits nobody. A draft written with no signal one evening and sent
        // by itself the next morning became exactly that (the M11 §1.7 airplane test, 26 Sep): it is refused instead,
        // in words the phone keeps on the draft for the resident to read (D-191).
        if (! SiteTime::parse((string) $data['valid_to'], $context->tenantId)->isFuture()) {
            throw ApiError::unprocessable('pass_refused', 'The time chosen for this visit has already passed, so the pass would let nobody in. Invite them again for a time that is still ahead.');
        }

        try {
            $pass = $this->passes->issue(
                $context->tenantId,
                $home['unit'],
                $home['resident']?->id,
                (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
                [
                    ...$data,
                    // The phone sends the window WITH ITS OFFSET: honoured, kept in UTC. With none, the estate's clock (M4 §3.1).
                    'valid_from' => SiteTime::parse((string) $data['valid_from'], $context->tenantId),
                    'valid_to' => SiteTime::parse((string) $data['valid_to'], $context->tenantId),
                ],
                $request->header('Idempotency-Key'),
                $request->boolean('simulated'),
            );
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('pass_refused', $refused->getMessage());
        }

        return response()->json($this->shape($pass), 201);
    }

    public function update(Request $request, int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);

        // Sent only when changed, and never blanked (M18C). A plate given clears "no vehicle"; "no vehicle" clears the plate.
        $data = $request->validate([
            'visitor_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'visitor_phone' => ['sometimes', 'required', 'string', 'max:40', new PhoneNumber],
            'purpose' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'vehicle_plate' => ['sometimes', 'required', 'string', 'min:2', 'max:16'],
            'no_vehicle' => ['sometimes', 'boolean'],
            'arrival' => ['sometimes', 'nullable', 'string', 'in:walk,drive,ride'],
            'valid_from' => ['prohibited'],
            'valid_to' => ['prohibited'],
            'category' => ['prohibited'],
        ]);

        if (isset($data['visitor_phone'])) {
            $data['visitor_phone'] = JamaicanNumber::normalise($data['visitor_phone']);
        }

        // A plate is Drive in; "no vehicle" is Walk in or Ride in — whichever was said, and never Drive in.
        if (isset($data['vehicle_plate'])) {
            $data['no_vehicle'] = false;
            $data['arrival'] = 'drive';
        } elseif (($data['no_vehicle'] ?? false) === true) {
            $data['vehicle_plate'] = null;
            $data['arrival'] = in_array($data['arrival'] ?? null, ['walk', 'ride'], true) ? $data['arrival'] : null;
        } else {
            unset($data['no_vehicle'], $data['arrival']);
        }

        // The status the app is shown, window included (bug hunt B065) — not only the stored one.
        $status = $this->statusOf($record);

        if ($status !== VisitorPass::ACTIVE) {
            throw ApiError::conflict('pass_not_active', 'Only an active pass can be changed. This one is '.$status.'.');
        }

        // Every member's Visitors list moves (M11 Part C, DESIGN.md §2.9 I3).
        $this->passes->changed($record, 'updated', static fn () => $record->forceFill($data)->save());

        return response()->json($this->shape($record));
    }

    public function cancel(int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);

        try {
            $this->passes->cancel($record);
        } catch (DomainException $refused) {
            throw ApiError::conflict('pass_already_used', $refused->getMessage());
        }

        return response()->json($this->shape($record->refresh()));
    }

    public function share(Request $request, int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);
        $request->validate(['channel' => ['nullable', 'string', 'in:whatsapp,sms,email,copy']]);

        $status = $this->statusOf($record);

        if ($status !== VisitorPass::ACTIVE) {
            throw ApiError::conflict('pass_not_active', 'This pass is '.$status.', so it cannot be shared: a visitor sent it would be turned away.');
        }

        $this->passes->changed($record, 'shared', static fn () => $record->increment('share_count'));
        $estate = (string) Tenant::query()->whereKey($context->tenantId)->value('name');

        // M18F §4: the invitation in the client's words, per pass type: the date once, in the one form ("27 Sep 2026"),
        // no clock named (M98: everything on a pass is estate time), the host's name; and a words-only version for a
        // text message. The phone writes the same offline.
        $words = PassInvitation::for($record, [
            'estate' => $estate,
            'unit' => $record->unit->reference,
            'phase' => $record->unit->block,
            'host' => (string) $context->residentOrFail()->full_name,
            'zone' => SiteTime::zone($context->tenantId),
        ]);

        return response()->json([
            'pass_id' => $record->pass_id,
            'share_count' => $record->share_count,
            'code' => $record->code,
            'token' => $record->token,
            'message' => $words['message'],
            'sms' => $words['sms'],
        ]);
    }

    public function epass(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        if ($home['resident'] === null) {
            throw ApiError::forbidden('account_pending', 'This account is not linked to a resident on the register yet.');
        }

        /*
         * A HELPER'S E-PASS WORKS IN THEIR DAYS AND HOURS (8 Oct 2026) — the household's own for them, else the estate's
         * default. Paused, or past their end date, there is no e-Pass to show, and the words say who can change that.
         */
        $window = AccessLevels::of($home['resident']) === AppMatrix::HELPER ? AccessRules::window($home['resident']) : null;
        $refused = $window === null ? null : AccessRules::refusedAt($window, Carbon::now(), $context->tenantId);
        $primary = AccessLevels::primaryOf($home['household'])?->full_name;

        if ($refused === 'paused') {
            throw ApiError::forbidden('access_paused', 'Your household has paused your e-Pass, so the gate will not admit you on it. Ask '.($primary ?? 'the household\'s primary resident').' to turn it back on.');
        }

        if ($refused === 'ended') {
            throw ApiError::forbidden('access_ended', 'Your e-Pass ended on '.Carbon::parse((string) $window['until'])->format('M j, Y').'. Ask '.($primary ?? 'the household\'s primary resident').' if you are still expected.');
        }

        $pass = $this->passes->residentPass($context->tenantId, $home['unit'], $home['resident']->id, $home['resident']->full_name, $request->boolean('simulated'));

        /*
         * HALF AN HOUR AT A TIME (M47 §6). The pass as the phone shows it is the half hour this moment is in — its window,
         * its token — so even an app that knows nothing of `slots` shows a QR that is dead within thirty minutes; and
         * `slots` is the day of them the phone keeps for a gate with no signal. The row's own two-day token never leaves
         * the server: a typed gate code is checked against it here. A Helper's slots are only those inside their hours.
         */
        $tenant = $context->tenantId;
        $slots = $this->passes->residentSlots($tenant, $pass, null, $window === null ? null : static fn (Carbon $from): bool => AccessRules::refusedAt($window, $from, $tenant) === null);

        if ($slots === []) {
            throw ApiError::forbidden('outside_hours', 'Your e-Pass works '.AccessRules::windowLine($window ?? []).'. It opens again then.');
        }

        $now = $slots[0];

        return response()->json([
            ...$this->shape($pass),
            'valid_from' => $now['valid_from'],
            'valid_to' => $now['valid_to'],
            'token' => $now['token'],
            'slots' => $slots,
            'refresh_after' => $now['valid_to'],
            // A Helper's days and hours in words, for the e-Pass screen — null for anybody else.
            'hours' => $window === null ? null : 'Your e-Pass works '.AccessRules::windowLine($window).'.',
        ]);
    }

    /** Board resident-app-07: the household answers a guard's walk-up request. */
    public function respond(Request $request, int $approval, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate(['decision' => ['required', 'string', 'in:approve,deny']]);

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->where('unit_id', $home['unit']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No request from the gate for your household with that id.');
        }

        if ($row->status === 'pending' && Carbon::parse((string) $row->respond_by)->isPast()) {
            // Expired where it is read, and the guard and the household hear it once (M11 Part C, §2.9 I17).
            GateController::expireIfDue((int) $approval);

            throw ApiError::conflict('approval_expired', 'The guard waited and the time ran out, so the estate\'s policy for unannounced visitors applied. Call the gate if you are expecting them.');
        }

        if ($row->status !== 'pending') {
            throw ApiError::conflict('approval_decided', 'This request was already answered ('.$row->status.').');
        }

        /*
         * THE ANSWER REACHES THE GUARD AT THE GATE AT ONCE (M11 Part C, DESIGN.md §2.9 I16): their waiting screen moves
         * the moment a member approves or declines, and the other members' phones show it answered. Only the write that
         * actually answered records it: a second member's tap a moment later changes no row and says nothing.
         */
        DB::connection('tenant')->transaction(function () use ($approval, $data, $home, $row): void {
            $answered = DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->where('status', 'pending')->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'denied',
                'responded_at' => Carbon::now(),
                'responded_by_account_id' => $home['account']->id,
                'responded_by_name' => $home['resident']->full_name ?? $home['account']->full_name,
                'updated_at' => Carbon::now(),
            ]);

            if ($answered > 0) {
                ApprovalLive::tell((int) $approval, $row->household_id === null ? null : (int) $row->household_id, 'answered');
            }
        });

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->first();

        /*
         * WHAT HAPPENS AT THE GATE NOW, SAID TO THE HOUSEHOLD (M22 §2). "The guard is letting them in" was untrue for a
         * household whose visitors are held: its own yes does not lift the estate's restriction (D-213), so the guard
         * denies, or admits on an override. A boolean, read the way the gate reads it; the household's own Dues page
         * says why.
         */
        $held = $row->status === 'approved' && ! $this->policy->decide($home['household'], 'guest')['admitted'];

        return response()->json([
            'approval_id' => (int) $row->id,
            'status' => (string) $row->status,
            'visitor_name' => (string) $row->visitor_name,
            'responded_at' => SiteTime::iso(Carbon::parse((string) $row->responded_at, 'UTC')),
            'visitors_held' => $held,
        ]);
    }

    private function mine(int $id, DeviceContext $context): VisitorPass
    {
        $home = $this->accounts->home($context);

        $pass = VisitorPass::query()->with('unit')->whereKey($id)
            ->where('unit_id', $home['unit']->id)
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->first();

        if ($pass === null) {
            throw ApiError::notFound('not_found', 'No pass of your household\'s with that id.');
        }

        return $pass;
    }

    /**
     * The status the app is shown: `expired` once an active pass's window has ended.
     *
     * ONE RULE FOR WHAT IS SHOWN AND WHAT IS ALLOWED (bug hunt B065). `update` and `share`
     * asked the STORED status, so a pass that ended yesterday was edited with a 200 whose
     * own body said `expired`, and shared with a message telling the visitor a time that
     * had passed — although the catalogue promises `409 pass_not_active`.
     */
    private function statusOf(VisitorPass $p): string
    {
        return $p->status === VisitorPass::ACTIVE && $p->valid_to->isPast() ? 'expired' : (string) $p->status;
    }

    /** @return array<string, mixed> */
    private function shape(VisitorPass $p): array
    {
        $status = $this->statusOf($p);

        return [
            'id' => $p->id,
            'pass_id' => $p->pass_id,
            'category' => $p->category,
            'visitor_name' => $p->visitor_name,
            'visitor_phone' => $p->visitor_phone,
            'purpose' => $p->purpose,
            'vehicle_plate' => $p->vehicle_plate,
            // "No vehicle" (M18C §3.1): chosen, not a blank plate — and how they come instead (M50 §6.3).
            'no_vehicle' => (bool) $p->getAttribute('no_vehicle'),
            'arrival' => $p->getAttribute('arrival'),
            // ONE TIME RULE (M4 §3.1): UTC instants; the app shows them in the estate's `timezone`.
            'valid_from' => SiteTime::iso($p->valid_from),
            'valid_to' => SiteTime::iso($p->valid_to),
            'single_use' => $p->single_use,
            'status' => $status,
            'code' => $p->code,
            'token' => $p->token,
            'share_count' => (int) $p->share_count,
            'used_at' => SiteTime::iso($p->used_at),
            'cancelled_at' => SiteTime::iso($p->cancelled_at),
            // The latest time a visitor was let in on this pass, any kind of pass (M11 §2, D-169).
            'last_admitted_at' => SiteTime::iso($p->last_admitted_at),
            // Where, and by whom, that admission was (M47 §5.4): "Entered · Main Gate · by Guard Whyte".
            'last_admitted_post' => $p->last_admitted_at === null ? null : $p->last_admitted_post,
            'last_admitted_by' => $p->last_admitted_at === null ? null : $p->last_admitted_by,
            // Whose guest it is (M47 §5.5): the member who issued it — not whoever happens to be signed in.
            'host' => (string) $p->issued_by_name,
        ];
    }
}
