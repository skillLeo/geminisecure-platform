<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicContactEmail;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\AlertEmailDispatch;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Notifications\PanicContactAlerted;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\HouseholdContactsMail;
use App\Services\Dispatch\PanicEmailOutcome;
use App\Support\SiteTime;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A PANIC EMAILS THE HOUSEHOLD'S OWN EMERGENCY CONTACTS — M12 §2.5
|--------------------------------------------------------------------------
|
| "Emergency contacts take a phone number and an email. Panic emails them and the estate immediately." The estate
| is emailed already (the escalation mail). What this file proves:
|
|   1  a contact takes an email (validated), and an existing one can be given one (`contacts.update`)
|   2  a resident's panic queues ONE job PER CONTACT with an address, carrying ids and no address, and
|      the email names who, where and when — and nothing to tap
|   3  "emailed" is counted from rows the mail server accepted, never from the queue: sent, failed after
|      every retry, an address gone by the time it was sent — each said as what it is
|   4  an officer's duress emails no household; a simulated or demonstration alert emails nobody
|   5  the ledger is append-only, and holds the address masked
|
| This file's lots are its own (9451, 9452) and its contacts are removed afterwards; the central database is
| wrapped in a transaction with the framework's TESTING manager, `HouseholdContactsAreTextedTest`'s way.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;
    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9451');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Mailcheck household', 'access_restricted' => false]);
    $this->resident = Resident::query()->firstOrCreate(['household_id' => $this->household->id, 'full_name' => 'Andrea Mailcheck'], ['relationship' => 'owner', 'is_primary' => true]);
    $this->resident->forceFill(['is_primary' => true, 'removed_at' => null])->save();

    $this->neighbourUnit = FacilitiesFixture::scratchUnit('Lot 9452');
    $this->neighbour = Household::query()->firstOrCreate(['unit_id' => $this->neighbourUnit->id], ['name' => 'Mailcheck neighbours', 'access_restricted' => false]);

    hheForget($this);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
    hheForget($this);
});

function hheForget(TestCase $test): void
{
    DB::connection('tenant')->table('household_emergency_contacts')->whereIn('household_id', [$test->household->id, $test->neighbour->id])->delete();
}

/** @param list<array{0: string, 1: string|null, 2: string, 3: string|null}> $contacts [name, relationship, phone, email] @return list<int> */
function hheContacts(int $householdId, array $contacts): array
{
    return array_map(static fn (array $c): int => (int) DB::connection('tenant')->table('household_emergency_contacts')->insertGetId([
        'household_id' => $householdId, 'name' => $c[0], 'relationship' => $c[1], 'phone' => $c[2], 'email' => $c[3],
        'created_at' => now(), 'updated_at' => now(),
    ]), $contacts);
}

/** @return array{0: ResidentAccount, 1: string} */
function hheResident(TestCase $test, string $email): array
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => $email, 'full_name' => 'Andrea Mailcheck',
        'resident_id' => $test->resident->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);

    return [$account, $account->createToken('resident-app:'.$email, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

function hheCall(TestCase $test, string $token, string $name, array $body = [], array $path = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = $entry['uri'];

    foreach ($path as $key => $value) {
        $uri = str_replace('{'.$key.'}', (string) $value, $uri);
    }

    app('auth')->forgetGuards();

    $response = $test->withToken($token)->json($entry['method'], '/api/v1/'.$uri, $body, [
        'Idempotency-Key' => 'hhe-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ]);

    $response->assertStatus($status ?? $entry['status']);

    if ($status === null) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** Run one contact's job as the worker would. */
function hheRun(int $alertId, int $contactId): void
{
    (new SendPanicContactEmail($alertId, $contactId))->handle(app(HouseholdContactsMail::class));
    FacilitiesFixture::boot();
}

/* ── 1 · a contact takes an email ─────────────────────────────────────── */

it('takes an email beside the phone, validates it, and lets an existing contact be given one', function () {
    [, $token] = hheResident($this, 'mail.form@example.com');

    $added = hheCall($this, $token, 'contacts.store', ['name' => 'Marcia Mailcheck', 'relationship' => 'sister', 'phone' => '876 555 0141', 'email' => 'marcia@example.com']);
    expect($added->json('email'))->toBe('marcia@example.com');

    hheCall($this, $token, 'contacts.store', ['name' => 'Bad Address', 'phone' => '876 555 0142', 'email' => 'not-an-address'], status: 422)
        ->assertJsonPath('error.code', 'validation_failed');

    // Saved before emails were asked for: the email is added, the rest kept.
    [$old] = hheContacts($this->household->id, [['Uncle Mailcheck', 'uncle', '876 555 0143', null]]);
    $updated = hheCall($this, $token, 'contacts.update', ['email' => 'uncle@example.com'], ['contact' => $old]);
    expect($updated->json())->toMatchArray(['id' => $old, 'name' => 'Uncle Mailcheck', 'phone' => '876 555 0143', 'email' => 'uncle@example.com']);

    // Another contact's number is refused; another household's contact is not ours to change.
    hheCall($this, $token, 'contacts.update', ['phone' => '876 555 0141'], ['contact' => $old], status: 409)->assertJsonPath('error.code', 'already_listed');
    [$theirs] = hheContacts($this->neighbour->id, [['Not Yours', null, '876 555 0199', 'x@example.com']]);
    hheCall($this, $token, 'contacts.update', ['email' => 'mine@example.com'], ['contact' => $theirs], status: 404);

    expect(collect(hheCall($this, $token, 'contacts.index')->json('items'))->pluck('email', 'name')->all())
        ->toMatchArray(['Marcia Mailcheck' => 'marcia@example.com', 'Uncle Mailcheck' => 'uncle@example.com']);
});

/* ── 2 · a panic emails them ──────────────────────────────────────────── */

it('queues one job per contact with an address, and says emailed only once the mail server has taken each', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();

    [$sister, $none, $brother, $dup] = hheContacts($this->household->id, [
        ['Marcia Mailcheck', 'sister', '876 555 0151', 'marcia@example.com'],
        ['No Mail', 'friend', '876 555 0152', null],
        ['Dwayne Mailcheck', 'brother', '876 555 0153', 'dwayne@example.com'],
        ['Marcia Again', 'sister', '876 555 0154', 'MARCIA@example.com'],
    ]);
    // The neighbours' contact is never read by this household's panic.
    hheContacts($this->neighbour->id, [['Next Door', null, '876 555 0199', 'nextdoor@example.com']]);

    [, $token] = hheResident($this, 'mail.panic@example.com');
    $answer = hheCall($this, $token, 'duress.store', ['mode' => 'audible']);
    $alertId = (int) $answer->json('id');

    // Two jobs — the address listed twice is one person — carrying ids and no address.
    Bus::assertDispatchedTimes(SendPanicContactEmail::class, 2);
    Bus::assertDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => $job->alertId === $alertId && $job->contactId === $sister && $job->queue === config('push.urgent_queue'));
    Bus::assertDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => $job->contactId === $brother);
    Bus::assertNotDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => in_array($job->contactId, [$none, $dup], true));

    // Before any worker ran: two being emailed, none confirmed — never "emailed".
    expect($answer->json('email.household'))->toBe(['recipients' => 2, 'sent' => 0, 'failed' => 0, 'finished' => false, 'members_sent' => 0, 'contacts_sent' => 0]);

    hheRun($alertId, $sister);
    hheRun($alertId, $brother);

    Notification::assertSentOnDemand(PanicContactAlerted::class, fn (PanicContactAlerted $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'marcia@example.com');
    Notification::assertSentOnDemand(PanicContactAlerted::class, fn (PanicContactAlerted $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'dwayne@example.com');
    Notification::assertSentOnDemandTimes(PanicContactAlerted::class, 2);

    // The one form, "27 Sep 2026, 2:14 AM Jamaica time" (Hassam, 12 Oct): the date with its year and the clock named,
    // because the mail leaves the estate (M19A §8.7, §10.7) and may be read the next morning.
    Notification::assertSentOnDemand(PanicContactAlerted::class, fn (PanicContactAlerted $mail): bool => preg_match('/^\d{1,2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} [AP]M Jamaica time$/', $mail->when) === 1);

    $alert = DuressAlert::query()->findOrFail($alertId);
    expect(app(PanicEmailOutcome::class)->of($alert))->toBe(['recipients' => 2, 'sent' => 2, 'failed' => 0, 'finished' => true, 'members_sent' => 0, 'contacts_sent' => 2])
        ->and(app(PanicEmailOutcome::class)->householdLine($alert))->toBe('2 emailed');

    // What the email says: who, where, when — and nothing to tap or quote.
    $mail = (new PanicContactAlerted('Andrea', 'Facilities Test Estate', 'Lot 47', '27 Sep 2026, 2:14 AM Jamaica time', false, '119', '2:14 AM Jamaica time', '27 Sep 2026'))->toMail(new AnonymousNotifiable);
    $text = implode(' ', [$mail->subject, ...$mail->introLines]);
    // Hassam, 12 Oct: the subject is what, where, then when, no name and no date, because a lock screen cuts it near
    // forty characters; the date and the name open the body, the preview most mail apps show under the subject.
    expect($mail->subject)->toBe('Panic alert, Lot 47, 2:14 AM Jamaica time')
        ->and(mb_strlen($mail->subject))->toBeLessThanOrEqual(41)
        ->and($mail->greeting)->toBe('27 Sep 2026: Andrea raised an emergency alert.')
        ->and($text)->toContain('Lot 47')->toContain('2:14 AM')->toContain('call 119')
        ->and($mail->actionUrl)->toBeNull()
        ->and($text)->not->toContain('http');

    // A drill keeps DRILL and gives up the time, which opens the body with the date instead (Hassam, 12 Oct).
    $drill = (new PanicContactAlerted('Andrea', 'Facilities Test Estate', 'Lot 47', '27 Sep 2026, 2:14 AM Jamaica time', true, '119', '2:14 AM Jamaica time', '27 Sep 2026'))->toMail(new AnonymousNotifiable);
    expect($drill->subject)->toBe('DRILL: Panic alert, Lot 47')
        ->and(mb_strlen($drill->subject))->toBeLessThanOrEqual(41)
        ->and($drill->greeting)->toBe('27 Sep 2026, 2:14 AM Jamaica time: this is a drill.');

    // The ledger holds the relationship and a MASKED address; a retry finds its row and sends nothing twice.
    $rows = AlertEmailDispatch::query()->where('duress_alert_id', $alertId)->orderBy('id')->get();
    expect($rows->pluck('address')->all())->toBe(['ma***@ex***.com', 'dw***@ex***.com'])
        ->and($rows->pluck('label')->all())->toBe(['sister', 'brother']);
    hheRun($alertId, $sister);
    Notification::assertSentOnDemandTimes(PanicContactAlerted::class, 2);
});

it('emails the household\'s own members as well — never the one who pressed — and each address once (M18C §4.1)', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();

    [$sister] = hheContacts($this->household->id, [['Marcia Mailcheck', 'sister', '876 555 0181', 'marcia.member@example.com']]);
    $son = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Kemar Mailcheck', 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::VERIFIED, 'phone' => '+1 876 555 0182', 'email' => 'kemar@example.com']);
    // Listed as a contact AND a member: written to once.
    $wife = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Marcia Twice', 'relationship' => 'spouse', 'is_primary' => false, 'status' => Resident::VERIFIED, 'phone' => '+1 876 555 0183', 'email' => 'MARCIA.member@example.com']);
    // No email of their own — alerts go to the primary, who is the one pressing here.
    $child = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Baby Mailcheck', 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::VERIFIED, 'phone' => '+1 876 555 0184', 'email' => null, 'alerts_to_primary' => true]);

    try {
        $this->resident->forceFill(['email' => 'andrea.presser@example.com'])->save();
        [, $token] = hheResident($this, 'mail.members@example.com');
        $alertId = (int) hheCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

        // The contact, and the one member with an address of their own: the presser and the duplicate are not.
        Bus::assertDispatchedTimes(SendPanicContactEmail::class, 2);
        Bus::assertDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => $job->contactId === $sister && $job->kind === SendPanicContactEmail::CONTACT);
        Bus::assertDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => $job->contactId === $son->id && $job->kind === SendPanicContactEmail::MEMBER);

        (new SendPanicContactEmail($alertId, $son->id, SendPanicContactEmail::MEMBER))->handle(app(HouseholdContactsMail::class));
        FacilitiesFixture::boot();
        hheRun($alertId, $sister);

        Notification::assertSentOnDemand(PanicContactAlerted::class, fn (PanicContactAlerted $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'kemar@example.com');
        expect(app(PanicEmailOutcome::class)->of(DuressAlert::query()->findOrFail($alertId)))->toBe(['recipients' => 2, 'sent' => 2, 'failed' => 0, 'finished' => true, 'members_sent' => 1, 'contacts_sent' => 1])
            ->and(AlertEmailDispatch::query()->where('duress_alert_id', $alertId)->whereNotNull('household_member_id')->value('household_member_id'))->toBe($son->id);
    } finally {
        Resident::query()->whereKey([$son->id, $wife->id, $child->id])->delete();
    }
});

it('says what became of a mail that failed after every retry, and of an address gone before it went', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();

    [$a, $b] = hheContacts($this->household->id, [
        ['First', 'mother', '876 555 0161', 'mother@example.com'],
        ['Second', 'father', '876 555 0162', 'father@example.com'],
    ]);
    [, $token] = hheResident($this, 'mail.fail@example.com');
    $alertId = (int) hheCall($this, $token, 'duress.store', ['mode' => 'audible'])->json('id');

    // Every attempt refused: the worker's last word is `failed`, with a code.
    (new SendPanicContactEmail($alertId, $a))->failed(new RuntimeException('550 mailbox unavailable'));
    FacilitiesFixture::boot();
    // The resident cleared the second address before the worker got to it.
    DB::connection('tenant')->table('household_emergency_contacts')->where('id', $b)->update(['email' => null]);
    hheRun($alertId, $b);

    $alert = DuressAlert::query()->findOrFail($alertId);
    expect(app(PanicEmailOutcome::class)->of($alert))->toBe(['recipients' => 2, 'sent' => 0, 'failed' => 2, 'finished' => true, 'members_sent' => 0, 'contacts_sent' => 0])
        ->and(app(PanicEmailOutcome::class)->householdLine($alert))->toBe('not emailed — the mail server refused them')
        ->and(AlertEmailDispatch::query()->where('duress_alert_id', $alertId)->pluck('status', 'error')->all())
        ->toBe(['RuntimeException' => AlertEmailDispatch::FAILED, 'address_gone' => AlertEmailDispatch::NO_ADDRESS]);
    // No contact's mail, counted across every recipient. Not "nothing at all": the estate's own escalation mail
    // (PanicRaisedAtSite) goes to whoever is on the estate's staff, and other files leave staff on this shared estate.
    Notification::assertSentTimes(PanicContactAlerted::class, 0);
});

/* ── 4 · who never gets one ───────────────────────────────────────────── */

it('emails no household on an officer\'s duress, and nobody for a demonstration estate', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();
    hheContacts($this->household->id, [['Marcia', 'sister', '876 555 0171', 'marcia@example.com']]);

    $post = Post::create(['tenant_id' => $this->site, 'name' => 'Mailcheck Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Officer Mailcheck', 'employee_number' => 'GS-HHE-1', 'psra_number' => 'PSRA-HHE-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $this->site, 'post_id' => $post->id,
    ]);
    $duress = hheCall($this, app(DeviceEnrolment::class)->enrol($guard, 'Mailcheck handset')['token'], 'duress.store', ['mode' => 'audible']);
    expect($duress->json('email.household'))->toBeNull();
    Bus::assertNotDispatched(SendPanicContactEmail::class);

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => true])->save();
    [, $token] = hheResident($this, 'mail.demo@example.com');
    $demo = hheCall($this, $token, 'duress.store', ['mode' => 'audible']);
    expect($demo->json('email.household'))->toMatchArray(['recipients' => 0, 'sent' => 0, 'finished' => true]);
    Bus::assertNotDispatched(SendPanicContactEmail::class);
});

/* ── 5 · the ledger ───────────────────────────────────────────────────── */

it('keeps the record of who was emailed append-only', function () {
    $row = AlertEmailDispatch::query()->create([
        'duress_alert_id' => 1, 'tenant_id' => $this->site, 'audience' => AlertEmailDispatch::HOUSEHOLD, 'household_emergency_contact_id' => 1,
        'label' => 'sister', 'address' => 'ma***@ex***.com', 'status' => AlertEmailDispatch::SENT, 'attempted_at' => now(),
    ]);

    expect(fn () => DB::connection('mysql')->table('alert_email_dispatches')->where('id', $row->id)->update(['status' => AlertEmailDispatch::FAILED]))->toThrow(QueryException::class)
        ->and(fn () => DB::connection('mysql')->table('alert_email_dispatches')->where('id', $row->id)->delete())->toThrow(QueryException::class)
        ->and(AlertEmailDispatch::mask('someone@gmail.com'))->toBe('so***@gm***.com')
        ->and(AlertEmailDispatch::mask('x'))->toBe('x***');
});
