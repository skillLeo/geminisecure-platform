<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Live\ChannelName;
use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingMinutes;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ResidentSignInCode;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Amenities;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Governance;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Notices;
use App\Services\Estate\Residents;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Live\Support\LiveAuthFixture;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The Resident App's API, walked end to end — 13 D3
|--------------------------------------------------------------------------
|
| A resident signs in with a code, claims a unit, waits, is approved by the
| estate, and then uses every endpoint the Resident App's token reaches: the
| household, passes and the e-pass, a guard's walk-up request, dues, tickets,
| notices, meetings, a vote and its result, bookings, and panic.
|
| Every response is held to its catalogue entry, and the test fails at the end
| if a resident route in the catalogue was never called. A guard's token is
| refused on every resident-only route, at the ability.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // live.auth writes its grants down (M11 Part C); the table exists before the transaction does.

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();

    /*
     * A LOT OF THIS FILE'S OWN (M4 §4, defect 3). This used to take the first of
     * the fixture's five shared lots with no payment plan over it — and the estate
     * database is committed, so which lot that was, and what it owed, depended on
     * which files had run before this one. After the amenity suite it was Lot 3,
     * which that suite had put 120 days in arrears, and the walk below was refused
     * its vote. What this walk depends on — a household in good standing with no
     * plan over it — it now arranges for itself, on a lot no other file posts to,
     * and says so before it starts rather than 250 lines in.
     */
    $this->unit = FacilitiesFixture::scratchUnit('Lot 981');

    expect(app(Collections::class)->isProtected($this->unit))->toBeFalse('Lot 981 is this file\'s own; nothing should have put a payment plan over it')
        ->and(app(Governance::class)->eligibility($this->unit)['eligible'])->toBeTrue('Lot 981 is this file\'s own; nothing should have put it in arrears');

    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Morgan household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();

    $GLOBALS['residentContractCalled'] = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->delete();
    $this->household->forceFill(['access_restricted' => false])->save();

    /*
     * AND THE REST OF WHAT THE WALK PUBLISHED (M4 §4, defect 3, again).
     *
     * The estate database is COMMITTED and shared with every facilities suite, so a ticket this
     * walk raises is a ticket board 17 then counts, and a notice it publishes is a notice board 32
     * then lists. In file order this ran after both of them and nobody noticed; `phpunit.xml` now
     * runs the suite in random order, and `EstateFacilitiesMaintenanceTest` read 14 open tickets
     * where the seeder made 12.
     *
     * Scoped, not swept: the ticket is on THIS FILE'S OWN LOT, and the notice and the meetings are
     * the ones written by name above. Nothing the seeder made is touched.
     *
     * THREE THINGS ARE DELIBERATELY LEFT. A `documents` row cannot be deleted — the table's own
     * trigger refuses, which is the seven-year retention doing its job; the notice it is filed
     * under therefore stays with it, rather than leaving an attachment filed against a notice that
     * no longer exists; and an amenity booking's deposit has a journal behind it that an
     * append-only ledger has no way to take back.
     */
    DB::connection('tenant')->table('ticket_media')
        ->whereIn('maintenance_ticket_id', DB::connection('tenant')->table('maintenance_tickets')->where('unit_id', $this->unit->id)->pluck('id'))
        ->delete();
    DB::connection('tenant')->table('maintenance_tickets')->where('unit_id', $this->unit->id)->delete();

    $meetings = DB::connection('tenant')->table('meetings')
        ->where('title', 'like', 'Annual General Meeting %')
        ->orWhere('title', 'like', 'Ten o\'clock meeting %')
        ->orWhere('title', 'like', 'Held meeting %')
        ->pluck('id');
    DB::connection('tenant')->table('meeting_minutes')->whereIn('meeting_id', $meetings)->delete();
    DB::connection('tenant')->table('meeting_rsvps')->whereIn('meeting_id', $meetings)->delete();
    DB::connection('tenant')->table('meetings')->whereIn('id', $meetings)->delete();
});

/**
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function asResident(TestCase $test, ?string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'res-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $client = $token === null ? $test : $test->withToken($token);

    $response = $entry['method'] === 'GET'
        ? $client->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $client->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);

    $GLOBALS['residentContractCalled'][$name] = true;

    FacilitiesFixture::boot();

    return $response;
}

it('walks every Resident App endpoint from sign-in to a vote, each answer inside its catalogue allowlist', function () {
    $site = FacilitiesFixture::ESTATE;
    $email = 'resident.'.bin2hex(random_bytes(3)).'@example.com';

    /* ── Pick the estate — before any token exists ───────────────── */

    // The list offers ACTIVE estates only, and says no more than the sign on the gate (the catalogue allowlist is
    // checked by asResident): an estate still being set up must not be offered, exactly as otp.request refuses it.
    $settingUp = Tenant::query()->where('status', '!=', 'active')->pluck('id')->map(fn ($id) => (string) $id);
    $listed = collect(asResident($this, null, 'estates.index')->json('items'));
    expect($listed->pluck('id'))->toContain($site)
        ->and($listed->pluck('id')->intersect($settingUp))->toBeEmpty()
        ->and($listed->firstWhere('id', $site)['name'])->not->toBeEmpty();

    /* ── Sign in by code ─────────────────────────────────────────── */

    asResident($this, null, 'auth.otp.request', [], ['estate' => 'nowhere', 'channel' => 'email', 'destination' => $email], 404);
    $sent = asResident($this, null, 'auth.otp.request', [], ['estate' => $site, 'channel' => 'email', 'destination' => $email]);
    expect($sent->json('destination_hint'))->toStartWith('r•••@');

    $code = null;
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$code): bool {
        $code = $n->code;

        return true;
    });

    $verify = ['estate' => $site, 'channel' => 'email', 'destination' => strtoupper($email), 'device_uid' => 'res-install-1', 'platform' => 'ios', 'device_name' => 'iPhone 15'];

    asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code === '000000' ? '111111' : '000000'], 422);
    $signedIn = asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code]);

    // Where the Resident App first learns its estate, it learns the estate's ZONE — by IANA name (M4 §3.1).
    expect($signedIn->json('estate.timezone'))->toBe('America/Jamaica');

    expect($signedIn->json('account.status'))->toBe('pending')
        ->and($signedIn->json('next'))->toBe('claim_unit')
        ->and($signedIn->json('abilities'))->toBe(AppMatrix::abilitiesFor(AppMatrix::RESIDENT));

    $t = $signedIn->json('token');

    // A used code is gone.
    asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code], 422);

    // "Restore my account" takes only a ticket the server issued (M18H): one made up is refused. The whole restore —
    // deleted, signed in to, restored — is walked on its own account in ResidentAccountDeletionTest.
    asResident($this, null, 'auth.restore', [], ['estate' => $site, 'ticket' => 'not-a-ticket', 'device_uid' => 'res-install-1', 'platform' => 'ios'], 422);

    // No SMS provider in production reads as unavailable, not as a silent success.
    config(['services.sms.driver' => '']);
    asResident($this, null, 'auth.otp.request', [], ['estate' => $site, 'channel' => 'sms', 'destination' => '+1 876 555 0147'], 503);
    config(['services.sms.driver' => 'log']);

    /* ── Pending: the claim and nothing else ─────────────────────── */

    expect(asResident($this, $t, 'me.show')->json('claim'))->toBeNull();
    asResident($this, $t, 'me.household', [], [], 403);

    $claim = asResident($this, $t, 'auth.claim_unit', [], ['full_name' => 'Patricia Morgan', 'lot' => $this->unit->reference, 'phase' => $this->unit->block, 'phone' => '876 555 0133', 'relationship' => 'owner']);
    expect($claim->json('claim.status'))->toBe('pending');
    asResident($this, $t, 'auth.claim_unit', [], ['full_name' => 'Patricia Morgan', 'lot' => $this->unit->reference, 'phone' => '876 555 0133', 'relationship' => 'owner'], 200);

    // M11 Part C: while pending, live updates for its own channel and its claim's, and nothing of a household.
    LiveAuthFixture::credentials();
    $account = $signedIn->json('account.id');
    $ownLive = 'resident.'.$site.'.'.$account;
    $claimLive = 'claim.'.$site.'.'.$claim->json('claim.id');

    expect(collect(asResident($this, $t, 'live.config')->json('channels'))->pluck('name')->all())->toBe([$ownLive, $claimLive]);

    // The estate approves it, and the account is active on its next request.
    app(Residents::class)->approveClaim(UnitClaim::query()->findOrFail($claim->json('claim.id')));

    $me = asResident($this, $t, 'me.show');
    expect($me->json('estate.timezone'))->toBe('America/Jamaica');

    expect($me->json('account.status'))->toBe('active')
        ->and($me->json('household.id'))->toBe($this->household->id)
        // What this person may do, from the matrix the server enforces (walked level by level in AccessLevelsTest).
        ->and($me->json('access.level'))->toBe('member')
        ->and($me->json('access.may.dues'))->toBeTrue();

    // Approved: the household's, the estate's and the phase's channels join the list, and are granted.
    $householdLive = 'household.'.$site.'.'.$this->household->id;
    $live = collect(asResident($this, $t, 'live.config')->json('channels'))->pluck('name')->all();

    expect($live)->toBe([$ownLive, $claimLive, $householdLive, 'community.'.$site, 'phase.'.$site.'.'.ChannelName::phaseKey((string) $this->unit->block)]);

    $granted = asResident($this, $t, 'live.auth', [], ['socket_id' => LiveAuthFixture::SOCKET, 'channel_names' => ['private-live.'.$householdLive, 'private-live.estate.'.$site.'.passes']]);
    expect($granted->json('channels.0.refused'))->toBeFalse()
        ->and($granted->json('channels.0.auth'))->toBe(LiveAuthFixture::expectedAuth('private-live.'.$householdLive, LiveAuthFixture::channelData('r-'.$account)))
        ->and($granted->json('channels.1.refused'))->toBeTrue('the gate\'s revocation feed is the guards\'');

    // What the household's channel missed, from the log (walked in full in CatchUpTest); the gate's feed stays the guards'.
    $caught = asResident($this, $t, 'live.catch_up', [], ['channels' => [['name' => $householdLive, 'c' => null, 'e' => null], ['name' => 'estate.'.$site.'.passes', 'e' => 0]]]);
    expect($caught->json('channels.0.status'))->toBe('ok')
        ->and($caught->json('channels.1'))->toBe(['name' => 'estate.'.$site.'.passes', 'status' => 'refused']);

    $resident = Resident::query()->findOrFail($me->json('resident.id'));

    /* ── The household ───────────────────────────────────────────── */

    asResident($this, $t, 'me.update', [], ['full_name' => 'Pat Morgan', 'phone' => '+18765550100']);
    // In its one stored form (M18C §6).
    expect($resident->refresh()->phone)->toBe('+1 876 555 0100');

    // Members are the PRIMARY resident's to add and to remove — a household member cannot invite (M4 §3.5).
    $this->household->residents()->whereKeyNot($resident->id)->update(['is_primary' => false]);
    $resident->forceFill(['is_primary' => false])->save();
    asResident($this, $t, 'members.store', [], ['full_name' => 'Devon Morgan', 'relationship' => 'child', 'phone' => '876 555 0110', 'no_email' => true], 403);
    expect(asResident($this, $t, 'me.household')->json('can_manage_members'))->toBeFalse();

    $resident->forceFill(['is_primary' => true])->save();
    expect(asResident($this, $t, 'me.household')->json('can_manage_members'))->toBeTrue();
    $devon = asResident($this, $t, 'members.store', [], ['full_name' => 'Devon Morgan', 'relationship' => 'child', 'phone' => '876 555 0110', 'no_email' => true]);
    expect(collect(asResident($this, $t, 'members.index')->json('items'))->firstWhere('full_name', 'Devon Morgan')['status'])->toBe('pending');

    // With no email there is no invite, and none can be sent — said, not faked; cancelling nothing answers the member.
    // (ResidentInvitesAreSentTest walks the invite itself: sent, refused, the resend timer, cancelled.)
    expect($devon->json('invite.status'))->toBe('none');
    expect(asResident($this, $t, 'members.invite', ['member' => $devon->json('id')], [], 422)->json('error.code'))->toBe('invite_no_email');
    expect(asResident($this, $t, 'members.invite_cancel', ['member' => $devon->json('id')])->json('invite.status'))->toBe('none');

    // Access levels — Member, Dependent, Helper — are the primary's to change, and never their own (AccessLevelsTest walks them).
    // They ship closed, so nobody is narrowed before the app that keeps a Helper's panic contacts is out; then switched on.
    expect($devon->json('access_level'))->toBe('member')
        ->and(asResident($this, $t, 'members.level', ['member' => $devon->json('id')], ['access_level' => 'dependent'], 409)->json('error.code'))->toBe('access_levels_not_open');
    config(['services.resident_app.access_levels' => true]);
    expect(asResident($this, $t, 'members.level', ['member' => $devon->json('id')], ['access_level' => 'dependent'])->json('access_level'))->toBe('dependent')
        ->and(asResident($this, $t, 'members.level', ['member' => $resident->id], ['access_level' => 'helper'], 409)->json('error.code'))->toBe('primary_is_member');

    // A Helper's days, hours and end date (8 Oct 2026): the primary's to set, and a Dependent has none; M50HelperAccessTest walks the rest.
    asResident($this, $t, 'members.access', ['member' => $devon->json('id')], ['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '17:00', 'paused' => false], 409);
    asResident($this, $t, 'members.level', ['member' => $devon->json('id')], ['access_level' => 'helper']);
    expect(asResident($this, $t, 'members.access', ['member' => $devon->json('id')], ['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '17:00', 'until' => '2030-12-31', 'paused' => false])->json('access_window.line'))
        ->toBe('Mon–Fri, 8:00 AM – 5:00 PM, until Dec 31, 2030');

    // Removing: the primary stays; somebody else's member and an id nobody has are the same 404; a removed member
    // leaves every list at once, and the row stays for the history that points at it.
    asResident($this, $t, 'members.destroy', ['member' => $resident->id], [], 409);
    asResident($this, $t, 'members.destroy', ['member' => 987654321], [], 404);
    $theirs = Resident::query()->where('household_id', '!=', $this->household->id)->value('id');
    if ($theirs !== null) {
        asResident($this, $t, 'members.destroy', ['member' => $theirs], [], 404);
        expect(Resident::query()->find($theirs))->not->toBeNull();
    }

    $resident->forceFill(['is_primary' => false])->save();
    asResident($this, $t, 'members.destroy', ['member' => $devon->json('id')], [], 403);
    $resident->forceFill(['is_primary' => true])->save();

    expect(asResident($this, $t, 'members.destroy', ['member' => $devon->json('id')])->json('removed'))->toBeTrue();
    expect(collect(asResident($this, $t, 'members.index')->json('items'))->pluck('full_name'))->not->toContain('Devon Morgan')
        ->and(Resident::query()->find($devon->json('id')))->toBeNull()
        ->and(Resident::withRemoved()->find($devon->json('id'))?->removed_by_resident_id)->toBe($resident->id);
    asResident($this, $t, 'members.destroy', ['member' => $devon->json('id')], [], 404);

    // Make, model and colour are REQUIRED since 23 September 2026 — a guard matches the car in front of
    // them against the register, and a plate alone does not let them. The second call is the same plate
    // again: it returns the row already registered, 200 rather than a second car.
    $plate = 'PM '.random_int(1000, 9999);
    $car = ['make' => 'Toyota', 'model' => 'Axio', 'colour' => 'Silver'];
    asResident($this, $t, 'vehicles.store', [], ['plate' => strtolower($plate), ...$car]);
    asResident($this, $t, 'vehicles.store', [], ['plate' => $plate, ...$car], 200);
    expect(collect(asResident($this, $t, 'vehicles.index')->json('items'))->pluck('plate'))->toContain($plate);

    foreach (['make', 'model', 'colour'] as $field) {
        asResident($this, $t, 'vehicles.store', [], ['plate' => 'PM 1', ...collect($car)->except($field)->all()], 422);
    }

    // The household changes its own vehicle in place (M50 §5.3b) — the plate upper-cased as on the way in…
    $carId = collect(asResident($this, $t, 'vehicles.index')->json('items'))->firstWhere('plate', $plate)['id'];
    $changed = asResident($this, $t, 'vehicles.update', ['vehicle' => $carId], ['plate' => strtolower($plate), 'make' => 'Toyota', 'model' => 'Corolla', 'colour' => 'Blue']);
    expect($changed->json('model'))->toBe('Corolla')->and($changed->json('plate'))->toBe($plate);

    // …and removes it; somebody else's and an id nobody has are the same 404 (INV-11).
    $theirCar = DB::connection('tenant')->table('household_vehicles')->where('household_id', '!=', $this->household->id)->value('id');
    if ($theirCar !== null) {
        asResident($this, $t, 'vehicles.update', ['vehicle' => $theirCar], ['plate' => 'PM 2', ...$car], 404);
        asResident($this, $t, 'vehicles.destroy', ['vehicle' => $theirCar], [], 404);
    }
    expect(asResident($this, $t, 'vehicles.destroy', ['vehicle' => $carId])->json('removed'))->toBeTrue();
    asResident($this, $t, 'vehicles.destroy', ['vehicle' => $carId], [], 404);

    // The household's pets (M50 §5.3d): added (the same pet again is the row already there), changed, removed.
    $pet = asResident($this, $t, 'pets.store', [], ['name' => 'Bella', 'kind' => 'dog']);
    expect($pet->json('kind_label'))->toBe('Dog')->and($pet->json('already_listed'))->toBeFalse();
    expect(asResident($this, $t, 'pets.store', [], ['name' => 'bella', 'kind' => 'dog'], 200)->json('id'))->toBe($pet->json('id'));
    asResident($this, $t, 'pets.store', [], ['name' => 'Rex', 'kind' => 'dragon'], 422);
    expect(asResident($this, $t, 'pets.update', ['pet' => $pet->json('id')], ['name' => 'Bella', 'kind' => 'cat'])->json('kind'))->toBe('cat');
    expect(collect(asResident($this, $t, 'pets.index')->json('items'))->pluck('name'))->toContain('Bella');
    expect(collect(asResident($this, $t, 'me.household')->json('pets'))->pluck('name'))->toContain('Bella');
    expect(asResident($this, $t, 'pets.destroy', ['pet' => $pet->json('id')])->json('removed'))->toBeTrue();
    asResident($this, $t, 'pets.destroy', ['pet' => $pet->json('id')], [], 404);

    // A number already on the list is answered with the row already there, however it was typed (DESIGN §14)…
    $karen = asResident($this, $t, 'contacts.store', [], ['name' => 'Karen Morgan', 'relationship' => 'sister', 'phone' => '+1 876 555 0199', 'email' => 'karen.morgan@example.com']);
    expect($karen->json('already_listed'))->toBeFalse();
    $again = asResident($this, $t, 'contacts.store', [], ['name' => 'Karen Morgan', 'relationship' => 'sister', 'phone' => '876-555-0199', 'email' => 'karen.morgan@example.com'], 200);
    expect($again->json('id'))->toBe($karen->json('id'))->and($again->json('already_listed'))->toBeTrue();
    // M12 §2.5: Karen is given the email a panic now writes to.
    expect(asResident($this, $t, 'contacts.update', ['contact' => $karen->json('id')], ['email' => 'karen.morgan@example.com'])->json('email'))->toBe('karen.morgan@example.com');
    asResident($this, $t, 'contacts.index');

    // …and the household removes its own emergency contact (a second one, so Karen stays for the panic below).
    $spare = asResident($this, $t, 'contacts.store', [], ['name' => 'Spare Morgan', 'relationship' => 'cousin', 'phone' => '876 555 0198', 'email' => 'spare.morgan@example.com']);
    expect(asResident($this, $t, 'contacts.destroy', ['contact' => $spare->json('id')])->json('removed'))->toBeTrue();
    asResident($this, $t, 'contacts.destroy', ['contact' => $spare->json('id')], [], 404);
    expect(collect(asResident($this, $t, 'contacts.index')->json('items'))->pluck('id'))->toContain($karen->json('id'))->not->toContain($spare->json('id'));

    /* ── Passes, the e-pass, a guard at the gate ─────────────────── */

    $pass = asResident($this, $t, 'visitor_passes.store', [], [
        'category' => 'single', 'visitor_name' => 'Marcia James', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'vehicle_plate' => 'PC 4471', 'arrival' => 'drive',
        'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHours(3)->toIso8601String(),
    ]);
    expect($pass->json('token'))->toContain('.')->and($pass->json('single_use'))->toBeTrue()->and($pass->json('arrival'))->toBe('drive');

    // How the visitor arrives (M50 §6.3): Ride in comes with "no vehicle", and Drive in never does.
    $ride = ['category' => 'single', 'visitor_name' => 'Kemar Ride', 'purpose' => 'Ride pickup or drop-off', 'visitor_phone' => '876 555 0124', 'no_vehicle' => true, 'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHours(2)->toIso8601String()];
    expect(asResident($this, $t, 'visitor_passes.store', [], [...$ride, 'arrival' => 'ride'])->json('arrival'))->toBe('ride');
    asResident($this, $t, 'visitor_passes.store', [], [...$ride, 'visitor_name' => 'Kemar Drive', 'arrival' => 'drive'], 422);

    asResident($this, $t, 'visitor_passes.update', ['pass' => $pass->json('id')], ['vehicle_plate' => '1234 AB']);
    asResident($this, $t, 'visitor_passes.update', ['pass' => $pass->json('id')], ['valid_to' => now()->addDays(3)->toIso8601String()], 422);
    expect(asResident($this, $t, 'visitor_passes.share', ['pass' => $pass->json('id')], ['channel' => 'whatsapp'])->json('share_count'))->toBe(1);
    expect(collect(asResident($this, $t, 'visitor_passes.index')->json('items'))->pluck('id'))->toContain($pass->json('id'));
    expect(asResident($this, $t, 'visitor_passes.cancel', ['pass' => $pass->json('id')])->json('status'))->toBe('cancelled');

    $epass = asResident($this, $t, 'epass.show');
    expect($epass->json('category'))->toBe('resident')->and($epass->json('single_use'))->toBeFalse();
    expect(asResident($this, $t, 'epass.show')->json('id'))->toBe($epass->json('id'));

    // A restricted household is told in the gate's words, and nothing more.
    $this->household->forceFill(['access_restricted' => true])->save();
    asResident($this, $t, 'visitor_passes.store', [], [
        'category' => 'single', 'visitor_name' => 'Guest', 'purpose' => 'Family visit', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true, 'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHour()->toIso8601String(),
    ], 409)->assertJsonPath('error.code', 'access_restricted');
    // M12 §2.13: a pass says what the visit is for, or it is refused in words.
    asResident($this, $t, 'visitor_passes.store', [], [
        'category' => 'single', 'visitor_name' => 'Guest', 'visitor_phone' => '876 555 0123', 'no_vehicle' => true, 'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHour()->toIso8601String(),
    ], 422)->assertJsonPath('errors.purpose.0', 'Say what the visit is for — e.g. Family visit, Plumber, Delivery. The guard sees it at the gate.');
    // …and is told on their OWN dues screen first: the balance carries the gate's decision, so a resident never
    // first learns of a restriction from a guest being turned away.
    expect(asResident($this, $t, 'households.balance', ['household' => $this->household->id])->json('access_restricted'))->toBeTrue();
    $this->household->forceFill(['access_restricted' => false])->save();
    expect(asResident($this, $t, 'households.balance', ['household' => $this->household->id])->json('access_restricted'))->toBeFalse();

    $walkUp = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
        'unit_id' => $this->unit->id, 'household_id' => $this->household->id, 'guard_id' => 1, 'guard_name' => 'Kemar Reid', 'post_name' => 'Main Gate',
        'visitor_name' => 'Courier — Tank Weld', 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(collect(asResident($this, $t, 'me.household')->json('pending_approvals'))->pluck('id'))->toContain($walkUp);
    $approved = asResident($this, $t, 'approvals.respond', ['approval' => $walkUp], ['decision' => 'approve']);
    expect($approved->json('status'))->toBe('approved')->and($approved->json('visitors_held'))->toBeFalse();
    asResident($this, $t, 'approvals.respond', ['approval' => $walkUp], ['decision' => 'deny'], 409);

    // M22: a household whose visitors are held is told so when it approves one — not "the guard is letting them in".
    $this->household->forceFill(['access_restricted' => true])->save();
    $heldWalkUp = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
        'unit_id' => $this->unit->id, 'household_id' => $this->household->id, 'guard_id' => 1, 'guard_name' => 'Kemar Reid', 'post_name' => 'Main Gate',
        'visitor_name' => 'Cousin Andre', 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $held = asResident($this, $t, 'approvals.respond', ['approval' => $heldWalkUp], ['decision' => 'approve']);
    expect($held->json('visitors_held'))->toBeTrue()
        ->and(json_encode($held->json()))->not->toMatch('/balance|owe|overdue|\$\d/i');
    $this->household->forceFill(['access_restricted' => false])->save();

    // The panic screen's own read of the household's emergency contacts — every access level reaches it, because
    // panic is never removed. (Here, beside the panic, and not beside `contacts.index`, where W-DATA's removals go.)
    expect(collect(asResident($this, $t, 'panic.contacts')->json('items'))->pluck('name'))->toContain('Karen Morgan');

    $panic = asResident($this, $t, 'duress.store', [], ['mode' => 'audible']);
    expect($panic->json('kind'))->toBe('panic');
    // "Message dispatch…" while it is open (M50 §4.2 #2) — walked in full in M50PanicMessagesTest.
    expect(asResident($this, $t, 'duress.messages.store', ['alert' => $panic->json('id')], ['body' => 'At the back door.', 'client_key' => 'walk-1'])->json('messages.0.from'))->toBe('you');
    // Where the resident is while the panic stays open (12 Oct 2026, GUARD_TRACKING.md 5.3) — walked in full in GuardSignalTest.
    expect(asResident($this, $t, 'duress.positions.store', ['alert' => $panic->json('id')], ['positions' => [
        ['idempotency_key' => 'walk-fix-1', 'captured_at' => now()->subSeconds(5)->toIso8601String(), 'lat' => 18.0181, 'lng' => -76.8102, 'accuracy_metres' => 9],
    ]])->json('results.0.result'))->toBe('accepted');

    // "Share live audio" (M50 §4.2 #1): one piece, multipart — walked in full in M50PanicAudioTest.
    Storage::fake('panic_audio');
    app('auth')->forgetGuards();
    $piece = $this->withToken($t)->post('/api/v1/duress/'.$panic->json('id').'/audio', ['file' => UploadedFile::fake()->create('piece.m4a', 16, 'audio/mp4'), 'seq' => 0, 'duration_ms' => 5000], [
        'Accept' => 'application/json', 'Idempotency-Key' => 'walk-audio-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String(),
    ])->assertCreated();
    ApiContract::assertMatches($piece, 'duress.audio.store');
    $GLOBALS['residentContractCalled']['duress.audio.store'] = true;
    FacilitiesFixture::boot();
    expect(asResident($this, $t, 'duress.cancel', ['alert' => $panic->json('id')])->json('status'))->toBe('false_alarm');

    // Read back (mobile-api): the panic timeline polls this. The resident's own alert only.
    expect(asResident($this, $t, 'duress.show', ['alert' => $panic->json('id')])->json('status'))->toBe('false_alarm');
    asResident($this, $t, 'duress.show', ['alert' => 999_999_999], [], 404);

    /* ── M4 §3.2: where to reach this phone when the app is closed (walked in full in PushNotificationsTest) ── */

    $registered = asResident($this, $t, 'push.register', [], ['token' => 'ExponentPushToken[contractResidentPhone1]', 'platform' => 'ios', 'muted' => ['maintenance', 'panic', 'gate']]);
    expect($registered->json('muted'))->toBe(['maintenance'])                                // panic and the gate cannot be switched off
        ->and((string) $registered->getContent())->not->toContain('ExponentPushToken');
    asResident($this, $t, 'push.register', [], ['token' => 'https://exp.host/not-a-token', 'platform' => 'ios'], 422);
    expect(asResident($this, $t, 'push.unregister')->json('registered'))->toBeFalse();

    /* ── Dues — read, and how to pay by hand ─────────────────────── */

    $charge = Charge::query()->create([
        'unit_id' => $this->unit->id, 'type' => 'dues', 'period' => now()->format('Y-m'), 'reference' => 'API-'.strtoupper(bin2hex(random_bytes(4))),
        'description' => 'Maintenance fee', 'amount_minor' => 12_500_00, 'currency' => 'JMD', 'due_on' => now()->addDays(10)->toDateString(), 'status' => 'outstanding',
    ]);

    $payment = new Payment;
    $payment->forceFill([
        'unit_id' => $this->unit->id, 'receipt_no' => 'FXT-API-'.strtoupper(bin2hex(random_bytes(4))), 'amount_minor' => 12_500_00, 'currency' => 'JMD',
        'method' => Payment::BANK, 'reference' => 'NCB transfer', 'received_at' => now()->subDay(), 'entered_at' => now(), 'status' => 'recorded',
    ])->save();

    $invoices = asResident($this, $t, 'invoices.index');
    expect(collect($invoices->json('items'))->firstWhere('id', $charge->id)['amount'])->toBe('12500.00');

    $household = $this->household->id;
    expect(asResident($this, $t, 'households.balance', ['household' => $household])->json('currency'))->toBe('JMD');
    asResident($this, $t, 'households.balance', ['household' => $household + 100000], [], 404);
    asResident($this, $t, 'households.statement', ['household' => $household], ['limit' => 10]);

    $intent = asResident($this, $t, 'invoices.pay_intent', ['invoice' => $charge->id]);
    // The test-card checkout is on (M12 §2.6): a card, as a demonstration, and the bank transfer beside it.
    expect($intent->json('online_payment_available'))->toBeTrue()
        ->and($intent->json('card_mode'))->toBe('demonstration')
        ->and($intent->json('quote_reference'))->toContain($charge->reference);

    // PAID WITH A TEST CARD, FOR REAL IN THE BOOKS (D-179): a charge posted through the ledger, part-paid in the app.
    app(Dues::class)->charge(unit: $this->unit, amount: Money::of('500.00', 'JMD'), description: 'Contract walk charge', dueOn: now()->toDateString(), type: 'dues', account: '4000');
    $before = (float) asResident($this, $t, 'households.balance', ['household' => $this->household->id])->json('balance');
    $paid = asResident($this, $t, 'payments.card', [], ['amount' => '120.00', 'brand' => 'visa', 'last4' => '4242']);
    expect($paid->json('is_demonstration'))->toBeTrue()
        ->and($paid->json('method'))->toBe('card')
        ->and($paid->json('reference'))->toBe('Demonstration card payment · Visa •••• 4242')
        ->and((float) $paid->json('balance'))->toBe($before - 120.0);
    asResident($this, $t, 'payments.card', [], ['amount' => '10.00', 'brand' => 'visa', 'last4' => '0002'], 422)->assertJsonPath('error.code', 'card_declined');
    asResident($this, $t, 'payments.card', [], ['amount' => '10.00', 'brand' => 'visa', 'last4' => '1111'], 409)->assertJsonPath('error.code', 'payment_refused');
    asResident($this, $t, 'payments.card', [], ['amount' => '99999.00', 'brand' => 'visa', 'last4' => '4242'], 409)->assertJsonPath('error.code', 'payment_refused');

    // The card gateway (M16 §2.2), against the simulated provider: one tap, one payment; its standing read back.
    config(['payments.gateway.driver' => 'simulated', 'payments.gateway.webhook_secret' => 'whsec_contract_walk']);
    $opened = asResident($this, $t, 'payments.intents.store', [], ['amount' => '50.00']);
    expect($opened->json('status'))->toBe('created')->and($opened->json('reference'))->toStartWith('PI-');
    expect(asResident($this, $t, 'payments.intents.show', ['reference' => $opened->json('reference')])->json('amount'))->toBe('50.00');
    config(['payments.gateway.driver' => null]);
    asResident($this, $t, 'payments.intents.store', [], ['amount' => '50.00'], 409)->assertJsonPath('error.code', 'payment_refused');

    expect(collect(asResident($this, $t, 'payments.index')->json('items'))->pluck('id'))->toContain($payment->id);
    expect(asResident($this, $t, 'payments.receipt', ['payment' => $payment->id])->json('amount'))->toBe('12500.00');
    // A saved test card and AutoPay (M50 §4.2 #3) — every rule walked in M50CardsAndAutopayTest.
    $card = asResident($this, $t, 'cards.store', [], ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2030, 'holder' => 'Contract Walk']);
    expect($card->json('default'))->toBeTrue();
    expect(asResident($this, $t, 'cards.index')->json('items.0.id'))->toBe($card->json('id'));
    asResident($this, $t, 'cards.default', ['card' => $card->json('id')]);
    $autopay = asResident($this, $t, 'autopay.store', [], ['card' => $card->json('id')]);
    expect($autopay->json('on'))->toBeTrue()->and(asResident($this, $t, 'autopay.show')->json('day'))->toBe(28);
    expect(asResident($this, $t, 'autopay.destroy', ['autopay' => $autopay->json('id')])->json('on'))->toBeFalse();
    asResident($this, $t, 'cards.destroy', ['card' => $card->json('id')]);

    /* ── Tickets, notices, meetings ──────────────────────────────── */

    $ticket = asResident($this, $t, 'tickets.store', [], ['title' => 'Street light out by the gate', 'category' => 'electrical', 'description' => 'Out since Tuesday night.', 'priority' => 'low']);
    // The console's own word for the status, not every client's own translation of the same seven (Hassam, Batch 1, 12 Oct).
    expect($ticket->json('status'))->toBe('submitted')->and($ticket->json('status_label'))->toBe('Submitted')
        ->and($ticket->json('timeline.0.event'))->toBe('reported');

    app('auth')->forgetGuards();
    $media = $this->withToken($t)->post('/api/v1/tickets/'.$ticket->json('id').'/media', [
        'file' => UploadedFile::fake()->image('street-light.jpg', 48, 48),
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'ticket-media-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String()])->assertCreated();
    ApiContract::assertMatches($media, 'tickets.media');
    $GLOBALS['residentContractCalled']['tickets.media'] = true;
    FacilitiesFixture::boot();

    FacilitiesFixture::platform()->run(function () use ($ticket, $media): void {
        $path = (string) DB::connection('tenant')->table('ticket_media')->where('id', $media->json('media_id'))->value('path');
        expect(Storage::disk('local')->exists($path))->toBeTrue();
        Storage::disk('local')->delete($path);
        expect($ticket->json('id'))->toBeInt();
    });
    FacilitiesFixture::boot();

    expect(collect(asResident($this, $t, 'tickets.index')->json('items'))->firstWhere('id', $ticket->json('id'))['media_count'])->toBe(1);

    // THE HOUSEHOLD ANSWERS "IS IT FIXED?" (26 Sep, R8). Not before the office says it is finished …
    asResident($this, $t, 'tickets.confirm_fixed', ['ticket' => $ticket->json('id')], [], 409);
    asResident($this, $t, 'tickets.still_a_problem', ['ticket' => $ticket->json('id')], ['reason' => 'Still dark'], 409);

    // … and then either answer, on the household's own request: confirmed, then reopened with what is still wrong.
    app(Maintenance::class)->resolve(MaintenanceTicket::query()->findOrFail($ticket->json('id')), 'Lamp replaced.');
    $verified = asResident($this, $t, 'tickets.confirm_fixed', ['ticket' => $ticket->json('id')]);
    // The stored word and the console's own word for it differ (M96), proof this is a real translation, not an echo.
    expect($verified->json('status'))->toBe('verified')->and($verified->json('status_label'))->toBe('Closed');
    $reopened = asResident($this, $t, 'tickets.still_a_problem', ['ticket' => $ticket->json('id')], ['reason' => 'It went out again the next night.']);
    expect($reopened->json('status'))->toBe('acknowledged')->and($reopened->json('status_label'))->toBe('Under review')
        ->and(collect($reopened->json('timeline'))->last()['event'])->toBe('reopened')
        ->and(collect($reopened->json('timeline'))->last()['note'])->toBe('It went out again the next night.');

    // M47 §11b: "Add a comment" sends — a note on the ticket's history, in the resident's words and under their name.
    $commented = asResident($this, $t, 'tickets.comment', ['ticket' => $ticket->json('id')], ['body' => 'The new lamp flickers after nine.']);
    expect(collect($commented->json('timeline'))->last())->toMatchArray(['event' => 'note', 'note' => 'The new lamp flickers after nine.'])
        ->and(collect($commented->json('timeline'))->last()['by']['kind'])->toBe('resident');

    $notice = new Notice;
    $notice->forceFill([
        'kind' => Notice::URGENT, 'title' => 'Water lock-off Thursday', 'body' => 'NWC works on the main line, 10 AM to 2 PM.',
        'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'Estate Secretary', 'published_at' => now()->subMinute(),
    ])->save();

    expect(collect(asResident($this, $t, 'notices.index')->json('items'))->firstWhere('id', $notice->id)['read_at'])->toBeNull();
    expect(asResident($this, $t, 'notices.read', ['notice' => $notice->id])->json('notice_id'))->toBe($notice->id);

    // A file the office put with the notice (M4 §3.5): named in the list, fetched with THIS token — the file itself,
    // not JSON, so it is walked by hand the way tickets.media is. Every refusal is in ResidentCommunityHistoryTest.
    $letter = "%PDF-1.4\n% contract walk\n%%EOF\n";
    $by = User::factory()->create();
    $attached = FacilitiesFixture::platform()->run(fn () => app(Notices::class)->attach($notice, 'NWC letter.pdf', $letter, 'application/pdf', $by));
    FacilitiesFixture::boot();

    $listed = collect(asResident($this, $t, 'notices.index')->json('items'))->firstWhere('id', $notice->id);
    expect($listed['attachments'])->toBe([['id' => $attached->id, 'name' => 'NWC letter.pdf', 'content_type' => 'application/pdf', 'bytes' => strlen($letter)]])
        ->and($listed['seen_households'])->toBe(1);

    asResident($this, $t, 'notices.attachment', ['notice' => $notice->id, 'attachment' => $attached->id + 100000], [], 404);
    app('auth')->forgetGuards();
    $file = $this->withToken($t)->get('/api/v1/notices/'.$notice->id.'/attachments/'.$attached->id)->assertOk();
    expect($file->getContent())->toBe($letter);
    $GLOBALS['residentContractCalled']['notices.attachment'] = true;
    FacilitiesFixture::boot();

    FacilitiesFixture::platform()->run(fn () => Storage::disk('local')->delete((string) $attached->path));
    FacilitiesFixture::boot();

    $meeting = new Meeting;
    $meeting->forceFill([
        'type' => Meeting::AGM, 'title' => 'Annual General Meeting '.now()->year, 'starts_at' => now()->addDays(30), 'venue' => 'Club House',
        'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now(),
    ])->save();

    expect(collect(asResident($this, $t, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id)['my_rsvp'])->toBeNull();

    // ONE TIME RULE (M4 §3.1). A meeting the office set for 10:00 AM is 10:00 ON THE ESTATE'S CLOCK: the row holds the
    // UTC moment that means (15:00), and the phone is sent that instant — in UTC, to be shown in `estate.timezone`.
    // (This used to be a relabelling workaround, `OfficeTime`, that sent `T10:00:00-05:00` over wall-clock digits.)
    $meetingDay = now()->addDays(20)->format('Y-m-d');
    $morning = new Meeting;
    $morning->forceFill([
        'type' => Meeting::AGM, 'title' => 'Ten o\'clock meeting '.bin2hex(random_bytes(2)), 'starts_at' => $meetingDay.' 10:00:00', 'venue' => 'Club House',
        'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now(),
    ])->save();
    expect(DB::connection('tenant')->table('meetings')->where('id', $morning->id)->value('starts_at'))->toBe($meetingDay.' 15:00:00')
        ->and($morning->fresh()->starts_at->format('g:i A'))->toBe('10:00 AM')
        ->and(collect(asResident($this, $t, 'meetings.index')->json('items'))->firstWhere('id', $morning->id)['starts_at'])->toBe($meetingDay.'T15:00:00+00:00');
    expect(asResident($this, $t, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending'])->json('households_attending'))->toBeGreaterThanOrEqual(1);

    // The minutes of a meeting already held (M21 §1.4): read in the app once published, and not before.
    $held = new Meeting;
    $held->forceFill([
        'type' => Meeting::EGM, 'title' => 'Held meeting '.bin2hex(random_bytes(2)), 'starts_at' => now()->subDays(3), 'venue' => 'Club House',
        'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now()->subDays(30),
    ])->save();
    $minutes = MeetingMinutes::query()->create(['meeting_id' => $held->id, 'body' => 'Present: 12 households. The pool hours were agreed.', 'recorded_by_name' => 'The Secretary']);
    asResident($this, $t, 'meetings.minutes', ['meeting' => $held->id], [], 404);
    $minutes->forceFill(['published_at' => now()])->save();
    expect(asResident($this, $t, 'meetings.minutes', ['meeting' => $held->id])->json('body'))->toBe('Present: 12 households. The pool hours were agreed.');

    /* ── An election ─────────────────────────────────────────────── */

    [$ballot, $options] = contractBallot('Contract Election '.bin2hex(random_bytes(2)), Ballot::VOTING_OPEN);

    expect(collect(asResident($this, $t, 'elections.index')->json('items'))->firstWhere('id', $ballot->id)['has_voted'])->toBeFalse();
    $before = asResident($this, $t, 'elections.eligibility', ['ballot' => $ballot->id]);
    expect($before->json('eligible'))->toBeTrue()
        ->and($before->json('has_voted'))->toBeFalse()
        ->and($before->json('voted_on'))->toBeNull();
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[0]->id, $options[1]->id, $options[2]->id]], 422);
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[0]->id]]);
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[1]->id]], 409);

    // A receipt opened later still knows its DAY — a date and never a time, and nothing about the paper.
    $after = asResident($this, $t, 'elections.eligibility', ['ballot' => $ballot->id]);
    expect($after->json('has_voted'))->toBeTrue()
        ->and($after->json('voted_on'))->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and(array_keys($after->json()))->not->toContain('option_ids', 'marks', 'receipt', 'voted_at');
    asResident($this, $t, 'elections.results', ['ballot' => $ballot->id], [], 409);

    [$certified] = contractBallot('Certified Election '.bin2hex(random_bytes(2)), Ballot::CERTIFIED, published: true);
    expect(asResident($this, $t, 'elections.results', ['ballot' => $certified->id])->json('positions.0.options.0.votes'))->toBe(0);

    /* ── A committee ballot (M62 A9): put to a recorded roll, seen and voted by it alone ── */

    // The fixture estate's tenancy again (a request ends it), and a second household of this file's own, on Lot 982
    // (the fixture keeps no households of its own): a roll names it; only Lot 981's household votes.
    FacilitiesFixture::boot();
    $governance = app(Governance::class);
    $year = (int) now()->year;
    $otherUnit = FacilitiesFixture::scratchUnit('Lot 982');
    $other = (int) Household::query()->firstOrCreate(['unit_id' => $otherUnit->id], ['name' => 'Rowe household', 'access_restricted' => false])->id;

    // Off its roll: never listed, every route a 404, and the service refuses the paper as well.
    $notOurs = $governance->createCommitteeBallot($year, 'Committee Ballot · Pool Hours '.bin2hex(random_bytes(2)), 'Approve the new pool hours?', [$other => 'a committee member\'s household']);
    $governance->open($notOurs, now()->addHours(2));
    $notOurs->refresh();
    $theirFor = (int) $notOurs->options()->where('label', 'For')->value('id');

    expect($notOurs->stage)->toBe(Ballot::VOTING_OPEN)
        ->and($governance->eligibleHouseholdCount($notOurs))->toBe(1)
        ->and(collect(asResident($this, $t, 'elections.index')->json('items'))->pluck('id')->all())->not->toContain($notOurs->id);
    asResident($this, $t, 'elections.eligibility', ['ballot' => $notOurs->id], [], 404);
    asResident($this, $t, 'elections.ballot', ['ballot' => $notOurs->id], ['option_ids' => [$theirFor]], 404);

    FacilitiesFixture::boot();
    expect(fn () => $governance->castVote($notOurs, $this->unit, [$theirFor]))->toThrow(DomainException::class, 'is put to the households on its roll');

    // On its roll: a motion goes from its draft straight to the vote and never to nominations; it counts its roll; the
    // household sees it, may vote, casts one paper and is refused a second.
    $ours = $governance->createCommitteeBallot($year, 'Committee Ballot · Budget '.bin2hex(random_bytes(2)), 'Approve the budget as presented by the Treasurer?', [$this->household->id => 'the household on the phone that votes', $other => 'a committee member\'s household']);
    expect($governance->eligibleHouseholdCount($ours))->toBe(2)
        ->and(fn () => $governance->openNominations($ours, now()->addDays(3)))->toThrow(DomainException::class, 'cannot move to "Nominations Open"');
    $governance->open($ours, now()->addHours(2));
    $ours->refresh();
    $choices = $ours->options()->orderBy('sort_order')->get();

    expect($ours->eligible_households)->toBe(2)
        ->and($choices->pluck('label')->all())->toBe(['For', 'Against', 'Abstain'])
        ->and(collect(asResident($this, $t, 'elections.index')->json('items'))->pluck('id')->all())->toContain($ours->id)
        ->and(asResident($this, $t, 'elections.eligibility', ['ballot' => $ours->id])->json('eligible'))->toBeTrue();
    asResident($this, $t, 'elections.ballot', ['ballot' => $ours->id], ['option_ids' => [$choices[0]->id]]);
    asResident($this, $t, 'elections.ballot', ['ballot' => $ours->id], ['option_ids' => [$choices[1]->id]], 409);

    // The console keeps a motion out of its year's election board and lists it as the committee's, with its count.
    FacilitiesFixture::boot();
    $room = $governance->controlRoom($year);
    $card = collect($room['committee_ballots'])->firstWhere('id', $ours->id);

    expect($card)->not->toBeNull()
        ->and($card['eligible_households'])->toBe(2)
        ->and($card['papers_in'])->toBe(1)
        ->and($room['subtitle'])->not->toContain('Committee Ballot');

    /* ── Amenities ───────────────────────────────────────────────── */

    $amenities = asResident($this, $t, 'amenities.index');
    $pool = collect($amenities->json('items'))->firstWhere('name', 'Pool Deck');
    // The phone sends a moment WITH THE ESTATE'S OFFSET (`…T07:00:00-05:00`), so the day is built on the estate's clock.
    $day = now($me->json('estate.timezone'))->addDays(6)->startOfDay();

    $booking = asResident($this, $t, 'bookings.store', [], [
        'amenity_id' => $pool['id'], 'starts_at' => $day->copy()->setTime(7, 0)->toIso8601String(), 'ends_at' => $day->copy()->setTime(8, 0)->toIso8601String(), 'guests' => 4,
    ]);
    expect($booking->json('status'))->toBe(AmenityBooking::PENDING);

    // THE DIARY, IN THE HOUSEHOLD'S TERMS (M21 §1.5). The hours now taken are on the list — the hours only, never whose
    // or which booking — and a request across them is refused with those hours and what to do. The estate's own
    // sentence named the other booking's reference and spoke to the office.
    $listed = collect(asResident($this, $t, 'amenities.index')->json('items'))->firstWhere('name', 'Pool Deck');
    expect(collect($listed['taken'])->contains(fn (array $p): bool => Carbon\Carbon::parse($p['starts_at'])->equalTo($day->copy()->setTime(7, 0)) && Carbon\Carbon::parse($p['ends_at'])->equalTo($day->copy()->setTime(8, 0))))->toBeTrue()
        ->and(array_keys($listed['taken'][0]))->toBe(['starts_at', 'ends_at']);
    $clash = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $pool['id'], 'starts_at' => $day->copy()->setTime(7, 30)->toIso8601String(), 'ends_at' => $day->copy()->setTime(9, 0)->toIso8601String(), 'guests' => 2], 422);
    expect($clash->json('error.message'))->toBe('The Pool Deck is already booked from 7:00 AM to 8:00 AM JA on '.$day->format('l, j F').'. Choose hours outside that time, or another day.')
        ->and($clash->json('error.message'))->not->toContain('BKG-');

    // A time keeps its INSTANT through the API. The app sends Kingston's offset; the offset is honoured and the row holds
    // true UTC (19:00); what comes back is that instant in UTC — it used to come back five hours early.
    $sent = $day->copy()->addDay()->format('Y-m-d').'T14:00:00-05:00';
    $afternoon = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $pool['id'], 'starts_at' => $sent, 'ends_at' => $day->copy()->addDay()->format('Y-m-d').'T15:00:00-05:00', 'guests' => 0]);
    expect(Carbon\Carbon::parse($afternoon->json('starts_at'))->equalTo(Carbon\Carbon::parse($sent)))->toBeTrue($afternoon->json('starts_at'))
        ->and($afternoon->json('starts_at'))->toBe($day->copy()->addDay()->format('Y-m-d').'T19:00:00+00:00')
        ->and(DB::connection('tenant')->table('amenity_bookings')->where('id', $afternoon->json('id'))->value('starts_at'))->toBe($day->copy()->addDay()->format('Y-m-d').' 19:00:00');
    expect(collect(asResident($this, $t, 'bookings.index')->json('items'))->firstWhere('id', $afternoon->json('id'))['starts_at'])->toBe($afternoon->json('starts_at'));
    asResident($this, $t, 'bookings.cancel', ['booking' => $afternoon->json('id')]);

    // Refused for the hour, not for a missing field: everything else is as the app sends it.
    $closed = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $pool['id'], 'starts_at' => $day->copy()->setTime(3, 0)->toIso8601String(), 'ends_at' => $day->copy()->setTime(4, 0)->toIso8601String(), 'guests' => 0], 422);
    expect($closed->json('errors.guests'))->toBeNull();
    expect(collect(asResident($this, $t, 'bookings.index')->json('items'))->pluck('id'))->toContain($booking->json('id'));
    expect(asResident($this, $t, 'bookings.cancel', ['booking' => $booking->json('id')])->json('status'))->toBe(AmenityBooking::CANCELLED);
    asResident($this, $t, 'bookings.cancel', ['booking' => $booking->json('id')], [], 409);

    // A DEPOSIT ALWAYS MOVES ON, AND THE APP IS TOLD THE TRUTH ABOUT IT (M4 §4, defect 2). The Gazebo takes one.
    $gazebo = collect($amenities->json('items'))->firstWhere('name', 'Gazebo');
    $morning = static fn (int $days): array => ['starts_at' => $day->copy()->addDays($days)->setTime(10, 0)->toIso8601String(), 'ends_at' => $day->copy()->addDays($days)->setTime(12, 0)->toIso8601String(), 'guests' => 6];
    $party = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $gazebo['id'], ...$morning(2)]);
    expect($party->json('deposit'))->toBe('5000.00')
        ->and($party->json('deposit_state'))->toBe(AmenityBooking::DEPOSIT_AWAITING)
        ->and($party->json('deposit_kept'))->toBeNull();

    // Paid at the office, then cancelled in the app inside the terms: it goes back in the same breath, and the answer says so.
    app(Amenities::class)->holdDeposit(AmenityBooking::findOrFail($party->json('id')));
    $returned = asResident($this, $t, 'bookings.cancel', ['booking' => $party->json('id')]);
    expect($returned->json('status'))->toBe(AmenityBooking::CANCELLED)
        ->and($returned->json('deposit_state'))->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and($returned->json('deposit_kept'))->toBeNull();

    // Kept IN PART by the office: `forfeited`, and how much — never "the deposit was kept" over a household that got most of it back.
    $damaged = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $gazebo['id'], ...$morning(3)]);
    app(Amenities::class)->holdDeposit(AmenityBooking::findOrFail($damaged->json('id')));
    app(Amenities::class)->forfeitDeposit(AmenityBooking::findOrFail($damaged->json('id')), 'Two chairs broken.', keepMinor: 2_000_00);
    $row = collect(asResident($this, $t, 'bookings.index')->json('items'))->firstWhere('id', $damaged->json('id'));
    expect($row['deposit_state'])->toBe(AmenityBooking::DEPOSIT_FORFEITED)
        ->and($row['deposit'])->toBe('5000.00')
        ->and($row['deposit_kept'])->toBe('2000.00');

    // An unpaid deposit under a cancelled booking is not "awaiting" for ever: nothing more is asked of the household.
    // (+5, not +4: board 19's seeded Gazebo booking is on that day.)
    $never = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $gazebo['id'], ...$morning(5)]);
    expect(asResident($this, $t, 'bookings.cancel', ['booking' => $never->json('id')])->json('deposit_state'))->toBe(AmenityBooking::DEPOSIT_NONE);

    // A BOOKING'S FEE AND DEPOSIT, PAID WITH A TEST CARD (M12 §2.6, §2.14). Not before the estate approves the fee …
    $approved = asResident($this, $t, 'bookings.store', [], ['amenity_id' => $gazebo['id'], ...$morning(7)]);
    asResident($this, $t, 'bookings.pay_card', ['booking' => $approved->json('id')], ['part' => 'fee', 'brand' => 'visa', 'last4' => '4242'], 409)->assertJsonPath('error.code', 'payment_refused');
    // … the deposit can be paid while it waits: held, against 1090, marked a demonstration.
    $deposit = asResident($this, $t, 'bookings.pay_card', ['booking' => $approved->json('id')], ['part' => 'deposit', 'brand' => 'mastercard', 'last4' => '4444']);
    expect($deposit->json('booking.deposit_state'))->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($deposit->json('booking.deposit_demonstration'))->toBeTrue()
        ->and($deposit->json('receipt'))->toBeNull();
    app(Amenities::class)->approve(AmenityBooking::findOrFail($approved->json('id')));
    // Payments clear the OLDEST charge first, so a fee is paid only with everything owed before it: the household
    // owes J$380 on the earlier charge, and a fee due last of all takes that J$380 too. The office raises it due in
    // thirty days — later than the J$12,500 charge above, which is due in ten.
    app(Amenities::class)->recordFee(AmenityBooking::with(['amenity', 'unit'])->findOrFail($approved->json('id')), dueOn: SiteTime::date()->addDays(30));
    $owing = collect(asResident($this, $t, 'bookings.index')->json('items'))->firstWhere('id', $approved->json('id'));
    expect($owing['fee_outstanding'])->toBe('3000.00')
        ->and($owing['fee_to_clear'])->toBe('3380.00')
        ->and($owing['fee_paid'])->toBeFalse();
    // A figure other than the one shown is refused and nothing is taken.
    asResident($this, $t, 'bookings.pay_card', ['booking' => $approved->json('id')], ['part' => 'fee', 'brand' => 'visa', 'last4' => '4242', 'amount' => '3000.00'], 409)
        ->assertJsonPath('error.code', 'payment_refused');
    $fee = asResident($this, $t, 'bookings.pay_card', ['booking' => $approved->json('id')], ['part' => 'fee', 'brand' => 'visa', 'last4' => '4242', 'amount' => '3380.00']);
    expect($fee->json('booking.fee_charged'))->toBeTrue()
        ->and($fee->json('booking.fee_paid'))->toBeTrue()
        ->and($fee->json('booking.fee_outstanding'))->toBe('0.00')
        ->and($fee->json('receipt.is_demonstration'))->toBeTrue();
    asResident($this, $t, 'bookings.pay_card', ['booking' => $approved->json('id')], ['part' => 'fee', 'brand' => 'visa', 'last4' => '4242'], 409);
    expect(asResident($this, $t, 'bookings.index')->json('card_payments'))->toBeTrue();

    // M47 §10: BOOKED AND PAID FOR IN ONE ACT, CONFIRMED AT ONCE — the server's own quote is what the card takes, and the
    // answer carries the code a guard reads aloud and the guests' pass for the gate.
    $hours = $morning(9);
    $quote = asResident($this, $t, 'bookings.quote', ['amenity' => $gazebo['id']], ['starts_at' => $hours['starts_at'], 'ends_at' => $hours['ends_at']]);
    $card = ['amenity_id' => $gazebo['id'], 'starts_at' => $hours['starts_at'], 'ends_at' => $hours['ends_at'], 'brand' => 'visa', 'last4' => '4242', 'total' => $quote->json('total')];

    // M53 §2: the Gazebo's bookings wait for the office (every amenity's default), so the app may not pay for one …
    expect($gazebo['requires_approval'])->toBeTrue();
    asResident($this, $t, 'bookings.book', [], $card, 409)->assertJsonPath('error.code', 'approval_required');

    // … until the estate switches its approval off (put back after, for every file after this one on the shared estate).
    Amenity::query()->whereKey($gazebo['id'])->update(['requires_approval' => false]);

    try {
        $paid = asResident($this, $t, 'bookings.book', [], $card);
        expect($paid->json('status'))->toBe(AmenityBooking::CONFIRMED)
            ->and($paid->json('confirmation_code'))->toStartWith('GZ-')
            ->and($paid->json('gate_token'))->not->toBeNull()
            ->and($paid->json('deposit_state'))->toBe(AmenityBooking::DEPOSIT_HELD);
    } finally {
        FacilitiesFixture::boot();
        Amenity::query()->whereKey($gazebo['id'])->update(['requires_approval' => true]);
    }

    /* ── Every phone signed in to the account, and signing one of the others out (the client, 26 Sep) ── */

    $second = ResidentAccount::query()->findOrFail($signedIn->json('account.id'))->createToken('resident-app:res-install-2', AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
    $second->accessToken->forceFill(['device_label' => 'SM-S938B', 'device_platform' => 'android'])->save();
    $phones = asResident($this, $t, 'me.sessions')->json('items');
    expect(array_column($phones, 'device'))->toBe(['iPhone 15', 'SM-S938B']) // this phone first, named as it signed in
        ->and($phones[0]['current'])->toBeTrue();
    asResident($this, $t, 'me.sessions.end', ['session' => $phones[0]['id']], [], 409); // not this phone: that is sign-out
    expect(asResident($this, $t, 'me.sessions.end', ['session' => $phones[1]['id']])->json('signed_out'))->toBeTrue();
    expect(asResident($this, $t, 'me.sessions')->json('items'))->toHaveCount(1);

    // "Sign out all other phones" (U5): two more, both gone at once, and this one still signed in.
    foreach (['res-install-3', 'res-install-4'] as $other) {
        ResidentAccount::query()->findOrFail($signedIn->json('account.id'))->createToken('resident-app:'.$other, AppMatrix::abilitiesFor(AppMatrix::RESIDENT));
    }
    expect(asResident($this, $t, 'me.sessions.end_others')->json('signed_out'))->toBe(2)
        ->and(asResident($this, $t, 'me.sessions')->json('items'))->toHaveCount(1);

    /* ── "Face ID / fingerprint" on this phone, and "Log in with biometrics" with it (M49) ── */

    // One sign-in with the key here (the sign-in doors share a rate limit); every refusal is walked in M49FingerprintSignInTest.
    $key = asResident($this, $t, 'me.device_key.store', [], ['platform' => 'ios', 'device_name' => 'iPhone 15'])->json('key');
    $back = asResident($this, null, 'auth.device_key', [], ['estate' => $site, 'key' => $key, 'device_uid' => 'res-install-1', 'platform' => 'ios', 'device_name' => 'iPhone 15']);
    expect($back->json('account.id'))->toBe($signedIn->json('account.id'))->and($back->json('fingerprint_sign_in'))->toBeTrue();
    // The install's token was replaced by the sign-in: the walk goes on with the new one.
    $t = $back->json('token');
    expect(asResident($this, $t, 'me.device_key.destroy')->json('revoked'))->toBe(1);

    /* ── "Email" on board 20 (M50 §4.2 #4): off until turned on; every rule of who is emailed is in M50QuietHoursAndEmailTest ── */

    expect(asResident($this, $t, 'me.notifications.email.show')->json('on'))->toBeFalse();
    expect(asResident($this, $t, 'me.notifications.email.update', [], ['on' => true, 'muted' => ['notices']])->json('muted'))->toBe(['notices']);

    /* ── The profile photo (the client, 26 Sep) ── */

    app('auth')->forgetGuards();
    $photo = $this->withToken($t)->post('/api/v1/me/photo', ['file' => UploadedFile::fake()->image('me.jpg', 120, 120)], [
        'Accept' => 'application/json', 'Idempotency-Key' => 'me-photo-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String(),
    ])->assertCreated();
    ApiContract::assertMatches($photo, 'me.photo.store');
    $GLOBALS['residentContractCalled']['me.photo.store'] = true;
    FacilitiesFixture::boot();

    expect(asResident($this, $t, 'me.show')->json('account.photo.url'))->toBe($photo->json('photo.url'));

    app('auth')->forgetGuards();
    $file = $this->withToken($t)->get('/api/v1/me/photo')->assertOk();
    expect((string) $file->headers->get('Content-Type'))->toStartWith('image/');
    $GLOBALS['residentContractCalled']['me.photo'] = true;
    FacilitiesFixture::boot();

    expect(asResident($this, $t, 'me.photo.remove')->json('photo'))->toBeNull();

    /* ── "Download my data" on board 33 (M50 §4.3): the file itself; what goes in is walked in M50DataExportTest ── */

    app('auth')->forgetGuards();
    $export = $this->withToken($t)->get('/api/v1/me/data-export')->assertOk();
    expect((string) $export->headers->get('Content-Type'))->toBe('application/pdf')
        ->and((string) $export->headers->get('Content-Disposition'))->toContain('GeminiSecure-my-data-')
        ->and(substr((string) $export->getContent(), 0, 5))->toBe('%PDF-');
    $GLOBALS['residentContractCalled']['me.data_export'] = true;
    FacilitiesFixture::boot();

    /* ── "Live chat" on board 39 (M50 §4.3): written, then read back; the control room's side is in M50LiveChatTest ── */

    expect(asResident($this, $t, 'support.chat.messages.store', [], ['body' => 'How do I add my helper?', 'client_key' => 'contract-chat-1'])->json('chat.open'))->toBeTrue();
    expect(asResident($this, $t, 'support.chat.show')->json('messages.0.body'))->toBe('How do I add my helper?');
    // No line saved in this run's console: no number, so the app shows no "Call Gemini Security" (M50SupportLineTest).
    expect(asResident($this, $t, 'support.contact')->json('email'))->toBeString();

    // M47 §3: what the account was shown once is the account's — the tour, finished or skipped, stays done.
    expect(asResident($this, $t, 'me.show')->json('account.shown.tour'))->toBeNull();
    expect(asResident($this, $t, 'me.shown', [], ['key' => 'tour'])->json('shown.tour'))->toBeString();
    expect(asResident($this, $t, 'me.show')->json('account.shown.tour'))->toBeString();

    /* ── M6 (Q-M42): signing out, for real. LAST, because it destroys the token this whole walk holds ── */

    expect(asResident($this, $t, 'auth.sign_out')->json('signed_out'))->toBeTrue();

    // Gone from the server, not just from the phone: the next request with the same token is refused.
    app('auth')->forgetGuards();
    $this->withToken($t)->getJson('/api/v1/me')->assertUnauthorized();
    FacilitiesFixture::boot();

    /* ── And nothing on the resident's side of the catalogue went uncalled ── */

    $residentRoutes = collect(Catalogue::endpoints())
        // The resident's own routes, plus the two the Resident App calls BEFORE it has a token: signing in, and the estate pick-list.
        ->filter(fn (array $e, string $name) => in_array(AppMatrix::RESIDENT, $e['apps'], true) || str_starts_with($name, 'auth.') || $name === 'estates.index')
        ->keys()
        /*
         * TWO THAT CANNOT BE WALKED HERE, EACH WALKED IN FULL SOMEWHERE ELSE:
         *   alerts.store          the panic button — its own file, with the ten-second window.
         *   me.delete_account     it destroys every token this walk is holding, so it is walked
         *                         on its own throwaway account in `ResidentAccountDeletionTest`.
         */
        ->reject(fn (string $name) => in_array($name, ['alerts.store', 'me.delete_account'], true))
        ->sort()->values()->all();

    expect(collect(array_keys($GLOBALS['residentContractCalled']))->sort()->values()->all())->toBe($residentRoutes);
});

it('refuses a Guard App token on every resident-only route, at the ability', function () {
    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Refusal Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Refused Guard', 'employee_number' => 'GS-REF-1', 'psra_number' => 'PSRA-REF-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($guard, 'Refusal handset')['token'];

    $residentOnly = collect(Catalogue::endpoints())->filter(fn (array $e) => $e['apps'] === [AppMatrix::RESIDENT]);

    expect($residentOnly->count())->toBeGreaterThan(35);

    foreach ($residentOnly as $name => $entry) {
        app('auth')->forgetGuards();
        $response = $this->withToken($token)->json($entry['method'], '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']), [], ['Idempotency-Key' => 'g-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        expect($response->status())->toBe(403, "[{$name}] answered a guard token with {$response->status()}")
            ->and($response->json('error.code'))->toBeIn(['missing_ability', 'wrong_app'], "[{$name}]");

        FacilitiesFixture::boot();
    }

    // And a pending account reaches its claim and `me`, and nothing else.
    $pending = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'pending.'.bin2hex(random_bytes(3)).'@example.com', 'status' => ResidentAccount::PENDING]);
    $pendingToken = $pending->createToken('resident-app:pending', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    foreach ($residentOnly as $name => $entry) {
        app('auth')->forgetGuards();
        $response = $this->withToken($pendingToken)->json($entry['method'], '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']), [], ['Idempotency-Key' => 'p-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        if ($entry['pending'] ?? false) {
            expect($response->status())->not->toBe(403, "[{$name}] should reach a pending account");
        } else {
            expect($response->json('error.code'))->toBe('account_pending', "[{$name}] answered a pending account with {$response->status()}");
        }

        FacilitiesFixture::boot();
    }
});

/**
 * A ballot with one two-seat position and three candidates.
 *
 * @return array{0: Ballot, 1: list<BallotOption>}
 */
function contractBallot(string $title, string $stage, bool $published = false): array
{
    $ballot = new Ballot;
    $ballot->forceFill([
        'year' => (int) now()->year, 'title' => $title, 'kind' => Ballot::ELECTION, 'stage' => $stage,
        'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(2), 'eligible_households' => 5,
        'certified_at' => $stage === Ballot::CERTIFIED ? now()->subHour() : null,
        'published_at' => $published ? now()->subMinutes(30) : null,
    ])->save();

    $position = new BallotPosition;
    $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Estate Executive', 'seat_count' => 2, 'scope' => 'estate', 'sort_order' => 1])->save();

    $options = [];

    foreach (['Andre Clarke', 'Simone Grant', 'Winston Hall'] as $i => $label) {
        $option = new BallotOption;
        $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label, 'sort_order' => $i])->save();
        $options[] = $option;
    }

    return [$ballot, $options];
}
