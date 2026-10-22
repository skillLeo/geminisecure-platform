<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\ClientOnboarding;
use App\Services\Invitations;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Stancl\Tenancy\Events\TenantCreated;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Onboarding a client invites its contact (DESIGN §11 row 5, T-ONB-1..4)
|--------------------------------------------------------------------------
|
| `ClientOnboarding::start` makes the client's contact an Estate Console account in `invited` standing, with a
| random password nobody knows, and sent them nothing. Sign-in refuses an account that is not active, and
| accepting an invitation refused an address that already had an account — so the person who signed for the
| estate could never get into it, while the onboarding checklist said "Estate admin invited".
|
| Now the onboarding sends them an invitation, the client page says whether it went and offers Send or
| Resend, the checklist is done only once one was emailed, and accepting it activates that one account.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    // `Tenant::create()` fires TenantCreated, whose pipeline builds a real database: faked, as ClientOnboardingReachabilityTest does.
    Event::fake([TenantCreated::class]);
    Notification::fake();

    $this->plan = Plan::query()->create([
        'key' => 'onboardinvite', 'name' => 'Onboarding Invitation', 'price_per_unit_minor' => 180_00, 'currency' => 'JMD',
        'min_units' => 25, 'is_active' => true, 'sort' => 97,
    ]);

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->payload = [
        'name' => 'Invitation Heights', 'address' => '3 Invitation Road, St. Ann', 'phases' => 1, 'plan_id' => $this->plan->id,
        'units' => 60, 'guards' => 0, 'term_months' => 12, 'contact_name' => 'Hope Contact',
        'contact_email' => 'hope.contact@invitationheights.test', 'contact_phone' => '+1 876 555 0177', 'timezone' => 'America/Jamaica',
    ];
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** @return array<string, mixed> the onboarding panel's checklist step "Estate admin invited" */
function onboardInviteStep(string $tenantId): array
{
    return collect(app(ClientDirectory::class)->detail($tenantId)['onboarding']['checklist'])->firstWhere('label', 'Estate admin invited');
}

it('emails the contact an invitation once the client is recorded, and the page says it went (T-ONB-1)', function () {
    $this->actingAs($this->director)->post('/clients/new', $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent' && str_contains($o['message'], 'Invitation emailed to hope.contact@invitationheights.test'));

    $contact = User::query()->where('email', 'hope.contact@invitationheights.test')->sole();
    $estate = (string) EstateAssignment::query()->where('user_id', $contact->id)->value('tenant_id');
    $invitation = Invitation::query()->where('email', $contact->email)->sole();

    expect($contact->status)->toBe('invited')
        ->and($invitation->tenant_id)->toBe($estate)
        ->and($invitation->role->name)->toBe(Role::PRESIDENT)
        ->and($invitation->invited_by)->toBe($this->director->id)
        ->and($invitation->sent_at)->not->toBeNull()
        ->and($invitation->attempts)->toBe(1)
        ->and($invitation->expires_at->diffInDays(now()->addDays(14), true))->toBeLessThan(1);

    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail, array $channels, object $to): bool => ($to->routes['mail'] ?? null) === 'hope.contact@invitationheights.test'
        && str_contains($mail->url(), '/invitations/'.$invitation->token));

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'user.invited')->where('entity_id', (string) $invitation->id)->first();
    expect(json_decode((string) $entry->after, true)['via'])->toBe('client_onboarding');

    // The client page draws it, in the contact's own panel.
    $this->actingAs($this->director)->get('/clients/'.$estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Clients/Show')
            ->where('contactInvitation.email', 'hope.contact@invitationheights.test')
            ->where('contactInvitation.delivery_state', 'sent')
            ->where('contactInvitation.action', 'Resend invitation'));
});

it('still records the client when the contact\'s email is refused — the invitation is sent after, not inside, the onboarding (T-ONB-1)', function () {
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    app('mail.manager')->forgetMailers();

    $this->actingAs($this->director)->post('/clients/new', $this->payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent' && str_contains($o['message'], 'is saved, but the email did not go'));

    expect(Tenant::query()->where('name', 'Invitation Heights')->exists())->toBeTrue()
        ->and(Invitation::query()->where('email', 'hope.contact@invitationheights.test')->sole()->sent_at)->toBeNull();
});

it('marks "Estate admin invited" done only once an invitation has been emailed (T-ONB-2)', function () {
    // The service alone, as every client onboarded before this release was: an invited contact and no invitation.
    $estate = (string) app(ClientOnboarding::class)->start($this->payload, $this->director)->getTenantKey();

    expect(onboardInviteStep($estate)['done'])->toBeFalse();

    // Tried and refused: still not done.
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    app('mail.manager')->forgetMailers();

    try {
        app(Invitations::class)->sendToOnboardingContact(Tenant::query()->findOrFail($estate), $this->director);
    } catch (App\Services\InvitationNotSent) {
        // Recorded on the row; asserted below.
    }

    expect(onboardInviteStep($estate)['done'])->toBeFalse();

    // Emailed: done.
    Notification::fake();
    Carbon::setTestNow(now()->addSeconds(61));
    app(Invitations::class)->sendToOnboardingContact(Tenant::query()->findOrFail($estate), $this->director);

    expect(onboardInviteStep($estate)['done'])->toBeTrue();
});

it('sends — or resends — the contact\'s invitation from the client page, on the timer, to Clients update only (T-ONB-3)', function () {
    $estate = (string) app(ClientOnboarding::class)->start($this->payload, $this->director)->getTenantKey();
    $path = '/clients/'.$estate.'/contact-invitation/resend';

    // A client onboarded before invitations were sent: the page offers the FIRST one.
    $this->actingAs($this->director)->get('/clients/'.$estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('contactInvitation.delivery_state', 'none')
            ->where('contactInvitation.action', 'Send invitation')
            ->where('invitationBlockedReason', null));

    $this->actingAs($this->director)->post($path)->assertRedirect('/clients/'.$estate)
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent');
    $first = Invitation::query()->where('email', 'hope.contact@invitationheights.test')->sole();

    // Straight away again: refused, saying when, and nothing more is sent.
    $this->actingAs($this->director)->post($path)
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'refused' && str_contains($o['message'], 'You can send it again at'));
    Notification::assertSentOnDemandTimes(InvitationSent::class, 1);

    // A minute on: the same invitation, a fresh link.
    Carbon::setTestNow(now()->addSeconds(61));
    $this->actingAs($this->director)->post($path)->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent');

    $again = Invitation::query()->where('email', 'hope.contact@invitationheights.test')->sole();
    expect($again->id)->toBe($first->id)->and($again->token)->not->toBe($first->token)->and($again->attempts)->toBe(2);

    // A reader of the client record sends nothing.
    $reader = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
    $this->actingAs($reader)->post($path)->assertForbidden();
});

it('activates the invited contact when they accept — name, password, verified, role — and overwrites no other account (T-ONB-4)', function () {
    $estate = (string) app(ClientOnboarding::class)->start($this->payload, $this->director)->getTenantKey();
    $invitation = app(Invitations::class)->sendToOnboardingContact(Tenant::query()->findOrFail($estate), $this->director);

    // Through the central acceptance door, as the link opens it.
    $this->post('/invitations/'.$invitation->token, [
        'name' => 'Hope A. Contact', 'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $contact = User::query()->where('email', 'hope.contact@invitationheights.test')->sole();
    expect($contact->status)->toBe('active')
        ->and($contact->name)->toBe('Hope A. Contact')
        ->and(Hash::check('a-long-enough-password', (string) $contact->password))->toBeTrue()
        ->and($contact->email_verified_at)->not->toBeNull()
        ->and($contact->console)->toBe(Console::Estate)
        ->and($contact->hasRole(Role::PRESIDENT))->toBeTrue()
        ->and($contact->canAccessEstate($estate))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(EstateAssignment::query()->where('user_id', $contact->id)->count())->toBe(1);
    $this->assertAuthenticatedAs($contact);

    $entry = DB::connection('mysql')->table('audit_log')->where('action', 'user.invitation_accepted')->where('entity_id', (string) $contact->id)->first();
    expect(json_decode((string) $entry->after, true)['via'])->toBe('client_onboarding');

    // Nobody is waiting any more: the page offers nothing, and the checklist is done.
    expect(Invitations::onboardingContactShape($estate))->toBeNull()
        ->and(onboardInviteStep($estate)['done'])->toBeTrue();
    expect(fn () => app(Invitations::class)->sendToOnboardingContact(Tenant::query()->findOrFail($estate), $this->director))
        ->toThrow(DomainException::class, 'has no contact still waiting for an invitation');
});

it('sends the contact one invitation even when two presses find none at once: the second waits on the contact\'s lock and is refused', function () {
    $estate = Tenant::query()->findOrFail((string) app(ClientOnboarding::class)->start($this->payload, $this->director)->getTenantKey());
    $contact = User::query()->where('email', 'hope.contact@invitationheights.test')->sole();

    /*
     * The other press, landing between this one's "is one open?" and its insert: it commits its own invitation
     * right after this request's unlocked check has answered "none".
     */
    $competing = null;
    $locked = false;
    $order = [];
    DB::connection('mysql')->listen(function ($query) use (&$competing, &$locked, &$order, $contact, $estate): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'from `users`') && str_contains($sql, 'for update') && in_array($contact->id, $query->bindings, false)) {
            $locked = true;
            $order[] = 'lock';
        }

        if (str_starts_with($sql, 'insert into `invitations`')) {
            $order[] = 'insert';
        }

        if ($competing === null && str_starts_with($sql, 'select') && str_contains($sql, 'from `invitations`')) {
            $competing = 0;
            $competing = (int) DB::connection('mysql')->table('invitations')->insertGetId([
                'email' => $contact->email, 'token' => str_repeat('e', 64), 'invited_by' => $this->director->id,
                'role_id' => Role::named(Role::PRESIDENT)->id, 'tenant_id' => (string) $estate->getTenantKey(),
                'expires_at' => now()->addDays(14), 'attempts' => 1, 'last_attempt_at' => now(), 'sent_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    expect(fn () => app(Invitations::class)->inviteOnboardingContact($estate, $contact, $this->director))
        ->toThrow(DomainException::class, 'An invitation to hope.contact@invitationheights.test is already open. Resend it rather than sending a second one.');

    // One live link: the other press's. Nothing of this one was written, and nothing was emailed by it.
    expect(Invitation::query()->where('email', $contact->email)->pluck('id')->all())->toBe([$competing])
        ->and($locked)->toBeTrue()
        // The other press's insert, then this one's lock on the contact — and no insert of its own after it.
        ->and($order)->toBe(['insert', 'lock']);
    Notification::assertNothingSent();
});

it('never tells the office the contact holds a link when no email has gone to them (first send refused, then the resend)', function () {
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    app('mail.manager')->forgetMailers();

    $this->actingAs($this->director)->post('/clients/new', $this->payload)
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent'
            && str_contains($o['message'], 'Press Resend once mail is working.')
            && ! str_contains($o['message'], 'from the list'));
    $estate = (string) Tenant::query()->where('name', 'Invitation Heights')->value('id');

    Carbon::setTestNow(now()->addSeconds(61));

    $this->actingAs($this->director)->post('/clients/'.$estate.'/contact-invitation/resend')
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent'
            && str_contains($o['message'], 'No email has gone to them yet, so they have no link to use.')
            && ! str_contains($o['message'], 'still works'));

    // And while Resend waits, the panel says the last try did not go — not "It was just sent".
    $this->actingAs($this->director)->get('/clients/'.$estate)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('contactInvitation.last_try', 'not_sent')->where('contactInvitation.delivery_state', 'not_sent'));
});

it('tells the contact, and the office, that a brand-new estate address may warn for a few minutes — and not to continue past it', function () {
    $this->actingAs($this->director)->post('/clients/new', $this->payload)
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent'
            && str_contains($o['message'], 'Its link opens on ')
            && str_contains($o['message'], 'which browsers trust once its certificate is issued, within five minutes'));

    $invitation = Invitation::query()->where('email', 'hope.contact@invitationheights.test')->sole();
    $lines = static function (Invitation $invitation): string {
        $mail = (new InvitationSent($invitation))->toMail(new stdClass);

        return implode("\n", array_map('strval', [...$mail->introLines, ...$mail->outroLines]));
    };

    expect($lines($invitation))->toContain('Invitation Heights\'s address is brand new')
        ->toContain('If your browser warns that the connection is not private, do not continue: wait a few minutes and open the link again.');

    // An hour on, the address has long had its certificate: the email says nothing about warnings.
    Tenant::query()->whereKey($invitation->tenant_id)->update(['created_at' => now()->subMinutes(InvitationSent::NEW_ADDRESS_MINUTES + 1)]);
    expect(InvitationSent::opensOnNewAddress($invitation))->toBeFalse()
        ->and($lines($invitation))->not->toContain('brand new')->not->toContain('not private');
});

it('never activates or re-invites an ACTIVE account, or an invited one that is not that estate\'s contact (T-ONB-4)', function () {
    $estate = Tenant::query()->findOrFail((string) app(ClientOnboarding::class)->start($this->payload, $this->director)->getTenantKey());

    // An active committee member elsewhere, with an invitation somebody made to their address for this estate.
    $active = FacilitiesFixture::viewer(Role::TREASURER);
    $passwordBefore = (string) $active->password;
    $stray = new Invitation;
    $stray->forceFill([
        'email' => $active->email, 'token' => str_repeat('b', 64), 'invited_by' => $this->director->id,
        'role_id' => Role::named(Role::PRESIDENT)->id, 'tenant_id' => (string) $estate->getTenantKey(), 'expires_at' => now()->addDays(14),
    ])->save();

    expect(fn () => app(Invitations::class)->accept($stray, 'Taken Over', 'a-long-enough-password'))
        ->toThrow(DomainException::class, 'An account already exists for this address');
    expect($active->fresh()->name)->toBe($active->name)
        ->and((string) $active->fresh()->password)->toBe($passwordBefore)
        ->and($stray->fresh()->accepted_at)->toBeNull();

    // And the onboarding path will not invite them either.
    expect(fn () => app(Invitations::class)->inviteOnboardingContact($estate, $active, $this->director))
        ->toThrow(DomainException::class, 'is not Invitation Heights\'s invited contact');

    // An `invited` account assigned to ANOTHER estate is not this one's contact.
    $elsewhere = User::query()->where('email', 'hope.contact@invitationheights.test')->sole();
    EstateAssignment::query()->where('user_id', $elsewhere->id)->update(['tenant_id' => FacilitiesFixture::ESTATE]);
    $foreign = new Invitation;
    $foreign->forceFill([
        'email' => $elsewhere->email, 'token' => str_repeat('c', 64), 'invited_by' => $this->director->id,
        'role_id' => Role::named(Role::PRESIDENT)->id, 'tenant_id' => (string) $estate->getTenantKey(), 'expires_at' => now()->addDays(14),
    ])->save();

    expect(fn () => app(Invitations::class)->accept($foreign, 'Wrong Estate', 'a-long-enough-password'))
        ->toThrow(DomainException::class, 'An account already exists for this address');
    expect($elsewhere->fresh()->status)->toBe('invited');
});
