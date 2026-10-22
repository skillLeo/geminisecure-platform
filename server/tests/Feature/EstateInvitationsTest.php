<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Invitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Inviting a committee member — boards 22 and 24 (12 §2, Wave 1)
|--------------------------------------------------------------------------
|
| "Names inviter, tenant, role, 14-day expiry. Without it no committee can be
| onboarded." An invitation is the only way an account comes into existence,
| so what is under test is the whole road: who may send one, what it carries,
| that the link is never shown to the sender, that accepting it creates an
| active account bound to this estate and this role and nothing wider, and
| that a lapsed or used link creates nothing.
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

it('lets the Community Super Admin invite, and refuses the officers who read the screen', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/settings/users'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Settings/Users')
            ->where('canInvite', false)
            // The eight estate roles: M10 §2 added the estate's own Head of Security.
            ->has('roles', 8)
            ->where('invitations', []));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'new@phoenixpark.org', 'role' => Role::SECRETARY])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'New@PhoenixPark.org', 'role' => Role::SECRETARY])
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    $invitation = Invitation::query()->where('email', 'new@phoenixpark.org')->firstOrFail();

    // Inviter, tenant, role, fourteen days — and lowercased, so the address
    // matches the one they will sign in with.
    expect($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($invitation->role->name)->toBe(Role::SECRETARY)
        ->and($invitation->expires_at->diffInDays(now()->addDays(14), true))->toBeLessThan(1)
        ->and(strlen($invitation->token))->toBe(64);

    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail, array $channels, $notifiable): bool => $notifiable->routes['mail'] === 'new@phoenixpark.org'
        && str_contains($mail->url(), '/invitations/'.$invitation->token));

    // The screen lists it, with who sent it and when it lapses — and never
    // the token, which is the credential until it is accepted.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/users'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.email', 'new@phoenixpark.org')
            ->where('invitations.0.role_label', 'Secretary')
            ->where('invitations.0.invited_by', $admin->name)
            ->where('invitations.0.expired', false)
            ->missing('invitations.0.token'));

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'user.invited')->where('tenant_id', FacilitiesFixture::ESTATE)->exists())->toBeTrue();
});

it('refuses an address that already holds an account, and a second open invitation to the same one', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $existing = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => $existing->email, 'role' => Role::SECRETARY])
        ->assertSessionHasErrors('email');

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'twice@phoenixpark.org', 'role' => Role::SECRETARY])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'twice@phoenixpark.org', 'role' => Role::PRESIDENT])
        ->assertSessionHasErrors('email');

    expect(Invitation::query()->where('email', 'twice@phoenixpark.org')->count())->toBe(1);

    // And a Gemini role cannot be invited onto an estate at all.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'staff@geminisecurity.test', 'role' => Role::DIRECTOR])
        ->assertSessionHasErrors('role');
});

it('creates an active account bound to this estate and this role when the link is accepted, and signs them in', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $invitation = app(Invitations::class)->invite('accept@phoenixpark.org', Role::named(Role::SECRETARY), FacilitiesFixture::ESTATE, $admin);

    $this->get(FacilitiesFixture::url('/invitations/'.$invitation->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/AcceptInvitation')
            ->where('door.key', 'estate')
            ->where('invitation.email', 'accept@phoenixpark.org')
            ->where('invitation.role', 'Secretary')
            ->where('invitation.inviter', $admin->name)
            ->where('invitation.where', 'Phoenix Park')
            ->where('refusal', null));

    $this->post(FacilitiesFixture::url('/invitations/'.$invitation->token), [
        'name' => 'Delroy Samuels',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect();

    $user = User::query()->where('email', 'accept@phoenixpark.org')->firstOrFail();

    expect($user->status)->toBe('active')
        ->and($user->console->value)->toBe('estate')
        ->and($user->hasRole(Role::SECRETARY))->toBeTrue()
        ->and($user->can('estate.governance.update'))->toBeTrue()
        ->and($user->can('estate.dues_ledger.view'))->toBeFalse()
        ->and(EstateAssignment::query()->where('user_id', $user->id)->where('tenant_id', FacilitiesFixture::ESTATE)->where('is_active', true)->exists())->toBeTrue()
        ->and($user->canAccessEstate(FacilitiesFixture::ESTATE))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);

    // Used once: the same link now opens the refusal, and no second account.
    auth()->logout();
    $this->flushSession();

    $this->get(FacilitiesFixture::url('/invitations/'.$invitation->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($r) => str_contains((string) $r, 'already been accepted')));

    expect(User::query()->where('email', 'accept@phoenixpark.org')->count())->toBe(1);
});

it('lapses after fourteen days, and a resend issues a fresh link that the old one no longer opens', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $invitation = app(Invitations::class)->invite('late@phoenixpark.org', Role::named(Role::SECRETARY), FacilitiesFixture::ESTATE, $admin);
    $oldToken = $invitation->token;

    $this->travel(15)->days();

    $this->get(FacilitiesFixture::url('/invitations/'.$oldToken))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($r) => str_contains((string) $r, 'expired')));

    $this->post(FacilitiesFixture::url('/invitations/'.$oldToken), [
        'name' => 'Too Late',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasErrors('name');

    expect(User::query()->where('email', 'late@phoenixpark.org')->exists())->toBeFalse();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations/'.$invitation->id.'/resend'))
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    $resent = $invitation->fresh();

    expect($resent->token)->not->toBe($oldToken)
        ->and($resent->isPending())->toBeTrue()
        ->and($resent->sent_at)->not->toBeNull();

    // AND IT WAS EMAILED, carrying the new link (DESIGN §11 row 1, T-RES-7): a resend that only rotated the
    // token would have left the person holding a dead link and nothing new.
    Notification::assertSentOnDemandTimes(InvitationSent::class, 2);
    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail, array $channels, $notifiable): bool => $notifiable->routes['mail'] === 'late@phoenixpark.org'
        && str_contains($mail->url(), '/invitations/'.$resent->token));

    // As a guest again: the acceptance door is a guest's, and a signed-in
    // admin is bounced away from it.
    auth()->logout();
    $this->flushSession();

    // The old link is dead; the new one opens.
    $this->get(FacilitiesFixture::url('/invitations/'.$oldToken))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));

    $this->get(FacilitiesFixture::url('/invitations/'.$resent->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', null));

    // Withdrawn: gone from the screen, and the link opens nothing.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations/'.$resent->id.'/revoke'))
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    expect(Invitation::query()->whereKey($resent->id)->exists())->toBeFalse();

    auth()->logout();
    $this->flushSession();

    $this->get(FacilitiesFixture::url('/invitations/'.$resent->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
});

it('links board 24\'s Invite user to board 22 for the one role that may invite', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $vp = FacilitiesFixture::viewer(Role::VICE_PRESIDENT);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canInvite', true)
            ->where('inviteHref', fn ($href) => str_ends_with((string) $href, '/settings/users?invite=1')));

    $this->actingAs($vp)
        ->get(FacilitiesFixture::url('/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canInvite', false)->has('inviteReason'));
});

it('changes a role and withdraws access, and refuses to touch the seniormost officer', function () {
    // 12 §2. Two acts on one panel: a role change changes what somebody may do,
    // a suspension stops them doing anything. Both are recorded, and nothing
    // deletes a person from an estate's history.
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    $this->withoutVite()->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/users'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManage', true)
            ->has('ownerReason')
            ->has('rows'));

    $target = EstateAssignment::query()
        ->where('user_id', $assistant->getKey())
        ->where('tenant_id', FacilitiesFixture::platform()->getTenantKey())
        ->firstOrFail();

    // A reader of the list manages nobody.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/settings/users/'.$target->id), ['role' => 'estate.secretary', 'status' => 'active'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/'.$target->id), [
            'role' => 'estate.secretary',
            'status' => 'suspended',
        ])
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    $target = EstateAssignment::query()->with(['user', 'role'])->findOrFail($target->id);

    /*
     * BOTH RECORDS MOVE. The platform role and the estate assignment are two
     * records of one fact; changing only one would give somebody a title here
     * and the old permissions everywhere.
     */
    expect($target->role->name)->toBe('estate.secretary')
        ->and($target->user->status)->toBe('suspended')
        ->and($target->user->fresh()->hasRole('estate.secretary'))->toBeTrue()
        ->and($target->is_active)->toBeTrue();

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'estate.user_managed')
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_name)->toBe($admin->name)
        ->and(json_decode((string) $entry->after, true)['status'])->toBe('suspended');

    // A Gemini role is not one an estate may assign to itself.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/'.$target->id), ['role' => 'gemini.director', 'status' => 'active'])
        ->assertSessionHasErrors('role');

    /*
     * THE SENIORMOST OFFICER IS NOT MANAGEABLE FROM HERE, and it is a rule
     * rather than a permission: demoting themselves or being suspended would
     * leave the estate with nobody who could undo it.
     */
    $owner = EstateAssignment::query()
        ->with('role')
        ->where('tenant_id', FacilitiesFixture::platform()->getTenantKey())
        ->where('is_active', true)
        ->whereHas('role', fn ($q) => $q->where('console', 'estate'))
        ->get()
        ->sortBy(fn (EstateAssignment $a): int => $a->role->sort)
        ->first();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/'.$owner->id), ['role' => 'estate.secretary', 'status' => 'suspended'])
        ->assertSessionHasErrors('role');

    expect(EstateAssignment::query()->with('role')->findOrFail($owner->id)->role->name)->toBe($owner->role->name);
});
