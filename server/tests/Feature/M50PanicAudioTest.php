<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\DuressAlertAudio;
use App\Models\Estate\Household;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M50 §4.2 #1 — "Share live audio" reaches the control room
|--------------------------------------------------------------------------
|
| The switch was drawn on and a tap said "Live audio isn't available yet". Now the resident's phone sends what it hears
| a few seconds at a time while the switch is on, the control room's alert page says whether audio is coming in, and
| "Listen" plays the pieces in order — from the private disk only. A piece sent twice is one; a closed alert takes none.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
    Storage::fake('panic_audio');

    $this->site = FacilitiesFixture::ESTATE;
    $unit = FacilitiesFixture::scratchUnit('Lot 737');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Audio household', 'access_restricted' => false]);
    $resident = Tests\Support\HouseholdHolder::of($household, 'Ade Audio');

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'ade.audio.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Ade Audio', 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $this->account->createToken('resident-app:audio-install', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function audioPanic(TestCase $test): DuressAlert
{
    app('auth')->forgetGuards();
    $answer = $test->withToken($test->token)->postJson('/api/v1/duress', ['mode' => 'audible'], ['Idempotency-Key' => 'audio-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()])->assertCreated();
    FacilitiesFixture::boot();

    return DuressAlert::query()->findOrFail($answer->json('id'));
}

function audioPiece(TestCase $test, DuressAlert $alert, int $seq, ?UploadedFile $file = null): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->post('/api/v1/duress/'.$alert->id.'/audio', [
        'file' => $file ?? UploadedFile::fake()->create('piece.m4a', 24, 'audio/mp4'),
        'seq' => $seq,
        'duration_ms' => 5000,
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'audio-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String()]);
    FacilitiesFixture::boot();

    return $response;
}

/** @return array<string, mixed> */
function audioScreen(TestCase $test, User $user, DuressAlert $alert): array
{
    app('auth')->forgetGuards();
    $response = $test->actingAs($user)->get('/dispatch/alerts/'.$alert->id)->assertOk();
    FacilitiesFixture::boot();

    return $response->viewData('page')['props'];
}

it('keeps each piece on the private disk, once however often the phone sends it, and says the audio has started', function () {
    $alert = audioPanic($this);

    $first = audioPiece($this, $alert, 0)->assertCreated();
    ApiContract::assertMatches($first, 'duress.audio.store');
    expect($first->json())->toMatchArray(['seq' => 0, 'received' => true]);

    audioPiece($this, $alert, 0)->assertCreated();
    audioPiece($this, $alert, 1)->assertCreated();

    $pieces = DuressAlertAudio::query()->where('duress_alert_id', $alert->id)->orderBy('seq')->get();
    expect($pieces)->toHaveCount(2);
    // On the central disk, whatever estate's request wrote it — the console reads it from outside any estate.
    Storage::disk('panic_audio')->assertExists($pieces[0]->path);
    expect($pieces[0]->path)->toStartWith($this->site.'/'.$alert->id.'/');
    expect(AuditEntry::query()->where('action', 'panic.live_audio_started')->where('entity_id', (string) $alert->id)->count())->toBe(1);
});

it('tells the control room it is live, lists the pieces after the last one played, and plays each from the private disk', function () {
    $alert = audioPanic($this);
    audioPiece($this, $alert, 0)->assertCreated();
    audioPiece($this, $alert, 1)->assertCreated();

    $audio = audioScreen($this, $this->dispatcher, $alert)['residentAudio'];
    expect($audio)->toMatchArray(['pieces' => 2, 'seconds' => 10, 'live' => true, 'list_url' => '/dispatch/alerts/'.$alert->id.'/audio']);

    app('auth')->forgetGuards();
    $list = $this->actingAs($this->dispatcher)->getJson('/dispatch/alerts/'.$alert->id.'/audio?after=0')->assertOk();
    expect(collect($list->json('pieces'))->pluck('seq')->all())->toBe([1]);

    app('auth')->forgetGuards();
    $piece = $this->actingAs($this->dispatcher)->get('/dispatch/alerts/'.$alert->id.'/audio/1')->assertOk();
    expect($piece->headers->get('Content-Type'))->toContain('audio/mp4');

    app('auth')->forgetGuards();
    $this->actingAs($this->dispatcher)->get('/dispatch/alerts/'.$alert->id.'/audio/9')->assertNotFound();
});

it('takes nothing for a closed alert, nothing that is not audio, and nothing on somebody else\'s alert', function () {
    $alert = audioPanic($this);

    audioPiece($this, $alert, 0, UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertStatus(422);
    audioPiece($this, $alert, 0, UploadedFile::fake()->create('huge.m4a', 4096, 'audio/mp4'))->assertStatus(422);

    $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    $closed = audioPiece($this, $alert, 0)->assertStatus(409);
    expect($closed->json('error.code'))->toBe('alert_closed');

    $this->token = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'nobody.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Nobody', 'resident_id' => $this->account->resident_id, 'unit_id' => $this->account->unit_id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ])->createToken('resident-app:nobody', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    audioPiece($this, $alert, 1)->assertNotFound();

    expect(DuressAlertAudio::query()->where('duress_alert_id', $alert->id)->count())->toBe(0);
});

it('draws no resident audio on an officer\'s duress', function () {
    $duress = DuressAlert::query()->create([
        'tenant_id' => $this->site, 'kind' => 'duress', 'status' => 'open', 'server_time' => now(), 'device_time' => now(),
        'idempotency_key' => 'audio-duress-'.bin2hex(random_bytes(4)),
    ]);

    expect(audioScreen($this, $this->dispatcher, $duress)['residentAudio'])->toBeNull();
});
