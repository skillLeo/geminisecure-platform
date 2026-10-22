<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Notifications\GuardPhoneRecovered;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Devices\DeviceResume;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M46 §1 — a guard's phone that lost its sign-in gets back in with the code it already used
|--------------------------------------------------------------------------
|
| Hassam enrolled a phone with a code and signed in; the next day the app had forgotten, and the same code was refused
| as "already used". A phone was bound by its INSTALL alone, so anything that wiped the app's data made the same handset
| a stranger. Now the binding also keeps the handset's own identity, hashed, and the code remembers the binding it made:
| a used code works again ONLY from that handset, for that guard, while the binding is still theirs — a recovery,
| audited and mailed to the Heads of Security as one. On any other handset a used code stays dead.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Recovery Gate', 'type' => 'gate', 'is_active' => true]);
    $this->guard = Guard::create([
        'full_name' => 'Hassam Recover', 'employee_number' => 'GS-RCV-1', 'psra_number' => 'PSRA-RCV-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** One install of the Guard App on a handset: its own install id and key pair; the handset's identity is what a reinstall keeps. */
function m46Install(string $uid, ?string $hardware): array
{
    $pair = sodium_crypto_sign_keypair();

    return [
        'uid' => $uid, 'hardware' => $hardware, 'secret' => sodium_crypto_sign_secretkey($pair),
        'public' => sodium_bin2base64(sodium_crypto_sign_publickey($pair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
    ];
}

/** `POST /devices/enrol` from this install, with this code — what the enrol screen sends. */
function m46Enrol(object $test, string $code, array $install)
{
    app('auth')->forgetGuards();
    $answer = $test->postJson('/api/v1/devices/enrol', [
        'enrolment_code' => $code, 'device_uid' => $install['uid'], 'platform' => 'android', 'public_key' => $install['public'],
        ...($install['hardware'] === null ? [] : ['hardware_id' => $install['hardware']]),
    ]);
    FacilitiesFixture::boot();
    app('auth')->forgetGuards();

    return $answer;
}

/** A fingerprint-free `check` from this install: challenge, signature, and the handset's identity beside them. */
function m46Check(object $test, array $install, ?string $hardware = null)
{
    $challenge = (string) $test->postJson('/api/v1/devices/challenge', ['device_uid' => $install['uid']])->assertOk()->json('challenge');
    $signature = sodium_bin2base64(sodium_crypto_sign_detached(DeviceResume::message($install['uid'], $challenge), $install['secret']), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

    app('auth')->forgetGuards();
    $answer = $test->postJson('/api/v1/devices/resume', [
        'device_uid' => $install['uid'], 'challenge' => $challenge, 'signature' => $signature, 'method' => 'check',
        ...($hardware === null ? [] : ['hardware_id' => $hardware]),
    ]);
    FacilitiesFixture::boot();
    app('auth')->forgetGuards();

    return $answer;
}

function m46TokenWorks(object $test, string $token): bool
{
    app('auth')->forgetGuards();
    $status = $test->withToken($token)->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/pass-keys')->status();
    FacilitiesFixture::boot();
    app('auth')->forgetGuards();

    return $status === 200;
}

function m46Bindings(Guard $guard): Collection
{
    return DB::connection('mysql')->table('device_bindings')->where('guard_id', $guard->id)->orderBy('id')->get();
}

it('lets the same handset take back its binding with the code it already used, as a recovery the Heads of Security are told of', function () {
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    EstateAssignment::create(['user_id' => $head->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true]);

    $code = app(DeviceEnrolment::class)->issueCode($this->guard);
    $first = m46Install('rcv-install-1', 'a1b2c3d4e5f60718');
    $firstToken = (string) m46Enrol($this, $code, $first)->assertCreated()->assertJsonPath('recovered', false)->json('token');

    $made = m46Bindings($this->guard)->sole();
    expect($made->hardware_hash)->toBe(DeviceEnrolment::hardwareHash('a1b2c3d4e5f60718'))
        ->and($made->hardware_hash)->not->toContain('a1b2c3d4e5f60718')
        ->and((int) DB::connection('mysql')->table('device_enrolment_codes')->where('guard_id', $this->guard->id)->value('binding_id'))->toBe((int) $made->id);

    // The next day the app's data is gone: a new install, a new key — the same handset.
    $this->travel(20)->hours();
    $again = m46Install('rcv-install-2', 'a1b2c3d4e5f60718');
    $answer = m46Enrol($this, $code, $again)->assertCreated()
        ->assertJsonPath('status', 'enrolled')
        ->assertJsonPath('recovered', true)
        ->assertJsonPath('guard.name', 'Hassam Recover');
    ApiContract::assertMatches($answer, 'devices.enrol');

    $rows = m46Bindings($this->guard);
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchObject(['device_uid' => 'rcv-install-1', 'ended_reason' => 'recovered', 'ended_by' => 'guard'])
        ->and($rows[1])->toMatchObject(['device_uid' => 'rcv-install-2', 'hardware_hash' => $made->hardware_hash, 'ended_at' => null])
        ->and((int) DB::connection('mysql')->table('device_enrolment_codes')->where('guard_id', $this->guard->id)->value('binding_id'))->toBe((int) $rows[1]->id);

    // The new install signs in as itself; the wiped one's token is gone.
    expect(m46TokenWorks($this, (string) $answer->json('token')))->toBeTrue()
        ->and(m46TokenWorks($this, $firstToken))->toBeFalse();
    m46Check($this, $again)->assertOk()->assertJsonPath('state', 'bound');

    // Recorded as a recovery, never as a second enrolment — and the Head of Security is told.
    $audit = DB::connection('mysql')->table('audit_log')->where('entity_id', (string) $this->guard->id)->whereIn('action', ['device.enrolled', 'device.recovered'])->orderBy('id')->pluck('action')->all();
    expect($audit)->toBe(['device.enrolled', 'device.recovered']);
    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'device.recovered')->where('entity_id', (string) $this->guard->id)->sole();
    expect(json_decode((string) $entry->before, true))->toBe(['binding' => (int) $rows[0]->id, 'device_uid' => 'rcv-install-1'])
        ->and(json_decode((string) $entry->after, true))->toMatchArray(['device_uid' => 'rcv-install-2', 'binding' => (int) $rows[1]->id]);

    if ($head->alertEmail() !== null) {
        Notification::assertSentTo($head, GuardPhoneRecovered::class);
    }

    // And again, the week after: the code follows its own handset from install to install.
    $third = m46Install('rcv-install-3', 'a1b2c3d4e5f60718');
    m46Enrol($this, $code, $third)->assertCreated()->assertJsonPath('recovered', true);
    expect(m46Bindings($this->guard)->whereNull('ended_at')->sole()->device_uid)->toBe('rcv-install-3');
});

it('keeps a used code dead on every other handset, in the words an unknown code gets', function () {
    $code = app(DeviceEnrolment::class)->issueCode($this->guard);
    m46Enrol($this, $code, m46Install('rcv-own', 'own-handset-0001'))->assertCreated();

    foreach ([m46Install('rcv-thief', 'another-handset-02'), m46Install('rcv-silent', null)] as $stranger) {
        m46Enrol($this, $code, $stranger)->assertUnprocessable()
            ->assertJsonPath('error.code', 'enrolment_code_invalid')
            ->assertJsonPath('error.message', DeviceEnrolment::CODE_REFUSED);
    }

    m46Enrol($this, 'ZZZZ-ZZZZ', m46Install('rcv-guess', 'own-handset-0001'))->assertUnprocessable()
        ->assertJsonPath('error.message', DeviceEnrolment::CODE_REFUSED);

    expect(m46Bindings($this->guard))->toHaveCount(1)
        ->and(m46Bindings($this->guard)->sole())->toMatchObject(['device_uid' => 'rcv-own', 'ended_at' => null])
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'device.recovered')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('does not recover a phone the office took out of service, or one a later code re-enrolled', function () {
    $enrolment = app(DeviceEnrolment::class);

    // Taken out of service: the handset's own code is dead with it.
    $removedCode = $enrolment->issueCode($this->guard);
    m46Enrol($this, $removedCode, m46Install('rcv-removed', 'removed-handset-1'))->assertCreated();
    $enrolment->revoke($this->guard->fresh());
    m46Enrol($this, $removedCode, m46Install('rcv-removed-2', 'removed-handset-1'))->assertUnprocessable()->assertJsonPath('error.code', 'enrolment_code_invalid');

    // A fresh code on the same handset binds at once — the same phone, so no rebind to approve — and supersedes the old.
    $older = $enrolment->issueCode($this->guard->fresh());
    m46Enrol($this, $older, m46Install('rcv-a', 'same-handset-77'))->assertCreated();
    $newer = $enrolment->issueCode($this->guard->fresh());
    m46Enrol($this, $newer, m46Install('rcv-b', 'same-handset-77'))->assertCreated()->assertJsonPath('status', 'enrolled')->assertJsonPath('recovered', false);
    expect(m46Bindings($this->guard)->firstWhere('device_uid', 'rcv-a')->ended_reason)->toBe('reenrolled');

    m46Enrol($this, $older, m46Install('rcv-c', 'same-handset-77'))->assertUnprocessable()->assertJsonPath('error.code', 'enrolment_code_invalid');
    m46Enrol($this, $newer, m46Install('rcv-d', 'same-handset-77'))->assertCreated()->assertJsonPath('recovered', true);
});

it('does not hand a handset back to an officer once another officer has been set up on it', function () {
    $other = Guard::create([
        'full_name' => 'Marcia Second', 'employee_number' => 'GS-RCV-2', 'psra_number' => 'PSRA-RCV-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $this->guard->post_id,
    ]);
    $enrolment = app(DeviceEnrolment::class);

    $mine = $enrolment->issueCode($this->guard);
    m46Enrol($this, $mine, m46Install('rcv-shared-1', 'shared-handset-5'))->assertCreated();

    // Wiped, then set up for the next shift's officer with their own code.
    $theirs = $enrolment->issueCode($other);
    m46Enrol($this, $theirs, m46Install('rcv-shared-2', 'shared-handset-5'))->assertCreated();

    m46Enrol($this, $mine, m46Install('rcv-shared-3', 'shared-handset-5'))->assertUnprocessable()->assertJsonPath('error.code', 'enrolment_code_invalid');
    m46Enrol($this, $theirs, m46Install('rcv-shared-4', 'shared-handset-5'))->assertCreated()->assertJsonPath('recovered', true)->assertJsonPath('guard.name', 'Marcia Second');
});

it('records the handset of a phone bound before handsets were, once its key proves it — so it can recover too', function () {
    $code = app(DeviceEnrolment::class)->issueCode($this->guard);
    // Today's app sends no handset identity.
    $old = m46Install('rcv-early', null);
    m46Enrol($this, $code, $old)->assertCreated();
    expect(m46Bindings($this->guard)->sole()->hardware_hash)->toBeNull();

    // Without one on record, the wiped phone cannot recover: one new code from the office, once.
    m46Enrol($this, $code, m46Install('rcv-early-wiped', 'early-handset-9'))->assertUnprocessable();

    // The updated app names its handset when it next signs in; the signature proves it is the bound install.
    m46Check($this, $old, 'early-handset-9')->assertOk()->assertJsonPath('state', 'bound');
    expect(m46Bindings($this->guard)->sole()->hardware_hash)->toBe(DeviceEnrolment::hardwareHash('early-handset-9'));

    // Never overwritten by a later claim.
    m46Check($this, $old, 'somebody-elses-handset')->assertOk();
    expect(m46Bindings($this->guard)->sole()->hardware_hash)->toBe(DeviceEnrolment::hardwareHash('early-handset-9'));

    m46Enrol($this, $code, m46Install('rcv-early-back', 'early-handset-9'))->assertCreated()->assertJsonPath('recovered', true);
});

it('carries the handset through a supervisor\'s rebind, so the new phone can recover with the code that moved it', function () {
    $enrolment = app(DeviceEnrolment::class);
    m46Enrol($this, $enrolment->issueCode($this->guard), m46Install('rcv-before', 'old-handset-11'))->assertCreated();

    $code = $enrolment->issueCode($this->guard->fresh());
    $pending = m46Enrol($this, $code, m46Install('rcv-moved', 'new-handset-22'))->assertStatus(202)->assertJsonPath('recovered', false);

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $this->actingAs($director)->post('/guards/'.$this->guard->id.'/device-rebinds/'.$pending->json('rebind_request_id').'/approve')->assertRedirect();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/devices/enrol/'.$pending->json('rebind_request_id').'/collect', ['claim_secret' => $pending->json('claim_secret'), 'device_uid' => 'rcv-moved'])->assertOk()->assertJsonPath('status', 'approved');
    FacilitiesFixture::boot();

    $open = m46Bindings($this->guard)->whereNull('ended_at')->sole();
    expect($open->hardware_hash)->toBe(DeviceEnrolment::hardwareHash('new-handset-22'));

    m46Enrol($this, $code, m46Install('rcv-moved-again', 'old-handset-11'))->assertUnprocessable();
    m46Enrol($this, $code, m46Install('rcv-moved-again', 'new-handset-22'))->assertCreated()->assertJsonPath('recovered', true);
});

it('refuses a recovery for a guard who is no longer on active duty', function () {
    $code = app(DeviceEnrolment::class)->issueCode($this->guard);
    m46Enrol($this, $code, m46Install('rcv-suspended', 'suspended-handset'))->assertCreated();
    DB::connection('mysql')->table('guards')->where('id', $this->guard->id)->update(['status' => 'suspended']);

    m46Enrol($this, $code, m46Install('rcv-suspended-2', 'suspended-handset'))->assertForbidden()->assertJsonPath('error.code', 'guard_not_active');
    expect(m46Bindings($this->guard)->whereNull('ended_at')->sole()->device_uid)->toBe('rcv-suspended');
});

it('tells an install its own handset recovered past it what happened, not that the office removed it', function () {
    $code = app(DeviceEnrolment::class)->issueCode($this->guard);
    $left = m46Install('rcv-left-behind', 'two-copies-handset');
    m46Enrol($this, $code, $left)->assertCreated();

    Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
    m46Enrol($this, $code, m46Install('rcv-new-copy', 'two-copies-handset'))->assertCreated()->assertJsonPath('recovered', true);
    Carbon::setTestNow();

    m46Check($this, $left)->assertForbidden()
        ->assertJsonPath('error.code', 'device_removed')
        ->assertJsonPath('error.details.removed_by', 'guard')
        ->assertJsonPath('error.message', 'This phone was set up again for Hassam Recover on 7 October, and this copy of the app was signed out then. Use the app it was set up on.');
});
