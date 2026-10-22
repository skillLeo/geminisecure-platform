<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\AccessLevels;
use App\Api\AccessRules;
use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\AlertEmailDispatch;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Rules\ReceivesMail;
use App\Services\Audit\AuditLogger;
use App\Services\Dispatch\PanicDrillMode;
use App\Services\Estate\EmergencyContactMerge;
use App\Services\Estate\HouseholdMembers;
use App\Services\Estate\InviteAddressRefused;
use App\Services\Estate\ResidentInvites;
use App\Services\ResidentApp\AccountDeletion;
use App\Services\ResidentApp\AccountPhotos;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\ResidentDataExport;
use App\Services\ResidentApp\ResidentPassCancellation;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\ResidentApp\ShownOnce;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in resident, their household, and the people and vehicles in it (13 D3).
 * Boards resident-app-02 (home), -09 (household), -10 (profile).
 *
 * A HOUSEHOLD MEMBER ADDED FROM THE APP IS PENDING. The register is the estate's:
 * a resident may say who lives with them, and the estate verifies it on board 31
 * before that person is anyone a guard admits on the household's word.
 */
class MeController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly ResidentInvites $invites,
    ) {}

    public function show(DeviceContext $context): JsonResponse
    {
        return response()->json($this->profile($context));
    }

    /** The account has been shown something meant to appear once (M47 §3, `ShownOnce`): never again, on any phone. */
    public function markShown(Request $request, DeviceContext $context, ShownOnce $once): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'in:'.implode(',', ShownOnce::KEYS)]]);

        return response()->json(['shown' => $once->mark($context->residentOrFail(), $data['key'])]);
    }

    public function update(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        // Sent only when changed — and never blanked (M18C): a resident with no number or email is one nobody can reach.
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'phone' => ['sometimes', 'required', 'string', 'max:40', new PhoneNumber],
            'email' => ['sometimes', 'required', 'email', 'max:190', new ReceivesMail],
        ]);

        if (isset($data['phone'])) {
            $data['phone'] = JamaicanNumber::normalise($data['phone']);
        }

        $home['account']->forceFill(array_intersect_key($data, ['full_name' => true]))->save();

        // Contact details on the register; the name there is the estate's to change.
        if ($home['resident'] !== null) {
            $home['resident']->forceFill(array_intersect_key($data, ['phone' => true, 'email' => true]))->save();
        }

        return response()->json($this->profile($context));
    }

    /*
     * THE PROFILE PHOTO (the client, 26 Sep: "user can change his profile dp in the mobile app"). The account's own,
     * at every access level; see AccountPhotos for where it is kept and why it has no public address.
     */
    public function photo(DeviceContext $context): Response
    {
        $account = $context->residentOrFail();
        $path = $account->getAttribute('photo_path');

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            throw ApiError::notFound('no_photo', 'This account has no photo.');
        }

        return Storage::disk('local')->response($path, null, [
            // The address carries the version (`?v=`), so a phone may keep it; nobody else may.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /*
     * "DOWNLOAD MY DATA" (board 33, M50 §4.3): everything the platform holds about the signed-in person, as a PDF they
     * keep. The account comes from the token and from nothing in the request, so it is only ever their own; see
     * ResidentDataExport for what goes in, what stays out, and why.
     */
    public function dataExport(DeviceContext $context, ResidentDataExport $export): Response
    {
        $account = $context->residentOrFail();

        return response($export->pdf($account), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$export->fileName($account).'"',
            // A person's whole record: no cache on the way may keep a copy.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function storePhoto(Request $request, DeviceContext $context, AccountPhotos $photos): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.AccountPhotos::MAX_KB, 'mimetypes:'.implode(',', AccountPhotos::TYPES)],
        ], [
            'file.max' => 'That photo is too large. Choose another, or take a new one.',
            'file.mimetypes' => 'A profile photo is a JPEG, PNG or HEIC picture.',
        ]);

        $account = $photos->store($context->residentOrFail(), $request->file('file'));

        return response()->json(['photo' => AccountPhotos::shape($account)], 201);
    }

    public function removePhoto(DeviceContext $context, AccountPhotos $photos): JsonResponse
    {
        $photos->remove($context->residentOrFail());

        return response()->json(['photo' => null]);
    }

    public function household(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $household = $home['household'];

        // A guard waiting at the gate is shown only to somebody whose access level may answer them.
        $mayAnswer = AppMatrix::levelAllows(AccessLevels::of($home['resident']), 'approvals.respond');

        return response()->json([
            'household' => ['id' => $household->id, 'name' => $household->name],
            // Adding and removing members is the primary resident's right; the app draws the controls only for them.
            'can_manage_members' => (bool) $home['resident']?->is_primary,
            'unit' => ['id' => $home['unit']->id, 'reference' => $home['unit']->reference, 'phase' => $home['unit']->block],
            'members' => $this->memberShapes($household, $home['resident']),
            'vehicles' => DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->orderBy('plate')->get()
                ->map(fn (object $v): array => $this->vehicleShape($v))->all(),
            'emergency_contacts' => DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->orderBy('name')->get()
                ->map(fn (object $c): array => $this->contactShape($c))->all(),
            // The household's pets (M50 §5.3d), drawn as pills beside the vehicles.
            'pets' => HouseholdItemsController::petsOf((int) $household->id),
            'pending_approvals' => ! $mayAnswer ? [] : DB::connection('tenant')->table('gate_approval_requests')
                ->where('unit_id', $home['unit']->id)->where('status', 'pending')->where('respond_by', '>=', Carbon::now())
                ->orderBy('requested_at')->get()
                ->map(static fn (object $a): array => [
                    'id' => (int) $a->id,
                    'visitor_name' => (string) $a->visitor_name,
                    'purpose' => $a->purpose,
                    'vehicle_plate' => $a->vehicle_plate,
                    'post_name' => $a->post_name,
                    'guard_name' => (string) $a->guard_name,
                    'requested_at' => SiteTime::iso(Carbon::parse((string) $a->requested_at, 'UTC')),
                    'respond_by' => SiteTime::iso(Carbon::parse((string) $a->respond_by, 'UTC')),
                ])->all(),
        ]);
    }

    public function members(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        return response()->json(['items' => $this->memberShapes($home['household'], $home['resident'])]);
    }

    /**
     * Every member of the household, the primary first — with their invites read in ONE query, not one per row
     * (M11 A2), where each member asked for their own. `$viewer` is the person reading (row 20).
     *
     * @return list<array<string, mixed>>
     */
    private function memberShapes(Household $household, ?Resident $viewer): array
    {
        // M32 F1: the demonstration's placeholder people ("Lot 413 householder") are nobody, so a resident never sees one.
        $members = $household->residents()->orderByDesc('is_primary')->orderBy('full_name')->get()
            ->reject(static fn (Resident $r): bool => $r->isSample())->values();
        $invites = $this->invites->latestForEach($members);

        return $members->map(fn (Resident $r): array => $this->memberShape($r, $invites, $viewer))->values()->all();
    }

    /**
     * A HELPER'S PHONE AND EMAIL ARE FOR THE PRIMARY RESIDENT AND THE ESTATE OFFICE ONLY (rules table row 20, approved
     * by the user on 8 Oct 2026, M51 §1): a cleaner's or a nanny's own number is not the household's to pass round. A
     * Member or a Dependent reading the household gets null for both; the helper still reads their own, and the office
     * reads both on the console's household page, which this does not touch.
     */
    private static function hidesContact(Resident $member, ?Resident $viewer): bool
    {
        return $viewer !== null
            && ! $viewer->is_primary
            && ! $viewer->is($member)
            && AccessLevels::of($member) === AppMatrix::HELPER;
    }

    public function addMember(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $household = $home['household'];
        $this->primaryOnly($home['resident'], 'add');

        /*
         * NOTHING IS OPTIONAL (M18C). Every field is required, because a panic emails the household and hands its numbers
         * to the dispatcher: a blank is a person nobody can reach. The one field that may genuinely not apply — a young
         * child's or an elderly relative's email — is an explicit choice, `no_email`: "This person has no email — alerts
         * go to me instead", which records the primary resident as the one their alerts reach. Never neither.
         */
        $noEmail = $request->boolean('no_email');

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'relationship' => ['required', 'string', 'in:spouse,child,parent,relative,tenant,domestic_staff,other'],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'email' => $noEmail ? ['prohibited'] : ['required', 'email', 'max:190', new ReceivesMail],
            'no_email' => ['sometimes', 'boolean'],
            'invite' => ['sometimes', 'boolean'],
            'access_level' => ['sometimes', 'string', 'in:'.implode(',', AppMatrix::LEVELS_ALL)],
        ], [
            'email.required' => 'Enter their email, or tick “This person has no email — alerts go to me instead”.',
            'email.prohibited' => 'Untick “This person has no email” to give an email address.',
        ]);

        // Nobody is added at less than Member until levels are open (`AccessLevels::open`) — and a 409 adds nobody.
        $this->levelMayBeGiven($data['access_level'] ?? AppMatrix::MEMBER);

        /*
         * THE SAME PERSON TWICE IS REFUSED (the user, 8 Oct 2026, M54 §2): a current member of this household with the
         * same email, or the same name and phone, is already here. Two rows for one person send two invites and put two
         * names on the gate's list. A removed member is not current, and may be added back.
         */
        $phone = JamaicanNumber::normalise($data['phone']);
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $data['full_name']) ?? ''));
        $already = $household->residents()
            ->where(static function ($same) use ($data, $noEmail, $name, $phone): void {
                if (! $noEmail) {
                    $same->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $data['email']))]);
                }
                $same->orWhere(static fn ($q) => $q->whereRaw('LOWER(full_name) = ?', [$name])->where('phone', $phone));
            })
            ->first();

        if ($already instanceof Resident) {
            throw ApiError::conflict('member_exists', $already->full_name.' is already in your household.');
        }

        $member = new Resident;
        $member->forceFill([
            'household_id' => $household->id,
            'full_name' => $data['full_name'],
            'relationship' => $data['relationship'],
            'phone' => JamaicanNumber::normalise($data['phone']),
            'email' => $noEmail ? null : $data['email'],
            'alerts_to_primary' => $noEmail,
            'is_primary' => false,
            // What the primary chose for them — Member unless they said otherwise (an app that predates levels says nothing).
            'access_level' => $data['access_level'] ?? AppMatrix::MEMBER,
            'status' => Resident::PENDING,
        ])->save();

        /*
         * Recorded — who added whom, and at what level. `AccessLevels::set()` audits only a later CHANGE, so the
         * level a person starts with would otherwise leave no trail. The account is the actor (`AuditLogger`
         * names it as the register does); the sign-in is beside it.
         */
        app(AuditLogger::class)->record(
            action: 'household.member_added',
            entityType: 'Resident',
            entityId: (string) $member->id,
            after: [
                'household_id' => $household->id,
                'relationship' => $member->relationship,
                'access_level' => $member->access_level,
                'by' => (string) $home['resident']?->full_name,
                'by_resident_id' => $home['resident']?->id,
                'by_account_id' => $home['account']->id,
                'via' => 'resident_app',
            ],
            tenantId: (string) tenant()?->getTenantKey(),
        );

        /*
         * AND THEY ARE INVITED (the user, 24 September: "when a household member is invited he gets no email").
         * This saved the member and sent nothing, while the app's result screen said "Sent … Confirmed". With an
         * email the invite goes now — unless the app said not to — and `invite` in the answer says whether it
         * went, in the words the screen shows.
         */
        if (($data['email'] ?? null) !== null && ($data['invite'] ?? true)) {
            /*
             * NO INVITE FROM A STALE ADD (stage 2, G14, D-ACCEPT-4). An add queued on a phone with no signal and
             * sent a day later would say something the primary may no longer mean, and with the estate's trust on
             * it could link an account unreviewed. More than STALE_ADD_SECONDS old by the phone's own clock, it is
             * saved and the invite is not sent: the row says so, and Send invite is offered.
             */
            $stale = $context->deviceTime !== null
                && $context->deviceTime->lt($context->serverTime->copy()->subSeconds(ResidentInvites::STALE_ADD_SECONDS));

            // The household and unit this request already read, handed on rather than read again.
            $member->setRelation('household', $household->setRelation('unit', $home['unit']));

            if ($stale) {
                $this->invites->recordNotSent($member, 'Saved on the phone while it was offline, so no invite went with it. Send it now.', 'stale_offline_add');
            } else {
                try {
                    $this->invites->send($member, (string) $home['resident']?->full_name, $home['resident']?->id);
                } catch (DomainException) {
                    // The invite's own record says why (`recordNotSent`, G7); `memberShape` carries it.
                }
            }
        }

        return response()->json($this->memberShape($member->refresh()), 201);
    }

    /**
     * Send — or resend — a pending member's invite. The primary resident only, and not faster than the timer
     * (`ResidentInvites::COOLDOWN_SECONDS`): the answer is the member, with what became of the invite.
     */
    public function inviteMember(int $member, Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $actor = $this->primaryOnly($home['resident'], 'invite');
        $target = $this->memberOf($home, $member);

        $level = $request->validate([
            'access_level' => ['sometimes', 'string', 'in:'.implode(',', AppMatrix::LEVELS_ALL)],
        ])['access_level'] ?? null;

        if ($target->status !== Resident::PENDING) {
            throw ApiError::conflict('invite_not_pending', $target->full_name.' is already confirmed on the register, so there is nothing to invite them to.');
        }

        if (trim((string) $target->email) === '') {
            throw ApiError::unprocessable('invite_no_email', 'There is no email address for '.$target->full_name.'. Remove them and add them again with one, or ask the estate office to add it.');
        }

        if ($level !== null && $level !== AppMatrix::MEMBER && $target->is_primary) {
            throw ApiError::conflict('primary_is_member', AccessLevels::PRIMARY_IS_MEMBER);
        }

        if ($level !== null) {
            $this->levelMayBeGiven($level);
        }

        try {
            $this->invites->send($target, (string) $actor->full_name, $actor->id);
        } catch (InviteAddressRefused $refused) {
            // The generic sentence only (DESIGN INV-14): the real reason is the office's, and the audit's.
            throw ApiError::conflict('invite_address_refused', $refused->getMessage());
        } catch (DomainException $wait) {
            throw ApiError::conflict('invite_wait', $wait->getMessage());
        }

        /*
         * THE LEVEL GOES WITH THE INVITE, OR NOT AT ALL. Set once the invite has been tried — never before:
         * a refused invite (the timer) answers 409, and a 409 must mean nothing changed.
         */
        if ($level !== null && ! $target->is_primary) {
            AccessLevels::set($target, $level, (string) $actor->full_name, null, 'resident_app', $actor->id, $home['account']->id);
        }

        return response()->json($this->memberShape($target->refresh()));
    }

    /** Cancel a pending member's invite. The primary resident only. */
    public function cancelInvite(int $member, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $actor = $this->primaryOnly($home['resident'], 'cancel invites for');
        $target = $this->memberOf($home, $member);

        $this->invites->cancel($target, (string) $actor->full_name);

        return response()->json($this->memberShape($target->refresh()));
    }

    /**
     * Change a member's access level — Member, Dependent or Helper. The primary resident only, and never
     * the primary's own: they hold the household and are always a Member. Takes effect on the member's
     * next request (`EnforceAccessLevel` reads it fresh); nobody is signed out. Audited.
     */
    public function setMemberLevel(int $member, Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $actor = $this->primaryOnly($home['resident'], 'change the access of');
        $target = $this->memberOf($home, $member);

        $level = (string) $request->validate([
            'access_level' => ['required', 'string', 'in:'.implode(',', AppMatrix::LEVELS_ALL)],
        ])['access_level'];

        if ($target->is_primary) {
            throw ApiError::conflict('primary_is_member', AccessLevels::PRIMARY_IS_MEMBER);
        }

        $this->levelMayBeGiven($level);

        AccessLevels::set($target, $level, (string) $actor->full_name, null, 'resident_app', $actor->id, $home['account']->id);

        return response()->json($this->memberShape($target->refresh()));
    }

    /**
     * `409 access_levels_not_open` for a level other than Member while levels are not open yet
     * (`AccessLevels::open`) — before anything is written or sent, so the refusal means nothing happened.
     */
    private function levelMayBeGiven(string $level): void
    {
        if (! AccessLevels::mayGive($level)) {
            throw ApiError::conflict('access_levels_not_open', AccessLevels::NOT_OPEN);
        }
    }

    /**
     * A member of THIS household, or the same 404 an id nobody has gets.
     *
     * @param  array{household: Household}  $home
     */
    private function memberOf(array $home, int $member): Resident
    {
        $target = $home['household']->residents()->whereKey($member)->first();

        if (! $target instanceof Resident) {
            throw ApiError::notFound('not_found', 'No such household member.');
        }

        return $target;
    }

    /**
     * The primary resident removes a member of THEIR household — what that does is
     * `HouseholdMembers::remove`'s, the one the estate office's command uses too.
     *
     * Another household's member and an id nobody has are the same 404.
     */
    public function removeMember(int $member, DeviceContext $context, HouseholdMembers $members): JsonResponse
    {
        $home = $this->accounts->home($context);
        $actor = $this->primaryOnly($home['resident'], 'remove');

        $target = $home['household']->residents()->whereKey($member)->first();

        if (! $target instanceof Resident) {
            throw ApiError::notFound('not_found', 'No such household member.');
        }

        if ($target->is_primary || $target->is($actor)) {
            throw ApiError::conflict('primary_stays', 'The primary resident cannot be removed in the app. To hand the household over or leave it, contact the estate office.');
        }

        $members->remove($target, (string) $actor->full_name, $actor, [
            'by_account_id' => $home['account']->id,
            'via' => 'resident_app',
        ]);

        return response()->json(['id' => $target->id, 'removed' => true]);
    }

    /**
     * Adding and removing members is the PRIMARY resident's right (M4 §3.5): household
     * members cannot invite, and cannot remove one another.
     */
    private function primaryOnly(?Resident $resident, string $verb): Resident
    {
        if (! $resident instanceof Resident || ! $resident->is_primary) {
            throw ApiError::forbidden('not_primary', 'Only the household\'s primary resident can '.$verb.' household members. Ask them to do it, or contact the estate office.');
        }

        return $resident;
    }

    public function vehicles(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->orderBy('plate')->get()
            ->map(fn (object $v): array => $this->vehicleShape($v))->all()]);
    }

    public function addVehicle(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
            // Required since 23 September 2026 (the client's instruction). A guard matching the car in
            // front of them against the register cannot do it on a plate alone, and the column stays
            // nullable because rows registered before this date have nothing to backfill from.
            'make' => ['required', 'string', 'max:40'],
            'model' => ['required', 'string', 'max:40'],
            'colour' => ['required', 'string', 'max:24'],
        ], [
            'make.required' => "Enter the vehicle's make — a guard checks it against the car at the gate.",
            'model.required' => "Enter the vehicle's model.",
            'colour.required' => "Enter the vehicle's colour.",
        ]);

        $plate = strtoupper(preg_replace('/\s+/', ' ', trim($data['plate'])) ?? '');
        $table = DB::connection('tenant')->table('household_vehicles');
        $existing = (clone $table)->where('household_id', $home['household']->id)->where('plate', $plate)->first();

        $id = $existing->id ?? $table->insertGetId([
            'household_id' => $home['household']->id,
            'plate' => $plate,
            'make' => $data['make'],
            'model' => $data['model'],
            'colour' => $data['colour'],
            'added_by_account_id' => $home['account']->id,
            'is_simulated' => $request->boolean('simulated'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->vehicleShape(DB::connection('tenant')->table('household_vehicles')->where('id', $id)->first()), $existing === null ? 201 : 200);
    }

    public function contacts(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->orderBy('name')->get()
            ->map(fn (object $c): array => $this->contactShape($c))->all()]);
    }

    public function addContact(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        // NOTHING IS OPTIONAL (M18C): a panic emails every contact at once and hands their number to the dispatcher.
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'relationship' => ['required', 'string', 'min:2', 'max:40'],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'email' => ['required', 'string', 'max:190', 'email:rfc', new ReceivesMail],
        ]);

        $data['phone'] = (string) JamaicanNumber::normalise($data['phone']);

        /*
         * A NUMBER ALREADY ON THE LIST IS ANSWERED WITH THE ROW ALREADY THERE (DESIGN §14). This inserted on every
         * call, and the user's household listed the same spouse four times — which a panic then texted four times,
         * leaving a fifth person untexted. Compared as a diallable number, however it was typed, inside a lock on
         * the household so two phones saving at once cannot both add it. 200 with `already_listed`, as a plate
         * already registered is answered. (Fully qualified: this hunk stays inside this method, for the merge.)
         */
        [$row, $added] = DB::connection('tenant')->transaction(function () use ($home, $data): array {
            Household::query()->whereKey($home['household']->id)->lockForUpdate()->first();

            $table = DB::connection('tenant')->table('household_emergency_contacts');
            $key = EmergencyContactMerge::numberKey($data['phone']);
            // A number with no digits in it is nobody's in particular: it is never answered with another row.
            $listed = ! EmergencyContactMerge::comparable($key) ? null : (clone $table)->where('household_id', $home['household']->id)->orderBy('id')->get()
                ->first(static fn (object $contact): bool => EmergencyContactMerge::numberKey((string) $contact->phone) === $key);

            if ($listed !== null) {
                // Already listed: an address typed now for a contact who had none is kept, never thrown away.
                $email = self::emailOf($data['email'] ?? null);

                if ($email !== null && ($listed->email ?? null) === null) {
                    (clone $table)->where('id', $listed->id)->update(['email' => $email, 'updated_at' => Carbon::now()]);
                    $listed = (clone $table)->where('id', $listed->id)->first();
                }

                return [$listed, false];
            }

            $id = $table->insertGetId([
                'household_id' => $home['household']->id,
                'name' => $data['name'],
                'relationship' => $data['relationship'] ?? null,
                'phone' => $data['phone'],
                'email' => self::emailOf($data['email'] ?? null),
                'added_by_account_id' => $home['account']->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return [DB::connection('tenant')->table('household_emergency_contacts')->where('id', $id)->first(), true];
        });

        return response()->json([...$this->contactShape($row), 'already_listed' => ! $added], $added ? 201 : 200);
    }

    /**
     * Change one of the household's emergency contacts (M12 §2.5): an email added to a contact saved before emails
     * were asked for, a new number, a corrected name. The household's own rows only; a number already on the list
     * for ANOTHER contact is refused, the store's own rule (DESIGN §14). Audited, masked.
     */
    public function updateContact(Request $request, int $contact, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        // Sent only when changed, and never blanked (M18C).
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'relationship' => ['sometimes', 'required', 'string', 'min:2', 'max:40'],
            'phone' => ['sometimes', 'required', 'string', 'max:40', new PhoneNumber],
            'email' => ['sometimes', 'required', 'string', 'max:190', 'email:rfc', new ReceivesMail],
        ]);

        if (isset($data['phone'])) {
            $data['phone'] = (string) JamaicanNumber::normalise($data['phone']);
        }

        $table = DB::connection('tenant')->table('household_emergency_contacts');
        $row = (clone $table)->where('household_id', $home['household']->id)->where('id', $contact)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'Not one of your household\'s emergency contacts.');
        }

        if (isset($data['phone'])) {
            $key = EmergencyContactMerge::numberKey($data['phone']);
            $taken = EmergencyContactMerge::comparable($key) && (clone $table)->where('household_id', $home['household']->id)->where('id', '!=', $contact)->get(['phone'])
                ->contains(static fn (object $other): bool => EmergencyContactMerge::numberKey((string) $other->phone) === $key);

            if ($taken) {
                throw ApiError::conflict('already_listed', 'That number is already on your list for another emergency contact.');
            }
        }

        $changes = array_intersect_key($data, ['name' => true, 'relationship' => true, 'phone' => true]);

        if (array_key_exists('email', $data)) {
            $changes['email'] = self::emailOf($data['email']);
        }

        if ($changes !== []) {
            (clone $table)->where('id', $contact)->update([...$changes, 'updated_at' => Carbon::now()]);

            rescue(fn () => app(AuditLogger::class)->record(
                action: 'household.emergency_contact_updated',
                entityType: 'HouseholdEmergencyContact',
                entityId: (string) $contact,
                after: [
                    'fields' => array_keys($changes),
                    'relationship' => $changes['relationship'] ?? $row->relationship,
                    'email' => isset($changes['email']) ? AlertEmailDispatch::mask((string) $changes['email']) : null,
                ],
            ), report: false);
        }

        return response()->json($this->contactShape((clone $table)->where('id', $contact)->first()));
    }

    /** An address as it is kept: trimmed, and null for nothing at all. */
    private static function emailOf(mixed $email): ?string
    {
        $email = is_string($email) ? trim($email) : '';

        return $email === '' ? null : $email;
    }

    /** @return array<string, mixed> */
    private function profile(DeviceContext $context): array
    {
        $account = $context->residentOrFail();
        $unit = $account->unit_id === null ? null : Unit::query()->with('household')->find($account->unit_id);
        $resident = $account->resident_id === null ? null : Resident::query()->find($account->resident_id);
        $claim = $account->claim_id === null ? null : UnitClaim::query()->find($account->claim_id);

        return [
            'account' => [
                'id' => $account->id,
                'status' => $account->status,
                'channel' => $account->channel,
                'destination_hint' => ResidentSignIn::mask($account->destination),
                'full_name' => $account->full_name,
                // The profile photo (26 Sep): where the app fetches it with its own token, versioned; null with none.
                'photo' => AccountPhotos::shape($account),
                // What the ACCOUNT has been shown once (M47 §3): it holds on every phone, after a sign-out and a reinstall.
                'shown' => ShownOnce::shape($account),
            ],
            // `timezone`: every time this API sends is a UTC instant; this IANA zone is the one to SHOW them in (M4 §3.1).
            'estate' => [
                'id' => $context->tenantId,
                'name' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
                'timezone' => SiteTime::zone($context->tenantId),
            ],
            'claim' => ResidentAccounts::claimShape($claim),
            'unit' => $unit === null ? null : ['id' => $unit->id, 'reference' => $unit->reference, 'phase' => $unit->block],
            'household' => $unit?->household === null ? null : ['id' => $unit->household->id, 'name' => $unit->household->name],
            'resident' => $resident === null ? null : $this->memberShape($resident),

            // What this person may do, from the matrix the server enforces; null while pending (nothing is reachable then).
            'access' => $account->isActive() ? AccessLevels::mayShape($resident, $unit?->household) : null,

            /*
             * WHAT IS STILL MISSING FROM THEIR OWN RECORD (M18C §5): `phone`, `email`, both or neither. A record saved
             * before every field was required is never locked out for it — the app asks once, politely, on sign-in,
             * may be dismissed, and asks again next time. A panic is never blocked by it. Empty with no register entry.
             */
            'missing_details' => self::missingDetails($resident),

            /*
             * DRILL MODE (M53 §3A.1): while the office has it on for this account, its Panic is a drill that wakes nobody.
             * The phone says so on the Panic screen, so the person pressing it knows; null when it is off. Only the
             * server sets it (`panic:drill-mode`), and it ends by itself.
             */
            'drill_mode' => PanicDrillMode::shape($account),

            // What "Delete my account" says before anything happens (M18H §2.1): this estate's two windows, in days.
            'account_deletion' => (static function () use ($context): array {
                $windows = AccountDeletion::windowsFor($context->tenantId);

                return ['self_days' => $windows['self'], 'office_days' => $windows['office']];
            })(),
        ];
    }

    /** @return list<string> */
    public static function missingDetails(?Resident $resident): array
    {
        if ($resident === null) {
            return [];
        }

        return array_values(array_filter([
            trim((string) $resident->phone) === '' ? 'phone' : null,
            trim((string) $resident->email) === '' && ! (bool) $resident->getAttribute('alerts_to_primary') ? 'email' : null,
        ]));
    }

    /**
     * @param  array<int, ResidentInvite>|null  $invites  `ResidentInvites::latestForEach()` for a list; null reads this member's own
     * @param  Resident|null  $viewer  who is reading, where it is not the primary resident's own write (row 20)
     * @return array<string, mixed>
     */
    private function memberShape(Resident $r, ?array $invites = null, ?Resident $viewer = null): array
    {
        $hidden = self::hidesContact($r, $viewer);

        return [
            'id' => $r->id,
            'full_name' => $r->full_name,
            'relationship' => $r->relationship,
            'is_primary' => (bool) $r->is_primary,
            'status' => $r->status,
            'phone' => $hidden ? null : $r->phone,
            'email' => $hidden ? null : $r->email,
            // "This person has no email — alerts go to me instead" (M18C §3.2): the primary resident is who their alerts reach.
            'alerts_to_primary' => (bool) $r->getAttribute('alerts_to_primary'),
            'access_level' => AccessLevels::of($r),
            // A Helper's days, hours and end date, and whether the household paused them (8 Oct 2026) — null otherwise.
            'access_window' => self::windowShape($r),
            // What became of their invite, in one shape the app draws as it is (`ResidentInvites::outcome`).
            'invite' => ResidentInvites::outcome($invites === null ? $this->invites->latestFor($r) : ($invites[(int) $r->id] ?? null)),
        ];
    }

    /** @return array{days: list<int>, from: string, to: string, until: string|null, paused: bool, own: bool, line: string}|null */
    private static function windowShape(Resident $r): ?array
    {
        if (AccessLevels::of($r) !== AppMatrix::HELPER) {
            return null;
        }

        $window = AccessRules::window($r);

        return [...$window, 'line' => $window['paused'] ? 'Paused by the household' : AccessRules::windowLine($window)];
    }

    /**
     * A Helper's days, hours and end date, and "pause now" (the user, 8 Oct 2026) — the PRIMARY resident only, for a
     * Helper of their household. Paused, or past their end date, their e-Pass is cancelled at once — every gate refuses
     * it in a cancelled pass's own words — and no new one is issued until they are resumed. Audited.
     */
    public function setMemberAccess(int $member, Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $actor = $this->primaryOnly($home['resident'], 'change the hours of');
        $target = $this->memberOf($home, $member);

        if (AccessLevels::of($target) !== AppMatrix::HELPER) {
            throw ApiError::conflict('not_a_helper', 'Days and hours are for a Helper. Change their access to Helper first.');
        }

        $data = $request->validate([
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['integer', 'between:1,7'],
            'from' => ['required', 'string', 'max:5'],
            'to' => ['required', 'string', 'max:5'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'paused' => ['required', 'boolean'],
        ], ['days.required' => 'Choose at least one day they may come.', 'days.min' => 'Choose at least one day they may come.']);

        $from = AccessRules::clock($data['from']);
        $to = AccessRules::clock($data['to']);

        if ($from === null || $to === null || $from === $to) {
            throw ApiError::unprocessable('hours_invalid', 'Choose the time they may come from and until, on the half hour — not the same time.');
        }

        $before = AccessRules::window($target);
        $paused = (bool) $data['paused'];

        $target->forceFill([
            'access_days' => AccessRules::days($data['days']),
            'access_from' => $from,
            'access_to' => $to,
            'access_until' => $data['until'] ?? null,
            'access_paused_at' => $paused ? ($target->access_paused_at ?? now()) : null,
        ])->save();

        $after = AccessRules::window($target);
        $today = SiteTime::local(now(), $context->tenantId)->toDateString();

        // Blocked now: whatever e-Pass they hold is refused at every gate from this moment.
        if ($after['paused'] || ($after['until'] !== null && $after['until'] < $today)) {
            app(ResidentPassCancellation::class)->cancelFor((int) $target->id);
        }

        app(AuditLogger::class)->record(
            action: 'household.helper_access_changed',
            entityType: 'Resident',
            entityId: (string) $target->id,
            before: ['window' => $before],
            after: ['window' => $after, 'by_resident_id' => $actor->id, 'by_account_id' => $home['account']->id],
            tenantId: $context->tenantId,
            actorName: (string) $actor->full_name,
        );

        return response()->json($this->memberShape($target->refresh()));
    }

    /** @return array<string, mixed> */
    private function vehicleShape(object $v): array
    {
        return [
            'id' => (int) $v->id,
            'plate' => (string) $v->plate,
            'make' => $v->make,
            'model' => $v->model,
            'colour' => $v->colour,
        ];
    }

    /** @return array<string, mixed> */
    private function contactShape(object $c): array
    {
        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'relationship' => $c->relationship,
            'phone' => (string) $c->phone,
            'email' => isset($c->email) && $c->email !== '' ? (string) $c->email : null,
        ];
    }
}
