<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\ResidentAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A Resident App account's profile photo (the client, 26 Sep: "user can change his profile dp in the mobile app")
|--------------------------------------------------------------------------
|
| The Profile screen said "there is no photo to change (the server keeps none)". Now a photo is set, read back with
| the account's own token only, replaced, removed — and deleted with the account.
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
});

/** @return array{0: ResidentAccount, 1: string} */
function photoAccount(): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'channel' => 'email',
        'destination' => 'photo.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Pat Photo',
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:photo', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/** @param  array<string, mixed>  $body */
function photoCall(TestCase $test, string $token, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = ['Accept' => 'application/json'] + ($entry['write'] ? ['Idempotency-Key' => 'photo-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : []);

    app('auth')->forgetGuards();
    $client = $test->withToken($token)->withHeaders($headers);
    $uri = '/api/v1/'.$entry['uri'];
    $response = match ($entry['method']) {
        'GET' => $client->get($uri),
        'POST' => $client->post($uri, $body),
        default => $client->delete($uri, $body),
    };
    $response->assertStatus($status ?? $entry['status']);

    if (str_contains((string) $response->headers->get('Content-Type'), 'json')) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

it('sets a photo, serves it only to the account\'s own token, and says where it is on the profile', function () {
    [$account, $token] = photoAccount();

    photoCall($this, $token, 'me.photo', [], 404);

    $set = photoCall($this, $token, 'me.photo.store', ['file' => UploadedFile::fake()->image('me.jpg', 200, 200)]);
    expect($set->json('photo.url'))->toStartWith('/api/v1/me/photo?v=');

    $profile = photoCall($this, $token, 'me.show');
    expect($profile->json('account.photo.url'))->toBe($set->json('photo.url'));

    $file = photoCall($this, $token, 'me.photo');
    expect((string) $file->headers->get('Content-Type'))->toStartWith('image/')
        ->and((string) $file->headers->get('Cache-Control'))->toContain('private');

    // No token, no photo.
    app('auth')->forgetGuards();
    $this->withoutToken()->flushHeaders()->getJson('/api/v1/me/photo')->assertUnauthorized();
    FacilitiesFixture::boot();

    // Another account's token reaches only its own (none).
    [, $other] = photoAccount();
    photoCall($this, $other, 'me.photo', [], 404);
});

it('replaces the file when a new photo is set, and removes it with the account\'s own button', function () {
    [$account, $token] = photoAccount();

    photoCall($this, $token, 'me.photo.store', ['file' => UploadedFile::fake()->image('one.jpg', 100, 100)]);
    FacilitiesFixture::platform()->run(function () use ($account): void {
        expect(Storage::disk('local')->exists((string) $account->fresh()->photo_path))->toBeTrue();
    });
    FacilitiesFixture::boot();
    $first = (string) $account->fresh()->photo_path;

    $second = photoCall($this, $token, 'me.photo.store', ['file' => UploadedFile::fake()->image('two.png', 120, 90)]);
    $path = (string) $account->fresh()->photo_path;
    expect($path)->not->toBe($first);

    FacilitiesFixture::platform()->run(function () use ($first, $path): void {
        expect(Storage::disk('local')->exists($first))->toBeFalse('the old photo is deleted, not kept beside the new one')
            ->and(Storage::disk('local')->exists($path))->toBeTrue();
    });
    FacilitiesFixture::boot();

    expect(photoCall($this, $token, 'me.photo.remove')->json('photo'))->toBeNull();
    FacilitiesFixture::platform()->run(function () use ($path): void {
        expect(Storage::disk('local')->exists($path))->toBeFalse();
    });
    FacilitiesFixture::boot();
    expect($account->fresh()->photo_path)->toBeNull();
    expect($second->json('photo.url'))->toBeString();
});

it('refuses what is not a picture, or is too large', function () {
    [, $token] = photoAccount();

    photoCall($this, $token, 'me.photo.store', ['file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], 422);
    photoCall($this, $token, 'me.photo.store', ['file' => UploadedFile::fake()->image('huge.jpg')->size(6000)], 422);
});
