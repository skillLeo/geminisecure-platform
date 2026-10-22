<?php

declare(strict_types=1);

use App\Models\DuressAlert;
use App\Models\Role;
use App\Models\SiteEmergencyNumber;
use App\Services\Dispatch\PanicWatch;
use App\Services\Dispatch\SiteEmergencyNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| The Gemini pop-up's dispatch lines, read once for every estate — M11 A2
|--------------------------------------------------------------------------
|
| The panic pop-up is mounted on every Gemini console page, and each outstanding alert
| carries the site's own Gemini Dispatch line. That line was looked up one estate at a
| time — a query per estate with an alert open, on every page a Director opened. It is
| now read for all of them together (`SiteEmergencyNumbers::forSites()`).
|
| The oracle is the one-estate lookup, which stays: for every alert the pop-up carries,
| the line must be the one `forSite()` finds for that estate — including an estate with no
| numbers at all, one whose dispatch line is not its first number, and one with more active
| numbers than a site is texted, whose dispatch line sits beyond the cut and so is not one.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('gives every alert on the pop-up its own estate\'s dispatch line, from one read, exactly as the one-estate lookup finds it', function (): void {
    $suffix = Str::lower(Str::random(5));
    $estates = [];

    foreach (['first', 'second', 'none', 'crowded'] as $kind) {
        $id = 'pw'.$kind.$suffix;
        $estates[$kind] = $id;

        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id, 'name' => 'Watch '.$kind.' '.$suffix, 'status' => 'active', 'demo' => 0,
            'created_at' => now(), 'updated_at' => now(), 'data' => '{}',
        ]);

        DuressAlert::query()->forceCreate([
            'tenant_id' => $id, 'kind' => 'panic', 'mode' => 'audible', 'raised_by_name' => 'Lot 1 household',
            'unit_reference' => 'Lot 1', 'status' => 'open', 'device_time' => now(), 'server_time' => now(),
            'clock_skewed' => false, 'captured_offline' => false, 'idempotency_key' => 'pw-'.$kind.'-'.$suffix,
            'is_simulated' => false, 'is_drill' => false,
        ]);
    }

    $number = static fn (string $tenant, string $role, string $label, string $digits, int $sort, bool $active = true) => SiteEmergencyNumber::query()->create([
        'tenant_id' => $tenant, 'role' => $role, 'label' => $label, 'number' => $digits, 'e164' => '+1'.preg_replace('/\D/', '', $digits), 'is_active' => $active, 'sort' => $sort,
    ]);

    // Dispatch is the only number.
    $number($estates['first'], 'dispatch', 'Gemini Dispatch', '876-354-2119', 1);

    // Dispatch after the president, and an INACTIVE dispatch row that must never be chosen.
    $number($estates['second'], 'president', 'President', '876-508-8082', 1);
    $number($estates['second'], 'dispatch', 'Old dispatch', '876-000-0000', 0, false);
    $number($estates['second'], 'dispatch', 'Dispatch desk', '876-354-2200', 2);

    // Eleven active numbers and the dispatch line the eleventh: a site is texted ten.
    for ($k = 1; $k <= SiteEmergencyNumbers::MAX_RECIPIENTS; $k++) {
        $number($estates['crowded'], 'property_office', 'Office '.$k, '876-224-44'.str_pad((string) $k, 2, '0', STR_PAD_LEFT), $k);
    }
    $number($estates['crowded'], 'dispatch', 'Late dispatch', '876-354-9999', SiteEmergencyNumbers::MAX_RECIPIENTS + 1);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $watched = QueryBudget::measure(fn (): ?array => app(PanicWatch::class)->forViewer($director));
    $calls = collect($watched['result']['alerts'])->keyBy('estate');

    // The oracle: the one-estate lookup `callFor()` still makes, asked afresh for each.
    $oracle = static function (string $tenant): ?array {
        $dispatch = app(SiteEmergencyNumbers::class)->forSite($tenant)->firstWhere('role', 'dispatch');

        return $dispatch === null ? null : ['label' => (string) $dispatch->label, 'number' => (string) $dispatch->number];
    };

    foreach ($estates as $kind => $tenant) {
        expect($calls->has($tenant))->toBeTrue("the {$kind} estate's alert is on the pop-up")
            ->and($calls[$tenant]['call'])->toBe($oracle($tenant), "the {$kind} estate's dispatch line");
    }

    expect($calls[$estates['first']]['call'])->toBe(['label' => 'Gemini Dispatch', 'number' => '876-354-2119'])
        ->and($calls[$estates['second']]['call'])->toBe(['label' => 'Dispatch desk', 'number' => '876-354-2200'])
        ->and($calls[$estates['none']]['call'])->toBeNull()
        ->and($calls[$estates['crowded']]['call'])->toBeNull();

    // One read of the numbers, whichever and however many estates have an alert open.
    expect(collect($watched['statements'])->filter(static fn (array $s): bool => str_contains($s['sql'], 'site_emergency_numbers'))->count())->toBe(1);
});
