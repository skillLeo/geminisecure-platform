<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A seeded committee account must be able to USE the console, not just open it
|--------------------------------------------------------------------------
|
| On 23 September 2026 the seven Phoenix Park committee accounts were handed to
| the client mid-demonstration. Every one of them signed in perfectly — correct
| email, correct password, "Signed in as Norman Case" — and then met "Not
| available to your role" on every screen, with an empty sidebar and a dashboard
| that said "No role assigned".
|
| The cause was that there are TWO records and only one was written:
|
|   estate_assignments   which estates this person acts for. The escalation
|                        ladder and the client directory read it.
|   the user's own role  what the console's navigation and every route gate
|                        read.
|
| Neither implies the other, and a sign-in succeeds with only the first — which
| is why this was invisible until somebody tried to use the account. So the test
| is not "was a row written": it is "can this person reach their own console".
|
*/

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/**
 * Exactly the roles `PhoenixDemoSeed::COMMITTEE` creates an account for — the
 * eight SEEDED estate roles, the estate's own Head of Security (M10 §2) among
 * them.
 */
const COMMITTEE_ROLES = [
    'estate.community_super_admin',
    'estate.president',
    'estate.vice_president',
    'estate.secretary',
    'estate.treasurer',
    'estate.property_manager',
    'estate.admin_assistant',
    'estate.head_of_security',
];

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

it('gives every committee account BOTH the estate assignment and the role the console reads', function (): void {
    $estate = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE);

    // Build the accounts exactly as the command does, through the command itself.
    $command = new ReflectionMethod(App\Console\Commands\PhoenixDemoSeed::class, 'committee');
    $seeder = app(App\Console\Commands\PhoenixDemoSeed::class);
    $command->setAccessible(true);

    /** @var list<array{role: string, name: string, email: string, password: string}> $accounts */
    $accounts = $command->invoke($seeder, $estate);

    expect($accounts)->toHaveCount(count(COMMITTEE_ROLES));

    foreach ($accounts as $account) {
        $user = User::query()->where('email', $account['email'])->first();

        expect($user)->not->toBeNull("no account was created for {$account['email']}");

        // The half that was there: which estate this person acts for.
        $assignment = DB::connection('mysql')->table('estate_assignments')
            ->where('user_id', $user->id)
            ->where('tenant_id', FacilitiesFixture::ESTATE)
            ->where('is_active', true)
            ->exists();

        expect($assignment)->toBeTrue("{$account['email']} has no active estate assignment");

        /*
         * The half that was missing, and the one that decides whether a person can DO anything.
         * An account with no role signs in and then finds every module refused.
         */
        $roles = $user->roles->pluck('name');

        expect($roles)->not->toBeEmpty("{$account['email']} signed in with NO role — every screen would refuse it")
            ->and($roles->first())->toStartWith('estate.');
    }
});

it('covers every estate role the console defines, so no committee member is left without an account', function (): void {
    $estate = Tenant::query()->findOrFail(FacilitiesFixture::ESTATE);

    $command = new ReflectionMethod(App\Console\Commands\PhoenixDemoSeed::class, 'committee');
    $command->setAccessible(true);
    $command->invoke(app(App\Console\Commands\PhoenixDemoSeed::class), $estate);

    $seeded = User::query()
        ->where('email', 'like', '%@'.FacilitiesFixture::ESTATE.'.demo.geminisl.com')
        ->get()
        ->flatMap(fn (User $u): array => $u->roles->pluck('name')->all())
        ->unique()
        ->sort()
        ->values();

    /*
     * THE SEEDED ROLES, NOT EVERY ROLE WHOSE NAME STARTS `estate.` (M10 §4).
     * The demonstration seed creates an account per role the PLATFORM defines;
     * a role a community invents at runtime — `estate.custom.<estate>.<slug>`,
     * including the one `gate:isolation` STEP 10 makes and deletes — has no demo
     * account by definition, and counting it here would fail this test the
     * first time any estate used the feature.
     */
    $defined = Role::query()->where('name', 'like', 'estate.%')->where('is_system', true)->pluck('name')->sort()->values();

    expect($seeded->all())->toBe($defined->all())
        ->and($defined->all())->toBe(collect(COMMITTEE_ROLES)->sort()->values()->all());
});
