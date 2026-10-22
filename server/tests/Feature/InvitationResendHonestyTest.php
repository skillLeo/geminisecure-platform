<?php

declare(strict_types=1);

use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\InvitationNotSent;
use App\Services\Invitations;
use App\Support\InviteMail;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A committee or staff invitation says whether its email went (DESIGN §11 rows 1 and 2, T-RES-1..7)
|--------------------------------------------------------------------------
|
| The user: "wherever there is the invite option there must be resend invite, so that if the user once does not
| get the mail by any issue, he can still send." Users & roles and Platform admins had Resend, and it lied:
| `resend()` rotated the token and wrote "resent" to the log BEFORE it sent, so a relay that refused killed the
| link the person already held, the log said it had gone, and the screen was a server error page. A first
| invite sent inside the transaction, so a refusal rolled the invitation back as well. Both screens said "sent".
|
| Now an invitation is saved before its email is tried, "sent" means the mail server took it, a failed resend
| leaves the link they hold exactly as it was, a resend waits a minute, and each answer is drawn in the
| invitation's own row.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** The real mail channel, pointed at a port nothing listens on. */
function honestRefusingRelay(): void
{
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    app('mail.manager')->forgetMailers();
}

/**
 * The two consoles' invitation screens, as the same five questions: where to invite, where to resend, whose
 * invitation it is.
 *
 * @return array{invite: string, resend: callable(int): string, list: string, component: string, actor: User, tenant: string|null, role: string}
 */
function honestConsole(object $test, string $console): array
{
    return $console === 'estate'
        ? ['invite' => FacilitiesFixture::url('/settings/users/invitations'), 'resend' => fn (int $id): string => FacilitiesFixture::url('/settings/users/invitations/'.$id.'/resend'),
            'list' => FacilitiesFixture::url('/settings/users'), 'component' => 'Estate/Settings/Users', 'actor' => $test->admin, 'tenant' => FacilitiesFixture::ESTATE, 'role' => Role::SECRETARY]
        : ['invite' => '/settings/admins/invitations', 'resend' => fn (int $id): string => '/settings/admins/invitations/'.$id.'/resend',
            'list' => '/settings/admins', 'component' => 'Gemini/Settings/Admins', 'actor' => $test->director, 'tenant' => null, 'role' => Role::DISPATCHER];
}

function honestInvitation(object $test, string $console, string $email): Invitation
{
    $c = honestConsole($test, $console);

    return app(Invitations::class)->invite($email, Role::named($c['role']), $c['tenant'], $c['actor']);
}

dataset('consoles', ['estate', 'gemini']);

it('keeps the link they hold when a resend is refused, says so in the row, and is never a server error (T-RES-1, T-RES-6)', function (string $console) {
    $c = honestConsole($this, $console);
    $invitation = honestInvitation($this, $console, 'kept.'.$console.'@honesty.test');
    [$token, $expires, $sentAt] = [$invitation->token, $invitation->expires_at->toIso8601String(), $invitation->sent_at?->toIso8601String()];

    Carbon::setTestNow(now()->addSeconds(61));
    honestRefusingRelay();

    $this->actingAs($c['actor'])->post(($c['resend'])($invitation->id))
        ->assertRedirect()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['id'] === $invitation->id
            && $o['state'] === 'not_sent'
            && str_contains($o['message'], 'Nothing was resent to kept.'.$console.'@honesty.test')
            && str_contains($o['message'], 'Nothing answered at that host and port')
            && str_contains($o['message'], 'The link they already have still works until'));

    $fresh = $invitation->fresh();
    expect($fresh->token)->toBe($token)
        ->and($fresh->expires_at->toIso8601String())->toBe($expires)
        ->and($fresh->sent_at?->toIso8601String())->toBe($sentAt)
        ->and($fresh->attempts)->toBe(2)
        ->and($fresh->last_error)->toContain('Nothing answered at that host and port')
        // The old link still opens the invitation.
        ->and(app(Invitations::class)->find($token)?->id)->toBe($invitation->id);

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'user.invitation_resend_failed')->where('entity_id', (string) $invitation->id)->exists())->toBeTrue()
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'user.invitation_resent')->where('entity_id', (string) $invitation->id)->exists())->toBeFalse();

    // The list says it in the row: the last email went, the resend did not.
    $this->actingAs($c['actor'])->get($c['list'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($c['component'])
            ->where('invitations', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $invitation->id
                && $row['delivery_state'] === 'not_sent'
                && str_starts_with($row['delivery'], 'Could not send it again: Nothing answered at that host and port'))));
})->with('consoles');

it('gives a fresh link only when the resend goes: the old one then opens nothing (T-RES-2, T-RES-6)', function (string $console) {
    $c = honestConsole($this, $console);
    $invitation = honestInvitation($this, $console, 'fresh.'.$console.'@honesty.test');
    $old = $invitation->token;

    Carbon::setTestNow(now()->addSeconds(61));

    $this->actingAs($c['actor'])->post(($c['resend'])($invitation->id))
        ->assertRedirect()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent' && str_contains($o['message'], 'Invitation emailed again to fresh.'.$console.'@honesty.test'));

    $fresh = $invitation->fresh();
    expect($fresh->token)->not->toBe($old)
        ->and($fresh->sent_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($fresh->last_error)->toBeNull()
        ->and($fresh->attempts)->toBe(2)
        ->and(app(Invitations::class)->find($old))->toBeNull()
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'user.invitation_resent')->where('entity_id', (string) $invitation->id)->exists())->toBeTrue();

    // The email that went carries the NEW link — the one now stored.
    Notification::assertSentOnDemandTimes(InvitationSent::class, 2);
    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail): bool => str_contains($mail->url(), '/invitations/'.$fresh->token));
})->with('consoles');

it('says mail is not set up on a production server whose mailer goes nowhere, and stamps nothing (T-RES-3)', function () {
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'log']);
    app('mail.manager')->forgetMailers();
    $was = app()->environment();
    app()->instance('env', 'production');

    try {
        honestInvitation($this, 'estate', 'nowhere@honesty.test');
        $this->fail('An invitation that went nowhere must not read as sent.');
    } catch (InvitationNotSent $unsent) {
        expect($unsent->state())->toBe('not_set_up')
            ->and($unsent->getMessage())->toContain('is saved, but the email did not go: Mail is not set up on this platform yet');
    } finally {
        app()->instance('env', $was);
    }

    $row = Invitation::query()->where('email', 'nowhere@honesty.test')->sole();
    expect($row->sent_at)->toBeNull()->and($row->last_error)->toBe(InviteMail::NOT_SET_UP)
        ->and(Invitations::deliveryShape($row))->toMatchArray(['delivery_state' => 'not_set_up', 'delivery' => 'Not sent: '.InviteMail::NOT_SET_UP]);
});

it('refuses a resend within a minute of the last try, saying when, in the row, and sends nothing (T-RES-4)', function (string $console) {
    $c = honestConsole($this, $console);
    $invitation = honestInvitation($this, $console, 'soon.'.$console.'@honesty.test');
    $token = $invitation->token;

    $this->actingAs($c['actor'])->post(($c['resend'])($invitation->id))
        ->assertRedirect()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'refused' && str_contains($o['message'], 'was sent moments ago. You can send it again at'));

    expect($invitation->fresh()->attempts)->toBe(1)->and($invitation->fresh()->token)->toBe($token);
    Notification::assertSentOnDemandTimes(InvitationSent::class, 1);

    // The list offers Resend only from then on, and says the last try went — the tooltip's "It was just sent".
    $this->actingAs($c['actor'])->get($c['list'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitations', fn ($rows) => collect($rows)->contains(
            fn ($row) => $row['id'] === $invitation->id && $row['resend_after'] === $invitation->last_attempt_at->copy()->addSeconds(60)->utc()->toIso8601String()
                && $row['last_try'] === 'sent'
        )));
})->with('consoles');

it('never tells the office a person holds a link when no email has ever gone to them (the first send refused, then the resend)', function (string $console) {
    $c = honestConsole($this, $console);
    honestRefusingRelay();

    $this->actingAs($c['actor'])->post($c['invite'], ['email' => 'never.'.$console.'@honesty.test', 'role' => $c['role']])
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent'
            && str_contains($o['message'], 'is saved, but the email did not go')
            // Words that fit every screen that shows them: the client page has no list.
            && str_contains($o['message'], 'Press Resend once mail is working.')
            && ! str_contains($o['message'], 'from the list'));
    $row = Invitation::query()->where('email', 'never.'.$console.'@honesty.test')->sole();

    // A minute on, the relay still down: nothing went, before or now — so there is no link to speak of.
    Carbon::setTestNow(now()->addSeconds(61));

    $this->actingAs($c['actor'])->post(($c['resend'])($row->id))
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent'
            && str_contains($o['message'], 'Nothing was resent to never.'.$console.'@honesty.test: Nothing answered at that host and port')
            && str_contains($o['message'], 'No email has gone to them yet, so they have no link to use. Press Resend once mail is working.')
            && ! str_contains($o['message'], 'still works')
            && ! str_contains($o['message'], 'already have'));

    expect($row->fresh()->sent_at)->toBeNull()->and($row->fresh()->attempts)->toBe(2);
})->with('consoles');

it('says a refused try was TRIED, not sent, while Resend waits out its minute — in the refusal and in the row (T-RES-4)', function (string $console) {
    $c = honestConsole($this, $console);
    honestRefusingRelay();

    $this->actingAs($c['actor'])->post($c['invite'], ['email' => 'tried.'.$console.'@honesty.test', 'role' => $c['role']])
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent');
    $row = Invitation::query()->where('email', 'tried.'.$console.'@honesty.test')->sole();

    // Straight away: refused on the timer, in words that match "Could not send: …" in the row above it.
    $this->actingAs($c['actor'])->post(($c['resend'])($row->id))
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'refused'
            && str_contains($o['message'], 'The invitation to tried.'.$console.'@honesty.test was tried moments ago and did not go. You can try again at')
            && ! str_contains($o['message'], 'sent moments ago'));

    $this->actingAs($c['actor'])->get($c['list'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitations', fn ($rows) => collect($rows)->contains(
            fn ($r) => $r['id'] === $row->id && $r['last_try'] === 'not_sent' && $r['resend_after'] !== null && str_starts_with($r['delivery'], 'Could not send:')
        )));

    // After a resend that fails, the same: the last try is the one that did not go, whatever went before it.
    Notification::fake();
    Carbon::setTestNow(now()->addSeconds(61));
    app(Invitations::class)->resend($row->fresh(), $c['actor']);
    expect(Invitations::lastTry($row->fresh()))->toBe('sent');

    honestRefusingRelay();
    Carbon::setTestNow(now()->addSeconds(61));

    try {
        app(Invitations::class)->resend($row->fresh(), $c['actor']);
        $this->fail('A refused resend must not read as sent.');
    } catch (InvitationNotSent) {
        // Recorded; asserted below.
    }

    expect(Invitations::lastTry($row->fresh()))->toBe('not_sent')
        ->and(fn () => app(Invitations::class)->resend($row->fresh(), $c['actor']))->toThrow(DomainException::class, 'was tried moments ago and did not go');
})->with('consoles');

it('claims the first try as the invitation is written, so a Resend pressed while that email is still going waits and sends nothing', function () {
    $pressedMeanwhile = null;
    $asWritten = null;

    Invitation::created(function (Invitation $written) use (&$pressedMeanwhile, &$asWritten): void {
        $asWritten = [$written->attempts, $written->last_attempt_at, Invitations::deliveryShape($written)['delivery_state']];

        // Another tab's Resend, after the row is listed and before its first email has been answered.
        try {
            app(Invitations::class)->resend($written, $this->admin);
        } catch (DomainException $refused) {
            $pressedMeanwhile = $refused->getMessage();
        }
    });

    try {
        honestInvitation($this, 'estate', 'inflight@honesty.test');
    } finally {
        Invitation::flushEventListeners();
    }

    expect($asWritten[0])->toBe(1)
        ->and($asWritten[1])->not->toBeNull()
        // Tried, and not yet answered — never "sent before delivery was recorded".
        ->and($asWritten[2])->toBe('unknown')
        ->and($pressedMeanwhile)->toContain('The invitation to inflight@honesty.test was tried moments ago. You can try again at')
        ->and($pressedMeanwhile)->not->toContain('did not go');

    // One email, from the first send; the row went on to record it.
    Notification::assertSentOnDemandTimes(InvitationSent::class, 1);
    $row = Invitation::query()->where('email', 'inflight@honesty.test')->sole();
    expect($row->attempts)->toBe(1)->and($row->sent_at?->toIso8601String())->toBe($row->last_attempt_at?->toIso8601String());
});

it('saves an invitation whose first email is refused, lists it unsent with Resend, and says so (T-RES-5)', function (string $console) {
    $c = honestConsole($this, $console);
    honestRefusingRelay();

    $this->actingAs($c['actor'])->post($c['invite'], ['email' => 'first.'.$console.'@honesty.test', 'role' => $c['role']])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'not_sent'
            && str_contains($o['message'], 'The invitation to first.'.$console.'@honesty.test is saved, but the email did not go'));

    $row = Invitation::query()->where('email', 'first.'.$console.'@honesty.test')->sole();
    expect($row->sent_at)->toBeNull()->and($row->attempts)->toBe(1)->and($row->last_error)->toContain('Nothing answered')
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'user.invited')->where('entity_id', (string) $row->id)->exists())->toBeTrue()
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'user.invitation_send_failed')->where('entity_id', (string) $row->id)->exists())->toBeTrue();

    $this->actingAs($c['actor'])->get($c['list'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitations', fn ($rows) => collect($rows)->contains(
            fn ($r) => $r['id'] === $row->id && $r['delivery_state'] === 'not_sent' && str_starts_with($r['delivery'], 'Could not send: Nothing answered')
        )));

    // And a success says "emailed", with the time, in the row's own outcome.
    Notification::fake();
    $this->actingAs($c['actor'])->post($c['invite'], ['email' => 'second.'.$console.'@honesty.test', 'role' => $c['role']])
        ->assertSessionHas('invitation_outcome', fn (array $o): bool => $o['state'] === 'sent' && str_contains($o['message'], 'Invitation emailed to second.'.$console.'@honesty.test'));
    expect(Invitation::query()->where('email', 'second.'.$console.'@honesty.test')->value('sent_at'))->not->toBeNull();
})->with('consoles');

it('reads an invitation written before delivery was recorded as exactly that, and a lapsed one\'s failed resend as lapsed', function () {
    $legacy = new Invitation;
    $legacy->forceFill([
        'email' => 'legacy@honesty.test', 'token' => str_repeat('a', 64), 'invited_by' => $this->admin->id,
        'role_id' => Role::named(Role::SECRETARY)->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'expires_at' => now()->subDay(),
    ])->save();
    $legacy->forceFill(['created_at' => now()->subDays(15)])->save();

    expect(Invitations::deliveryShape($legacy->fresh()))->toMatchArray(['delivery_state' => 'unrecorded', 'sent_at' => null, 'resend_after' => null])
        // A full stop where the em dash was (M53 law 3; the Settings pass, 12 Oct).
        ->and(Invitations::deliveryShape($legacy->fresh())['delivery'])->toEndWith('before delivery was recorded. Resend it if it never arrived.');

    honestRefusingRelay();

    try {
        app(Invitations::class)->resend($legacy->fresh(), $this->admin);
        $this->fail('A refused resend must not read as sent.');
    } catch (InvitationNotSent $unsent) {
        expect($unsent->getMessage())->toContain('The link they were sent lapsed on')->not->toContain('still works');
    }

    // One still standing: whether it ever reached them was not recorded, and the sentence says exactly that.
    $standing = new Invitation;
    $standing->forceFill([
        'email' => 'legacy.standing@honesty.test', 'token' => str_repeat('d', 64), 'invited_by' => $this->admin->id,
        'role_id' => Role::named(Role::SECRETARY)->id, 'tenant_id' => FacilitiesFixture::ESTATE, 'expires_at' => now()->addDays(5),
    ])->save();

    try {
        app(Invitations::class)->resend($standing->fresh(), $this->admin);
        $this->fail('A refused resend must not read as sent.');
    } catch (InvitationNotSent $unsent) {
        expect($unsent->getMessage())->toContain('Whether the last email reached them was not recorded; if it did, its link still works until')
            ->not->toContain('The link they already have')
            ->not->toContain('No email has gone to them yet');
    }

    expect($standing->fresh()->token)->toBe(str_repeat('d', 64));
});

it('throttles the resend routes to six a minute, whatever the service would answer (DESIGN §11)', function () {
    $invitation = honestInvitation($this, 'estate', 'throttle@honesty.test');

    foreach (range(1, 6) as $press) {
        $this->actingAs($this->admin)->post(FacilitiesFixture::url('/settings/users/invitations/'.$invitation->id.'/resend'))->assertRedirect();
    }

    $this->actingAs($this->admin)->post(FacilitiesFixture::url('/settings/users/invitations/'.$invitation->id.'/resend'))->assertStatus(429);
});

it('gives the relay eight seconds, not for ever (DESIGN INV-5)', function () {
    expect(config('mail.mailers.smtp.timeout'))->toBe(8.0);
});

it('says each answer where its console says what just happened, and Resend waits out the timer and cannot be pressed twice (the screens)', function () {
    foreach (['Estate/Settings/Users.vue', 'Gemini/Settings/Admins.vue'] as $screen) {
        $source = (string) file_get_contents(resource_path('js/Pages/'.$screen));

        expect($source)
            // The server's own words about the email, in the row.
            ->toContain('{{ invitation.delivery }}')
            // The answer to the last press, from the server.
            ->toContain('flash?.invitation_outcome')
            // The server's minute, counting down; one press at a time.
            ->toContain('invitation.resend_after')
            ->toContain('resendingId.value !== null')
            ->toContain('Resend in ${mmss(wait)}')
            // "Just sent" only when the last try went; a refused one was "tried" (the server's `last_try`).
            ->toContain("invitation.last_try === 'sent'")
            ->toContain('It was tried moments ago and did not go.')
            // Not destructive: no confirmation, and never a browser dialog.
            ->not->toContain('window.confirm');
    }

    // The Gemini console says it as what just happened, a toast top right with a cross, and a resend the server refused
    // stays inside its question, which stays open (Hassam, 12 October: the console's messages in three kinds).
    $admins = (string) file_get_contents(resource_path('js/Pages/Gemini/Settings/Admins.vue'));
    expect(str_contains($admins, "toast(said.message, { tone: said.state === 'sent' ? 'success' : 'error' })"))->toBeTrue()
        ->and(str_contains($admins, ':error="resendRefusal"'))->toBeTrue()
        ->and(str_contains($admins, 'invitation-outcome-'))->toBeFalse();

    // The estate's console says it as what just happened, a toast top right with a cross, as the Gemini console does: an
    // email that went is a success, one that did not go says "Not done" and stays; a resend the server refused stays
    // inside its question, which stays open (Hassam, 12 October, ruling 4). Nothing is drawn under the row or scrolled to.
    $users = (string) file_get_contents(resource_path('js/Pages/Estate/Settings/Users.vue'));
    expect(str_contains($users, "toast(said.message, { tone: said.state === 'sent' ? 'success' : 'error' })"))->toBeTrue()
        ->and(str_contains($users, 'sayOutcome(outcome.value)'))->toBeTrue()
        ->and(str_contains($users, ':error="resendRefusal"'))->toBeTrue()
        ->and(str_contains($users, 'invitation-outcome-'))->toBeFalse()
        ->and(str_contains($users, 'scrollIntoView'))->toBeFalse()
        // Only a press the server took closes the question; a refusal leaves it open, with its sentence in the band.
        ->and(preg_match('/onSuccess: \(visit\) => \{\s*resendAsk\.value = null/', $users))->toBe(1)
        ->and(preg_match('/onFinish: \(\) => \{\s*resendingId\.value = null\s*\}/', $users))->toBe(1)
        // The withdraw question's band says its own refusal, never a resend's.
        ->and(str_contains($users, ':error="withdrawForm.errors.invitation ?? null"'))->toBeTrue();

    // Who invited them — an invitation is MADE on a date; whether its email went is the delivery line.
    expect((string) file_get_contents(resource_path('js/Pages/Estate/Settings/Users.vue')))
        ->toContain('<th>Invited by</th>')->toContain('invited {{ invitation.invited_on }}')->not->toContain('Sent by');
    expect((string) file_get_contents(resource_path('js/Pages/Gemini/Settings/Admins.vue')))
        ->toContain('invited by {{ invitation.invited_by }}')->not->toContain('sent by {{');

    $client = (string) file_get_contents(resource_path('js/Pages/Gemini/Clients/Show.vue'));
    expect($client)->toContain('{{ contactInvitation.delivery }}')
        ->toContain('/contact-invitation/resend')
        ->toContain('invitationForm.processing')
        ->toContain('contactInvitation?.resend_after')
        ->toContain("props.contactInvitation?.last_try === 'sent'")
        ->toContain('It was tried moments ago and did not go.');
});
