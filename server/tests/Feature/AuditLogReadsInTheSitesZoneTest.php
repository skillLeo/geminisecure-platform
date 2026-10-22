<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The access and audit log reads and filters in the site's zone (B016)
|--------------------------------------------------------------------------
|
| Storage is UTC (M4 §3.1), and the audit log printed it as it was stored: an
| alert acknowledged at 8:15 PM in Kingston on 23 September read "Sep 24, 2026
| 1:15 AM", with nothing on the screen or in the export saying the clock was
| Greenwich's. And ?from / ?to compared UTC calendar dates, so a reviewer who
| typed the Kingston date of an evening incident missed it, and the day after
| found it. The dispatch screen printed the same acknowledgement as 8:15 PM.
|
| Every row is now printed on its own estate's clock — the zone `tenants.timezone`
| names, which is how every other console screen prints a time — and a row that
| belongs to no estate on the platform's default. A date filter means the day a
| row SHOWS: each row is compared with the day's bounds in its own zone.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: the row read "Sep 24, 2026 1:15 AM"
| and ?to=2026-09-23 did not find it.
|
*/

const B016_OTHER_ESTATE = 'b016eastcoast';

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // A second estate on another clock: Eastern time is Kingston's plus one hour in September.
    DB::connection('mysql')->table('tenants')->insert([
        'id' => B016_OTHER_ESTATE,
        'name' => 'East Coast Estate',
        'receipt_prefix' => 'B16',
        'status' => 'active',
        'timezone' => 'America/New_York',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('mysql')->table('tenants')->where('id', FacilitiesFixture::ESTATE)->update(['timezone' => 'America/Jamaica']);
    SiteTime::flush();

    $record = static function (string $at, ?string $tenantId, string $entityId): void {
        Carbon::setTestNow(Carbon::parse($at, 'UTC'));

        AuditEntry::create([
            'tenant_id' => $tenantId,
            'actor_name' => 'Rae Dispatcher',
            'actor_role' => 'Dispatcher',
            'action' => 'alert.acknowledged',
            'entity_type' => 'DuressAlert',
            'entity_id' => $entityId,
        ]);

        Carbon::setTestNow();
    };

    // The bug's own evening: 8:15 PM in Kingston is 01:15 the next day in UTC.
    $record('2026-09-24 01:15:00', FacilitiesFixture::ESTATE, 'b016-kingston');

    // The platform's own row at the same instant, which belongs to no estate.
    $record('2026-09-24 01:15:00', null, 'b016-platform');

    // 04:30 UTC: still the 23rd in Kingston, already the 24th in New York.
    $record('2026-09-24 04:30:00', B016_OTHER_ESTATE, 'b016-newyork');

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
});

/** @return array<string, array<string, mixed>> the b016 rows, keyed by entity id */
function b016Rows(object $test, array $query): array
{
    $rows = [];

    $test->actingAs($test->director)
        ->get('/audit?'.http_build_query(['q' => 'b016', ...$query]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$rows): AssertableInertia {
            foreach ($page->toArray()['props']['rows'] as $row) {
                $rows[(string) preg_replace('/^.* — /', '', $row['details'])] = $row;
            }

            return $page;
        });

    return $rows;
}

it('prints each row on its own estate\'s clock, and a platform row on the platform\'s', function () {
    $rows = b016Rows($this, []);

    // Each names its clock (M11 §4.1; M19A §4.2 — the tag, not "EST", which Kingston and a New York winter share):
    // the two estates' clocks are an hour apart in September.
    expect($rows['b016-kingston']['timestamp'])->toBe('Sep 23, 2026 8:15 PM JA')
        ->and($rows['b016-kingston']['date'])->toBe('2026-09-23')
        ->and($rows['b016-kingston']['date_label'])->toBe('23 September 2026')
        ->and($rows['b016-platform']['timestamp'])->toBe('Sep 23, 2026 8:15 PM JA')
        ->and($rows['b016-newyork']['timestamp'])->toBe('Sep 24, 2026 12:30 AM NY')
        ->and($rows['b016-newyork']['date'])->toBe('2026-09-24');
});

it('filters by the day each row shows, so the evening of the 23rd is on the 23rd', function () {
    $through23rd = b016Rows($this, ['to' => '2026-09-23']);

    expect(array_keys($through23rd))->toEqualCanonicalizing(['b016-kingston', 'b016-platform']);

    $from24th = b016Rows($this, ['from' => '2026-09-24']);

    expect(array_keys($from24th))->toBe(['b016-newyork']);

    // Clicking a row's own date is the round trip the screen offers: it finds that row.
    $onItsDay = b016Rows($this, ['from' => '2026-09-24', 'to' => '2026-09-24']);

    expect(array_keys($onItsDay))->toBe(['b016-newyork']);

    $onKingstonsDay = b016Rows($this, ['from' => '2026-09-23', 'to' => '2026-09-23']);

    expect(array_keys($onKingstonsDay))->toEqualCanonicalizing(['b016-kingston', 'b016-platform']);
});

it('writes the same site time into the export', function () {
    $csv = $this->actingAs($this->director)->get('/audit/export?q=b016')->assertOk()->streamedContent();

    $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => str_contains($line, 'b016-')));

    $kingston = collect($lines)->first(static fn (string $line): bool => str_contains($line, 'b016-kingston'));
    $newYork = collect($lines)->first(static fn (string $line): bool => str_contains($line, 'b016-newyork'));

    expect($kingston)->toStartWith('"Sep 23, 2026 8:15 PM JA"')
        ->and($newYork)->toStartWith('"Sep 24, 2026 12:30 AM NY"')
        // The file states its clock (M11 §4.3), in the header a spreadsheet keeps.
        ->and($csv)->toContain("When (each estate's own time)");
});
