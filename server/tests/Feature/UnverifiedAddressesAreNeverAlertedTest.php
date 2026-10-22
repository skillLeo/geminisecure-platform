<?php

declare(strict_types=1);

use App\Console\Commands\AccountsVerifyContact;
use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Channels\AlertMailChannel;
use App\Notifications\ContactVerificationCode;
use App\Notifications\InvitationSent;
use App\Notifications\PanicRaisedAtSite;
use App\Notifications\ResetPasswordLink;
use App\Notifications\ShiftAutoClosed;
use App\Services\ContactVerification;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\DeliveryReport;
use App\Services\Dispatch\ShiftAutoClose;
use App\Services\Dispatch\SiteEscalation;
use App\Services\Invitations;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An unverified address is never used for alerting — M10 §1.4
|--------------------------------------------------------------------------
|
| "Store a verified flag with the timestamp. An unverified address is never used
| for alerting." The gate (ContactVerificationGateTest) makes people PROVE an
| address; this file holds every alert mail to USING only the proven one:
|
|   1  a panic mails the Gemini Head of Security for the site, the estate's own
|      Head of Security and the committee at their proven contact address — and
|      mails nobody who has not proven one, whether they never typed an address
|      or typed one and never confirmed it
|   2  it goes to the CONTACT address, never the sign-in address, one person to
|      a mail; the channel reads the address again when the mail is sent
|   3  the counts are the people actually mailed — `escalated_contacts`, the
|      delivery rows — and the unproven get a row of their own that says why,
|      which is what `panic:walk` and the dispatcher's screen print
|   4  a Head of Security's other alerts (a shift closed automatically) follow the
|      same rule
|   5  a password reset, an invitation and the verification code itself still go
|      exactly where they went
|   6  `accounts:verify-contact`, the server operator's break glass
|
| The fixture estate, the testing transaction manager and a bare site, as
| PanicDeliveryTimingTest has them: the "after commit" fan-out runs inside
| `create()` here as it does in production.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();

    // Nobody on duty, nobody rostered, nobody on the committee, no numbers: each test adds its own people.
    Shift::query()->where('tenant_id', $this->site)->delete();
    Guard::query()->where('tenant_id', $this->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    config(['services.sms.driver' => 'log']);

    unprovenOutbox()->flush();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

/**
 * A console account at this site, in one of three states:
 *   `proven`  its contact address is verified (at `$contact`, or at the sign-in address)
 *   `typed`   a contact address was typed and never confirmed
 *   `none`    no contact address at all — every real account before M10 §1.4
 */
function unprovenPerson(string $roleName, string $state, ?string $login = null, ?string $contact = null): User
{
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $gemini = $role->console === Console::Gemini;

    $factory = $state === 'proven' ? User::factory() : User::factory()->unverifiedContact();

    /*
     * A proven address that differs from the sign-in address is written WITH the account,
     * not saved over it afterwards: a second save in the same second would leave the
     * timestamp unchanged, and `User::booted()` rightly reads "address changed, proof not
     * renewed" as a change that clears the proof.
     */
    $user = $factory->create(array_filter([
        'console' => $gemini ? Console::Gemini->value : Console::Estate->value,
        'status' => 'active',
        'email' => $login,
        'contact_email' => $state === 'proven' ? $contact : null,
    ]));

    if ($state === 'typed') {
        $user->forceFill(['contact_email' => $contact ?? 'typed.'.$user->id.'@example.org', 'contact_email_verified_at' => null])->save();
    }

    $user->syncRoles([$roleName]);

    // A Gemini Head of Security reaches a site by assignment; an estate account lives in one.
    EstateAssignment::create([
        'user_id' => $user->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => $role->id,
        'is_active' => true,
    ]);

    return $user->fresh();
}

function unprovenPanic(bool $drill = false): DuressAlert
{
    return app(AlertIntake::class)->record(
        tenantId: FacilitiesFixture::ESTATE,
        kind: 'panic',
        raisedByName: 'Una Proven',
        unitReference: 'Lot 47',
        isDrill: $drill,
    )->fresh();
}

/** @return Collection<int, DuressAlertDelivery> */
function unprovenLegs(DuressAlert $alert, string $channel = DuressAlertDelivery::EMAIL): Collection
{
    return DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)->where('channel', $channel)->orderBy('id')->get();
}

/** The array mailer's transport: what was actually handed to "the mail server" in this test. */
function unprovenOutbox(): object
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport();
}

/**
 * Every mail actually sent, as the list of addresses on each one's envelope.
 *
 * @return list<list<string>>
 */
function unprovenEnvelopes(): array
{
    return collect(unprovenOutbox()->messages())
        ->map(static fn ($sent): array => collect($sent->getEnvelope()->getRecipients())->map(static fn ($address): string => $address->getAddress())->all())
        ->values()
        ->all();
}

function unprovenNotification(): PanicRaisedAtSite
{
    return new PanicRaisedAtSite(alertId: 1, kind: 'panic', estate: 'Phoenix Park', where: 'Lot 47', raisedAt: 'Sep 24, 2026 9:00 PM', handsets: 0);
}

/* ── 1 · proven people are mailed; nobody else is ────────────────────── */

it('mails the proven Heads of Security and committee about a panic, and none of those who have not proven an address', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();

    $geminiProven = unprovenPerson(Role::HEAD_OF_SECURITY, 'proven');
    $geminiNone = unprovenPerson(Role::HEAD_OF_SECURITY, 'none');
    $estateProven = unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'proven');
    $estateTyped = unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'typed');
    $president = unprovenPerson(Role::PRESIDENT, 'proven');
    $treasurer = unprovenPerson(Role::TREASURER, 'none');

    $alert = unprovenPanic();

    foreach ([$geminiProven, $estateProven, $president] as $proven) {
        Notification::assertSentTo($proven, PanicRaisedAtSite::class, static fn (PanicRaisedAtSite $mail, array $channels): bool => $channels === [AlertMailChannel::class]);
    }

    // Never typed an address, or typed one and never confirmed it: either way, not mailed.
    foreach ([$geminiNone, $estateTyped, $treasurer] as $unproven) {
        Notification::assertNotSentTo($unproven, PanicRaisedAtSite::class);
    }

    /*
     * THE COUNT IS THE PEOPLE MAILED. `escalated_contacts` is what the resident's own
     * screen is built on; three were mailed, so it says three — not six.
     */
    expect($alert->escalated_contacts)->toBe(3)
        ->and($alert->alerted_via)->toBe('escalation');

    /*
     * AND THE ROWS SAY BOTH: each destination's mail to the proven person, queued, and a
     * row of its own for the one who could not be mailed — skipped, one person, and why.
     */
    $legs = unprovenLegs($alert);

    foreach ([DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_HEAD_OF_SECURITY, DuressAlertDelivery::ESTATE_CONSOLE] as $destination) {
        $mine = $legs->where('destination', $destination)->values();

        expect($mine)->toHaveCount(2)
            ->and($mine[0]->outcome)->toBe(DuressAlertDelivery::QUEUED)
            ->and($mine[0]->recipients)->toBe(1)
            ->and($mine[1]->outcome)->toBe(DuressAlertDelivery::SKIPPED)
            ->and($mine[1]->recipients)->toBe(1)
            ->and($mine[1]->note)->toStartWith('1 person could not be emailed: address not verified.')
            ->and($mine[1]->isUnverifiedSkip())->toBeTrue()
            ->and($mine[0]->isUnverifiedSkip())->toBeFalse();
    }

    // A count, never a name or an address, in the note column.
    foreach ($legs as $leg) {
        foreach ([$geminiNone, $estateTyped, $treasurer] as $unproven) {
            expect((string) $leg->note)->not->toContain((string) $unproven->email)
                ->and((string) $leg->note)->not->toContain((string) $unproven->name);
        }
    }

    /*
     * THE DISPATCHER'S SCREEN SAYS HOW MANY COULD NOT BE EMAILED — under each destination
     * that was reached anyway, and once for the alert.
     */
    $report = app(DeliveryReport::class)->forAlert($alert);
    $geminiRow = collect($report['destinations'])->firstWhere('key', DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY);

    expect($report['unverified'])->toBe(3)
        ->and($report['unverified_label'])->toBe('3 could not be emailed: address not verified.')
        ->and($geminiRow['reached'])->toBeTrue()
        ->and($geminiRow['unverified'])->toBe(1)
        ->and($geminiRow['reason'])->toBe('1 could not be emailed: address not verified.');

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/dispatch/alerts/'.$alert->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Dispatch/Alert')
            ->where('deliveries.unverified', 3)
            ->where('deliveries.unverified_label', '3 could not be emailed: address not verified.'));
});

it('does not let the unproven use up the places the cap leaves for people who can be mailed', function () {
    /*
     * The alert mail is capped at ten people. Ten unproven Presidents ahead of one proven
     * Secretary would, if the cap came first, fill every place and mail nobody.
     */
    foreach (range(1, SiteEscalation::MAX_CONTACTS) as $ignored) {
        unprovenPerson(Role::PRESIDENT, 'none');
    }

    $secretary = unprovenPerson(Role::SECRETARY, 'proven');

    ['mail' => $mail, 'unverified' => $unverified] = app(SiteEscalation::class)->alertRecipientsFor($this->site);

    expect($mail->pluck('id')->all())->toBe([$secretary->id])
        ->and($unverified)->toHaveCount(SiteEscalation::MAX_CONTACTS);
});

/* ── 2 · the contact address, never the sign-in address ──────────────── */

it('sends the alert to the proven contact address and never the sign-in address, one person to a mail', function () {
    // Queued work runs where it is queued, so the mail really goes through the channel to the array mailer.
    config(['queue.central_connection' => 'sync', 'mail.default' => 'array']);

    unprovenPerson(Role::HEAD_OF_SECURITY, 'proven', login: 'reid.login@example.com', contact: 'reid.alerts@example.org');
    unprovenPerson(Role::PRESIDENT, 'proven', login: 'lee.login@example.com', contact: 'lee.alerts@example.org');
    unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'typed', login: 'kim.login@example.com', contact: 'kim.typed@example.org');
    unprovenPerson(Role::TREASURER, 'none', login: 'pat.login@example.com');

    $alert = unprovenPanic();
    $envelopes = unprovenEnvelopes();

    expect($alert->escalated_contacts)->toBe(2)
        ->and($envelopes)->toHaveCount(2);

    // One address on each mail: nobody learns another person's mailbox from a panic.
    foreach ($envelopes as $to) {
        expect($to)->toHaveCount(1);
    }

    $everyone = collect($envelopes)->flatten()->sort()->values()->all();

    expect($everyone)->toBe(['lee.alerts@example.org', 'reid.alerts@example.org'])
        ->and($everyone)->not->toContain('reid.login@example.com')
        ->and($everyone)->not->toContain('lee.login@example.com')
        ->and($everyone)->not->toContain('kim.login@example.com')
        ->and($everyone)->not->toContain('kim.typed@example.org')
        ->and($everyone)->not->toContain('pat.login@example.com');
});

it('reads the proven address when the mail is sent, and sends nothing to a notifiable without one', function () {
    config(['mail.default' => 'array']);

    $head = unprovenPerson(Role::HEAD_OF_SECURITY, 'proven', login: 'ana.login@example.com', contact: 'ana.alerts@example.org');
    $channel = app(AlertMailChannel::class);

    expect($channel->send($head, unprovenNotification()))->not->toBeNull()
        ->and(unprovenEnvelopes())->toBe([['ana.alerts@example.org']]);

    unprovenOutbox()->flush();

    /*
     * THE ADDRESS CHANGED AFTER THE PANIC WAS QUEUED. `User::booted()` clears the proof,
     * and a queued alert reaching the worker now finds no address to use — and does not
     * fall back to the one the account signs in with.
     */
    $head->forceFill(['contact_email' => 'ana.new@example.org'])->save();

    expect($head->fresh()->alertEmail())->toBeNull()
        ->and($channel->send($head->fresh(), unprovenNotification()))->toBeNull()
        ->and(unprovenEnvelopes())->toBe([]);

    // An on-demand route — `mail` or even `alerts` — is an address nobody proved: the channel sends nothing to it.
    expect($channel->send((new AnonymousNotifiable)->route('mail', 'somebody@example.com'), unprovenNotification()))->toBeNull()
        ->and($channel->send((new AnonymousNotifiable)->route(AlertMailChannel::ROUTE, 'somebody@example.com'), unprovenNotification()))->toBeNull()
        ->and(unprovenEnvelopes())->toBe([]);
});

/* ── 3 · the honest record when nobody could be mailed ───────────────── */

it('counts nobody as told when every contact is unproven, and says why a destination was not reached', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();

    unprovenPerson(Role::HEAD_OF_SECURITY, 'none');
    unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'typed');
    unprovenPerson(Role::PRESIDENT, 'none');
    unprovenPerson(Role::SECRETARY, 'typed');

    $alert = unprovenPanic();

    Notification::assertNothingSent();

    // Nobody mailed and no handset: the resident is told nobody was reached, which is true.
    expect($alert->escalated_contacts)->toBe(0)
        ->and($alert->alerted_via)->toBe('none');

    $legs = unprovenLegs($alert);

    /*
     * NOT "NOBODY TO TELL". There was somebody at each destination; what they lack is an
     * address the platform may use, and that is what the row says.
     */
    expect($legs->pluck('outcome')->unique()->all())->toBe([DuressAlertDelivery::SKIPPED])
        ->and($legs->firstWhere('destination', DuressAlertDelivery::ESTATE_CONSOLE)->recipients)->toBe(2)
        ->and($legs->firstWhere('destination', DuressAlertDelivery::ESTATE_CONSOLE)->note)->toStartWith('2 people could not be emailed: address not verified.')
        ->and($legs->where('outcome', DuressAlertDelivery::NONE))->toBeEmpty();

    $report = app(DeliveryReport::class)->forAlert($alert);
    $gemini = collect($report['destinations'])->firstWhere('key', DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY);

    expect($gemini['reached'])->toBeFalse()
        ->and($gemini['reason'])->toContain('could not be emailed: address not verified')
        ->and($report['unverified'])->toBe(4);
});

it('prints on panic:walk why the Heads of Security were not reached: the walk is a drill, which mails nobody outside its officers', function () {
    config(['mail.default' => 'smtp']);
    Notification::fake();

    unprovenPerson(Role::HEAD_OF_SECURITY, 'none');
    unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'none');

    // A real estate, so it needs --force; everything it would tell anybody says DRILL, and here it tells nobody. Since
    // M73 (`f3fb090a`) a drill mails nobody outside its officers, so the walk gives the drill's reason before any
    // address is looked at; a real panic's reason for an unproven address is held by the two cases above.
    $this->artisan('panic:walk', ['estate' => $this->site, '--force' => true])
        ->expectsOutputToContain('NOT REACHED  Gemini Head of Security for this site — A drill: its officers are told, and nobody outside them is mailed about it.')
        ->expectsOutputToContain('NOT REACHED  The estate\'s own Head of Security — A drill: its officers are told, and nobody outside them is mailed about it.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

/* ── 4 · a Head of Security's other alerts, the same rule ────────────── */

it('does not mail an unproven Head of Security about a shift the platform closed, and mails a proven one', function () {
    Notification::fake();

    $proven = unprovenPerson(Role::HEAD_OF_SECURITY, 'proven');
    $unproven = unprovenPerson(Role::HEAD_OF_SECURITY, 'none');

    $post = Post::create(['tenant_id' => $this->site, 'name' => 'Unproven Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Officer Unproven', 'employee_number' => 'GS-UA-1', 'psra_number' => 'PSRA-GS-UA-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $post->id,
    ]);

    $end = Carbon::now()->subHours(5);

    Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => $end->copy()->subHours(12), 'rostered_end' => $end,
        'actual_start' => $end->copy()->subHours(12), 'status' => 'on_duty',
    ]));

    expect(app(ShiftAutoClose::class)->run(Carbon::now())['closed'])->toBeGreaterThanOrEqual(1);

    Notification::assertSentTo($proven, ShiftAutoClosed::class, static fn (ShiftAutoClosed $mail, array $channels): bool => $channels === [AlertMailChannel::class]);
    Notification::assertNotSentTo($unproven, ShiftAutoClosed::class);
});

/* ── 5 · everything that is not an alert goes where it went ──────────── */

it('still sends a password reset to the sign-in address, whatever the proven alert address is', function () {
    config(['mail.default' => 'array']);

    $user = unprovenPerson(Role::HEAD_OF_SECURITY, 'proven', login: 'rae.login@example.com', contact: 'rae.alerts@example.org');

    $user->sendPasswordResetNotification('a-reset-token');

    expect(unprovenEnvelopes())->toBe([['rae.login@example.com']]);

    // And an account locked out of an unproven alert address can still reset its password.
    unprovenOutbox()->flush();
    $locked = unprovenPerson(Role::PRESIDENT, 'none', login: 'sam.login@example.com');

    $locked->sendPasswordResetNotification('another-reset-token');

    expect(unprovenEnvelopes())->toBe([['sam.login@example.com']]);
});

it('still sends an invitation to the invited address and the verification code to the address being proven', function () {
    Notification::fake();

    $admin = unprovenPerson(Role::COMMUNITY_SUPER_ADMIN, 'proven', login: 'csa.login@example.com', contact: 'csa.alerts@example.org');

    app(Invitations::class)->invite('new.member@example.com', Role::named(Role::SECRETARY), $this->site, $admin);

    Notification::assertSentOnDemand(InvitationSent::class, static fn (InvitationSent $mail, array $channels, AnonymousNotifiable $to): bool => $channels === ['mail']
        && $to->routes === ['mail' => 'new.member@example.com']);

    $member = unprovenPerson(Role::TREASURER, 'none', login: 'tre.login@example.com');

    app(ContactVerification::class)->send($member, 'tre.fresh@example.org', null, 'Estate console');

    Notification::assertSentOnDemand(ContactVerificationCode::class, static fn (ContactVerificationCode $mail, array $channels, AnonymousNotifiable $to): bool => $channels === ['mail']
        && $to->routes === ['mail' => 'tre.fresh@example.org']);

    // Neither of them is an alert, and neither is sent on the alert channel.
    Notification::assertNotSentTo($admin, PanicRaisedAtSite::class);
});

/* ── 6 · accounts:verify-contact, the break glass ────────────────────── */

it('refuses to mark an address proven without a reason of ten characters, or without --confirm, and changes nothing', function () {
    $user = unprovenPerson(Role::PRESIDENT, 'typed', login: 'dana.login@example.com', contact: 'dana.mailbox@example.org');
    $audits = AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->count();

    $this->artisan('accounts:verify-contact', ['email' => 'dana.login@example.com', '--confirm' => true])
        ->expectsOutputToContain('Give a --reason of at least 10 characters')
        ->assertExitCode(1);

    $this->artisan('accounts:verify-contact', ['email' => 'dana.login@example.com', '--confirm' => true, '--reason' => 'too short'])
        ->expectsOutputToContain('Give a --reason of at least 10 characters')
        ->assertExitCode(1);

    // No --confirm: it says exactly what it WOULD mark, masked, and does not.
    $this->artisan('accounts:verify-contact', ['email' => 'dana.login@example.com', '--reason' => 'Relay bounces the code; confirmed by phone'])
        ->expectsOutputToContain('This would mark d•••@example.org as the proven alert address of account #'.$user->id)
        ->expectsOutputToContain('Pass --confirm to do it. Nothing was changed.')
        ->doesntExpectOutputToContain('dana.mailbox@example.org')
        ->doesntExpectOutputToContain('dana.login@example.com')
        ->assertExitCode(1);

    expect($user->fresh()->hasVerifiedContact())->toBeFalse()
        ->and(AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->count())->toBe($audits);
});

it('marks the account\'s CURRENT contact address proven, masked on the screen and in the audit log, with the reason', function () {
    $user = unprovenPerson(Role::ESTATE_HEAD_OF_SECURITY, 'typed', login: 'omar.login@example.com', contact: 'omar.mailbox@example.org');

    // A code that is still waiting has nothing left to prove afterwards.
    DB::connection('mysql')->table('contact_verification_codes')->insert([
        'user_id' => $user->id, 'email' => 'omar.mailbox@example.org', 'code_hash' => str_repeat('0', 64),
        'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('accounts:verify-contact', [
        'email' => 'Omar.Login@example.com',
        '--confirm' => true,
        '--reason' => 'Relay rejects the code; owner omar.mailbox@example.org confirmed on 876 555 0141',
    ])
        ->expectsOutputToContain('Marked o•••@example.org as the proven alert address of account #'.$user->id)
        ->expectsOutputToContain('Audited as the server operator')
        ->doesntExpectOutputToContain('omar.mailbox@example.org')
        ->doesntExpectOutputToContain('omar.login@example.com')
        ->assertSuccessful();

    $user = $user->fresh();

    // The address the OWNER typed — not the sign-in address — is now the one alerts use.
    expect($user->hasVerifiedContact())->toBeTrue()
        ->and($user->alertEmail())->toBe('omar.mailbox@example.org')
        ->and($user->needsContactVerification())->toBeFalse()
        ->and(DB::connection('mysql')->table('contact_verification_codes')->where('user_id', $user->id)->whereNull('consumed_at')->count())->toBe(0);

    $entry = AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->where('entity_id', (string) $user->id)->sole();

    expect($entry->actor_name)->toBe('server operator')
        ->and($entry->actor_id)->toBeNull()
        ->and($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($entry->after['address'])->toBe('o•••@example.org')
        ->and($entry->after['reason'])->toContain('Relay rejects the code')
        ->and($entry->after['reason'])->toContain('o•••@example.org')
        ->and($entry->after['reason'])->toContain('[number withheld]');

    // Nowhere in the entry is the whole address or the whole number.
    $stored = json_encode([$entry->before, $entry->after], JSON_UNESCAPED_UNICODE);

    expect($stored)->not->toContain('omar.mailbox@example.org')
        ->and($stored)->not->toContain('omar.login@example.com')
        ->and($stored)->not->toContain('555 0141');

    // Idempotent: asked again, it says so and writes nothing.
    $this->artisan('accounts:verify-contact', ['email' => 'omar.login@example.com', '--confirm' => true, '--reason' => 'Asked a second time by mistake'])
        ->expectsOutputToContain('is already the proven alert address')
        ->assertSuccessful();

    expect(AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->where('entity_id', (string) $user->id)->count())->toBe(1);
});

it('marks the sign-in address proven when the account never typed a contact address', function () {
    $head = unprovenPerson(Role::HEAD_OF_SECURITY, 'none', login: 'vic.login@example.com');

    $this->artisan('accounts:verify-contact', ['email' => 'vic.login@example.com', '--confirm' => true, '--reason' => 'Owner cannot receive the code; confirmed in person'])
        ->expectsOutputToContain('Marked v•••@example.com as the proven alert address')
        ->doesntExpectOutputToContain('vic.login@example.com')
        ->assertSuccessful();

    expect($head->fresh()->alertEmail())->toBe('vic.login@example.com')
        ->and(AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->where('entity_id', (string) $head->id)->sole()->tenant_id)->toBeNull();
});

it('refuses a demonstration account, and an address that is not a console account\'s', function () {
    $demo = unprovenPerson(Role::PRESIDENT, 'none', login: 'lena-president@facilitiestest.demo.geminisl.com');

    $this->artisan('accounts:verify-contact', ['email' => 'lena-president@facilitiestest.demo.geminisl.com', '--confirm' => true, '--reason' => 'Trying the demonstration account'])
        ->expectsOutputToContain('is a demonstration account. `php artisan accounts:verify-demonstration` pre-verifies those')
        ->doesntExpectOutputToContain('lena-president@facilitiestest.demo.geminisl.com')
        ->assertExitCode(1);

    expect($demo->fresh()->hasVerifiedContact())->toBeFalse();

    // An officer's record has an address and no console.
    Guard::create([
        'full_name' => 'Officer Addressed', 'employee_number' => 'GS-UA-2', 'psra_number' => 'PSRA-GS-UA-2',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'email' => 'officer.addressed@example.com',
    ]);

    $this->artisan('accounts:verify-contact', ['email' => 'officer.addressed@example.com', '--confirm' => true, '--reason' => 'Trying an officer\'s address'])
        ->expectsOutputToContain('belongs to an officer\'s record, not a console account')
        ->doesntExpectOutputToContain('officer.addressed@example.com')
        ->assertExitCode(1);

    // A Resident App account signs in to a phone, not a console.
    $unit = FacilitiesFixture::scratchUnit('Lot 7314');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Unproven household', 'access_restricted' => false]);
    $resident = Resident::query()->firstOrCreate(['household_id' => $household->id, 'full_name' => 'Rae Unproven'], ['relationship' => 'owner', 'is_primary' => true]);

    ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'rae.resident@example.com', 'full_name' => 'Rae Unproven',
        'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    $this->artisan('accounts:verify-contact', ['email' => 'rae.resident@example.com', '--confirm' => true, '--reason' => 'Trying a resident\'s address'])
        ->expectsOutputToContain('belongs to a Resident App account, not a console account')
        ->doesntExpectOutputToContain('rae.resident@example.com')
        ->assertExitCode(1);

    $this->artisan('accounts:verify-contact', ['email' => 'nobody.here@example.com', '--confirm' => true, '--reason' => 'Trying an address nobody holds'])
        ->expectsOutputToContain('n•••@example.com is not the sign-in address of any console account')
        ->doesntExpectOutputToContain('nobody.here@example.com')
        ->assertExitCode(1);

    expect(AuditEntry::query()->where('action', AccountsVerifyContact::ACTION)->count())->toBe(0);
});

it('never prints a whole address or phone number in what it keeps from the reason', function () {
    expect(AccountsVerifyContact::scrub('Owner jo.smith@example.org rang from +1 876 555 0141 at 9'))
        ->toBe('Owner j•••@example.org rang from [number withheld] at 9');
});
