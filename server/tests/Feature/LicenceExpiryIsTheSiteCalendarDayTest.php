<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\Gemini\GuardWorkforce;
use App\Services\Gemini\Roster;
use App\Services\Gemini\StandingOrders;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A licence is good through its expiry date, on the site's calendar (bug hunt B008)
|--------------------------------------------------------------------------
|
| `Guard::licenceDaysLeft()` already counts calendar days at the officer's site:
| 0 is "expires today and is still good today". Four other readers still asked
| the server's UTC clock, so from 19:00 in Kingston on the day before expiry —
| and all of the expiry day itself — a licence that was valid was counted as
| lapsed by the KPI, listed by ?licence=expired, labelled "Expired today" in the
| register and its CSV, raised as an unacknowledged compliance flag in the
| dispatch queue, and refused by the roster for a shift on a day it covers.
|
| Two estates, two clocks. Kingston is UTC-5. Kiritimati is UTC+14, so at the
| same instant it is already the next day there: one licence dated Sep 24 is
| still good in Kingston and has already run out on Kiritimati. One UTC "today"
| cannot answer both, and each officer is judged on their own estate's clock.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    DB::connection('mysql')->table('tenants')->insert([
        'id' => 'licencezonekiritimati',
        'name' => 'Line Islands Licence Test',
        'receipt_prefix' => 'LZK',
        'status' => 'active',
        'timezone' => 'Pacific/Kiritimati',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    SiteTime::flush();

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
    Carbon::setTestNow();
});

/** An officer whose licence expires on 24 September 2026, posted at the given estate. */
function siteDayOfficer(string $tenantId, string $name): Guard
{
    return Guard::query()->create([
        'full_name' => $name,
        'employee_number' => 'GS-SDO-'.uniqid(),
        'psra_number' => 'PSRA-SDO-'.uniqid(),
        'psra_expires_on' => '2026-09-24',
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $tenantId,
    ]);
}

/** @return list<int> */
function siteDayDirectory(User $viewer, string $licence): array
{
    $workforce = app(GuardWorkforce::class);

    return array_column(
        $workforce->roster($viewer, $workforce->normaliseFilters(['licence' => $licence, 'q' => 'SiteDay']))['rows'],
        'id',
    );
}

it('counts and lists a licence as valid all through its last day, on the officer\'s own estate\'s clock', function () {
    // 10:00 in Kingston on the 24th; 05:00 on the 25th on Kiritimati.
    $this->travelTo(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    $kingston = siteDayOfficer(FacilitiesFixture::ESTATE, 'SiteDay Kingston Officer');
    $kiritimati = siteDayOfficer('licencezonekiritimati', 'SiteDay Kiritimati Officer');

    expect($kingston->licenceState())->toBe('expiring')
        ->and($kingston->licenceDaysLeft())->toBe(0)
        ->and($kiritimati->licenceState())->toBe('expired')
        ->and($kiritimati->licenceDaysLeft())->toBe(-1);

    // The filters ask exactly what licenceState() answers.
    expect(siteDayDirectory($this->director, 'expired'))->toBe([$kiritimati->id])
        ->and(siteDayDirectory($this->director, 'expiring'))->toBe([$kingston->id]);

    // The KPI card counts what its own filter lists — and not the Kingston officer.
    $workforce = app(GuardWorkforce::class);
    $expiredEverywhere = $workforce->roster($this->director, $workforce->normaliseFilters(['licence' => 'expired']))['table']['total'];

    expect($workforce->summary($this->director)['licence_expired'])->toBe($expiredEverywhere);

    $kingston->forceFill(['psra_expires_on' => '2026-09-23'])->save();
    expect($workforce->summary($this->director)['licence_expired'])->toBe($expiredEverywhere + 1);
});

it('words the register, and its CSV, the way the badge beside them reads the licence', function () {
    $this->travelTo(Carbon::parse('2026-09-24 15:00:00', 'UTC'));

    $kingston = siteDayOfficer(FacilitiesFixture::ESTATE, 'SiteDay Register Kingston');
    $kiritimati = siteDayOfficer('licencezonekiritimati', 'SiteDay Register Kiritimati');

    $register = collect(app(GuardWorkforce::class)->compliance($this->director))->keyBy('id');

    expect($register[$kingston->id]['licence_label'])->toBe('Expires today')
        ->and($register[$kingston->id]['licence_badge'])->toBe('soon')
        ->and($register[$kiritimati->id]['licence_label'])->toBe('Expired 1 day ago')
        ->and($register[$kiritimati->id]['licence_badge'])->toBe('expired');

    $csv = $this->actingAs($this->director)->get('/guards/compliance/export')->streamedContent();

    $line = collect(explode("\n", $csv))->first(fn (string $row): bool => str_contains($row, 'SiteDay Register Kingston'));

    expect($line)->toContain('Expires today')->not->toContain('Expired');
});

it('raises no compliance flag in the dispatch queue on the evening of a licence\'s last valid day', function () {
    // 21:00 in Kingston on the 24th — already the 25th in UTC, and 16:00 on the 25th on Kiritimati.
    $this->travelTo(Carbon::parse('2026-09-25 02:00:00', 'UTC'));

    $kingston = siteDayOfficer(FacilitiesFixture::ESTATE, 'SiteDay Queue Kingston');
    $kiritimati = siteDayOfficer('licencezonekiritimati', 'SiteDay Queue Kiritimati');

    $this->actingAs($this->director)
        ->get('/dispatch/alerts?tab=all')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($kingston, $kiritimati) {
            $keys = collect($page->toArray()['props']['alerts'])->pluck('key');

            expect($keys)->not->toContain('guard-'.$kingston->id)
                ->and($keys)->toContain('guard-'.$kiritimati->id);
        });
});

it('lets an officer acknowledge their post\'s orders, and counts them as able to stand it, on the evening of their licence\'s last day', function () {
    // 21:00 in Kingston on the 24th; the 25th in UTC.
    $this->travelTo(Carbon::parse('2026-09-25 02:00:00', 'UTC'));

    $post = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'SiteDay Orders Gate', 'type' => 'gate', 'is_active' => true]);
    $officer = siteDayOfficer(FacilitiesFixture::ESTATE, 'SiteDay Orders Kingston');
    $officer->forceFill(['post_id' => $post->id])->save();

    $orders = app(StandingOrders::class);
    $setId = $orders->create([
        'category' => 'post_specific',
        'post_id' => $post->id,
        'body' => 'The barrier stays down between vehicles, and a following car waits its turn.',
        'effective_on' => '2026-09-25',
    ], $this->director);

    expect($orders->detail($setId, $this->director)['guards_on_post'][0]['can_stand'])->toBeTrue()
        ->and($orders->acknowledge($officer->fresh(), $setId, 1)['version'])->toBe(1);
});

it('rosters an officer for a shift on a day their licence covers, and refuses one that runs past it', function () {
    // 22:00 in Kingston on the 23rd: both shifts below are still ahead.
    $this->travelTo(Carbon::parse('2026-09-24 03:00:00', 'UTC'));

    $roster = app(Roster::class);
    $officer = siteDayOfficer(FacilitiesFixture::ESTATE, 'SiteDay Roster Kingston');

    $day = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'SiteDay Day Gate', 'type' => 'gate', 'is_active' => true]);
    $night = Post::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'SiteDay Night Gate', 'type' => 'gate', 'is_active' => true]);

    // 07:00 to 19:00 on the 24th at the estate — every minute of it inside the licence.
    $dayShift = $roster->postOpenShift($day, '2026-09-24 07:00', '2026-09-24 19:00', $this->director);

    $this->actingAs($this->director)
        ->post('/guards/roster/shifts/'.$dayShift->id.'/assign', ['guard_id' => $officer->id])
        ->assertSessionHasNoErrors();

    expect($dayShift->fresh()->guard_id)->toBe($officer->id);

    // 19:00 on the 24th to 07:00 on the 25th: seven hours of it after the licence has run out.
    $nightShift = $roster->postOpenShift($night, '2026-09-24 19:00', '2026-09-25 07:00', $this->director);

    $this->actingAs($this->director)
        ->post('/guards/roster/shifts/'.$nightShift->id.'/assign', ['guard_id' => $officer->id])
        ->assertSessionHasErrors('guard_id');

    expect($nightShift->fresh()->guard_id)->toBeNull();
});
