<?php

declare(strict_types=1);

use App\Console\Commands\PhoenixDemoSeed;
use App\Enums\Console;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ContactVerificationCode;
use App\Services\ContactVerification;
use App\Support\DemonstrationAccounts;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| No proven alert address, no console — M10 §1.4
|--------------------------------------------------------------------------
|
| "The alert email is useless if nobody has entered an address." Every estate
| console account, and the Gemini roles a panic reaches, supplies an address
| and proves it with a one-time code before the console opens.
|
| WHAT THESE TESTS HOLD THE GATE TO:
|   - it covers EVERY console page, by any URL and any kind of request — a
|     page, a write, a JSON call and an Inertia partial reload are all sent to
|     the verification page, never answered;
|   - the code is six digits, stored only as a hash, good for ten minutes and
|     five tries, and one a minute — each enforced by the SERVER;
|   - a proven address is the only one `alertEmail()` hands out, and changing it
|     clears the proof;
|   - the demonstration accounts are pre-verified by exactly the rule the
|     seeders write, and nothing else is.
|
| THE ESTATE IS `FacilitiesFixture`'s, reached on its own hostname through the
| real middleware stack, because the claim is about the route groups — a test
| that called the middleware directly would pass with the middleware missing
| from a group. The central database is wrapped in a transaction per test.
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
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** An estate-console account on the fixture estate, NOT yet verified — every real account's starting point. */
function gateCommittee(string $roleName = Role::PRESIDENT, bool $verified = false): User
{
    $factory = User::factory();

    $user = ($verified ? $factory : $factory->unverifiedContact())->create([
        'console' => Console::Estate->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$roleName]);

    EstateAssignment::create([
        'user_id' => $user->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => Role::query()->where('name', $roleName)->firstOrFail()->id,
        'is_active' => true,
    ]);

    return $user->fresh();
}

/** A Gemini-console account holding one role, NOT yet verified. */
function gateGemini(string $roleName): User
{
    $user = User::factory()->unverifiedContact()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$roleName]);

    return $user->fresh();
}

/** Ask for a code as this user, on the estate's door, and return the plaintext the mail carried. */
function gateSendCode(object $test, User $user, string $email, string $phone = '876 555 0141'): ?string
{
    // Every console user gives a phone as well as an address (M18C §2).
    $test->actingAs($user)->post(FacilitiesFixture::url('/verify-contact/code'), [
        'email' => $email,
        'phone' => $phone,
    ]);

    $sent = null;

    Notification::assertSentOnDemand(ContactVerificationCode::class, function (ContactVerificationCode $mail, array $channels, AnonymousNotifiable $to) use (&$sent, $email): bool {
        if (($to->routes['mail'] ?? null) === mb_strtolower(trim($email))) {
            $sent = $mail->code;
        }

        return true;
    });

    return $sent;
}

/** Any six digits that are not this code. */
function gateWrongCode(string $code): string
{
    return str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
}

it('sends an unverified committee member to the verification page from every console page, by any URL', function () {
    $president = gateCommittee(Role::PRESIDENT);
    $verify = FacilitiesFixture::url('/verify-contact');

    expect($president->needsContactVerification())->toBeTrue();

    /*
     * A spread across every group of the estate console, including a detail
     * record and a record addressed by id — the gate is on the GROUP, so a page
     * added tomorrow is covered too.
     *
     * NOT a model-bound URL with an id that does not exist (`/documents/1` on an
     * estate with no documents): `SubstituteBindings` runs ahead of every route
     * middleware here, `EnsureEstateAccess` included, so a missing record answers
     * 404 before either gate is asked — as it always has, and with nothing in it.
     */
    foreach (['/', '/activity', '/messages', '/residents', '/finance/arrears', '/security', '/governance/notices',
        '/settings/profile', '/reports', '/records/residents/1', '/estate'] as $path) {
        $response = $this->actingAs($president)->get(FacilitiesFixture::url($path));

        expect($response->status())->toBe(302, "{$path} answered {$response->status()} instead of the gate's redirect")
            ->and($response->headers->get('Location'))->toBe($verify, "{$path} redirected somewhere other than the verification page");
    }

    // A write is refused the same way, and never reaches its controller.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/governance/notices'), ['title' => 'Gate closed', 'body' => 'Tonight.'])
        ->assertRedirect($verify);
});

it('never answers a JSON call or an Inertia partial reload with the page behind the gate', function () {
    $president = gateCommittee(Role::PRESIDENT);
    $verify = FacilitiesFixture::url('/verify-contact');

    // No silent 200 with the data the account has not earned.
    $this->actingAs($president)
        ->getJson(FacilitiesFixture::url('/residents'))
        ->assertRedirect($verify);

    $version = app(HandleInertiaRequests::class)->version(Request::create('/'));

    $this->actingAs($president)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) $version,
            'X-Inertia-Partial-Component' => 'Estate/Residents/Index',
            'X-Inertia-Partial-Data' => 'rows',
        ])
        ->get(FacilitiesFixture::url('/residents'))
        ->assertRedirect($verify);
});

it('still answers 404 for another estate before it asks anybody to verify', function () {
    $president = gateCommittee(Role::PRESIDENT);

    /*
     * A REAL second estate, with a domain, that this President is not assigned
     * to. `EnsureEstateAccess` answers before the contact gate does, so the gate
     * is not a new way to learn that an estate exists.
     */
    $other = 'gateother'.random_int(1000, 9999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $other, 'name' => 'Other Estate', 'status' => 'active', 'data' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::connection('mysql')->table('domains')->insert([
        'domain' => $other, 'tenant_id' => $other, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($president)
        ->get('http://'.$other.'.'.config('app.estate_domain').'/residents')
        ->assertNotFound();
});

it('draws one verification page, prefilled with the sign-in address, honest about text messages', function () {
    $president = gateCommittee(Role::PRESIDENT);

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/verify-contact'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/VerifyContact')
            ->where('contact.email', mb_strtolower($president->email))
            ->where('contact.verified', false)
            ->where('rules.ttl_minutes', ContactVerification::TTL_MINUTES)
            ->where('rules.max_attempts', ContactVerification::MAX_ATTEMPTS)
            ->where('rules.resend_seconds', ContactVerification::RESEND_SECONDS)

            // No provider is connected, so the page must not claim a text will be sent.
            ->where('sms.can_send', false)
            // In sentences, without an em dash (M97 item 2).
            ->where('sms.note', fn (string $note): bool => str_contains($note, 'texts are not sent') && ! str_contains($note, '—'))
            ->where('required', true)
            ->where('code', null));
});

it('mails a six-digit code on the ordinary queue and stores only a hash of it', function () {
    $president = gateCommittee(Role::PRESIDENT);

    $code = gateSendCode($this, $president, 'Patrice.Campbell@Example.com');

    expect($code)->toMatch('/^\d{6}$/');

    Notification::assertSentOnDemand(ContactVerificationCode::class, function (ContactVerificationCode $mail): bool {
        /*
         * QUEUED, ON THE CENTRAL CONNECTION, AND NOT ON THE PANIC QUEUE. §1.3 keeps
         * that queue for mail that must never wait behind anything; a sign-in code
         * is the kind of mail it must not wait behind.
         */
        expect($mail)->toBeInstanceOf(ShouldQueue::class)
            ->and($mail->connection)->toBe('central')
            ->and($mail->queue)->not->toBe(config('push.urgent_queue'));

        return true;
    });

    $rows = DB::connection('mysql')->table('contact_verification_codes')->where('user_id', $president->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->email)->toBe('patrice.campbell@example.com')
        ->and(strlen((string) $rows[0]->code_hash))->toBe(64)

        // The plaintext is nowhere in the row — not as the hash, not in any column.
        ->and(json_encode($rows[0]))->not->toContain($code);

    // And the address went on the account at once, unverified.
    $president->refresh();

    expect($president->contact_email)->toBe('patrice.campbell@example.com')
        ->and($president->hasVerifiedContact())->toBeFalse()
        ->and($president->alertEmail())->toBeNull();
});

it('counts wrong codes, and after five the code is dead and a new one must be sent', function () {
    $president = gateCommittee(Role::PRESIDENT);
    $code = gateSendCode($this, $president, 'president@example.com');
    $wrong = gateWrongCode($code);

    foreach ([4, 3, 2, 1] as $left) {
        $this->actingAs($president)
            ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $wrong])
            ->assertSessionHasErrors(['verification_code' => "That code is not right. {$left} ".($left === 1 ? 'try' : 'tries').' left for this code.']);
    }

    // The fifth wrong try says it was the last.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $wrong])
        ->assertSessionHasErrors('verification_code');

    // The right code, now, is refused: the code is used up, not merely guessed at.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $code])
        ->assertSessionHasErrors(['verification_code' => 'Five wrong codes have been entered, so this code no longer works. Send a new code.']);

    expect($president->fresh()->hasVerifiedContact())->toBeFalse();

    // After the cooldown a new code is sent, and the new one works.
    Carbon::setTestNow(Carbon::now()->addSeconds(ContactVerification::RESEND_SECONDS + 1));
    Notification::fake();

    $fresh = gateSendCode($this, $president->fresh(), 'president@example.com');

    $this->actingAs($president->fresh())
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $fresh])
        ->assertSessionHasNoErrors();

    expect($president->fresh()->hasVerifiedContact())->toBeTrue();
});

it('refuses an expired code', function () {
    $president = gateCommittee(Role::PRESIDENT);
    $code = gateSendCode($this, $president, 'president@example.com');

    Carbon::setTestNow(Carbon::now()->addMinutes(ContactVerification::TTL_MINUTES)->addSecond());

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $code])
        ->assertSessionHasErrors(['verification_code' => 'That code has expired. A code lasts 10 minutes. Send a new one.']);

    expect($president->fresh()->hasVerifiedContact())->toBeFalse();
});

it('refuses a resend inside the cooldown on the server, whatever the page shows', function () {
    $president = gateCommittee(Role::PRESIDENT);
    gateSendCode($this, $president, 'president@example.com');

    // A hand-built POST a second later — no page, no countdown — is still refused.
    Carbon::setTestNow(Carbon::now()->addSeconds(30));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'president@example.com', 'phone' => '876 555 0141'])
        ->assertSessionHasErrors('resend');

    // Changing the address does not reset the clock either.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'somebody.else@example.com', 'phone' => '876 555 0141'])
        ->assertSessionHasErrors('resend');

    Notification::assertSentOnDemandTimes(ContactVerificationCode::class, 1);

    expect(app(ContactVerification::class)->resendIn($president))->toBeGreaterThan(0)
        ->and(DB::connection('mysql')->table('contact_verification_codes')->where('user_id', $president->id)->count())->toBe(1);

    // Once the minute is up, it goes.
    Carbon::setTestNow(Carbon::now()->addSeconds(31));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'president@example.com', 'phone' => '876 555 0141'])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(ContactVerificationCode::class, 2);
});

it('stores the flag and the time for a correct code, audits it, and the console opens', function () {
    $president = gateCommittee(Role::PRESIDENT);
    $code = gateSendCode($this, $president, 'Patrice@Example.com');

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => ' '.$code.' '])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $president->refresh();

    expect($president->hasVerifiedContact())->toBeTrue()
        ->and($president->contact_email_verified_at)->not->toBeNull()
        ->and($president->alertEmail())->toBe('patrice@example.com')
        ->and($president->needsContactVerification())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'user.contact_verified')->where('entity_id', (string) $president->id)->exists())->toBeTrue();

    // A code works once.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/verify-contact'), ['verification_code' => $code])
        ->assertSessionHasErrors('verification_code');

    // And the console is open.
    $this->actingAs($president)->get(FacilitiesFixture::url('/'))->assertOk();
});

it('clears the verification when the contact email changes, on the page and on the model', function () {
    $president = gateCommittee(Role::PRESIDENT, verified: true);

    expect($president->hasVerifiedContact())->toBeTrue();

    // The same address written differently is not a change.
    $president->forceFill(['contact_email' => '  '.strtoupper((string) $president->contact_email).' '])->save();
    expect($president->fresh()->hasVerifiedContact())->toBeTrue();

    // A different one is, wherever it is written from.
    $president->forceFill(['contact_email' => 'another@example.com'])->save();
    $president->refresh();

    expect($president->hasVerifiedContact())->toBeFalse()
        ->and($president->alertEmail())->toBeNull()
        ->and($president->needsContactVerification())->toBeTrue();

    // Through the page: sending a code to a new address takes the old proof away at once.
    $verified = gateCommittee(Role::VICE_PRESIDENT, verified: true);
    gateSendCode($this, $verified, 'new.address@example.com');

    $verified->refresh();

    expect($verified->contact_email)->toBe('new.address@example.com')
        ->and($verified->hasVerifiedContact())->toBeFalse();

    $this->actingAs($verified)
        ->get(FacilitiesFixture::url('/'))
        ->assertRedirect(FacilitiesFixture::url('/verify-contact'));
});

it('requires the phone, stores it in one form, and refuses a number it cannot dial (M18C)', function () {
    $president = gateCommittee(Role::PRESIDENT);

    gateSendCode($this, $president, 'president@example.com', '876-555-0141');
    expect($president->fresh()->contact_phone)->toBe('+1 876 555 0141');

    // No phone at all is refused now: the control room calls it in an emergency, and no code goes out.
    $secretary = gateCommittee(Role::SECRETARY);

    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'secretary@example.com'])
        ->assertSessionHasErrors(['phone' => 'Enter your mobile number: the control room calls it in an emergency. Example: 876 555 0123.']);

    expect(DB::connection('mysql')->table('contact_verification_codes')->where('user_id', $secretary->id)->exists())->toBeFalse();

    // A number that cannot be read is refused with a sentence, and no code goes out.
    $treasurer = gateCommittee(Role::TREASURER);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/verify-contact/code'), ['email' => 'treasurer@example.com', 'phone' => '12'])
        ->assertSessionHasErrors('phone');

    expect(DB::connection('mysql')->table('contact_verification_codes')->where('user_id', $treasurer->id)->exists())->toBeFalse();

    // A verified account may change its phone without losing its verification.
    $verified = gateCommittee(Role::PROPERTY_MANAGER, verified: true);

    $this->actingAs($verified)
        ->post(FacilitiesFixture::url('/verify-contact/phone'), ['phone' => '1-876-508-8082'])
        ->assertSessionHasNoErrors();

    expect($verified->fresh()->contact_phone)->toBe('+1 876 508 8082')
        ->and($verified->fresh()->hasVerifiedContact())->toBeTrue();
});

it('gates every estate role, custom ones included, by the console and not by a role list', function () {
    $estateRoles = Role::query()->where('console', Console::Estate->value)->pluck('name')->all();

    // The eight system roles, the new Head of Security among them.
    expect($estateRoles)->toContain(Role::COMMUNITY_SUPER_ADMIN, Role::ESTATE_HEAD_OF_SECURITY, Role::ESTATE_ADMIN_ASSISTANT);

    foreach ($estateRoles as $roleName) {
        expect(gateCommittee($roleName)->needsContactVerification())->toBeTrue("{$roleName} must meet the contact gate");
    }

    // A role §4 might create tomorrow is gated the day it exists.
    $custom = Role::findOrCreate('estate.gate_test_custom', 'web');
    $custom->forceFill(['console' => Console::Estate->value, 'label' => 'Custom', 'sort' => 99, 'scope_default' => 'all'])->save();

    $member = User::factory()->unverifiedContact()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $member->syncRoles([$custom->name]);

    expect($member->fresh()->needsContactVerification())->toBeTrue();
});

it('gates the Gemini roles a panic reaches, and leaves the others alone', function () {
    expect(Role::GEMINI_ALERT_RECIPIENTS)->toEqualCanonicalizing([
        Role::DIRECTOR, Role::OPERATIONS_MANAGER, Role::HEAD_OF_SECURITY, Role::DISPATCHER,
    ]);

    foreach (Role::GEMINI_ALERT_RECIPIENTS as $roleName) {
        $user = gateGemini($roleName);

        expect($user->needsContactVerification())->toBeTrue("{$roleName} receives alerts and must verify");

        $this->actingAs($user)->get('/dashboard')->assertRedirect(url('/verify-contact'));
        $this->actingAs($user)->get('/dispatch/alerts')->assertRedirect(url('/verify-contact'));
    }

    /*
     * The Admin Assistant watches the queue and may not act on it; the Accountant
     * holds no Dispatch at all. Neither receives an alert, so neither is stopped.
     */
    foreach ([Role::ADMIN_ASSISTANT, Role::ACCOUNTANT] as $roleName) {
        $user = gateGemini($roleName);

        expect($user->needsContactVerification())->toBeFalse("{$roleName} receives no alert and is not gated");

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // A verified Director opens the console.
    $director = gateGemini(Role::DIRECTOR);
    $code = (function () use ($director): string {
        $this->actingAs($director)->post('/verify-contact/code', ['email' => 'andrea.case@example.com', 'phone' => '876 555 0141']);

        $sent = '';
        Notification::assertSentOnDemand(ContactVerificationCode::class, function (ContactVerificationCode $mail) use (&$sent): bool {
            $sent = $mail->code;

            return true;
        });

        return $sent;
    })->call($this);

    $this->actingAs($director)->post('/verify-contact', ['verification_code' => $code])->assertSessionHasNoErrors();
    $this->actingAs($director->fresh())->get('/dashboard')->assertOk();
});

it('carries an estate account off the central verification page to its own estate door', function () {
    $president = gateCommittee(Role::PRESIDENT);

    // The session cookie is host-only: verifying on the central host would be verifying as a stranger.
    $this->actingAs($president)->get('/verify-contact')->assertRedirectContains(FacilitiesFixture::ESTATE);
});

it('leaves logout reachable for an unverified account', function () {
    $president = gateCommittee(Role::PRESIDENT);

    $this->actingAs($president)->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('pre-verifies the accounts the seeders create, and nothing a person could own', function () {
    $estate = FacilitiesFixture::platform();

    // DemoDataSeeder's Gemini staff and committee, on `.test`.
    $seeder = new DemoDataSeeder;
    (new ReflectionMethod($seeder, 'seedGeminiStaff'))->invoke($seeder);
    (new ReflectionMethod($seeder, 'seedEstateCommittee'))->invoke($seeder, $estate);

    foreach (['director@geminisecurity.test', 'security@geminisecurity.test', 'president@facilitiestest.test', 'treasurer@facilitiestest.test', 'manager@facilitiestest.test'] as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->hasVerifiedContact())->toBeTrue("{$email} is a seeded demonstration account")
            ->and($user->alertEmail())->toBe($email);
    }

    // phoenix:demo-seed's committee, on `demo.geminisl.com`.
    $accounts = (new ReflectionMethod(PhoenixDemoSeed::class, 'committee'))->invoke(app(PhoenixDemoSeed::class), $estate);

    expect($accounts)->toHaveCount(8);

    foreach ($accounts as $account) {
        expect(User::query()->where('email', $account['email'])->firstOrFail()->hasVerifiedContact())->toBeTrue();
    }

    // estate:add-committee-account: a demonstration address yes, a person's address no.
    Storage::fake('local');

    $this->artisan('estate:add-committee-account', [
        'estate' => FacilitiesFixture::ESTATE, 'role' => Role::ESTATE_HEAD_OF_SECURITY,
        'name' => 'Clifton Rowe', 'email' => 'clifton-hos@facilitiestest.demo.geminisl.com',
    ])->expectsOutputToContain('pre-verified for alerts')->assertSuccessful();

    $this->artisan('estate:add-committee-account', [
        'estate' => FacilitiesFixture::ESTATE, 'role' => Role::SECRETARY,
        'name' => 'Real Person', 'email' => 'real.person@gmail.com',
    ])->expectsOutputToContain('asked to confirm an alert address')->assertSuccessful();

    expect(User::query()->where('email', 'clifton-hos@facilitiestest.demo.geminisl.com')->firstOrFail()->hasVerifiedContact())->toBeTrue()
        ->and(User::query()->where('email', 'real.person@gmail.com')->firstOrFail()->hasVerifiedContact())->toBeFalse();

    // And the rule refuses to wave a real person through, whoever asks.
    $real = gateCommittee(Role::PRESIDENT);

    expect(DemonstrationAccounts::verify($real))->toBeFalse()
        ->and($real->fresh()->hasVerifiedContact())->toBeFalse();
});

it('pre-verifies exactly the demonstration accounts with accounts:verify-demonstration, and prints the count', function () {
    $make = fn (string $email, Console $console = Console::Estate): User => User::factory()->unverifiedContact()->create([
        'email' => $email, 'console' => $console->value, 'status' => 'active',
    ]);

    $demo = [
        $make('everton-president@gatetest.demo.geminisl.com'),
        // The bare domain, the form production's estate Head of Security was given (M10 §2).
        $make('clifton-head-of-security-gatetest@demo.geminisl.com'),
        $make('president@gatetest.test'),
        $make('director-probe@geminisecurity.test', Console::Gemini),
        $make('estate-president@quicklogin.local'),
    ];

    // Real addresses — including ones that merely look close.
    $real = [
        $make('someone@gmail.com'),
        $make('office@geminisl.com'),
        $make('demo@geminisl.com'),
        $make('person@contest.com'),
        $make('person@test.example.org'),
    ];

    // Whatever else this database already holds is counted too; the command's number must match it.
    $pending = DemonstrationAccounts::query()->get()
        ->filter(fn (User $u): bool => DemonstrationAccounts::isDemonstrationAddress($u->email))
        ->reject(fn (User $u): bool => $u->hasVerifiedContact() && $u->contact_email === mb_strtolower($u->email))
        ->count();

    expect($pending)->toBeGreaterThanOrEqual(count($demo));

    $this->artisan('accounts:verify-demonstration', ['--dry-run' => true])
        ->expectsOutputToContain("Dry run: {$pending} demonstration accounts would be pre-verified. Nothing was changed.")
        ->assertSuccessful();

    foreach ($demo as $user) {
        expect($user->fresh()->hasVerifiedContact())->toBeFalse('a dry run changes nothing');
    }

    $this->artisan('accounts:verify-demonstration')
        ->expectsOutputToContain("{$pending} demonstration accounts pre-verified.")
        ->assertSuccessful();

    foreach ($demo as $user) {
        expect($user->fresh()->alertEmail())->toBe($user->email);
    }

    foreach ($real as $user) {
        expect($user->fresh()->hasVerifiedContact())->toBeFalse("{$user->email} belongs to a person and meets the gate");
    }

    // Idempotent: nothing left to do the second time.
    $this->artisan('accounts:verify-demonstration')
        ->expectsOutputToContain('0 demonstration accounts pre-verified.')
        ->assertSuccessful();
});
