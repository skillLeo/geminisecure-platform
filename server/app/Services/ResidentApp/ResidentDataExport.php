<?php

declare(strict_types=1);

namespace App\Services\ResidentApp;

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Http\Controllers\Api\V1\Resident\HouseholdItemsController;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertAudio;
use App\Models\DuressAlertMessage;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AutopayArrangement;
use App\Models\Estate\BallotReceipt;
use App\Models\Estate\Household;
use App\Models\Estate\HouseholdCard;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\NoticeRead;
use App\Models\Estate\Payment;
use App\Models\Estate\PaymentIntent;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\ResidentDeviceKey;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Passes\VisitorPasses;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "DOWNLOAD MY DATA" (board resident-app-33, M50 §4.3): A COPY OF EVERYTHING GEMINISECURE HOLDS ABOUT THE PERSON.
 *
 * The board's row promises "A copy of everything GeminiSecure holds about you", so this reads every table that names
 * the person — the account, its sign-ins, its phones, the register entry, the household it belongs to, the passes it
 * sent, the gate it answered, the requests it made, its bookings, its money, its panics and what was said on them, its
 * claim, its invites, the notices it opened and the meetings it answered — and lays it out as a PDF the person keeps.
 *
 * ONLY WHAT IS CERTAINLY THEIRS. A row joins the file by the link that names the person — an account id, a register
 * id — never by a guess. Where the platform holds something about them that it cannot tie to them with certainty (the
 * gate log, kept by name), or keeps but does not put in a file (photos, recordings), the last section says so, and how
 * to ask for it. Nothing about the person is left out silently.
 *
 * ONLY WHAT THEIR ACCESS REACHES of the HOUSEHOLD'S records — the members, the vehicles, the money, the votes — the same
 * rows the app shows them (`AppMatrix::levelAllows`), and of the other members nothing but their names: another
 * person's address and number are theirs, not this person's.
 *
 * NEVER A SECRET. No token, no key, no code, no full card number — the platform holds none of the last — and never how
 * the household voted: ballots are secret, and the platform records only that the lot voted.
 *
 * Audited with the count of each section and nothing of what was in it.
 */
final class ResidentDataExport
{
    /** The document's own heading: what it is, in the words the Privacy & data screen uses. */
    public const TITLE = 'Your data in GeminiSecure';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Everything, as sections — each a title, a sentence of what it is, and its facts or its table.
     *
     * @return array{title: string, person: string, estate: string, made_at: string, clock: string, sections: list<array<string, mixed>>, not_included: list<string>}
     */
    public function collect(ResidentAccount $account): array
    {
        $tenant = (string) $account->tenant_id;
        $estate = Tenant::query()->find($tenant);
        $resident = $account->resident_id === null ? null : Resident::withRemoved()->find((int) $account->resident_id);
        $unit = $account->unit_id === null ? null : Unit::query()->find((int) $account->unit_id);
        $household = $resident?->household_id !== null
            ? Household::query()->find((int) $resident->household_id)
            : ($unit === null ? null : Household::query()->where('unit_id', $unit->id)->first());
        $level = AccessLevels::of($resident);
        // The names a person's own words are signed with in the estate's records (a request, a comment on one).
        $names = array_values(array_unique(array_filter([trim((string) $resident?->full_name), trim((string) $account->full_name)])));
        $person = $names[0] ?? (string) $account->destination;

        $sections = [
            $this->account($account, $tenant),
            $this->register($resident, $household, $unit, $level),
            $this->household($household, $level, (int) $account->id),
            $this->signIns($account, $tenant),
            $this->notifications($account, $tenant),
            $this->ePass($resident, $tenant),
            $this->visitorPasses($resident, $tenant),
            $this->gateAnswers($account, $tenant),
            $this->requests($unit, $names, (int) $account->id, $tenant),
            $this->bookings($resident, $tenant),
            $this->money($account, $unit, $level, $tenant),
            $this->panics($account, $tenant),
            $this->claim($account, $tenant),
            $this->invites($resident, $tenant),
            $this->noticesRead($resident, $tenant),
            $this->meetings($resident, $tenant),
            $this->elections($unit, $level),
        ];

        $zone = SiteTime::zone($tenant);

        return [
            'title' => self::TITLE,
            'person' => $person,
            'estate' => (string) ($estate->name ?? ''),
            'made_at' => $this->when(Carbon::now(), $tenant) ?? '',
            'clock' => 'Every time in this file is '.SiteTime::clockName($zone).', the estate\'s clock.',
            'sections' => $sections,
            'not_included' => $this->notIncluded(),
        ];
    }

    /** The PDF the person downloads, and the audit entry that says it was made. */
    public function pdf(ResidentAccount $account): string
    {
        $data = $this->collect($account);

        // Only the letters it uses of the embedded face: the whole of DejaVu Sans would make a four-page record 1.2 MB.
        $bytes = Pdf::loadView('documents.resident-data', ['data' => $data])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();

        $this->audit->record(
            action: 'resident_account.data_exported',
            entityType: 'ResidentAccount',
            entityId: (string) $account->id,
            // How much went into the file — never what: the audit log outlives the account.
            after: ['sections' => array_map(static fn (array $s): int => (int) $s['count'], array_column($data['sections'], null, 'key'))],
            tenantId: (string) $account->tenant_id,
            actorName: AccountDeletion::RESIDENT_ACTOR,
        );

        return $bytes;
    }

    /** "GeminiSecure-my-data-2026-10-08.pdf" — the day on the estate's calendar. */
    public function fileName(ResidentAccount $account): string
    {
        return 'GeminiSecure-my-data-'.SiteTime::local(Carbon::now(), (string) $account->tenant_id)->toDateString().'.pdf';
    }

    /* ------------------------------------------------------------------ the sections */

    /** @return array<string, mixed> */
    private function account(ResidentAccount $account, string $tenant): array
    {
        $muted = array_values(array_filter((array) ($account->notify_email_muted ?? []), 'is_string'));

        return $this->facts('account', 'Your account', 'How you sign in, and what the account keeps about you.', [
            ['Signs in with', (string) $account->destination],
            ['Name on the account', $account->full_name ?: 'Not given'],
            ['Account status', ucfirst((string) $account->status)],
            ['Opened', $this->when($account->created_at, $tenant) ?? 'Not recorded'],
            ['Email address confirmed', $this->when($account->verified_at, $tenant) ?? 'Not yet'],
            ['Profile photo', $account->photo_path ? 'On file since '.($this->when($account->photo_updated_at, $tenant) ?? 'an unrecorded date') : 'None'],
            ['Email notifications', $account->notify_email ? 'On'.($muted === [] ? '' : ' — except '.implode(', ', array_map([$this, 'category'], $muted))) : 'Off'],
        ]);
    }

    /** @return array<string, mixed> */
    private function register(?Resident $resident, ?Household $household, ?Unit $unit, string $level): array
    {
        if ($resident === null) {
            return $this->facts('register', 'You on the estate\'s register', 'The estate office\'s record of the people who live here.', [], 'You are not on the estate\'s register yet: your claim to a home has not been approved.');
        }

        return $this->facts('register', 'You on the estate\'s register', 'The estate office\'s record of the people who live here.', [
            ['Name', (string) $resident->full_name],
            ['Email', $resident->email ?: 'Not given'],
            ['Phone', $resident->phone ?: 'Not given'],
            ['Relationship to the household', $resident->relationship ? ucfirst((string) $resident->relationship) : 'Not given'],
            ['Primary resident', $resident->is_primary ? 'Yes' : 'No'],
            ['Access in the app', AccessLevels::label($level)],
            ['Household', $household?->name ?: 'Not named'],
            ['Home', $unit?->reference ?? 'Not linked'],
            ['Moved in', $resident->moved_in_on ? Carbon::parse($resident->moved_in_on)->format('M j, Y') : 'Not recorded'],
            ['Confirmed by the estate office', $this->when($resident->verified_at) ?? 'Not yet'],
            ['On the register', $resident->removed_at === null ? 'Yes' : 'Removed '.$this->when($resident->removed_at)],
        ]);
    }

    /** @return array<string, mixed> */
    private function household(?Household $household, string $level, int $accountId): array
    {
        $title = 'Your household';
        $intro = 'The people, vehicles, pets and emergency contacts the estate holds for your home. Of the other people, only their names: their details are theirs.';

        if ($household === null) {
            return $this->facts('household', $title, $intro, [], 'You are not linked to a home on the estate yet.');
        }

        if (! AppMatrix::levelAllows($level, 'me.household')) {
            return $this->facts('household', $title, $intro, [], 'Your access in the app does not include the household\'s members, vehicles and emergency contacts.');
        }

        $members = Resident::query()->where('household_id', $household->id)->orderByDesc('is_primary')->orderBy('full_name')->get()
            ->map(static fn (Resident $r): array => [(string) $r->full_name, $r->relationship ? ucfirst((string) $r->relationship) : '—', $r->is_primary ? 'Primary' : AccessLevels::label(AccessLevels::of($r))])
            ->all();
        $vehicles = DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->orderBy('plate')->get()
            ->map(static fn (object $v): array => [(string) $v->plate, trim(($v->colour ?? '').' '.($v->make ?? '').' '.($v->model ?? '')) ?: '—', (int) ($v->added_by_account_id ?? 0) === $accountId ? 'You' : '—'])
            ->all();
        $contacts = DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->orderBy('name')->get()
            ->map(static fn (object $c): array => [(string) $c->name, $c->relationship ? ucfirst((string) $c->relationship) : '—', (string) ($c->phone ?? '—'), (string) ($c->email ?? '—')])
            ->all();
        $pets = array_map(static fn (array $p): array => [(string) $p['name'], (string) $p['kind_label']], HouseholdItemsController::petsOf((int) $household->id));

        return [
            'key' => 'household',
            'title' => $title,
            'intro' => $intro,
            'count' => count($members) + count($vehicles) + count($pets) + count($contacts),
            'tables' => [
                ['caption' => 'People', 'headers' => ['Name', 'Relationship', 'Access'], 'rows' => $members, 'empty' => 'Nobody is on the register for this home.'],
                ['caption' => 'Vehicles', 'headers' => ['Plate', 'Vehicle', 'Added by'], 'rows' => $vehicles, 'empty' => 'No vehicles.'],
                ['caption' => 'Pets', 'headers' => ['Name', 'Kind'], 'rows' => $pets, 'empty' => 'No pets.'],
                ['caption' => 'Emergency contacts', 'headers' => ['Name', 'Relationship', 'Phone', 'Email'], 'rows' => $contacts, 'empty' => 'No emergency contacts.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function signIns(ResidentAccount $account, string $tenant): array
    {
        $sessions = $account->tokens()->orderByDesc('created_at')->get()
            ->map(fn ($t): array => [
                (string) ($t->device_label ?: 'A phone'),
                ucfirst((string) ($t->device_platform ?: '—')),
                $this->when($t->created_at, $tenant) ?? '—',
                $this->when($t->last_used_at, $tenant) ?? 'Not yet',
            ])->all();

        // Fingerprint sign-in: which phones may sign in with it — never the key, which only the phone holds.
        $keys = ResidentDeviceKey::query()->where('resident_account_id', $account->id)->orderByDesc('created_at')->get()
            ->map(fn (ResidentDeviceKey $k): array => [
                (string) ($k->device_label ?: 'A phone'),
                $this->when($k->created_at, $tenant) ?? '—',
                $this->when($k->last_used_at, $tenant) ?? 'Not yet',
                $k->revoked_at === null ? 'On' : 'Turned off '.$this->when($k->revoked_at, $tenant),
            ])->all();

        return [
            'key' => 'sign_ins',
            'title' => 'Phones signed in to your account',
            'intro' => 'Every phone signed in now, and every phone that can sign in with a fingerprint or face.',
            'count' => count($sessions) + count($keys),
            'tables' => [
                ['caption' => 'Signed in', 'headers' => ['Phone', 'System', 'Signed in', 'Last used'], 'rows' => $sessions, 'empty' => 'No phone is signed in.'],
                ['caption' => 'Fingerprint or face sign-in', 'headers' => ['Phone', 'Turned on', 'Last used', 'Now'], 'rows' => $keys, 'empty' => 'Not turned on for any phone.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function notifications(ResidentAccount $account, string $tenant): array
    {
        $rows = DevicePushToken::query()
            ->where('tenant_id', $tenant)
            ->where('principal_type', DevicePushToken::RESIDENT)
            ->where('principal_id', $account->id)
            ->orderByDesc('last_registered_at')
            ->get()
            ->map(fn (DevicePushToken $t): array => [
                ucfirst((string) ($t->platform ?: '—')),
                $this->when($t->last_registered_at, $tenant) ?? '—',
                ($muted = array_values(array_filter((array) ($t->muted ?? []), 'is_string'))) === [] ? 'Nothing' : implode(', ', array_map([$this, 'category'], $muted)),
                $t->quiet_from && $t->quiet_to ? $this->clock((string) $t->quiet_from).' – '.$this->clock((string) $t->quiet_to) : 'Off',
                $t->revoked_at === null ? 'Receiving' : 'Stopped '.$this->when($t->revoked_at, $tenant),
            ])->all();

        return $this->table('notifications', 'Notifications to your phones', 'Where the app sends your notifications, and what each phone has muted.', ['System', 'Registered', 'Muted', 'Quiet hours', 'Now'], $rows, 'No phone receives notifications.');
    }

    /** @return array<string, mixed> */
    private function ePass(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : VisitorPass::query()
            ->where('category', VisitorPasses::RESIDENT)
            ->where('issued_by_resident_id', $resident->id)
            ->orderByDesc('valid_from')
            ->get()
            ->map(fn (VisitorPass $p): array => [
                $this->when($p->valid_from, $tenant) ?? '—',
                $this->when($p->valid_to, $tenant) ?? '—',
                ucfirst((string) $p->status),
                $p->used_at === null ? 'Not used' : $this->when($p->used_at, $tenant).($p->last_admitted_post ? ' · '.$p->last_admitted_post : ''),
            ])->all();

        return $this->table('e_pass', 'Your e-Pass', 'The passes that let you in at the gate.', ['From', 'Until', 'Status', 'Used at the gate'], $rows, 'You have not had an e-Pass.');
    }

    /** @return array<string, mixed> */
    private function visitorPasses(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : VisitorPass::query()
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->where('issued_by_resident_id', $resident->id)
            ->orderByDesc('valid_from')
            ->get()
            ->map(fn (VisitorPass $p): array => [
                (string) $p->visitor_name,
                $p->visitor_phone ?: '—',
                $p->vehicle_plate ?: ($p->no_vehicle ? match ($p->getAttribute('arrival')) { 'walk' => 'Walk in', 'ride' => 'Ride in', default => 'No vehicle' } : '—'),
                ($this->when($p->valid_from, $tenant) ?? '—').' – '.($this->when($p->valid_to, $tenant) ?? '—'),
                ucfirst((string) $p->status),
                $p->used_at === null ? 'Not used' : $this->when($p->used_at, $tenant),
            ])->all();

        return $this->table('visitor_passes', 'Visitor passes you sent', 'Everyone you invited, and whether they came.', ['Visitor', 'Phone', 'Vehicle', 'Window', 'Status', 'Arrived'], $rows, 'You have not sent a visitor pass.');
    }

    /** @return array<string, mixed> */
    private function gateAnswers(ResidentAccount $account, string $tenant): array
    {
        $rows = DB::connection('tenant')->table('gate_approval_requests')
            ->where('responded_by_account_id', $account->id)
            ->orderByDesc('requested_at')
            ->get()
            ->map(fn (object $r): array => [
                (string) $r->visitor_name,
                (string) ($r->purpose ?: '—'),
                (string) ($r->post_name ?: '—'),
                $this->when($r->requested_at, $tenant) ?? '—',
                ucfirst((string) $r->status),
                $this->when($r->responded_at, $tenant) ?? '—',
            ])->all();

        return $this->table('gate_answers', 'Visitors you answered at the gate', 'When a guard asked whether to let somebody in, and what you said.', ['Visitor', 'Why', 'Gate', 'Asked', 'Your answer', 'Answered'], $rows, 'You have not been asked about a visitor.');
    }

    /**
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private function requests(?Unit $unit, array $names, int $accountId, string $tenant): array
    {
        $tickets = collect();
        if ($unit !== null && $names !== []) {
            $withPhotos = DB::connection('tenant')->table('ticket_media')->where('uploaded_by_account_id', $accountId)->pluck('maintenance_ticket_id')->all();
            $tickets = MaintenanceTicket::query()
                ->where('unit_id', $unit->id)
                ->where(static fn ($q) => $q->whereIn('reported_by_name', $names)->orWhereIn('id', $withPhotos))
                ->orderByDesc('reported_at')
                ->get();
        }

        $rows = $tickets->map(fn (MaintenanceTicket $t): array => [
            '#'.$t->number,
            (string) $t->title,
            ucfirst((string) $t->category),
            ucfirst(str_replace('_', ' ', (string) $t->status)),
            $this->when($t->reported_at, $tenant) ?? '—',
            $t->latitude !== null && $t->longitude !== null ? (string) $t->location_label.' · shared location '.$this->place($t->latitude, $t->longitude) : (string) $t->location_label,
        ])->all();

        $comments = $tickets->isEmpty() ? [] : TicketActivity::query()
            ->whereIn('maintenance_ticket_id', $tickets->pluck('id'))
            ->where('actor_kind', TicketActivity::RESIDENT)
            ->whereIn('actor_name', $names)
            ->whereNotNull('note')
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (TicketActivity $a): array => ['#'.($tickets->firstWhere('id', $a->maintenance_ticket_id)->number ?? '?'), (string) $a->note, $this->when($a->occurred_at, $tenant) ?? '—'])
            ->all();

        $photos = DB::connection('tenant')->table('ticket_media')->where('uploaded_by_account_id', $accountId)->count();

        return [
            'key' => 'requests',
            'title' => 'Requests and reports you made',
            'intro' => 'Maintenance requests and security reports filed in your name, and what you wrote on them.'.($photos > 0 ? ' You added '.$photos.' '.($photos === 1 ? 'photo or video' : 'photos or videos').' to them (see the last section).' : ''),
            'count' => count($rows) + count($comments),
            'tables' => [
                ['caption' => 'Requests', 'headers' => ['Number', 'What', 'Kind', 'Status', 'Reported', 'Where'], 'rows' => $rows, 'empty' => 'You have not made a request or a report.'],
                ['caption' => 'Your comments', 'headers' => ['Request', 'You wrote', 'When'], 'rows' => $comments, 'empty' => 'No comments.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function bookings(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : AmenityBooking::query()->with('amenity')
            ->where('resident_id', $resident->id)
            ->orderByDesc('starts_at')
            ->get()
            ->map(fn (AmenityBooking $b): array => [
                (string) $b->reference,
                (string) ($b->amenity->name ?? '—'),
                ($this->when($b->starts_at, $tenant) ?? '—').' – '.(SiteTime::local($b->ends_at, $tenant)->format('g:i A')),
                (string) $b->guests,
                ucfirst((string) $b->status),
                MoneyFormatter::fromMinor((int) $b->fee_minor, (string) ($b->currency ?: 'JMD')).' fee · '.MoneyFormatter::fromMinor((int) $b->deposit_minor, (string) ($b->currency ?: 'JMD')).' deposit',
            ])->all();

        return $this->table('bookings', 'Amenity bookings', 'The bookings you made, and what they cost.', ['Reference', 'Amenity', 'When', 'Guests', 'Status', 'Cost'], $rows, 'You have not booked an amenity.');
    }

    /** @return array<string, mixed> */
    private function money(ResidentAccount $account, ?Unit $unit, string $level, string $tenant): array
    {
        $title = 'Payments, cards and AutoPay';
        $intro = 'The payments recorded on your home, the test cards you saved and the AutoPay you set up. The platform never holds a full card number.';

        if ($unit === null) {
            return $this->facts('money', $title, $intro, [], 'You are not linked to a home on the estate yet.');
        }

        if (! AppMatrix::levelAllows($level, 'invoices.index')) {
            return $this->facts('money', $title, $intro, [], 'Your access in the app does not include the household\'s dues and payments.');
        }

        $payments = Payment::query()->where('unit_id', $unit->id)->orderByDesc('received_at')->get()
            ->map(fn (Payment $p): array => [(string) $p->receipt_no, $this->when($p->received_at, $tenant) ?? '—', MoneyFormatter::fromMinor((int) $p->amount_minor, (string) ($p->currency ?: 'JMD')), ucfirst((string) $p->method), ucfirst((string) $p->status)])
            ->all();
        $intents = PaymentIntent::query()->where('payer_account_id', $account->id)->orderByDesc('created_at')->get()
            ->map(fn (PaymentIntent $i): array => [(string) $i->reference, $this->when($i->created_at, $tenant) ?? '—', MoneyFormatter::fromMinor((int) $i->amount_minor, (string) ($i->currency ?: 'JMD')), ucfirst((string) $i->status)])
            ->all();
        $cards = HouseholdCard::query()->where('resident_account_id', $account->id)->orderByDesc('is_default')->orderBy('id')->get()
            ->map(static fn (HouseholdCard $c): array => [ucfirst((string) $c->brand).' •••• '.$c->last4, sprintf('%02d/%02d', $c->exp_month, $c->exp_year % 100), (string) ($c->holder ?: '—'), $c->is_default ? 'Yes' : 'No'])
            ->all();
        $autopay = AutopayArrangement::query()->with('card')->where('resident_account_id', $account->id)->orderByDesc('turned_on_at')->get()
            ->map(fn (AutopayArrangement $a): array => [
                'Up to '.MoneyFormatter::fromMinor((int) $a->limit_minor).' on day '.$a->day_of_month,
                $a->card ? ucfirst((string) $a->card->brand).' •••• '.$a->card->last4 : 'A card since removed',
                ucfirst((string) $a->status),
                ($this->when($a->turned_on_at, $tenant) ?? '—').($a->turned_off_at ? ' – '.$this->when($a->turned_off_at, $tenant) : ''),
                (string) ($a->last_outcome ?: 'Not run yet'),
            ])->all();

        return [
            'key' => 'money',
            'title' => $title,
            'intro' => $intro,
            'count' => count($payments) + count($intents) + count($cards) + count($autopay),
            'tables' => [
                ['caption' => 'Payments recorded on '.$unit->reference, 'headers' => ['Receipt', 'Received', 'Amount', 'Method', 'Status'], 'rows' => $payments, 'empty' => 'No payments recorded.'],
                ['caption' => 'Card payments you started', 'headers' => ['Reference', 'Started', 'Amount', 'Status'], 'rows' => $intents, 'empty' => 'None.'],
                ['caption' => 'Cards you saved', 'headers' => ['Card', 'Expires', 'Name on card', 'Default'], 'rows' => $cards, 'empty' => 'No saved cards.'],
                ['caption' => 'AutoPay you set up', 'headers' => ['Terms', 'Card', 'Status', 'On – off', 'Last run'], 'rows' => $autopay, 'empty' => 'AutoPay has not been set up.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function panics(ResidentAccount $account, string $tenant): array
    {
        $alerts = DuressAlert::query()->where('tenant_id', $tenant)->where('raised_by_account_id', $account->id)->orderByDesc('server_time')->get();
        $ids = $alerts->pluck('id')->all();
        $raised = static fn (int $id) => $alerts->firstWhere('id', $id);

        $rows = $alerts->map(fn (DuressAlert $a): array => [
            ($this->when($a->server_time, $tenant) ?? '—').($a->is_simulated ? ' (practice)' : ''),
            ucfirst(str_replace('_', ' ', (string) $a->status)),
            $a->latitude !== null && $a->longitude !== null ? $this->place($a->latitude, $a->longitude) : 'Not shared',
            $this->when($a->acknowledged_at, $tenant) ?? '—',
            $this->when($a->resolved_at ?? $a->cancelled_at, $tenant) ?? '—',
        ])->all();

        $messages = $ids === [] ? [] : DuressAlertMessage::query()->whereIn('duress_alert_id', $ids)->orderBy('created_at')->get()
            ->map(fn (DuressAlertMessage $m): array => [
                $this->when($raised((int) $m->duress_alert_id)?->server_time, $tenant) ?? '—',
                $m->author === 'resident' ? 'You' : 'Control room'.($m->author_name ? ' ('.$m->author_name.')' : ''),
                (string) $m->body,
                $this->when($m->created_at, $tenant) ?? '—',
            ])->all();

        $audio = $ids === [] ? [] : DuressAlertAudio::query()->whereIn('duress_alert_id', $ids)
            ->selectRaw('duress_alert_id, COUNT(*) AS pieces, COALESCE(SUM(duration_ms), 0) AS ms')
            ->groupBy('duress_alert_id')->get()
            ->map(fn ($row): array => [
                $this->when($raised((int) $row->duress_alert_id)?->server_time, $tenant) ?? '—',
                (string) $row->pieces,
                $this->duration((int) $row->ms),
            ])->all();

        return [
            'key' => 'panics',
            'title' => 'Panic alerts you raised',
            'intro' => 'Each alert, where your phone said you were, and what was said with the control room.',
            'count' => count($rows) + count($messages) + count($audio),
            'tables' => [
                ['caption' => 'Alerts', 'headers' => ['Raised', 'Outcome', 'Location shared', 'Answered', 'Closed'], 'rows' => $rows, 'empty' => 'You have not raised a panic alert.'],
                ['caption' => 'Messages with the control room', 'headers' => ['Alert', 'From', 'Message', 'Sent'], 'rows' => $messages, 'empty' => 'No messages.'],
                ['caption' => 'Live audio sent (see the last section)', 'headers' => ['Alert', 'Pieces', 'Length'], 'rows' => $audio, 'empty' => 'No live audio.'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function claim(ResidentAccount $account, string $tenant): array
    {
        $claim = $account->claim_id === null ? null : UnitClaim::query()->find((int) $account->claim_id);

        if ($claim === null) {
            return $this->facts('claim', 'Your claim to a home', 'What you told the estate when you asked to be linked to a home.', [], 'You did not make a claim: you were added by your household or the estate office.');
        }

        return $this->facts('claim', 'Your claim to a home', 'What you told the estate when you asked to be linked to a home.', [
            ['Name you gave', (string) ($claim->submitted_name ?: '—')],
            ['Phase and lot', trim(($claim->submitted_phase ?? '').' '.($claim->submitted_lot ?? '')) ?: '—'],
            ['Phone you gave', (string) ($claim->submitted_phone ?: '—')],
            ['Relationship', $claim->submitted_relationship ? ucfirst((string) $claim->submitted_relationship) : '—'],
            ['Made', $this->when($claim->created_at, $tenant) ?? '—'],
            ['Outcome', ucfirst(str_replace('_', ' ', (string) $claim->status)).($claim->reviewed_at ? ', '.$this->when($claim->reviewed_at, $tenant) : '')],
            ['Reason given', (string) ($claim->decision_reason ?: '—')],
        ]);
    }

    /** @return array<string, mixed> */
    private function invites(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : ResidentInvite::query()->where('resident_id', $resident->id)->orderByDesc('sent_at')->get()
            ->map(fn (ResidentInvite $i): array => [
                (string) ($i->email ?: '—'),
                $this->when($i->sent_at, $tenant) ?? 'Not sent',
                (string) ($i->invited_by_name ?: '—'),
                $i->claimed_at ? 'Accepted '.$this->when($i->claimed_at, $tenant) : ($i->cancelled_at ? 'Cancelled '.$this->when($i->cancelled_at, $tenant) : 'Not accepted'),
            ])->all();

        return $this->table('invites', 'Invitations to the app', 'The invitations that were sent to you.', ['Sent to', 'Sent', 'By', 'Outcome'], $rows, 'No invitation was sent to you.');
    }

    /** @return array<string, mixed> */
    private function noticesRead(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : NoticeRead::query()->with('notice')->where('resident_id', $resident->id)->orderByDesc('read_at')->get()
            ->map(fn (NoticeRead $r): array => [(string) ($r->notice->title ?? 'A notice since removed'), $this->when($r->read_at, $tenant) ?? '—'])
            ->all();

        return $this->table('notices', 'Notices you opened', 'The estate notices you have read — the estate counts who has seen each one.', ['Notice', 'Opened'], $rows, 'You have not opened a notice.');
    }

    /** @return array<string, mixed> */
    private function meetings(?Resident $resident, string $tenant): array
    {
        $rows = $resident === null ? [] : DB::connection('tenant')->table('meeting_rsvps')
            ->join('meetings', 'meetings.id', '=', 'meeting_rsvps.meeting_id')
            ->where('meeting_rsvps.resident_id', $resident->id)
            ->orderByDesc('meeting_rsvps.responded_at')
            ->get(['meetings.title', 'meetings.starts_at', 'meeting_rsvps.response', 'meeting_rsvps.responded_at'])
            ->map(fn (object $r): array => [(string) $r->title, $this->when($r->starts_at, $tenant) ?? '—', ucfirst((string) $r->response), $this->when($r->responded_at, $tenant) ?? '—'])
            ->all();

        return $this->table('meetings', 'Meetings you answered', 'Whether you said you would come.', ['Meeting', 'When', 'Your answer', 'Answered'], $rows, 'You have not answered a meeting invitation.');
    }

    /** @return array<string, mixed> */
    private function elections(?Unit $unit, string $level): array
    {
        $title = 'Elections';
        $intro = 'Ballots are secret: the platform records that your home voted, and never how.';

        if ($unit === null || ! AppMatrix::levelAllows($level, 'elections.index')) {
            return $this->facts('elections', $title, $intro, [], $unit === null ? 'You are not linked to a home on the estate yet.' : 'Your access in the app does not include voting.');
        }

        $rows = BallotReceipt::query()->with('ballot')->where('unit_id', $unit->id)->orderByDesc('voted_on')->get()
            ->map(static fn (BallotReceipt $r): array => [(string) ($r->ballot->title ?? 'An election'), Carbon::parse($r->voted_on)->format('M j, Y')])
            ->all();

        return $this->table('elections', $title, $intro, ['Election', 'Your home voted on'], $rows, 'Your home has not voted in an election.');
    }

    /** @return list<string> */
    private function notIncluded(): array
    {
        $support = (string) config('legal.support_email');

        return [
            'Photos, videos and recordings. The photos and videos you added to requests, and the live audio your phone sent during a panic alert, are kept but are not in this file — it lists them. To get the files themselves, write to '.$support.'.',
            'The gate log. Guards record every arrival by the name they are given, not by account, so your own arrivals cannot be picked out with certainty and are not listed here. The estate office can search the log for you.',
            'How your home voted. Ballots are secret: the platform never stores a vote with a name.',
            'The audit log. The estate keeps a record of every change made to your account — by whom and when — without your name in it. The estate office can show you the entries about you.',
            'Anything kept by the estate office outside GeminiSecure — paper records, its bank, its email — is the estate\'s own, and you can ask the office for it.',
        ];
    }

    /* ------------------------------------------------------------------ the shapes */

    /**
     * @param  list<array{0: string, 1: string}>  $facts
     * @return array<string, mixed>
     */
    private function facts(string $key, string $title, string $intro, array $facts, ?string $empty = null): array
    {
        return ['key' => $key, 'title' => $title, 'intro' => $intro, 'count' => count($facts), 'facts' => $facts, 'empty' => $empty];
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    private function table(string $key, string $title, string $intro, array $headers, array $rows, string $empty): array
    {
        return ['key' => $key, 'title' => $title, 'intro' => $intro, 'count' => count($rows), 'tables' => [['caption' => null, 'headers' => $headers, 'rows' => $rows, 'empty' => $empty]]];
    }

    /** A moment on the estate's clock, as a person reads it: "Oct 8, 2026, 9:41 AM". Null when there is none. */
    private function when(mixed $at, ?string $tenant = null): ?string
    {
        if ($at === null || $at === '') {
            return null;
        }

        try {
            $instant = $at instanceof DateTimeInterface ? $at : Carbon::parse((string) $at, 'UTC');

            return SiteTime::local($instant, $tenant)->format('M j, Y, g:i A');
        } catch (Throwable) {
            return null;
        }
    }

    /** "22:00" → "10:00 PM". */
    private function clock(string $hhmm): string
    {
        try {
            return Carbon::createFromFormat('H:i', substr($hhmm, 0, 5))->format('g:i A');
        } catch (Throwable) {
            return $hhmm;
        }
    }

    /** A stored coordinate pair as a person reads one: "18.0179000", "-76.8099000" → "18.0179, -76.8099". */
    private function place(mixed $latitude, mixed $longitude): string
    {
        $tidy = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');

        return $tidy($latitude).', '.$tidy($longitude);
    }

    /** 95000 ms → "1 min 35 s". */
    private function duration(int $ms): string
    {
        $seconds = (int) round($ms / 1000);

        return $seconds < 60 ? $seconds.' s' : intdiv($seconds, 60).' min '.($seconds % 60).' s';
    }

    /** A notification category's key, as a word: "panic_update" → "Panic update". */
    private function category(string $key): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $key));
    }
}
