<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccountEmailChanged;
use App\Notifications\AccountEmailCode;
use App\Services\Account\SignInEmailChange;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| THE SIGN-IN ADDRESS, CHANGED BY ITS OWNER — M34 §1
|--------------------------------------------------------------------------
|
| Production, 6 October: the only Director signed in as support@geminisl.com, which takes no mail, and nothing could
| change it — My account said "Ask a Director", and the Director had no screen for it either. Now My account changes
| it in two steps, the way a password changes: the CURRENT PASSWORD and the new address, then the CODE emailed to the
| NEW address, so the account never depends on an address nobody has shown receives mail. The change is in the audit
| log with both addresses, the old address is told, and every session stays. A demonstration account keeps its address.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    // The routes' own per-address limits are the doors' to prove; this file proves what the two steps do.
    $this->withoutMiddleware(ThrottleRequests::class);
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** The code the last step one sent to `$to`, read off the faked mail. */
function m34CodeSentTo(string $to): string
{
    $code = '';

    Notification::assertSentOnDemand(AccountEmailCode::class, function (AccountEmailCode $mail, array $channels, object $notifiable) use (&$code, $to): bool {
        if ($notifiable->routes['mail'] !== $to) {
            return false;
        }

        $code = $mail->code;

        return true;
    });

    return $code;
}

function m34Wrong(string $code): string
{
    return $code === '000000' ? '000001' : '000000';
}

describe('the page', function () {
    it('offers the change on both consoles, and says what it needs', function () {
        $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($director)->get('/account')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Account/Show')
            ->where('details.email_note', 'The address you sign in with. Changing it needs your password and a code we email to the new address.')
            ->where('signInEmail.locked', null)
            ->where('signInEmail.pending', null)
            ->where('signInEmail.minutes', SignInEmailChange::TTL_MINUTES));

        app('auth')->forgetGuards();
        $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

        $this->actingAs($treasurer)->get(FacilitiesFixture::url('/account'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('signInEmail.locked', null));
    });

    it('draws the two steps, and the address waiting for its code', function () {
        $page = (string) file_get_contents(resource_path('js/Pages/Account/Show.vue'));

        expect($page)->toContain("path('/sign-in-email/code')")
            ->toContain("path('/sign-in-email')")
            ->toContain("path('/sign-in-email/cancel')")
            ->toContain('Change my sign-in email')
            ->toContain('Email a code to the new address')
            ->toContain('{{ signInEmail.pending }}');
    });
});

describe('step one: the new address and the current password', function () {
    it('refuses a wrong password, audited, and sends nothing', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->from('/account')->post('/account/sign-in-email/code', ['new_email' => 'director.new@example.com', 'current_password' => 'not-it'])
            ->assertSessionHasErrors(['current_password' => 'That is not your current password.']);

        Notification::assertNothingSent();
        expect(session(SignInEmailChange::PENDING))->toBeNull()
            ->and(AuditEntry::query()->where('action', 'account.sign_in_email_change_refused')->where('entity_id', (string) $user->id)->value('after'))
            ->toBe(['why' => 'current_password']);
    });

    it('refuses the same address, an invented one, another account\'s, and one that is not an address', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
        $other = User::factory()->create();
        $try = fn (string $to) => $this->actingAs($user)->from('/account')->post('/account/sign-in-email/code', ['new_email' => $to, 'current_password' => 'password']);

        $try(strtoupper($user->email))->assertSessionHasErrors(['new_email' => 'That is the address you sign in with now. Enter the new one.']);
        $try('lorna@demo.geminisl.com')->assertSessionHasErrors(['new_email' => 'That is a demonstration address, and nobody can read its mail. Use an address you can open.']);
        $try(' '.strtoupper($other->email).' ')->assertSessionHasErrors(['new_email' => 'That address already belongs to another GeminiSecure account, so it cannot be yours as well. Use another, or ask that account\'s owner.']);
        $try('not an address')->assertSessionHasErrors(['new_email' => 'That is not an email address. Check it and try again.']);
        $try('')->assertSessionHasErrors(['new_email' => 'Enter the new address you want to sign in with.']);

        Notification::assertNothingSent();
        expect($user->fresh()->email)->toBe($user->email);
    });

    it('emails the code to the NEW address and nowhere else, one a minute', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->from('/account')->post('/account/sign-in-email/code', ['new_email' => ' Director.New@Example.com ', 'current_password' => 'password'])
            ->assertRedirect('/account')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'A code is on its way to director.new@example.com. It works for 10 minutes: enter it below to finish.');

        Notification::assertSentOnDemandTimes(AccountEmailCode::class, 1);
        expect(m34CodeSentTo('director.new@example.com'))->toMatch('/^\d{6}$/')
            ->and(session(SignInEmailChange::PENDING))->toBe('director.new@example.com')
            ->and($user->fresh()->email)->toBe($user->email);

        $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page->where('signInEmail.pending', 'director.new@example.com'));

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'director.other@example.com', 'current_password' => 'password'])
            ->assertSessionHasErrors(['new_email' => 'A code was sent less than a minute ago. Check that inbox, or ask again in a minute.']);
        Notification::assertSentOnDemandTimes(AccountEmailCode::class, 1);

        expect(AuditEntry::query()->where('action', 'account.sign_in_email_code_sent')->where('entity_id', (string) $user->id)->value('after'))
            ->toBe(['to' => 'director.new@example.com']);
    });
});

describe('step two: the code', function () {
    it('changes the sign-in address with the right code, audits both, tells the old address, and keeps every session', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
        $old = $user->email;
        $generation = (int) $user->sign_in_generation;

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'director.new@example.com', 'current_password' => 'password']);
        $code = m34CodeSentTo('director.new@example.com');

        $this->actingAs($user)->from('/account')->post('/account/sign-in-email', ['code' => m34Wrong($code)])
            ->assertSessionHasErrors(['code' => 'That code is not right. Check the email sent to director.new@example.com and enter the six digits it gives.']);
        expect($user->fresh()->email)->toBe($old);

        $this->actingAs($user)->from('/account')->post('/account/sign-in-email', ['code' => $code])
            ->assertRedirect('/account')->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'You now sign in with director.new@example.com. '.$old.' no longer signs you in, and we have emailed it to say so.');

        $user->refresh();
        expect($user->email)->toBe('director.new@example.com')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and((int) $user->sign_in_generation)->toBe($generation)
            ->and(session(SignInEmailChange::PENDING))->toBeNull();

        $entry = AuditEntry::query()->where('action', 'account.sign_in_email_changed')->where('entity_id', (string) $user->id)->firstOrFail();
        expect($entry->before)->toBe(['email' => $old])->and($entry->after)->toBe(['email' => 'director.new@example.com']);

        Notification::assertSentOnDemand(AccountEmailChanged::class, fn (AccountEmailChanged $mail, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $old
            && $mail->newAddress === 'di•••@example.com');

        // The same code does not work twice, and the page still works on this browser.
        $this->actingAs($user)->from('/account')->post('/account/sign-in-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'Start again: enter the new address and your password, and we will email that address a code.']);
        $this->actingAs($user)->get('/account')->assertOk();
    });

    it('signs in with the new address afterwards, and no longer with the old one', function () {
        config(['auth.gemini_two_factor' => false]);
        $user = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
        $old = $user->email;

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'dispatch.new@example.com', 'current_password' => 'password']);
        $this->actingAs($user)->post('/account/sign-in-email', ['code' => m34CodeSentTo('dispatch.new@example.com')])->assertSessionHasNoErrors();

        auth()->logout();
        app('auth')->forgetGuards();

        $this->post('/login', ['email' => $old, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => 'dispatch.new@example.com', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    });

    it('binds the code to the address it was sent to: asking again for another address retires it', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'first.choice@example.com', 'current_password' => 'password']);
        $first = m34CodeSentTo('first.choice@example.com');

        // A minute later, a different address.
        Carbon::setTestNow(now()->addSeconds(SignInEmailChange::RESEND_SECONDS + 1));
        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'second.choice@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $second = m34CodeSentTo('second.choice@example.com');

        if ($first !== $second) {
            $this->actingAs($user)->post('/account/sign-in-email', ['code' => $first])->assertSessionHasErrors('code');
            expect($user->fresh()->email)->not->toBe('first.choice@example.com');
        }

        $this->actingAs($user)->post('/account/sign-in-email', ['code' => $second])->assertSessionHasNoErrors();
        expect($user->fresh()->email)->toBe('second.choice@example.com');

        Carbon::setTestNow();
    });

    it('refuses a code after ten minutes, and retires one tried wrongly five times', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'late@example.com', 'current_password' => 'password']);
        $code = m34CodeSentTo('late@example.com');

        Carbon::setTestNow(now()->addMinutes(SignInEmailChange::TTL_MINUTES)->addSecond());
        $this->actingAs($user)->post('/account/sign-in-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'That code has expired, or none was sent. Enter the new address and your password again for a new one.']);
        Carbon::setTestNow();

        Carbon::setTestNow(now()->addMinutes(SignInEmailChange::TTL_MINUTES + 2));
        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'tried@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $code = m34CodeSentTo('tried@example.com');

        for ($i = 0; $i < SignInEmailChange::MAX_ATTEMPTS; $i++) {
            $this->actingAs($user)->post('/account/sign-in-email', ['code' => m34Wrong($code)]);
        }

        $this->actingAs($user)->post('/account/sign-in-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'That code has expired, or none was sent. Enter the new address and your password again for a new one.']);
        expect($user->fresh()->email)->not->toBe('tried@example.com');
        Carbon::setTestNow();
    });

    it('refuses an address another account took while the code was in the post', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'contested@example.com', 'current_password' => 'password']);
        $code = m34CodeSentTo('contested@example.com');
        User::factory()->create(['email' => 'contested@example.com']);

        $this->actingAs($user)->post('/account/sign-in-email', ['code' => $code])
            ->assertSessionHasErrors(['new_email' => 'That address already belongs to another GeminiSecure account, so it cannot be yours as well. Use another, or ask that account\'s owner.']);
        expect($user->fresh()->email)->not->toBe('contested@example.com');
    });

    it('forgets the waiting address on Cancel, so its code cannot finish', function () {
        $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'changed.mind@example.com', 'current_password' => 'password']);
        $code = m34CodeSentTo('changed.mind@example.com');

        $this->actingAs($user)->post('/account/sign-in-email/cancel')->assertRedirect();
        expect(session(SignInEmailChange::PENDING))->toBeNull();

        $this->actingAs($user)->post('/account/sign-in-email', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'Start again: enter the new address and your password, and we will email that address a code.']);
        expect($user->fresh()->email)->not->toBe('changed.mind@example.com');
    });

    it('works the same on an estate\'s door', function () {
        $user = FacilitiesFixture::viewer(Role::TREASURER);

        $this->actingAs($user)->from(FacilitiesFixture::url('/account'))->post(FacilitiesFixture::url('/account/sign-in-email/code'), ['new_email' => 'treasurer.new@example.com', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->from(FacilitiesFixture::url('/account'))->post(FacilitiesFixture::url('/account/sign-in-email'), ['code' => m34CodeSentTo('treasurer.new@example.com')])
            ->assertSessionHasNoErrors();

        expect($user->fresh()->email)->toBe('treasurer.new@example.com');
    });
});

it('leaves a demonstration account\'s address alone: the page says why, and the server refuses both steps by hand', function () {
    $user = FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER);
    $invented = 'm34-'.strtolower((string) Str::ulid()).'@demo.geminisl.com';
    $user->forceFill(['email' => $invented])->save();
    $locked = 'This is a demonstration account. The demonstration signs it in by this address, so it is not changed here.';

    $this->actingAs($user)->get('/account')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('signInEmail.locked', $locked)
        ->where('details.email_note', $locked));

    $this->actingAs($user)->post('/account/sign-in-email/code', ['new_email' => 'mine.now@example.com', 'current_password' => 'password'])
        ->assertSessionHasErrors(['new_email' => $locked]);
    $this->withSession([SignInEmailChange::PENDING => 'mine.now@example.com'])->actingAs($user)->post('/account/sign-in-email', ['code' => '123456'])
        ->assertSessionHasErrors(['new_email' => $locked]);

    Notification::assertNothingSent();
    expect($user->fresh()->email)->toBe($invented);
});

it('puts both steps in the audit log, with who, the address and the browser', function () {
    $user = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $as = fn () => $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.34'])->withHeader('User-Agent', 'M34 audit browser')->from('/account');

    $as()->post('/account/sign-in-email/code', ['new_email' => 'audited@example.com', 'current_password' => 'password']);
    $as()->post('/account/sign-in-email', ['code' => m34CodeSentTo('audited@example.com')]);

    foreach (['account.sign_in_email_code_sent', 'account.sign_in_email_changed'] as $action) {
        $entry = AuditEntry::query()->where('action', $action)->where('entity_id', (string) $user->id)->firstOrFail();

        expect((int) $entry->actor_id)->toBe((int) $user->id)
            ->and($entry->ip)->toBe('203.0.113.34')
            ->and($entry->user_agent)->toBe('M34 audit browser');
    }
});
