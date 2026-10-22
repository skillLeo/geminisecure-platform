<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Http\Controllers\Controller;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\ResidentAccount;
use App\Rules\PhoneNumber;
use App\Rules\ReceivesMail;
use App\Services\Estate\DeletedAccounts;
use App\Services\Estate\Dues;
use App\Services\Estate\HouseholdChoiceNeeded;
use App\Services\Estate\HouseholdRemoval;
use App\Services\Estate\InviteAddressRefused;
use App\Services\Estate\MissingContactDetails;
use App\Services\Estate\ResidentImport;
use App\Services\Estate\ResidentInvites;
use App\Services\Estate\Residents;
use App\Services\ResidentApp\RevokeResidentAccess;
use App\Support\EstatePhone;
use App\Support\JamaicanNumber;
use App\Support\SiteTime;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Residents, unit claims and the register — board screens 4, 31, 34 and 38.
 *
 * NO SCREEN HERE PRINTS A RESTRICTED HOUSEHOLD'S BALANCE. Boards 4 and 38 both
 * draw a figure where a household's standing goes and board 31 draws an ageing
 * bucket, and all three read it out of `Residents::standingOf()` — which returns
 * amber and D-025's wording for a restricted household and no amount, no bucket
 * and no number of days. One function, one place to audit; three payload
 * builders each deciding for themselves is three places to forget.
 *
 * WHETHER MONEY MAY BE SEEN IS DECIDED HERE AND PASSED DOWN. `estate.residents`
 * and `estate.dues_ledger` are different modules on purpose: the Property
 * Manager holds Full on the first and nothing at all on the second (D-010), so a
 * resident detail screen that printed a balance would be a side door into
 * exactly the financial position that ruling closes. The service is told the
 * answer rather than asking the container for it, because a service that read
 * the permission itself would be a second copy of the route matrix.
 *
 * APPROVING A CLAIM NEEDS `approve`, NOT `update` (D-013). It binds a person to
 * a household — whose guest passes they may issue, whose gate they may be
 * admitted at — and no edit afterwards unbinds the night somebody was let
 * through. Refusing a claim and asking for a document are both `update`: each is
 * reversible by making the opposite decision, and neither authorises anybody.
 * The gates are on the routes; a controller that checked them again would be a
 * second place to keep in step with the matrix.
 */
class ResidentsController extends Controller
{
    /**
     * What a missing contact detail says, beside its field (M18C §6), with this estate's own example number (M32 F7).
     *
     * @return array<string, string>
     */
    private static function contactMessages(): array
    {
        return [
            'phone.required' => 'Enter a phone number: the dispatcher calls it in an emergency. Example: '.EstatePhone::forEstate()['local'].'.',
            'email.required' => 'Enter an email: a panic emails the household at once. Or tick “This person has no email”.',
            'email.prohibited' => 'Untick “This person has no email” to give an email address.',
        ];
    }

    private const NO_LEDGER_LINK_YET = 'Dues & ledger is a separate module. A role that may read this register is not thereby allowed a resident\'s financial position.';

    /** The register — board community-admin-04. */
    public function index(Request $request, Residents $residents, ResidentInvites $invites): Response
    {
        /*
         * Every bit of list state lives in the query string — phase, search,
         * sort, direction, page — so the register is linkable, correct under
         * the back button, and reproducible in a bug report. The service
         * whitelists `sort` against its own map and reads the filters back
         * out in the payload, so a made-up column does not merely fail to
         * sort: it is gone by the time the query or the page sees it.
         */
        /*
         * TWO VIEWS OF ONE REGISTER (M32 F1). People, the default: every resident, one row each, so a person who exists
         * is on this page. Households, the second tab: the list board 4 draws, one row a household, its balance beside
         * it. `?view=households` chooses it; the search, the phase and the sort apply to whichever is open.
         */
        $view = $request->string('view')->toString() === 'households' ? 'households' : 'people';

        // M32 F5: the sort, its way, the page, the page size and the search, read from the address by one rule.
        $board = $view === 'households'
            ? $residents->listBoard(
                maySeeMoney: $this->maySeeMoney($request),
                phase: $request->string('phase')->toString(),
                table: Residents::householdsTable($request),

                // M10 §5: every household's balance on one register is the
                // estate's position, which the ledger alone does not grant.
                maySeePosition: Dues::maySeeEstatePosition($request->user()),
            )
            : [
                // What the page around the table needs whichever view is open: the claims banner and the phase chips.
                ...$residents->registerFrame(),
                ...$residents->peopleBoard(
                    phase: $request->string('phase')->toString(),
                    table: Residents::peopleTable($request),
                ),
                // People carry no balance: a person is not an account; the household's is on the Households tab.
                'money_visible' => false,
                'money_reason' => null,
            ];

        return inertia('Estate/Residents/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'view' => $view,
            ...$board,

            // Under a pending household's badge: what became of its invite (stage 2, DESIGN §7.2). A person's own row
            // on the People tab carries their state already.
            'inviteStates' => $view === 'households' ? $this->inviteStates($board['rows'], $invites) : [],

            // The Deleted accounts tab's badge (M18H §6).
            'deletedAccounts' => app(DeletedAccounts::class)->count((string) tenant()->getTenantKey()),
            'canCreate' => $request->user()->can('estate.residents.create'),
            'canReview' => $request->user()->can('estate.residents.approve'),
            'blockedReason' => 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',

            /*
             * ADD RESIDENT OPENS IN A MODAL OVER THE REGISTER (Hassam, 11 Oct: "Add resident opens the form inline on
             * the page. It must open in a modal."). The form's board, and each lookup of the unit as it is typed, is read
             * only when the modal asks for it (a partial reload of `addResident`, the phase and unit as `add_phase` and
             * `add_lot` so they never touch the register's own phase filter), so opening the register costs nothing
             * more. The same board `/residents/new` is drawn from; the save is the same `store`, behind `create`.
             */
            'addResident' => Inertia::optional(fn (): array => [
                ...$residents->newResidentBoard(
                    $request->string('add_phase')->toString(),
                    $request->string('add_lot')->toString(),
                    self::lotQuestion($request),
                    $this->maySeeMoney($request),
                ),
                'canCreate' => $request->user()->can('estate.residents.create'),
                'blockedReason' => 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
            ]),

            // The bulk import's preview, while the reader is between its two presses (M98 census).
            'residentImport' => $this->importPreview($request),
        ]);
    }

    /**
     * The previewed import named by `?import=`, or null: the first 200 rows, every problem, and the counts.
     *
     * @return array<string, mixed>|null
     */
    private function importPreview(Request $request): ?array
    {
        $token = (string) $request->query('import', '');
        $preview = $token === '' ? null : $request->session()->get('residents-import.'.$token);

        if (! is_array($preview) || ! $request->user()->can('estate.residents.create')) {
            return null;
        }

        return [
            'token' => $token,
            'file' => $preview['file'],
            'rows' => array_slice($preview['rows'], 0, 200),
            'row_count' => count($preview['rows']),
            'errors' => $preview['errors'],
            'people' => $preview['people'],
            'households' => $preview['households'],
            'valid' => $preview['valid'],
        ];
    }

    /**
     * BULK IMPORT, STEP ONE (M98 census): read the file, check every row against the register as it stands, show the
     * preview. Nothing is written. Behind `create`, the permission Add resident is behind.
     */
    public function previewImport(Request $request, ResidentImport $import): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt'],
        ], [
            'file.required' => 'Choose a CSV file.',
            'file.mimes' => 'The file is a CSV: a spreadsheet saved as comma-separated values.',
            'file.max' => 'The file is larger than 2 MB. Split it and import each part.',
        ]);

        $file = $request->file('file');
        $token = Str::random(20);

        $request->session()->put('residents-import.'.$token, [
            ...$import->parse((string) $file->get()),
            'file' => $file->getClientOriginalName(),
        ]);

        return redirect()->to($this->path('/residents?import='.$token));
    }

    /** Step two: write the previewed people, all of them or none, as Pending, with no invite sent. */
    public function commitImport(Request $request, ResidentImport $import): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:20']]);
        $preview = $request->session()->get('residents-import.'.$data['token']);

        if (! is_array($preview)) {
            return back()->withErrors(['import' => 'That preview has expired. Choose the file again.']);
        }

        if (! $preview['valid']) {
            return back()->withErrors(['import' => 'Some rows have problems. Fix the file and preview it again.']);
        }

        try {
            $result = $import->import($preview['rows'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['import' => $refused->getMessage()]);
        }

        $request->session()->forget('residents-import.'.$data['token']);

        return redirect()
            ->to($this->path('/residents'))
            ->with('success', sprintf(
                '%d %s added to the register as Pending, %d new household%s. No invite has been sent: send each from the lot\'s page.',
                $result['people'],
                $result['people'] === 1 ? 'person' : 'people',
                $result['households'],
                $result['households'] === 1 ? '' : 's',
            ));
    }

    /**
     * HOUSEHOLDS WITH MISSING CONTACT DETAILS (M18C §5) — the Property Manager's chase list. Every field a person fills
     * in became required on 27 September; a record saved before then with a blank is somebody a panic cannot reach.
     */
    public function missingContacts(Request $request, MissingContactDetails $missing): Response
    {
        $households = $missing->households();

        // The whole list, ordered and cut to the page its headings ask for (M32 F5); the counts are the whole list's.
        $table = MissingContactDetails::table($request);
        $page = $table->pageOf($households, 'household_id');

        return inertia('Estate/Residents/MissingContacts', [
            'estate' => ['name' => (string) tenant()->name],
            'households' => array_values($page->items()),
            'table' => $table->meta($page),
            'people' => array_sum(array_map(static fn (array $h): int => count($h['people']), $households)),
        ]);
    }

    /** Unit claim review — board community-admin-31. */
    public function claims(Request $request, Residents $residents): Response
    {
        $claims = $residents->claimsBoard($this->maySeeMoney($request), Dues::maySeeEstatePosition($request->user()), Residents::claimsTable($request));

        return inertia('Estate/Residents/Claims', [
            'estate' => ['name' => (string) tenant()->name],
            ...$claims,

            // Category B (13 C3): a resident claims a unit from the Resident App (13 D3).
            'sourceBadge' => SourceBadge::resident(SourceBadge::anySimulatedIn('tenant', 'unit_claims', array_column($claims['claims'], 'id'))),

            /*
             * TWO FLAGS AND THEY ARE NOT THE SAME ONE. Approving binds a person
             * to a household and is `approve`; refusing one and asking for a
             * document are `update`. A screen that drew both off one flag would
             * hand the irreversible act to every role that holds the reversible
             * ones — which is the collapse D-013 exists to prevent.
             */
            'canApprove' => $request->user()->can('estate.residents.approve'),
            'canReview' => $request->user()->can('estate.residents.update'),
            'blockedReason' => 'Approving a claim binds a person to a household and needs Residents approve access. You are able to read this screen and to ask for a document.',
        ]);
    }

    /** Add resident — board community-admin-34. */
    public function create(Request $request, Residents $residents): Response
    {
        return inertia('Estate/Residents/New', [
            'estate' => ['name' => (string) tenant()->name],

            // The phase chosen and the unit typed so far, which the page sends as they change (M11 A2),
            // and the number the page gave that lookup, handed back with the answer.
            ...$residents->newResidentBoard(
                $request->string('phase')->toString(),
                $request->string('lot')->toString(),
                self::lotQuestion($request),
                // M32 F1: the balance of a household the person would join, in the words this viewer may read.
                $this->maySeeMoney($request),
            ),
            'canCreate' => $request->user()->can('estate.residents.create'),
            'blockedReason' => 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this screen.',
        ]);
    }

    /**
     * The add-resident page's number for the unit lookup this request is (M11 A2).
     *
     * In a header, not the query string, so the page's address stays the phase and
     * the unit typed. Anything but a plain number is no question at all: the value
     * is only ever handed back to the page, and never read for anything else.
     */
    private static function lotQuestion(Request $request): ?int
    {
        $question = (string) $request->header('X-Lot-Question', '');

        return preg_match('/^\d{1,9}$/', $question) === 1 ? (int) $question : null;
    }

    /** One household in full — board community-admin-38. */
    public function show(Request $request, string $unit, Residents $residents): Response
    {
        $found = $this->resolveUnit($unit);

        return inertia('Estate/Residents/Show', [
            'estate' => ['name' => (string) tenant()->name],
            // M32 F1: `?household=` picks one household of a lot that holds more than one; without it, the latest.
            ...$residents->residentBoard($found, $this->maySeeMoney($request), $request->integer('household') ?: null),

            // What this page's live events are named by (M18H §6): the register's and the dues' are the unit's.
            'unitId' => (int) $found->getKey(),

            /*
             * The "View ledger" action is drawn only for a role that holds the
             * ledger. Absent rather than disabled, exactly as the sidebar
             * treats a module a role cannot reach — a greyed link to a
             * resident's financial position still tells somebody there is one.
             */
            'canViewLedger' => $this->maySeeMoney($request),
            'canEdit' => $request->user()->can('estate.residents.update'),
            // One line, the fact, nothing about permissions (M53 Laws 1 and 2; M78 took "You are able to read" out elsewhere).
            'editBlockedReason' => 'Correcting a record is not part of your role.',

            /*
             * Q-M44. The same permission that corrects a register row, and a flag of its
             * own so the screen names the act it is gating rather than borrowing another
             * one's — if this ever moves to its own permission, one line here moves with it.
             */
            'canEndAppAccess' => $request->user()->can('estate.residents.update'),
            // M21 §3.1: removing the household is the same permission, for every member at once.
            'canRemoveHousehold' => $request->user()->can('estate.residents.update'),
            'appAccessBlockedReason' => 'Ending app access is not part of your role.',

            // The same permission Add resident sends the first invite under.
            'canInvite' => $request->user()->can('estate.residents.create'),
            'inviteBlockedReason' => 'Inviting is not part of your role.',

            // What each person may do in the Resident App — Member, Dependent, Helper — and who may change it.
            'accessLevels' => AccessLevels::options(),
            'canChangeLevel' => $request->user()->can('estate.residents.update'),
            'levelBlockedReason' => 'Changing a level is not part of your role.',
            // Whether anybody may be given less than Member yet (`AccessLevels::open`) — and why not, when not.
            'levelsOpen' => AccessLevels::open(),
            'levelsClosedReason' => AccessLevels::NOT_OPEN,
            // Message household is the office's own email now (Show.vue); the old "Deferred" sentence said it did not work.
            'reasons' => [
                'ledger' => self::NO_LEDGER_LINK_YET,
            ],
        ]);
    }

    /**
     * Correct a resident's record — board 38's "Edit details" (12 §2, Wave 2).
     *
     * IT EDITS WHO SOMEBODY IS, NOT WHETHER THEY ARE AUTHORISED. Verification
     * stays where the decision is made — the claims queue, or an officer
     * vouching on the add-resident screen — both of which record who decided
     * and when. A status editable from a details form would let somebody
     * verify themselves with no decision behind it.
     */
    public function editResident(Request $request, string $unit, int $resident, Residents $residents): RedirectResponse
    {
        $found = $this->resolveUnit($unit);

        $person = Resident::query()
            ->whereKey($resident)
            ->whereHas('household', static fn ($query) => $query->where('unit_id', $found->id))
            ->first();

        if ($person === null) {
            return back()->withErrors(['full_name' => 'That person is not on this household.']);
        }

        // Nothing is optional (M18C): a number and an email — or, for somebody with no email, alerts to the primary.
        $noEmail = $request->boolean('no_email');

        $fields = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => $noEmail ? ['nullable', 'prohibited'] : ['required', 'email', 'max:160', new ReceivesMail],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'no_email' => ['nullable', 'boolean'],
            'relationship' => ['required', 'string', 'max:40'],
            'moved_in_on' => ['nullable', 'date'],
            'is_primary' => ['nullable', 'boolean'],
        ], self::contactMessages());

        $fields['is_primary'] = $request->boolean('is_primary');
        $fields['phone'] = JamaicanNumber::normalise($fields['phone']);
        $fields['email'] = $noEmail ? null : $fields['email'];
        unset($fields['no_email']);

        if ($noEmail && $fields['is_primary']) {
            return back()->withErrors(['email' => 'The primary resident is who everybody else\'s alerts reach, so they need an email of their own.'])->withInput();
        }

        try {
            $residents->edit($person, $fields, $request->user());
            $person->forceFill(['alerts_to_primary' => $noEmail])->save();
        } catch (DomainException $refused) {
            return back()->withErrors(['full_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $person->full_name.'\'s record updated. Their verification is unchanged: that is decided where a claim is reviewed, not here.');
    }

    /**
     * END A PERSON'S RESIDENT APP ACCESS — Q-M44, board 38.
     *
     * The office's act, and the only one in the product that takes an app account away
     * from somebody who has moved out. It is its own route with its own reason field on
     * purpose: nothing here happens as a consequence of saving the edit form above.
     *
     * The register entry is NOT touched. "Moved out" and "locked out" are different
     * facts and one of them is often wrong for a week — the office edits the register
     * separately, where that decision carries a name and a date of its own.
     */
    public function revokeAppAccess(Request $request, string $unit, int $resident, RevokeResidentAccess $access): RedirectResponse
    {
        $found = $this->resolveUnit($unit);
        $person = $this->personAt($found, $resident);

        if ($person === null) {
            return back()->withErrors(['reason' => 'That person is not on this household.']);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:190'],
        ]);

        $account = $this->accountFor($person);

        if ($account === null) {
            return back()->withErrors(['reason' => $person->full_name.' has no Resident App account at this estate, so there is no access to end.']);
        }

        try {
            $access->revoke($account, $data['reason'], (string) $request->user()->name);
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $person->full_name.'\'s app access has ended. Every phone is signed out, no notification can reach them, and their e-pass is cancelled. At the gate they are a visitor now and go to the Visitor Entrance. '.
                $person->full_name.' is still on the register, and this household\'s gate history and accounts are unchanged.');
    }

    /**
     * REMOVE THE HOUSEHOLD FROM ITS LOT (M21 §3.1) — a family has moved out. Every member's app account is deleted as
     * the office deletes one (recoverable for the estate's office window), every member leaves the register, unused
     * passes and future bookings are cancelled, and the lot reads as vacant. The ledger and the gate log keep every row.
     * The lot's reference is typed to confirm, and a reason is required: it is kept in the audit trail.
     */
    public function removeHousehold(Request $request, string $unit, HouseholdRemoval $removal): RedirectResponse
    {
        $found = $this->resolveUnit($unit);

        /*
         * THE HOUSEHOLD ON THE PAGE, not the lot's first (M32 F1). A lot can hold more than one household now, and the
         * page sends the id of the one it shows; without one, the lot's latest, which is what the page shows by default.
         */
        $household = $request->filled('household_id')
            ? Household::query()->where('unit_id', $found->id)->whereKey($request->integer('household_id'))->first()
            : $found->household;

        if (! $household instanceof Household) {
            return back()->withErrors(['remove' => 'Nobody is living at '.$found->reference.' on the register, so there is no household to remove.']);
        }

        $data = $request->validate([
            'confirm_lot' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ], [
            'confirm_lot.required' => 'Type the lot to confirm.',
            'reason.required' => 'Say why the household is being removed; it is kept in the audit trail.',
            'reason.min' => 'Say why in a sentence; it is kept in the audit trail.',
        ]);

        if (mb_strtolower(trim($data['confirm_lot'])) !== mb_strtolower(trim((string) $found->reference))) {
            return back()->withErrors(['confirm_lot' => 'That is not this lot. Type it exactly as shown: '.$found->reference.'.'])->withInput();
        }

        try {
            $done = $removal->remove($household, $request->user(), (string) $data['reason']);
        } catch (DomainException $refused) {
            return back()->withErrors(['remove' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/residents/'.$found->slug()))
            ->with('success', $household->name.' is removed from '.$found->reference.' (reference '.$done['reference'].'). '.
                ($done['accounts'] === 1 ? '1 app account was' : $done['accounts'].' app accounts were').' deleted and '.
                ($done['residents'] === 1 ? '1 person left' : $done['residents'].' people left').' the register; unused passes and future bookings are cancelled. '.
                'What the lot owes and its gate history are unchanged. The office can restore the household from Residents → Deleted accounts until '.Carbon::parse($done['restore_until'])->format('j M Y').'.');
    }

    /** Give it back. People come back, and a wrong row is picked more often than anybody admits. */
    public function restoreAppAccess(Request $request, string $unit, int $resident, RevokeResidentAccess $access): RedirectResponse
    {
        $found = $this->resolveUnit($unit);
        $person = $this->personAt($found, $resident);

        if ($person === null) {
            return back()->withErrors(['reason' => 'That person is not on this household.']);
        }

        $account = $this->accountFor($person);

        if ($account === null) {
            return back()->withErrors(['reason' => $person->full_name.' has no Resident App account at this estate.']);
        }

        try {
            $status = $access->restore($account, (string) $request->user()->name);
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $status === ResidentAccount::ACTIVE
                ? $person->full_name.'\'s app access is back. They sign in again on their own phone; their e-pass is issued fresh the first time they open it.'
                : $person->full_name.'\'s app access is back, and the account is waiting on a unit claim again: approve it in the claims queue.');
    }

    /**
     * A household's page (M32 F1): the lot's, naming the household when the lot holds more than one and it is not the
     * latest, which the page shows when it is not told which. Every act on a person comes back to that person's page.
     */
    private function householdPage(Unit $unit, ?int $householdId = null): string
    {
        $latest = $unit->households()->max('id');

        return $this->path('/residents/'.$unit->slug().($householdId !== null && $latest !== null && $householdId !== (int) $latest ? '?household='.$householdId : ''));
    }

    /** One person at this unit, or null. Somebody else's household and an unknown id are the same answer. */
    private function personAt(Unit $unit, int $resident): ?Resident
    {
        return Resident::query()
            ->whereKey($resident)
            ->whereHas('household', static fn ($query) => $query->where('unit_id', $unit->id))
            ->first();
    }

    /**
     * This person's Resident App account at THIS estate.
     *
     * A deleted account is not one: the person destroyed it themselves and there is no
     * access left to end. Central table, estate-scoped by `tenant_id`.
     */
    private function accountFor(Resident $person): ?ResidentAccount
    {
        return ResidentAccount::query()
            ->where('tenant_id', (string) tenant()->getTenantKey())
            ->where('resident_id', $person->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Put a person on the register — board 34's submit. */
    public function store(Request $request, Residents $residents, ResidentInvites $invites): RedirectResponse
    {
        // Nothing is optional (M18C). An invite goes by email, so "no email" is for somebody the office vouches for.
        $noEmail = $request->boolean('no_email');

        $data = $request->validate([
            'phase' => ['required', 'string', 'max:64'],
            'lot' => ['required', 'string', 'max:32'],
            'full_name' => ['required', 'string', 'max:160'],
            'email' => $noEmail ? ['nullable', 'prohibited'] : ['required', 'email', 'max:190', new ReceivesMail],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
            'no_email' => ['nullable', 'boolean'],
            'verification' => $noEmail ? ['required', 'string', 'in:manual'] : ['required', 'string', 'in:invite,manual'],

            /*
             * Absent means NO. `boolean` accepts a missing key as false, which
             * is the only correct reading of a consent checkbox nobody ticked —
             * and the default is off in the service as well, so a caller that
             * skipped this validator entirely still enrols nobody.
             */
            'biometric_consent' => ['nullable', 'boolean'],

            // M32 F1: what this person is at a lot that already has a household. Required there, by the service.
            'household_choice' => ['nullable', 'string', 'in:'.implode(',', array_keys(Residents::HOUSEHOLD_CHOICES))],
            // And which household, where the lot holds more than one with people in it.
            'household_id' => ['nullable', 'integer'],
        ], [
            ...self::contactMessages(),
            'verification.in' => 'Somebody with no email cannot be sent an invite. Vouch for them instead.',
        ]);

        try {
            $resident = $residents->add(
                phase: $data['phase'],
                lot: $data['lot'],
                fullName: $data['full_name'],
                email: $noEmail ? null : $data['email'],
                phone: JamaicanNumber::normalise($data['phone']),
                verification: $data['verification'],
                biometricConsent: $request->boolean('biometric_consent'),
                by: $request->user(),
                householdChoice: $data['household_choice'] ?? null,
                householdId: isset($data['household_id']) ? (int) $data['household_id'] : null,
            );

            if ($noEmail) {
                $resident->forceFill(['alerts_to_primary' => true])->save();
            }
        } catch (HouseholdChoiceNeeded $ask) {
            // M32 F1: the lot is right and already has a household; the answer that is missing is what this person is.
            return back()->withErrors(['household_choice' => $ask->getMessage()])->withInput();
        } catch (DomainException $refused) {
            // Against `lot`, because the phase came from a select and the lot is
            // the field a person can actually have got wrong.
            return back()->withErrors(['lot' => $refused->getMessage()])->withInput();
        }

        /*
         * Added, and — in its own sentence — what became of the invite, never one word that could read as "emailed".
         * ON THE HOUSEHOLD PAGE, where the invite's row is (stage 2, DESIGN §7.1): the outcome is drawn in that
         * person's row too, scrolled into view, so an invite that did not go is read where it can be sent again.
         */
        $unit = $resident->household?->unit;
        $vouched = $data['verification'] === 'manual';
        $sentence = $vouched ? 'You vouched for them, so no invite is needed.' : $this->inviteSentence($resident, $invites);

        // The state the register shows them in (seen on the local app, 12 Oct: a vouched person was "added … as Pending").
        $redirect = redirect()
            ->to($unit === null ? $this->path('/residents') : $this->householdPage($unit, (int) $resident->household_id))
            ->with('success', $resident->full_name.' added to '.($unit?->addressLabel() ?? 'the register').' as '.($vouched ? 'Active' : 'Pending').'. '.$sentence);

        if ($data['verification'] !== 'manual') {
            $redirect->with('invite_outcome', [
                'resident_id' => $resident->id,
                'state' => ResidentInvites::outcome($invites->latestFor($resident))['status'],
                'message' => $sentence,
            ]);
        }

        return $redirect;
    }

    /**
     * Send — or RESEND — a Pending resident's invite to the Resident App (the user, 24 September: "wherever there is
     * the invite option there must be resend invite"). Not faster than the timer: 1, then 2, then 5 minutes.
     */
    public function sendInvite(Request $request, string $unit, int $resident, ResidentInvites $invites): RedirectResponse
    {
        $found = $this->resolveUnit($unit);
        $person = $this->personAt($found, $resident);

        if ($person === null) {
            return back()->withErrors(['invite' => 'That person is not on this household.']);
        }

        try {
            $invites->send($person, (string) $request->user()->name, null, $request->user());
        } catch (InviteAddressRefused $refused) {
            // The office holds the register, so it reads the real reason; the app only ever gets the generic one.
            return back()->withErrors(['invite' => $refused->officeSentence]);
        } catch (DomainException $refused) {
            return back()->withErrors(['invite' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $this->inviteSentence($person, $invites));
    }

    /** Cancel a Pending resident's invite. */
    public function cancelInvite(Request $request, string $unit, int $resident, ResidentInvites $invites): RedirectResponse
    {
        $found = $this->resolveUnit($unit);
        $person = $this->personAt($found, $resident);

        if ($person === null) {
            return back()->withErrors(['invite' => 'That person is not on this household.']);
        }

        $invites->cancel($person, (string) $request->user()->name, $request->user());

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $person->full_name.'\'s invite is cancelled. It can be sent again from here.');
    }

    /**
     * Change what a person may do in the Resident App — Member, Dependent or Helper (board 38). The office
     * sees every level and may change any but the primary resident's, who is always a Member. It takes
     * effect on the person's next request in the app; nobody is signed out. Audited.
     */
    public function setAccessLevel(Request $request, string $unit, int $resident): RedirectResponse
    {
        $found = $this->resolveUnit($unit);
        $person = $this->personAt($found, $resident);

        if ($person === null) {
            return back()->withErrors(['access_level' => 'That person is not on this household.']);
        }

        $data = $request->validate([
            'access_level' => ['required', 'string', 'in:'.implode(',', AppMatrix::LEVELS_ALL)],
        ]);

        try {
            $changed = AccessLevels::set($person, $data['access_level'], (string) $request->user()->name, $request->user(), 'office');
        } catch (DomainException $refused) {
            return back()->withErrors(['access_level' => $refused->getMessage()]);
        }

        $label = AccessLevels::label($data['access_level']);

        return redirect()
            ->to($this->householdPage($found, (int) $person->household_id))
            ->with('success', $changed
                ? $person->full_name.' now has '.$label.' access in the Resident App, from their next request. Nobody was signed out.'
                : $person->full_name.' already has '.$label.' access; nothing changed.');
    }

    /**
     * WHAT BECAME OF EACH PENDING HOUSEHOLD'S INVITE, for the register's rows (stage 2, SPEC: "wherever a Pending
     * resident with an open invite is listed"). One read of the pending people on this page and one of their
     * latest invites, keyed by household id; the person reported is the household's primary where they are
     * pending, else its first pending person.
     *
     * @param  list<array<string, mixed>>  $rows  `listBoard()`'s rows
     * @return array<int, array{resident_id: int, status: string, line: string, can_resend: bool, resend_after: string|null}>
     */
    private function inviteStates(array $rows, ResidentInvites $invites): array
    {
        $householdIds = array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($rows, static fn (array $row): bool => ($row['verification'] ?? null) === Resident::PENDING),
        ));

        if ($householdIds === []) {
            return [];
        }

        $people = Resident::query()
            ->whereIn('household_id', $householdIds)
            ->where('status', Resident::PENDING)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        $latest = $invites->latestForEach($people);
        $states = [];

        foreach ($people as $person) {
            if (isset($states[(int) $person->household_id])) {
                continue;
            }

            $outcome = ResidentInvites::outcome($latest[$person->id] ?? null);
            $hasEmail = trim((string) $person->email) !== '';

            $line = match ($outcome['status']) {
                'sent' => 'Invite sent'.($outcome['sent_at'] === null ? '' : ' '.SiteTime::local(Carbon::parse((string) $outcome['sent_at']))->format('j M')),
                'expired' => 'Invite expired',
                'not_sent' => 'Invite not sent',
                'cancelled' => 'Invite cancelled',
                'claimed' => 'Invite accepted · claim in Unit claims',
                default => $hasEmail ? 'Invite not sent' : null,
            };

            if ($line === null) {
                continue;
            }

            $states[(int) $person->household_id] = [
                'resident_id' => (int) $person->id,
                'status' => $outcome['status'],
                'line' => $line,
                'can_resend' => $hasEmail && in_array($outcome['status'], ['none', 'not_sent', 'expired', 'cancelled'], true),
                'resend_after' => $outcome['resend_after'],
            ];
        }

        return $states;
    }

    /** What became of a person's invite, in the words the office reads. */
    private function inviteSentence(Resident $person, ResidentInvites $invites): string
    {
        if (trim((string) $person->email) === '') {
            return 'No invite was sent: there is no email address for them. Add one on their household page, then send it from there.';
        }

        $invite = $invites->latestFor($person);
        $outcome = ResidentInvites::outcome($invite);

        /*
         * "Invite emailed to … at 9:38 AM" is the sentence that read as broken to a reader on another clock (M19A §1):
         * something that has just happened is said RELATIVELY, which belongs to no clock; an older one is the estate's
         * own time, with no zone after it (Law 5 as decided, M62 A5, DR-039: the console is all on the estate's clock).
         */
        $sentAt = $outcome['sent_at'] === null ? null : SiteTime::local(Carbon::parse((string) $outcome['sent_at']));
        $when = $sentAt === null ? '' : ($sentAt->diffInSeconds(Carbon::now(), true) < 120 ? ' just now' : ' at '.$sentAt->format('g:i A'));

        return match ($outcome['status']) {
            'sent' => 'Invite emailed to '.$outcome['email'].$when.'.',
            'none' => 'No invite was sent: there is no email address for them. Add one on their household page, then send it from there.',
            // The office's own words for a refusal (stage 2): the app's generic sentence is not the office's.
            default => 'Invite NOT sent: '.(ResidentInvites::officeReason($invite) ?? $outcome['reason']).' Resend it from their household page.',
        };
    }

    /**
     * Approve a claim — the irreversible one.
     *
     * THE OFFICE MAY CHOOSE THE ACCESS IT GIVES (stage 2, G8), and only an office that could change it afterwards:
     * a level is Residents `update`, so from anybody without it the posted level is dropped and the register
     * entry's own stands. A refusal comes back with the claim's id, so the page says it inside that card.
     */
    public function approveClaim(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'access_level' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', AppMatrix::LEVELS_ALL)],
        ]);

        $level = $request->user()->can('estate.residents.update') ? ($data['access_level'] ?? null) : null;

        try {
            $residents->approveClaim($claim, $request->user(), $level);
        } catch (DomainException $refused) {
            return back()->withErrors(['claim' => $refused->getMessage(), 'claim_id' => (string) $claim->id]);
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Claim by '.$claim->submitted_name.' approved.');
    }

    /** Refuse one, with the reason on the record. */
    public function rejectClaim(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $residents->rejectClaim($claim, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Claim by '.$claim->submitted_name.' refused.');
    }

    /** Ask the claimant for an identity document. */
    public function requestDocument(Request $request, UnitClaim $claim, Residents $residents): RedirectResponse
    {
        $data = $request->validate([
            'document' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $residents->requestDocument($claim, $data['document'] ?? 'photo ID', $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['document' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/residents/claims'))
            ->with('success', 'Identity document requested from '.$claim->submitted_name.'.');
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this viewer may be shown a resident's financial position.
     *
     * `estate.dues_ledger.view` and NOT `estate.residents.view`. They are
     * different modules because D-010 made them different: the Property Manager
     * holds Full on the register and nothing at all on the ledger, and this
     * single method is what keeps that true on four screens.
     */
    private function maySeeMoney(Request $request): bool
    {
        return $request->user()->can('estate.dues_ledger.view');
    }

    /**
     * The unit board 38 addresses by its reference — "lot-47", "ov_1a".
     *
     * Resolved here rather than by route-model binding, because the binding
     * would have to be registered on the model and would then apply to every
     * other route that takes a unit — including the dues screens, which bind on
     * the id. One screen's URL shape is not a model's identity.
     *
     * DECODED, NOT GUESSED AT (B040). This used to turn every "-" back into a
     * space, so a reference that really has a hyphen in it — every one of Ocean
     * View's, and any "A-12" the unit import brought in — was looked up as
     * something else and answered 404, and so did the edit and app-access routes
     * keyed on the same segment. `Unit::slugFor()` gives every character one
     * spelling and `referenceFromSlug()` reads it back.
     *
     * Compared with LOWER() on both sides rather than relying on the column's
     * collation. It happens to be case-insensitive today; a schema that was
     * re-collated for some unrelated reason would otherwise turn every one of
     * these links into a 404, and a 450-row scan is not a cost worth that. The
     * unit found must then spell exactly this segment, so each unit answers at
     * one address and not at every one its collation would also accept.
     */
    private function resolveUnit(string $slug): Unit
    {
        $reference = Unit::referenceFromSlug($slug);

        $unit = $reference === null ? null : Unit::query()
            ->whereRaw('LOWER(reference) = LOWER(?)', [$reference])
            ->get()
            ->first(static fn (Unit $candidate): bool => $candidate->slug() === $slug);

        if ($unit === null) {
            abort(404);
        }

        return $unit;
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
