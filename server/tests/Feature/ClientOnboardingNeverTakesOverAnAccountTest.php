<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gemini\ClientOnboarding;
use App\Services\Gemini\PlatformAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Onboarding a client never takes over an account that already exists (B001)
|--------------------------------------------------------------------------
|
| `ClientOnboarding::start()` made the signing contact with
| `User::updateOrCreate(['email' => ...], [name, password, console, status])`.
| When the address already belonged to somebody, that was not a creation: it
| replaced their password with a random one, moved them to the Estate Console,
| set them to `invited` and renamed them. An active Treasurer of another estate
| was locked out of it, and a Director typed in by an Operations Manager lost
| their console for good — `activeDirectorIds()` only counts Gemini accounts, so
| the last-Director guard stopped counting them, and no screen can repair an
| Estate-console account from the Gemini side.
|
| `Invitations::invite()` already refuses an address that holds an account ("a
| person belongs to one console and one estate"). Onboarding now refuses it too,
| in the form and again in the service, and it only ever CREATES the contact.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: every refusal below failed — the
| request was accepted and the account overwritten.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // The provisioning pipeline builds a real MySQL database; nothing here is about it.
    Event::fake([TenantCreated::class]);

    $this->plan = Plan::query()->create([
        'key' => 'b001plan',
        'name' => 'B001 Plan',
        'price_per_unit_minor' => 180_00,
        'currency' => 'JMD',
        'min_units' => 25,
        'is_active' => true,
        'sort' => 97,
    ]);

    $this->payload = fn (string $email): array => [
        'name' => 'Palm Crest Estate',
        'address' => '2 Palm Crest Drive, St. Catherine',
        'phases' => 1,
        'plan_id' => $this->plan->id,
        'units' => 120,
        'guards' => 0,
        'term_months' => 12,
        'contact_name' => 'Somebody Else Entirely',
        'contact_email' => $email,
        'contact_phone' => '+1 876 555 0199',
        'timezone' => 'America/Jamaica',
    ];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/*
 * The sentence the operator is shown, written out here rather than read from the
 * service's constant, so this file still states what it expects against a service
 * that has no such constant — which is how it was run first.
 */
const ONBOARDING_REFUSAL = 'That address already holds an account on this platform, and onboarding never changes an existing account. '
    .'A person belongs to one console and one estate: give the client\'s contact an address no account uses yet.';

/** Every fact about an account that onboarding used to overwrite. */
function accountFacts(User $user): array
{
    $user = $user->fresh();

    return [
        'name' => $user->name,
        'email' => $user->email,
        'password' => $user->password,
        'console' => $user->console,
        'status' => $user->status,
        'roles' => $user->roles->pluck('name')->all(),
        'assignments' => EstateAssignment::query()->where('user_id', $user->id)->orderBy('id')->get(['tenant_id', 'role_id', 'is_active'])->toArray(),
    ];
}

it('refuses an address that already signs in to another estate, and leaves that account exactly as it was', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $treasurer->forceFill(['email' => 'jane.b001@example.test', 'name' => 'Jane Treasurer', 'password' => Hash::make('her-own-password')])->save();

    $before = accountFacts($treasurer);
    $tenants = Tenant::query()->count();

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->post('/clients/new', ($this->payload)('JANE.b001@Example.test'))
        ->assertSessionHasErrors(['contact_email' => ONBOARDING_REFUSAL]);

    expect(accountFacts($treasurer))->toBe($before)
        ->and(Hash::check('her-own-password', (string) $treasurer->fresh()->password))->toBeTrue()
        ->and($treasurer->fresh()->canAccessEstate(FacilitiesFixture::ESTATE))->toBeTrue()
        // Nothing half-made: no client record was written for the refused request.
        ->and(Tenant::query()->count())->toBe($tenants);
});

it('refuses a Director\'s address typed in by anybody who may onboard a client, so no Director can be disabled that way', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $director->forceFill(['email' => 'the.director.b001@gemini.test', 'password' => Hash::make('director-secret')])->save();

    $before = accountFacts($director);

    /*
     * Taking a client on prices it, so it needs Billing & subscriptions as well as
     * Clients (bug hunt B069), and among the seeded roles that is the Director alone.
     * The Operations Manager this test first used is refused before the address is
     * read, which closes the path even harder; the address check is proved by the
     * one role that still reaches it — a second Director.
     */
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER))
        ->post('/clients/new', ($this->payload)('The.Director.B001@gemini.test'))
        ->assertForbidden();

    expect(accountFacts($director))->toBe($before);

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->post('/clients/new', ($this->payload)('The.Director.B001@gemini.test'))
        ->assertSessionHasErrors(['contact_email' => ONBOARDING_REFUSAL]);

    $after = $director->fresh();

    expect(accountFacts($director))->toBe($before)
        ->and($after->console)->toBe(Console::Gemini)
        ->and($after->status)->toBe('active')
        ->and(Hash::check('director-secret', (string) $after->password))->toBeTrue();

    // Still counted by the last-Director guard, which reads Gemini accounts only.
    $directors = (fn (): array => $this->activeDirectorIds())->call(app(PlatformAdmins::class));

    expect($directors)->toContain($director->id);
});

it('refuses in the service too, before any client record is written, whoever calls it', function () {
    $existing = User::factory()->create([
        'email' => 'service.b001@example.test',
        'console' => Console::Estate->value,
        'status' => 'suspended',
        'password' => Hash::make('kept'),
    ]);

    $before = accountFacts($existing);
    $tenants = Tenant::query()->count();

    expect(fn () => app(ClientOnboarding::class)->start(($this->payload)(' SERVICE.b001@example.test '), FacilitiesFixture::geminiViewer(Role::DIRECTOR)))
        ->toThrow(DomainException::class, ONBOARDING_REFUSAL);

    expect(accountFacts($existing))->toBe($before)
        ->and(Tenant::query()->count())->toBe($tenants);
});

it('refuses an address taken between its check and its write, in the same words, before any estate is made', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $tenants = Tenant::query()->count();

    /*
     * Another request takes the address the instant after this one checked it: the
     * row lands right after the service's `exists` query, as a second operator's
     * onboarding or an invitation being accepted would land it in production.
     */
    $taken = false;

    DB::listen(function ($query) use (&$taken): void {
        if ($taken || ! str_contains($query->sql, 'exists') || ! in_array('raced.b001@example.test', $query->bindings, true)) {
            return;
        }

        $taken = true;

        User::factory()->create([
            'name' => 'Got There First',
            'email' => 'raced.b001@example.test',
            'password' => Hash::make('theirs'),
            'console' => Console::Estate->value,
            'status' => 'active',
        ]);
    });

    expect(fn () => app(ClientOnboarding::class)->start(($this->payload)('raced.b001@example.test'), $director))
        ->toThrow(DomainException::class, ONBOARDING_REFUSAL);

    $winner = User::query()->where('email', 'raced.b001@example.test')->sole();

    expect($taken)->toBeTrue()
        ->and($winner->name)->toBe('Got There First')
        ->and($winner->status)->toBe('active')
        ->and(Hash::check('theirs', (string) $winner->password))->toBeTrue()
        ->and(Tenant::query()->count())->toBe($tenants);

    // Refused before the provisioning pipeline was ever asked to build a database.
    Event::assertNotDispatched(TenantCreated::class);
});

it('still creates a new contact as an invited president of the new estate', function () {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->post('/clients/new', ($this->payload)('  New.Contact.B001@Example.test '))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $contact = User::query()->where('email', 'new.contact.b001@example.test')->sole();
    $estate = Tenant::query()->where('name', 'Palm Crest Estate')->sole();

    expect($contact->name)->toBe('Somebody Else Entirely')
        ->and($contact->console)->toBe(Console::Estate)
        ->and($contact->status)->toBe('invited')
        ->and(EstateAssignment::query()->where('user_id', $contact->id)->pluck('tenant_id')->all())->toBe([$estate->getTenantKey()])
        ->and(EstateAssignment::query()->where('user_id', $contact->id)->value('role_id'))->toBe(Role::named(Role::PRESIDENT)->id);
});
