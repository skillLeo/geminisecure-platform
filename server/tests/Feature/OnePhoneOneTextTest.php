<?php

declare(strict_types=1);

use App\Models\SiteEmergencyNumber;
use App\Services\Dispatch\SiteEmergencyNumbers;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| ONE PHONE IS ON A SITE'S LIST ONCE, SO A PANIC TEXTS IT ONCE (bug hunt B101)
|--------------------------------------------------------------------------
|
| The site's emergency numbers were unique only on the string the office typed.
| "Correcting" the President's formatting —
|
|     site:emergency-numbers phoenixpark --set="president|President|876 508 8082"
|
| — printed "Added" and left two active rows for +1 876 508 8082, so every panic
| texted the President twice (two texts read as two emergencies) and counted a
| fifth number. A phone is now one row per site: the same phone typed another way
| corrects the row it already has, the same phone under another role is refused
| with the row it would have collided with, and the database will not hold two
| ACTIVE rows for one phone at one site.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->site = FacilitiesFixture::ESTATE;
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $numbers = app(SiteEmergencyNumbers::class);
    $numbers->put($this->site, 'president', 'President', '1-876-508-8082', 1);
    $numbers->put($this->site, 'head_of_security', 'PPV1 Head of Security', '1-876-845-9422', 2);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function onePhoneRows(string $tenantId, string $e164): Illuminate\Support\Collection
{
    return SiteEmergencyNumber::query()->where('tenant_id', $tenantId)->where('e164', $e164)->get();
}

it('corrects the row a phone already has when the office types the same phone another way', function () {
    $exit = Artisan::call('site:emergency-numbers', ['tenant' => $this->site, '--set' => ['president|President|876 508 8082']]);
    $output = Artisan::output();

    $rows = onePhoneRows($this->site, '+18765088082');

    expect($exit)->toBe(0)
        ->and($output)->toContain('Updated: President 876 508 8082')
        ->and($output)->not->toContain('Added')
        ->and($rows)->toHaveCount(1)
        ->and($rows->first()->number)->toBe('876 508 8082')
        ->and($rows->first()->is_active)->toBeTrue();

    // What a panic texts: each phone once.
    expect(app(SiteEmergencyNumbers::class)->forSite($this->site)->pluck('e164')->all())
        ->toBe(['+18765088082', '+18768459422']);
});

it('refuses the same phone under a second role, naming the row it is already on', function () {
    $exit = Artisan::call('site:emergency-numbers', ['tenant' => $this->site, '--set' => ['property_office|Property Office|876 845 9422']]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('already on this estate\'s list as PPV1 Head of Security (1-876-845-9422)')
        ->and(onePhoneRows($this->site, '+18768459422'))->toHaveCount(1)
        ->and(onePhoneRows($this->site, '+18768459422')->first()->role)->toBe('head_of_security');
});

it('brings a retired number back on the row it had, rather than beside the phone that replaced it', function () {
    // Retired, then the same phone put back under its new spelling: one active row, still.
    Artisan::call('site:emergency-numbers', ['tenant' => $this->site, '--disable' => ['1-876-508-8082']]);
    app(SiteEmergencyNumbers::class)->put($this->site, 'president', 'President', '(876) 508-8082', 3);

    $active = onePhoneRows($this->site, '+18765088082')->where('is_active', true);

    expect($active)->toHaveCount(1);

    // …and the old spelling put back retires the one that stood in for it.
    app(SiteEmergencyNumbers::class)->put($this->site, 'president', 'President', '1-876-508-8082', 1);

    $active = onePhoneRows($this->site, '+18765088082')->where('is_active', true);

    expect($active)->toHaveCount(1)
        ->and($active->first()->number)->toBe('1-876-508-8082');
});

it('will not hold two active rows for one phone at one site, whoever writes them', function () {
    $insert = static fn (bool $active) => DB::connection('mysql')->table('site_emergency_numbers')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE, 'role' => 'president', 'label' => 'President again',
        'number' => '876.508.8082', 'e164' => '+18765088082', 'is_active' => $active, 'sort' => 9,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // A retired copy may stay on file — nothing is deleted, and it texts nobody.
    $insert(false);

    expect(fn () => DB::connection('mysql')->transaction(static fn () => DB::connection('mysql')->table('site_emergency_numbers')->insert([
        'tenant_id' => FacilitiesFixture::ESTATE, 'role' => 'president', 'label' => 'President twice',
        'number' => '876-508-8082', 'e164' => '+18765088082', 'is_active' => true, 'sort' => 9,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});
