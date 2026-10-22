<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\Estate\Household;
use App\Models\PlatformContactSetting;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Platform\SupportLine;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Gemini Security's phone line is a console setting, and the app calls it or hides the button (8 Oct 2026)
|--------------------------------------------------------------------------
|
| The company has given no support number. Until the console saves one, `support.contact` says there is none and the
| Resident App shows no "Call Gemini Security" — no placeholder, no button that rings nothing. Saved, it is kept in
| international form and called. Only the messaging permission changes it, and every change is audited.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    config(['legal.support_phone' => null]);
    PlatformContactSetting::query()->delete();

    $unit = FacilitiesFixture::scratchUnit('Lot 9531');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Line household', 'access_restricted' => false]);
    $resident = Tests\Support\HouseholdHolder::of($household, 'Lena Line');
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'lena.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Lena Line', 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $account->createToken('resident-app:line', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function lineAsPhone(Tests\TestCase $test): array
{
    app('auth')->forgetGuards();
    $json = $test->withToken($test->token)->getJson('/api/v1/support/contact')->assertOk()->json();
    FacilitiesFixture::boot();

    return $json;
}

it('says there is no number until the console saves one — so the app shows no call button', function () {
    expect(lineAsPhone($this))->toMatchArray(['phone' => null, 'phone_display' => null, 'email' => config('legal.support_email')]);
});

it('keeps what the Director types in international form, and the phone calls exactly that', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    foreach (['876-555-0123', '(876) 555 0123', '+1 876 555 0123', '1 876 555 0123'] as $typed) {
        app('auth')->forgetGuards();
        $this->actingAs($director)->from('/settings/sms')->post('/settings/support-line', ['support_phone' => $typed])->assertRedirect('/settings/sms')->assertSessionHasNoErrors();
        FacilitiesFixture::boot();

        expect(lineAsPhone($this))->toMatchArray(['phone' => '+18765550123', 'phone_display' => '+1 876 555 0123']);
    }

    expect(AuditEntry::query()->where('action', 'platform.support_line_changed')->latest('id')->first()?->after)->toBe(['support_phone' => '+18765550123']);

    // Cleared: the app hides the button again.
    app('auth')->forgetGuards();
    $this->actingAs($director)->from('/settings/sms')->post('/settings/support-line', ['support_phone' => ''])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(lineAsPhone($this)['phone'])->toBeNull();
});

it('refuses what cannot be called without a guess — a bare seven-digit number, letters — and keeps the number it had', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    app(SupportLine::class)->save('876 555 0123', $director);

    foreach (['555 0123', 'call us'] as $typed) {
        app('auth')->forgetGuards();
        $this->actingAs($director)->from('/settings/sms')->post('/settings/support-line', ['support_phone' => $typed])->assertSessionHasErrors('support_phone');
        FacilitiesFixture::boot();
    }

    expect(lineAsPhone($this)['phone'])->toBe('+18765550123');
});

it('is changed only with the messaging permission; a dispatcher cannot', function () {
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    app('auth')->forgetGuards();
    $this->actingAs($dispatcher)->post('/settings/support-line', ['support_phone' => '876 555 0123'])->assertForbidden();
    FacilitiesFixture::boot();

    expect(lineAsPhone($this)['phone'])->toBeNull()
        ->and(SupportLine::spaced('+442071838750'))->toBe('+442071838750');
});
