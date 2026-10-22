<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Guard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /api/v1 is not open
|--------------------------------------------------------------------------
|
| POST /api/v1/alerts was briefly unauthenticated while device enrolment was
| unwritten. That made a LIFE-SAFETY path into a denial-of-service surface:
| anyone who could reach the host could flood the dispatch queue, and a real
| panic would have arrived in a queue full of noise.
|
| These tests assert the door is shut, that a token is not a general key — a
| resident's handset must not be able to ask the system to adjudicate arrivals at
| the gate — and (13 D1) that a console account's token is not a handset's.
|
*/

function tokenGuard(): Guard
{
    DB::table('tenants')->updateOrInsert(['id' => 'tokentest'], ['name' => 'Token Test Estate', 'created_at' => now(), 'updated_at' => now()]);

    return Guard::create([
        'full_name' => 'Token Test Guard',
        'employee_number' => 'GS-TOKEN-1',
        'psra_number' => 'PSRA-TOKEN-1',
        'psra_expires_on' => now()->addYear(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => 'tokentest',
    ]);
}

it('refuses an alert with no token', function () {
    $this->postJson('/api/v1/alerts', ['kind' => 'duress'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a scan verdict with no token', function () {
    $this->postJson('/api/v1/passes/verify', ['household_id' => 1, 'pass_category' => 'guest'])->assertUnauthorized();
});

it('refuses a token that lacks the ability', function () {
    // A token with SOME ability is not a token with EVERY ability.
    Sanctum::actingAs(tokenGuard(), ['something:else']);

    $this->postJson('/api/v1/alerts', ['kind' => 'duress'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'missing_ability');
});

it('refuses a resident token at the gate', function () {
    // The Resident App's abilities, from the matrix — no `gate` among them. If
    // they could call the verify endpoint they could probe which neighbours are restricted.
    expect(AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->not->toContain(AppMatrix::ability('gate', AppMatrix::WRITE));

    Sanctum::actingAs(tokenGuard(), AppMatrix::abilitiesFor(AppMatrix::RESIDENT));

    $this->postJson('/api/v1/passes/verify', ['household_id' => 1, 'pass_category' => 'guest'])->assertForbidden();
});

it('refuses a console account\'s token even when it carries every ability', function () {
    // A token minted on a `users` row is a credential that could reach a console.
    // The API answers only a guard or a resident account.
    Sanctum::actingAs(User::factory()->create(), AppMatrix::abilitiesFor(AppMatrix::GUARD));

    $this->postJson('/api/v1/alerts', ['kind' => 'duress', 'idempotency_key' => 'k1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'wrong_app');
});

it('lets a guard token reach both endpoints', function () {
    Sanctum::actingAs(tokenGuard(), AppMatrix::abilitiesFor(AppMatrix::GUARD));

    foreach ([
        ['/api/v1/alerts', ['kind' => 'duress', 'idempotency_key' => 'reach-1']],
        ['/api/v1/passes/verify', ['household_id' => 1, 'pass_category' => 'guest']],
    ] as [$url, $payload]) {
        $status = $this->postJson($url, $payload)->getStatusCode();

        expect($status)->not->toBe(401, "{$url} rejected a guard token as unauthenticated")
            ->and($status)->not->toBe(403, "{$url} rejected a guard token as forbidden");
    }
});
