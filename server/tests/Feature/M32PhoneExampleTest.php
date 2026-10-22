<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Support\EstatePhone;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F7: a phone number's example is the estate's own country's
|--------------------------------------------------------------------------
|
| "That does not look like a Jamaican number … for example +92 301 111 6259." It was not the viewer's browser: the
| Pakistani number was written into the rule. The example is now the estate's, from its own record (the country of its
| time zone), and the platform's home, Jamaica, in the Gemini console. These requests come from a viewer whose browser
| is in Karachi (Accept-Language en-PK, and a console clock set to Asia/Karachi), and it changes nothing.
|
| The estate's zone is the platform's row, changed inside the transaction and rolled back.
|
*/

const M32_KARACHI = ['Accept-Language' => 'en-PK,en;q=0.9,ur;q=0.8'];

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->zone = static function (string $zone): void {
        DB::connection('mysql')->table('tenants')->where('id', FacilitiesFixture::ESTATE)->update(['timezone' => $zone]);
        SiteTime::flush();

        // The next request reads the estate afresh, as every request on a server does. In one test process an estate a
        // request already opened stays open, with the row it was read with, and opening it again keeps that row.
        if (tenancy()->initialized) {
            tenancy()->end();
            FacilitiesFixture::boot();
        }
    };
    ($this->zone)('America/Jamaica');
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    SiteTime::flush();
    FacilitiesFixture::boot();

    $unitId = Unit::query()->where('reference', 'Lot 7791')->value('id');
    $households = DB::connection('tenant')->table('households')->where('unit_id', $unitId)->pluck('id');
    DB::connection('tenant')->table('residents')->whereIn('household_id', $households)->delete();
    DB::connection('tenant')->table('households')->whereIn('id', $households)->delete();
});

it('takes the country from the estate\'s own zone, says the platform\'s home outside an estate, and invents no example', function () {
    expect(EstatePhone::country(FacilitiesFixture::ESTATE))->toBe('JM')
        ->and(EstatePhone::forEstate(FacilitiesFixture::ESTATE)['abroad'])->toBe('That does not look like a Jamaican number. For a number in another country, start with + and the country code, for example +1 876 555 0123.')
        ->and(EstatePhone::forEstate(FacilitiesFixture::ESTATE)['invalid'])->toBe('That does not look like a phone number. Example: 876 555 0123.')
        ->and(EstatePhone::country())->toBe('JM');

    ($this->zone)('Europe/London');
    expect(EstatePhone::forEstate(FacilitiesFixture::ESTATE)['invalid'])->toBe('That does not look like a phone number. Write it with + and the country code, for example +44 7700 900123.')
        ->and(EstatePhone::example(FacilitiesFixture::ESTATE))->toBe(['local' => '+44 7700 900123', 'international' => '+44 7700 900123']);

    // A country the table does not hold: how to write a number, and no example made up.
    ($this->zone)('Asia/Tokyo');
    expect(EstatePhone::forEstate(FacilitiesFixture::ESTATE)['invalid'])->toBe('That does not look like a phone number. Write it with + and the country code first.');
});

it('gives a viewer in Karachi the Jamaican example on a Jamaican estate\'s Add resident, and the British one on a British estate\'s', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 7791');
    $post = fn (string $phone) => $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->withHeaders(M32_KARACHI)->post(FacilitiesFixture::url('/residents'), [
        'phase' => (string) $unit->block, 'lot' => (string) $unit->reference, 'full_name' => 'Phone Example Person', 'email' => 'phone.example@m32.test',
        'phone' => $phone, 'verification' => 'manual',
    ]);

    // Ten digits with no "+": a number from somewhere else, typed without its country code.
    $post('301 111 6259')->assertSessionHasErrors(['phone' => 'That does not look like a Jamaican number. For a number in another country, start with + and the country code, for example +1 876 555 0123.']);
    $post('12345')->assertSessionHasErrors(['phone' => 'That does not look like a phone number. Example: 876 555 0123.']);
    $post('')->assertSessionHasErrors(['phone' => 'Enter a phone number: the dispatcher calls it in an emergency. Example: 876 555 0123.']);

    ($this->zone)('Europe/London');
    $post('12345')->assertSessionHasErrors(['phone' => 'That does not look like a phone number. Write it with + and the country code, for example +44 7700 900123.']);
    $post('')->assertSessionHasErrors(['phone' => 'Enter a phone number: the dispatcher calls it in an emergency. Example: +44 7700 900123.']);

    FacilitiesFixture::boot();
    expect(Resident::query()->where('full_name', 'Phone Example Person')->exists())->toBeFalse();
});

it('shows every estate page the estate\'s own example for its phone boxes, and the Gemini console the platform\'s home', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->withHeaders(M32_KARACHI)->get(FacilitiesFixture::url('/residents/new'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phoneExample', ['local' => '876 555 0123', 'international' => '+1 876 555 0123']));

    ($this->zone)('Europe/London');
    FacilitiesFixture::boot();
    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))->get(FacilitiesFixture::url('/residents/new'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phoneExample.local', '+44 7700 900123'));

    // The Gemini console's own forms (a new client, the SMS settings): the platform's home, whatever the viewer's clock.
    // The Gemini console is no estate's: the estate the request above opened is closed, as between two requests on a server.
    tenancy()->end();
    FacilitiesFixture::boot();
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $director->forceFill(['console_time_view' => 'mine', 'console_zone' => 'Asia/Karachi'])->save();
    $this->actingAs($director)->withHeaders(M32_KARACHI)->get('/clients/new')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phoneExample.local', '876 555 0123'));

    // Places is about one estate: its supervisor's number box shows that estate's own example.
    $this->actingAs($director)->get('/guards/places?client='.FacilitiesFixture::ESTATE.'&step=settings')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phoneExample.local', '+44 7700 900123'));
});

it('answers a supplier, a console user\'s own number and a guard\'s with the estate\'s example', function () {
    // A supplier, on the estate's console.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))->withHeaders(M32_KARACHI)->post(FacilitiesFixture::url('/accounting/vendors'), [
        'name' => 'M32 Phone Supplier', 'category' => 'Plumbing', 'contact_name' => 'Devon', 'contact_phone' => '301 111 6259', 'contact_email' => 'supplier@m32.test',
    ])->assertSessionHasErrors(['contact_phone' => 'That does not look like a Jamaican number. For a number in another country, start with + and the country code, for example +1 876 555 0123.']);

    // A console user's own mobile number, on the estate's door.
    $this->actingAs(FacilitiesFixture::viewer(Role::SECRETARY))->withHeaders(M32_KARACHI)->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'secretary.m32@example.com', 'phone' => '12345'])
        ->assertSessionHasErrors(['phone' => 'That does not look like a phone number. Example: 876 555 0123.']);

    // A guard, in the Gemini console, posted to the estate: that estate's country.
    ($this->zone)('Europe/London');
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->actingAs($director)->withHeaders(M32_KARACHI)->post('/guards', [
        'full_name' => 'M32 Phone Guard', 'psra_number' => 'PSRA-M32PH', 'psra_expires_on' => now()->addYear()->toDateString(), 'phone' => '12345',
        'email' => 'phone.guard@m32.test', 'employment_type' => 'full_time', 'tenant_id' => FacilitiesFixture::ESTATE, 'standard_rate' => '425',
    ])->assertSessionHasErrors(['phone' => 'That does not look like a phone number. Write it with + and the country code, for example +44 7700 900123.']);

    // A post's supervisor, on Places: the estate's example in the refusal.
    // Places answers a refusal in its own error bag (`PlacesController::done()`).
    $this->actingAs($director)->from('/guards/places?client='.FacilitiesFixture::ESTATE.'&step=settings')
        ->post('/guards/places/'.FacilitiesFixture::ESTATE.'/supervisor', ['phone' => '12'])
        ->assertSessionHasErrors(['places' => 'That is not a phone number a guard\'s phone can call. Write it with its area code, e.g. +44 7700 900123.']);
});

it('answers the Resident App with the estate\'s example too', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 7791');
    $household = Household::query()->create(['unit_id' => $unit->id, 'name' => 'Lot 7791 household', 'access_restricted' => false]);
    $person = new Resident;
    $person->forceFill(['household_id' => $household->id, 'full_name' => 'App Phone Person', 'email' => 'app.phone@m32.test', 'phone' => '+1 658 555 0791', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'app.phone@m32.test', 'full_name' => 'App Phone Person',
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(), 'resident_id' => $person->id, 'unit_id' => $unit->id,
    ]);
    $token = $account->createToken('resident-app:m32-phone', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    app('auth')->forgetGuards();
    $answer = $this->withToken($token)->withHeaders([...M32_KARACHI, 'Idempotency-Key' => 'm32-phone-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String()])
        ->patchJson('/api/v1/me', ['phone' => '301 111 6259'])->assertStatus(422)->json();
    FacilitiesFixture::boot();

    expect(json_encode($answer, JSON_UNESCAPED_UNICODE))->toContain('That does not look like a Jamaican number. For a number in another country, start with + and the country code, for example +1 876 555 0123.')
        ->not->toContain('+92');
});
