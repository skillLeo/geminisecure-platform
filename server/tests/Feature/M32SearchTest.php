<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Services\Estate\Residents;
use App\Support\SiteTime;
use Brick\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F4: the same search on every list where a person, a guard, a unit or a household is chosen
|--------------------------------------------------------------------------
|
| The register's search (M32RegisterTest) is the model: a person by name, email or phone however punctuated, a home by
| its lot, phase, name or vehicles. The Dues register, the receipts and the Guard App's gate search reach it through
| `Residents::matchResident()` and `plateCondition()`; the Gemini guard directory reads a guard's phone and email too;
| the Schedule's shift panel finds a guard by phone in the browser.
|
| THE PHONES HERE ARE 658, Jamaica's other area code. The fixture's seeded people are `876-555-0NNN`, NNN their lot, so a
| test's 876 number can be a seeded household's too, and a search that finds both is right.
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
    FacilitiesFixture::boot();

    $unitId = Unit::query()->where('reference', 'Lot 7781')->value('id');
    $households = DB::connection('tenant')->table('households')->where('unit_id', $unitId)->pluck('id');
    DB::connection('tenant')->table('household_vehicles')->whereIn('household_id', $households)->delete();
    DB::connection('tenant')->table('residents')->whereIn('household_id', $households)->delete();
    DB::connection('tenant')->table('households')->whereIn('id', $households)->delete();
});

it('finds a household member by name, email or phone however punctuated, through the shared resident match', function (string $query) {
    $unit = FacilitiesFixture::scratchUnit('Lot 7781');
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'Lot 7781 household', 'access_restricted' => false]);
    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Shared Match Person', 'email' => 'shared.match@m32.test', 'phone' => '+1 658 555 0281', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();

    $found = Resident::query()->where('household_id', $household->id)->tap(static fn ($q) => Residents::matchResident($q, $query))->pluck('full_name')->all();

    expect($found)->toBe(['Shared Match Person']);
})->with([
    'name' => ['match person'],
    'email' => ['SHARED.MATCH@'],
    'phone, spaced' => ['658 555 0281'],
    'phone, digits only' => ['6585550281'],
    'phone, with the country code' => ['+16585550281'],
]);

it('finds a receipt by one of the paying household\'s people, not by its numbers alone', function (string $query) {
    $unit = FacilitiesFixture::scratchUnit('Lot 7781');
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'Lot 7781 household', 'access_restricted' => false]);
    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Receipt Search Payer', 'email' => 'receipt.payer@m32.test', 'phone' => '658-555-0291', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();

    $dues = app(Dues::class);
    $dues->charge($unit, Money::ofMinor(1_000_00, 'JMD'), 'M32 search dues', SiteTime::date());
    $payment = $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), Payment::CASH, SiteTime::date());

    $rows = collect(app(Receipts::class)->registerPage(table: Receipts::registerTable(Request::create('/finance/receipts', 'GET', ['q' => $query])))['rows']);

    expect($rows->pluck('unit')->unique()->values()->all())->toBe(['Lot 7781'])
        ->and($rows->pluck('receipt_no')->all())->toContain($payment->receipt_no);
})->with([
    'name' => ['receipt search payer'],
    'email' => ['RECEIPT.PAYER@'],
    'phone, spaced' => ['658 555 0291'],
    'phone, with the country code' => ['+1 (658) 555-0291'],
]);

it('finds a household by a vehicle plate written any way, and says nothing for a search with no plate in it', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 7781');
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'Lot 7781 household', 'access_restricted' => false]);
    DB::connection('tenant')->table('household_vehicles')->insert(['household_id' => $household->id, 'plate' => 'PK-7781', 'created_at' => now(), 'updated_at' => now()]);

    foreach (['PK7781', 'pk 7781', 'Pk-7781'] as $query) {
        [$sql, $bindings] = Residents::plateCondition($query);
        expect(DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->whereRaw($sql, $bindings)->exists())->toBeTrue($query);
    }

    expect(Residents::plateCondition('   '))->toBeNull();
});

it('lets the Guard App find a household at the gate by a person\'s name, email or phone, or by a vehicle plate', function (string $query) {
    $unit = FacilitiesFixture::scratchUnit('Lot 7781');
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'Lot 7781 household', 'access_restricted' => false]);
    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'Gate Search Member', 'email' => 'gate.member@m32.test', 'phone' => '658 555 0299', 'relationship' => 'spouse', 'is_primary' => false, 'status' => Resident::VERIFIED])->save();
    DB::connection('tenant')->table('household_vehicles')->insert(['household_id' => $household->id, 'plate' => 'PK-7781', 'created_at' => now(), 'updated_at' => now()]);

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'M32 Search Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'M32 Gate Officer', 'employee_number' => 'GS-M32S-2', 'psra_number' => 'PSRA-M32S-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($guard, 'M32 search handset')['token'];

    app('auth')->forgetGuards();
    $answer = $this->withToken($token)->getJson('/api/v1/gate/search?'.http_build_query(['name' => $query]))->assertOk()->json();
    FacilitiesFixture::boot();

    expect(collect($answer['items'])->pluck('household')->all())->toBe(['Lot 7781 household'])
        ->and($answer['total'])->toBe(1);
})->with([
    'a person\'s name' => ['gate search member'],
    'a person\'s email' => ['GATE.MEMBER@'],
    'a person\'s phone, with the country code' => ['+1 658 555 0299'],
    'a plate, run together' => ['pk7781'],
    'a plate, spaced' => ['PK 7781'],
]);

it('finds a guard in the Gemini directory by phone however punctuated, and by email', function (string $query) {
    Guard::create([
        'full_name' => 'M32 Search Guard', 'employee_number' => 'GS-M32S-1', 'psra_number' => 'PSRA-M32S-1', 'phone' => '658 555 0188', 'email' => 'search.guard@m32.test',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => FacilitiesFixture::ESTATE,
    ]);

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->get('/guards?q='.rawurlencode($query))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('guards', fn ($rows) => collect($rows)->pluck('name')->all() === ['M32 Search Guard']));
})->with([
    'phone, spaced' => ['658 555 0188'],
    'phone, digits only' => ['6585550188'],
    'phone, with the country code' => ['+16585550188'],
    'email' => ['search.guard@'],
    'employee number' => ['GS-M32S'],
]);

it('sends the Schedule\'s guard picker each guard\'s phone, which the picker searches as typed any way', function () {
    // The browser half, the search itself, is tests/js/workforce-shift-panel.test.mjs ("finds a guard by phone …").
    DB::connection('mysql')->table('tenants')->where('id', 'm32picker')->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => 'm32picker', 'name' => 'Picker Test Estate', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $gate = Post::create(['tenant_id' => 'm32picker', 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    foreach ([['Phone Guard', '876 555 0199'], ['No Phone Guard', null]] as $i => [$name, $phone]) {
        Guard::create([
            'full_name' => $name, 'employee_number' => 'GS-M32P-'.$i, 'psra_number' => 'PSRA-M32P-'.$i, 'phone' => $phone,
            'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => 'm32picker',
        ]);
    }

    $answer = $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->postJson('/guards/schedule/check', [
        'post_id' => $gate->id, 'date' => SiteTime::date('m32picker')->addDays(2)->toDateString(), 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'repeat' => 'once',
    ])->assertOk()->json();

    $phones = collect($answer['guards'])->whereIn('name', ['Phone Guard', 'No Phone Guard'])->pluck('phone', 'name')->all();

    expect($phones)->toEqual(['Phone Guard' => '876 555 0199', 'No Phone Guard' => null]);
    SiteTime::flush();
});
