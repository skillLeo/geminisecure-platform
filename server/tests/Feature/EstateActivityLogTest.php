<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Role;
use App\Models\SecurityIncident;
use App\Models\Tenant;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\Amenities;
use App\Services\Estate\Maintenance;
use App\Services\Rbac\RoleEditor;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The activity log and the notification centre (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| NEITHER KEEPS A TABLE OF ITS OWN. What is stored is the read mark, per
| viewer — two officers do not share an inbox — and every item is a live read
| of the record, gated on the record's own module. An item that resolves stops
| being derived, with nothing to go back and tidy.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    DB::connection('tenant')->table('notification_reads')->delete();
});

it('pages the full activity log and leaves the dashboard panel at five', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activity.seeAllHref')
            ->has('notifications.href')
            ->where('activity.rows', fn ($rows) => count($rows) <= ActivityLog::DASHBOARD_ROWS));

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/activity'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Activity/Index')
            ->where('page', 1)
            ->where('rows', fn ($rows) => count($rows) <= ActivityLog::PAGE));

    // Page two exists or it does not; either way it answers rather than 404s,
    // and its rows never repeat page one's.
    $one = app(ActivityLog::class)->feed(ActivityLog::PAGE, 0);
    $two = app(ActivityLog::class)->feed(ActivityLog::PAGE, ActivityLog::PAGE);

    expect(array_intersect(array_column($one['rows'], 'title'), array_column($two['rows'], 'title')))
        ->toBe(array_intersect(array_column($one['rows'], 'title'), array_column($two['rows'], 'title')));

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/activity').'?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page', 2));
});

it('shows each viewer only what their own modules reach, and keeps their read marks apart', function () {
    $log = app(ActivityLog::class);

    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    // Something for each of them to be told about: a claim (Residents) and a
    // ticket long past its target (Facilities).
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $claim = UnitClaim::query()->forceCreate([
        'unit_id' => $unit->id,
        'submitted_name' => 'Hopeful Claimant',
        'submitted_lot' => $unit->reference,
        'status' => UnitClaim::PENDING,
    ]);

    $ticket = app(Maintenance::class)->report(
        title: 'Pump down',
        locationLabel: 'Plant room',
        priority: MaintenanceTicket::HIGH,
        reportedAt: now()->subDays(9),
        by: $manager,
    );

    /*
     * A deposit still held after the booking has ended. It is gated on
     * Facilities APPROVE — deciding a deposit is refund-or-forfeit, which the
     * matrix gives the Property Manager and not the Secretary (D-086).
     */
    /*
     * A BOOKING OF THIS TEST'S OWN (M4 §4, defect 3). This used to take the FIRST
     * booking in the estate — the seeded Gazebo booking board 19 draws for Lot 47
     * — force it three days into the past with its deposit "held", and leave it
     * there. The estate database is shared by every file in the run and is not
     * rolled back, so every file that read that booking afterwards read a
     * different one depending on whether this file had already run. Made through
     * the service instead, so the deposit it holds is a real entry on 2200, on a
     * lot nobody else stands on — and given back at the end.
     */
    $amenities = app(Amenities::class);
    $day = now()->subDays(2)->format('Y-m-d');

    $booking = $amenities->approve($amenities->book(
        amenity: FacilitiesFixture::amenity('Community Centre'),
        unit: FacilitiesFixture::scratchUnit('Lot 962'),
        residentName: 'Deposit Waiting',
        startsAt: $day.' 10:00',
        endsAt: $day.' 14:00',
    ));

    $amenities->holdDeposit($booking);

    $forManager = $log->attention($manager);
    $forSecretary = $log->attention($secretary);

    $managerKeys = array_column($forManager['items'], 'key');
    $secretaryKeys = array_column($forSecretary['items'], 'key');

    /*
     * GATED PER ITEM. Both hold Facilities view, so both are told the ticket is
     * past its target. Only the approver is told a deposit is still held, and a
     * notification is a summary of a record: a role that may not decide the
     * deposit is not told there is one waiting.
     */
    expect($managerKeys)->toContain('ticket:'.$ticket->id)
        ->and($secretaryKeys)->toContain('ticket:'.$ticket->id)
        ->and($managerKeys)->toContain('deposit:'.$booking->id)
        ->and($secretaryKeys)->not->toContain('deposit:'.$booking->id)
        ->and($secretaryKeys)->toContain('claim:'.$claim->id);

    // READ MARKS ARE PER VIEWER.
    $before = $forManager['unread'];

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/notifications/read'), ['keys' => ['ticket:'.$ticket->id]])
        ->assertRedirect(FacilitiesFixture::url('/notifications'));

    expect($log->attention($manager)['unread'])->toBe($before - 1)
        ->and($log->attention($secretary)['unread'])->toBe($forSecretary['unread']);

    // AN ITEM THAT RESOLVES SIMPLY STOPS BEING DERIVED — the read mark is left
    // behind, matches nothing, and nobody has to tidy it.
    $claim->forceFill(['status' => UnitClaim::APPROVED])->save();

    expect(array_column($log->attention($secretary)['items'], 'key'))->not->toContain('claim:'.$claim->id);

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/notifications'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Notifications/Index'));

    $ticket->forceFill(['closed_at' => now(), 'status' => MaintenanceTicket::VERIFIED])->save();
    $claim->delete();

    // The deposit goes back, and that item stops being derived too — which also
    // leaves nothing held in the past for the files that run after this one.
    $amenities->refundDeposit($booking->refresh());

    expect(array_column($log->attention($manager)['items'], 'key'))->not->toContain('deposit:'.$booking->id);

    /*
     * AND THE TICKET GOES OUT AGAIN (M4 §4, defect 3 — the other half of the leak fixed above).
     * The estate database is committed, not rolled back, so a ticket this file raised nine days
     * ago and then closed stayed in `maintenance_tickets` as a SIXTH resolved job — and board
     * 17's mean resolution time, which `EstateFacilitiesMaintenanceTest` traces in raw SQL over
     * the seeded five, read 4.2 days instead of 3.2 whenever this file had already run. A test
     * whose answer depends on what ran before it is not a test.
     */
    DB::connection('tenant')->table('maintenance_ticket_activity')->where('maintenance_ticket_id', $ticket->id)->delete();
    MaintenanceTicket::query()->whereKey($ticket->id)->delete();
});

it('shows a security incident in the feed only to a role that may open Security (B049)', function () {
    /*
     * CENTRAL, SO THE ROLLBACK TAKES IT AWAY AGAIN, and the newest thing in
     * the estate, so it would lead the dashboard's five for anybody who may
     * read it. The kind is the only detail a feed row carries — and "a
     * suspicious person at the gate" is still Security's record (M10 §2).
     *
     * NEWEST BY CONSTRUCTION, NOT BY THE CLOCK. When a file that runs
     * EstateFinanceSeeder (EstateDunningSendTest) has gone first, the shared
     * estate holds its dues history, which banks each month's receipts on the
     * 3rd: on the 1st and 2nd of a month the newest payment is dated tomorrow,
     * and a page of them put this incident behind the dashboard's five (seed
     * 1790905329, 2 October 2026).
     *
     * AND NEWER THAN EVERY ROW, NOT ONLY THE PAYMENTS (seed 1791114851,
     * 4 October 2026): what an earlier file leaves in the shared estate dated
     * after now can be of any kind the feed reads. So the incident goes a
     * little after the feed's own newest row, read for nobody in particular. The
     * feed gives that moment on the estate's clock to the minute ("Oct 4, 2026
     * 7:40 AM JA"), so it is read back there and two minutes are added.
     */
    tenancy()->initialize(Tenant::findOrFail(FacilitiesFixture::ESTATE));
    $shown = app(ActivityLog::class)->feed(1)['rows'][0]['at'] ?? null;
    tenancy()->end();
    FacilitiesFixture::boot();
    $newest = $shown === null ? null : Carbon::createFromFormat('M j, Y g:i A', (string) preg_replace('/\s+[A-Z]{2,5}$/', '', (string) $shown), SiteTime::zone(FacilitiesFixture::ESTATE))->addMinute();

    SecurityIncident::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'kind' => 'Suspicious person — B049',
        'severity' => 'med',
        'status' => SecurityIncident::OPEN,
        'occurred_at' => $newest === null ? now() : $newest->copy()->utc()->max(now())->addMinute(),
    ]);

    $incidents = static fn ($rows): array => array_values(array_filter(
        collect($rows)->all(),
        static fn (array $row): bool => $row['icon'] === 'incident' && $row['detail'] === 'Suspicious person — B049',
    ));

    /*
     * THE MATRIX'S OWN '-'. The Secretary, the Treasurer and the Admin
     * Assistant are refused the Security screen, and a row is a summary of a
     * record: a role that may not read the record may not read the summary.
     */
    foreach ([Role::SECRETARY, Role::TREASURER, Role::ESTATE_ADMIN_ASSISTANT] as $barred) {
        $viewer = FacilitiesFixture::viewer($barred);

        $this->actingAs($viewer)->get(FacilitiesFixture::url('/security'))->assertForbidden();

        $this->actingAs($viewer)
            ->get(FacilitiesFixture::url('/'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activity.rows', fn ($rows) => $incidents($rows) === []));

        $this->actingAs($viewer)
            ->get(FacilitiesFixture::url('/activity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => $incidents($rows) === []));
    }

    // A positive control, without which the refusals above would pass on a
    // feed that never read incidents at all.
    foreach ([Role::PRESIDENT, Role::PROPERTY_MANAGER, Role::ESTATE_HEAD_OF_SECURITY] as $allowed) {
        $viewer = FacilitiesFixture::viewer($allowed);

        $this->actingAs($viewer)
            ->get(FacilitiesFixture::url('/'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activity.rows', fn ($rows) => count($incidents($rows)) === 1));

        $this->actingAs($viewer)
            ->get(FacilitiesFixture::url('/activity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => count($incidents($rows)) === 1));
    }

    // And nobody in particular — a caller that passes no viewer — still reads the whole feed.
    FacilitiesFixture::boot();
    tenancy()->initialize(Tenant::findOrFail(FacilitiesFixture::ESTATE));

    try {
        expect($incidents(app(ActivityLog::class)->feed(ActivityLog::PAGE)['rows']))->toHaveCount(1);
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }
});

it('shows a verification, a closed ticket and a booking only to a role that may open the module each comes from (B049)', function () {
    /*
     * THE SAME RULE FOR THE THREE CATEGORIES LEFT. No seeded role lacks
     * Residents or Facilities, so this is a role a Community Super Admin builds
     * today (M10 §4): a gate liaison who reads Security and nothing else, and
     * who is refused the residents register and every facilities screen.
     */
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);

    tenancy()->initialize(Tenant::findOrFail(FacilitiesFixture::ESTATE));

    try {
        $liaisonRole = app(RoleEditor::class)->create($admin, 'Gate liaison (B049)', [
            'dashboard' => ['level' => 'view'],
            'security' => ['level' => 'view'],
        ]);
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }

    $liaison = FacilitiesFixture::viewer($liaisonRole->name);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    /*
     * A VERIFIED RESIDENT OF THIS TEST'S OWN, because the fixture estate seeds
     * units and no residents. Raw and on this file's own lot, and taken away
     * again at the end: the estate database is committed, not rolled back.
     * Closed tickets and bookings are the seeder's, and the positive control
     * below proves they are there to be withheld.
     */
    $unit = FacilitiesFixture::scratchUnit('Lot 962');
    $tenantDb = DB::connection('tenant');

    $householdId = (int) $tenantDb->table('households')->insertGetId([
        'unit_id' => $unit->id, 'name' => 'B049 household', 'created_at' => now()->utc(), 'updated_at' => now()->utc(),
    ]);

    $residentId = (int) $tenantDb->table('residents')->insertGetId([
        'household_id' => $householdId, 'full_name' => 'Verified B049', 'relationship' => 'owner', 'is_primary' => true,
        'status' => 'verified', 'verified_at' => now()->utc(), 'created_at' => now()->utc(), 'updated_at' => now()->utc(),
    ]);

    $withheld = ['residents', 'maintenance', 'facility'];
    $kinds = static fn ($rows): array => array_values(array_intersect($withheld, array_unique(array_column(collect($rows)->all(), 'icon'))));

    try {
        $this->actingAs($liaison)->get(FacilitiesFixture::url('/residents'))->assertForbidden();
        $this->actingAs($liaison)->get(FacilitiesFixture::url('/facilities/maintenance'))->assertForbidden();

        $this->actingAs($liaison)
            ->get(FacilitiesFixture::url('/'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('activity.rows', fn ($rows) => $kinds($rows) === []));

        $this->actingAs($liaison)
            ->get(FacilitiesFixture::url('/activity'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('rows', fn ($rows) => $kinds($rows) === []));

        // The whole log, not one page of it, so no row is hiding past the fold.
        tenancy()->initialize(Tenant::findOrFail(FacilitiesFixture::ESTATE));

        try {
            $log = app(ActivityLog::class);

            expect($kinds($log->feed(500, 0, $liaison)['rows']))->toBe([])
                // A positive control: a reader holding both modules is given all three.
                ->and($kinds($log->feed(500, 0, $president)['rows']))->toEqualCanonicalizing($withheld)
                ->and(collect($log->feed(500, 0, $president)['rows'])->contains(
                    static fn (array $row): bool => $row['icon'] === 'residents' && $row['detail'] === 'Verified B049',
                ))->toBeTrue();
        } finally {
            tenancy()->end();
            FacilitiesFixture::boot();
        }
    } finally {
        $tenantDb->table('residents')->where('id', $residentId)->delete();
        $tenantDb->table('households')->where('id', $householdId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});
