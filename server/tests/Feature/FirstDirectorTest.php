<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Role;
use App\Models\User;
use App\Services\Invitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The first Director on an empty platform (M7)
|--------------------------------------------------------------------------
|
| Every account is an accepted invitation, and an invitation needs somebody to send
| it — so a new production server has no way in. `platform:first-director` makes that
| one account, the way `accept()` makes any other, and refuses the moment Gemini staff
| exist.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('refuses once any Gemini staff exist, so it can never mint a second Director', function () {
    FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    expect(fn () => app(Invitations::class)->bootstrapFirstDirector('another@example.com', 'Another', str_repeat('x', 20)))
        ->toThrow(DomainException::class, 'already has Gemini staff');
});

it('makes exactly one active, verified Director on a platform with no staff', function () {
    // An empty platform, inside this test's transaction only.
    User::query()->where('console', Console::Gemini->value)->update(['console' => Console::Estate->value]);

    expect(fn () => app(Invitations::class)->bootstrapFirstDirector('first@example.com', 'First', 'short'))
        ->toThrow(DomainException::class, 'at least 16');

    $user = app(Invitations::class)->bootstrapFirstDirector(' First.Director@Example.com ', 'First Director', 'a-long-generated-password');

    expect($user->email)->toBe('first.director@example.com')
        ->and($user->console)->toBe(Console::Gemini)
        ->and($user->status)->toBe('active')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->getRoleNames()->all())->toBe([Role::DIRECTOR])
        ->and(Hash::check('a-long-generated-password', $user->password))->toBeTrue();

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'user.bootstrapped')->where('entity_id', (string) $user->id)->exists())->toBeTrue();

    // And the platform now has staff, so a second run refuses.
    expect(fn () => app(Invitations::class)->bootstrapFirstDirector('second@example.com', 'Second', str_repeat('y', 20)))
        ->toThrow(DomainException::class, 'already has Gemini staff');
});
