<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Gemini\PlatformAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Platform admins — board 42's tab (12 §2, item 43)
|--------------------------------------------------------------------------
|
| Gemini accounts are issued by invitation. A person moves between the
| console's roles here; what a role may do is the matrix's and does not move.
| Nobody changes their own account, and the last Director cannot be demoted.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('invites to a console role, moves people between roles and sites, and never locks the platform out', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $opsManager = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);
    $dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);

    // Platform settings is the Director's alone.
    $this->actingAs($opsManager)->get('/settings/admins')->assertForbidden();

    $this->actingAs($director)
        ->get('/settings/admins')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Settings/Admins')
            ->where('canInvite', true)
            ->where('tabs.3.href', '/settings/admins'));

    // An invitation carries a Gemini role — never an estate one.
    $this->actingAs($director)
        ->post('/settings/admins/invitations', ['email' => 'newhire@gemini.test', 'role' => Role::TREASURER])
        ->assertSessionHasErrors('admins');

    $this->actingAs($director)
        ->post('/settings/admins/invitations', ['email' => 'newhire@gemini.test', 'role' => Role::DISPATCHER])
        ->assertSessionHasNoErrors();

    $invitation = Invitation::query()->where('email', 'newhire@gemini.test')->sole();

    expect($invitation->tenant_id)->toBeNull();
    Notification::assertSentOnDemand(InvitationSent::class);

    // Nobody changes their own account.
    $this->actingAs($director)
        ->post('/settings/admins/'.$director->id, ['role' => Role::DISPATCHER, 'status' => 'active'])
        ->assertSessionHasErrors('admins');

    // Moving somebody to a site-scoped role takes the sites with it.
    $this->actingAs($director)
        ->post('/settings/admins/'.$dispatcher->id, [
            'role' => Role::HEAD_OF_SECURITY,
            'status' => 'active',
            'sites' => [FacilitiesFixture::ESTATE, 'not-a-client'],
        ])
        ->assertSessionHasNoErrors();

    $dispatcher->refresh();

    expect($dispatcher->hasRole(Role::HEAD_OF_SECURITY))->toBeTrue()
        ->and(EstateAssignment::query()->where('user_id', $dispatcher->id)->where('is_active', true)->pluck('tenant_id')->all())
        ->toBe([FacilitiesFixture::ESTATE]);

    // And a role that covers every client clears them.
    $this->actingAs($director)
        ->post('/settings/admins/'.$dispatcher->id, ['role' => Role::DISPATCHER, 'status' => 'active', 'sites' => [FacilitiesFixture::ESTATE]])
        ->assertSessionHasNoErrors();

    expect(EstateAssignment::query()->where('user_id', $dispatcher->id)->where('is_active', true)->count())->toBe(0);

    /*
     * THE LAST ACTIVE DIRECTOR. Over HTTP this cannot be reached — only a
     * Director holds Platform settings, and changing yourself is refused first
     * — so the invariant is asserted on the service, where any future caller
     * would meet it. Every other Director is suspended for the test (inside the
     * central transaction, rolled back after).
     */
    User::query()
        ->where('id', '!=', $director->id)
        ->whereHas('roles', fn ($q) => $q->where('name', Role::DIRECTOR))
        ->update(['status' => 'suspended']);

    expect(fn () => app(PlatformAdmins::class)->manage($director->fresh(), Role::OPERATIONS_MANAGER, 'active', [], $opsManager))
        ->toThrow(DomainException::class, 'only active Director');

    expect(fn () => app(PlatformAdmins::class)->manage($director->fresh(), Role::DIRECTOR, 'suspended', [], $opsManager))
        ->toThrow(DomainException::class, 'only active Director');

    expect($director->fresh()->hasRole(Role::DIRECTOR))->toBeTrue();
});
