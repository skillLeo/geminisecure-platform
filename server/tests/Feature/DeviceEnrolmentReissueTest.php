<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Issue another code" retires the earlier one (DESIGN §11 row 6)
|--------------------------------------------------------------------------
|
| A Guard App enrolment code is not a message: it is shown once on the console and read to the officer, and
| "Issue another code" is its resend — so there is nothing to email and nothing to resend by mail. What was
| wrong is that the earlier code kept working for the rest of its day: two codes read down two telephones,
| either of which enrols a handset as this officer. Now the earlier unused code stops working the moment the
| next is issued, and the log says so.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Reissue Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = Guard::create([
        'full_name' => 'Reissue Officer', 'employee_number' => 'GS-RIS-1', 'psra_number' => 'PSRA-RIS-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function reissueKey(): string
{
    return sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
}

it('retires the earlier unused code when another is issued, so only the newest enrols a handset', function () {
    $enrolment = app(DeviceEnrolment::class);

    $first = $enrolment->issueCode($this->guard);

    // One code, nothing retired: nothing to say.
    expect(DB::connection('mysql')->table('audit_log')->where('action', 'device.enrolment_code_reissued')->where('entity_id', (string) $this->guard->id)->exists())->toBeFalse();

    $second = $enrolment->issueCode($this->guard);
    expect($second)->not->toBe($first);

    // The first no longer enrols anything…
    $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $first, 'device_uid' => 'reissue-phone-1', 'platform' => 'android', 'public_key' => reissueKey(),
    ])->assertStatus(422)->assertJsonPath('error.code', 'enrolment_code_invalid');
    FacilitiesFixture::boot();

    // …and the newest does.
    $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $second, 'device_uid' => 'reissue-phone-2', 'platform' => 'android', 'public_key' => reissueKey(),
    ])->assertCreated()->assertJsonPath('status', 'enrolled');
    FacilitiesFixture::boot();

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'device.enrolment_code_reissued')->where('entity_id', (string) $this->guard->id)->first();
    expect($entry)->not->toBeNull()
        ->and(json_decode((string) $entry->after, true))->toBe(['retired_codes' => 1])
        // Only this officer's codes: never another guard's.
        ->and(DB::connection('mysql')->table('device_enrolment_codes')->where('guard_id', $this->guard->id)->whereNull('used_at')->where('expires_at', '>', now())->count())->toBe(0);
});

it('never retires another officer\'s code', function () {
    $enrolment = app(DeviceEnrolment::class);
    $other = Guard::create([
        'full_name' => 'Other Officer', 'employee_number' => 'GS-RIS-2', 'psra_number' => 'PSRA-RIS-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->guard->post_id,
    ]);

    $theirs = $enrolment->issueCode($other);
    $enrolment->issueCode($this->guard);
    $enrolment->issueCode($this->guard);

    $this->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $theirs, 'device_uid' => 'reissue-phone-3', 'platform' => 'ios', 'public_key' => reissueKey(),
    ])->assertCreated()->assertJsonPath('status', 'enrolled');
    FacilitiesFixture::boot();
});
