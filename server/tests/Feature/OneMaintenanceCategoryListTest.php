<?php

declare(strict_types=1);

use App\Models\Estate\MaintenanceTicket;
use App\Models\Role;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Reports;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One list of maintenance categories, for the console and the phones (FAC-6, register DR-080)
|--------------------------------------------------------------------------
|
| The console offered "Plumbing" and the Resident App sends "plumbing", so the maintenance summary counted Plumbing 5
| and plumbing 7, and a raw "security" beside them. There is now one list on the server, keys and their words: the
| phone's keys (what the Resident App already sends) and the console's own, without "security", which is not a
| maintenance category (FAC-4). Every screen and the summary say a category in its one word, whichever way it was
| stored; the console's form offers the list and is refused anything else.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED: the tickets here stand on a scratch lot of this file's own.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->maintenance = app(Maintenance::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('says a category in its one word, however it was stored', function () {
    expect(MaintenanceTicket::categoryLabel('plumbing'))->toBe('Plumbing')
        ->and(MaintenanceTicket::categoryLabel('Plumbing'))->toBe('Plumbing')
        ->and(MaintenanceTicket::categoryLabel('PLUMBING'))->toBe('Plumbing')
        ->and(MaintenanceTicket::categoryLabel('access control'))->toBe('Access control')
        ->and(MaintenanceTicket::categoryLabel('security'))->toBe('Security')
        ->and(MaintenanceTicket::categoryLabel(null))->toBeNull();
});

it('offers the console one list, the phone\'s keys with its own, without security, and refuses anything else', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/maintenance'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('categories', fn ($categories) => collect($categories)->pluck('key')->all() === array_keys(MaintenanceTicket::CATEGORIES)
                && collect($categories)->firstWhere('key', 'plumbing')['label'] === 'Plumbing'
                && ! collect($categories)->pluck('key')->contains('security')));

    foreach (['plumbing', 'electrical', 'landscaping', 'roads', 'water', 'waste', 'amenity', 'other'] as $phoneKey) {
        expect(MaintenanceTicket::CATEGORIES)->toHaveKey($phoneKey);
    }

    // A tag of this run's own: the estate's database is committed, so an earlier run's tickets are still there.
    $tag = 'FAC-6 '.bin2hex(random_bytes(3));
    $order = fn (string $category): array => ['title' => $tag.' '.$category, 'location' => 'Back gate', 'category' => $category, 'priority' => 'low'];

    try {
        foreach (['security', 'Gate stuff'] as $refused) {
            $this->actingAs($manager)
                ->from(FacilitiesFixture::url('/facilities/maintenance'))
                ->post(FacilitiesFixture::url('/facilities/maintenance'), $order($refused))
                ->assertSessionHasErrors('category');
        }

        $this->actingAs($manager)
            ->post(FacilitiesFixture::url('/facilities/maintenance'), $order('plumbing'))
            ->assertSessionHasNoErrors();

        FacilitiesFixture::boot();

        expect(MaintenanceTicket::query()->where('title', 'like', $tag.' %')->pluck('category')->all())->toBe(['plumbing']);
    } finally {
        FacilitiesFixture::boot();
    }
});

it('says the one word on the ticket and counts one row for it in the maintenance summary', function () {
    $unit = FacilitiesFixture::scratchUnit('MNT-80', 'Phase 9');
    $report = fn (string $title, string $category) => $this->maintenance->report(
        title: $title,
        locationLabel: 'Lot MNT-80',
        unit: $unit,
        category: $category,
        reportedByName: 'Categorised Household',
        reportedAt: SiteTime::now()->subHours(2),
    );

    try {
        $fromPhone = $report('FAC-6 tap dripping', 'plumbing');
        $report('FAC-6 pipe knocking', 'Plumbing');

        expect($this->maintenance->ticketBoard($fromPhone->refresh())['ticket']['meta'])->toContain('Category: Plumbing');

        $today = SiteTime::today()->toDateString();
        $rows = collect(app(Reports::class)->run('maintenance_summary', $today, $today)['rows']);

        expect($rows->filter(fn (array $row): bool => strtolower($row[0]) === 'plumbing')->pluck(0)->all())->toBe(['Plumbing']);
    } finally {
        FacilitiesFixture::boot();
    }
});
