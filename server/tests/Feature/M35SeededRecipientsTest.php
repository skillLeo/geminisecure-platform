<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Alerts\SendHouseholdContactsSms;
use App\Jobs\Alerts\SendPanicContactEmail;
use App\Jobs\Alerts\SendPanicSms;
use App\Jobs\Push\SendExpoPush;
use App\Models\DuressAlert;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Notifications\PanicContactAlerted;
use App\Services\Dispatch\HouseholdContactsMail;
use App\Services\Dispatch\HouseholdContactsSms;
use App\Services\Dispatch\PanicEmailOutcome;
use App\Services\Estate\ResidentInvites;
use App\Services\Platform\MailSettings;
use App\Services\ResidentApp\ScreenedSmsGateway;
use App\Services\ResidentApp\SmsGateway;
use App\Services\ResidentApp\SmsResult;
use App\Support\InviteMail;
use App\Support\Seeded\Seeding;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as Message;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Address;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| No message reaches a person a seeder invented (M35 §5.2, layer 1)
|--------------------------------------------------------------------------
|
| The demonstration seed gave invented residents addresses on email.com, a live public mail service, and a panic
| emailed one of them twice. Every email passes the mail transport's guard and every text the SMS driver's, and a
| recipient whom only a seeded person holds is dropped there — counted in `seeded_recipient_drops`, logged by row and
| estate, and never handed to the transport. A real person is not affected, in the same message or alone.
|
| The household and its people are this file's own (Lot 9465) and removed afterwards; the central database is wrapped
| in a transaction with the framework's TESTING manager, `HouseholdContactsAreEmailedTest`'s way.
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

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9465');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Seedcheck household', 'access_restricted' => false]);
    m35Forget($this);

    // The one who presses, real. Beside them, one person the seed invented and one real person.
    $this->presser = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Pressing Seedcheck', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED, 'email' => 'presser.m35@example.org']);
    $this->invented = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Invented Seedcheck', 'relationship' => 'sister', 'is_primary' => false, 'status' => Resident::VERIFIED, 'email' => 'invented.seedcheck@email.com', 'phone' => '876 508 8090']));
    $this->real = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Real Seedcheck', 'relationship' => 'spouse', 'is_primary' => false, 'status' => Resident::VERIFIED, 'email' => 'real.seedcheck@gmail.com', 'phone' => '+1 876 508 8091']);

    m35Transport()->flush();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    if (tenancy()->initialized) {
        tenancy()->end();
    }
    SiteTime::flush();
    FacilitiesFixture::boot();
    m35Forget($this);
});

function m35Forget(object $test): void
{
    DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $test->household->id)->delete();
    DB::connection('tenant')->table('resident_invites')->where('unit_id', $test->unit->id)->delete();
    DB::connection('tenant')->table('residents')->where('household_id', $test->household->id)->delete();
}

/** The presser's Resident App sign-in, and a panic raised through the API, as the phone raises it. */
function m35Panic(object $test): int
{
    $account = ResidentAccount::query()->create([
        'tenant_id' => $test->site, 'channel' => 'email', 'destination' => 'presser.m35@example.org', 'full_name' => 'Pressing Seedcheck',
        'resident_id' => $test->presser->id, 'unit_id' => $test->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app:m35', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $entry = Catalogue::endpoints()['duress.store'];
    app('auth')->forgetGuards();
    $answer = $test->withToken($token)->json($entry['method'], '/api/v1/'.$entry['uri'], ['mode' => 'audible'], [
        'Idempotency-Key' => 'm35-'.bin2hex(random_bytes(8)),
        'X-Device-Time' => now()->toIso8601String(),
    ])->assertStatus($entry['status']);
    FacilitiesFixture::boot();
    $test->answer = $answer;

    return (int) $answer->json('id');
}

function m35Transport(): ArrayTransport
{
    $transport = app('mailer')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    return $transport;
}

/** Every address the transport was handed, in every message. @return list<string> */
function m35Delivered(): array
{
    return m35Transport()->messages()->flatMap(static fn ($sent): array => array_map(
        static fn (Address $a): string => $a->getAddress(),
        $sent->getEnvelope()->getRecipients(),
    ))->values()->all();
}

function m35Message(): Message
{
    return new class extends Message
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('A message from the M35 test')->line('Hello.');
        }
    };
}

/** The recorded seed range a legacy row sits in, as `phoenix:demo-seed` writes it before the mark existed. */
function m35Range(string $site, string $table, int $id): void
{
    DB::connection('mysql')->table('sample_data_ranges')->insert([
        'tag' => 'phoenix-demo', 'tenant_id' => $site, 'connection' => 'tenant', 'table_name' => $table,
        'from_id' => $id - 1, 'to_id' => $id, 'append_only' => false, 'seeded_at' => now(),
    ]);
}

it('drops an email to a seeded person inside the estate — counted, logged by row, and the transport never called', function () {
    Log::spy();
    tenancy()->initialize(Tenant::query()->findOrFail($this->site));

    Notification::route('mail', 'invented.seedcheck@email.com')->notifyNow(m35Message());

    expect(m35Delivered())->toBe([])
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->get(['channel', 'row_connection', 'row_table', 'row_id', 'tenant_id'])->map(fn ($r): array => (array) $r)->all())
        ->toBe([['channel' => 'mail', 'row_connection' => 'tenant', 'row_table' => 'residents', 'row_id' => $this->invented->id, 'tenant_id' => $this->site]]);

    // The console's Recent emails says it was not sent, and names the row — never the address.
    $logged = DB::connection('mysql')->table('mail_log')->where('outcome', 'not_sent')->first(['recipient', 'reason', 'tenant_id']);
    expect($logged->recipient)->toBe('residents #'.$this->invented->id.' at '.$this->site)
        ->and($logged->tenant_id)->toBe($this->site)
        ->and(json_encode($logged))->not->toContain('@');

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message, array $context): bool => $message === 'message.not_sent_to_a_seeded_person'
        && $context['channel'] === 'mail' && $context['tenant'] === FacilitiesFixture::ESTATE
        && ! str_contains((string) json_encode($context), '@'))->once();
});

it('drops it with no estate open too, for a person the seed wrote before the mark existed', function () {
    // As on production: the row carries no mark, and the seed's recorded range says it is the seed's.
    $legacy = Resident::query()->create(['household_id' => $this->household->id, 'full_name' => 'Legacy Seedcheck', 'relationship' => 'brother', 'is_primary' => false, 'status' => Resident::VERIFIED, 'email' => 'legacy.seedcheck@email.com']);
    expect(DB::connection('tenant')->table('residents')->where('id', $legacy->id)->value('seeded_at'))->toBeNull();
    m35Range($this->site, 'residents', $legacy->id);

    expect(tenancy()->initialized)->toBeFalse();
    Notification::route('mail', 'LEGACY.seedcheck@email.com')->notifyNow(m35Message());

    expect(m35Delivered())->toBe([])
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->where('row_id', $legacy->id)->value('tenant_id'))->toBe($this->site);

    // And without the range it is a real person's address, and goes.
    DB::connection('mysql')->table('sample_data_ranges')->where('tenant_id', $this->site)->where('to_id', $legacy->id)->delete();
    Notification::route('mail', 'legacy.seedcheck@email.com')->notifyNow(m35Message());
    expect(m35Delivered())->toBe(['legacy.seedcheck@email.com']);
});

it('sends to a real person exactly as before, alone or in the same message as a seeded one', function () {
    Notification::route('mail', 'real.seedcheck@gmail.com')->notifyNow(m35Message());

    expect(m35Delivered())->toBe(['real.seedcheck@gmail.com'])
        ->and(DB::connection('mysql')->table('mail_log')->where('recipient', 'real.seedcheck@gmail.com')->value('outcome'))->toBe('accepted')
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->count())->toBe(0);

    // One message to both: the real person gets it, the invented one is taken off it.
    m35Transport()->flush();
    tenancy()->initialize(Tenant::query()->findOrFail($this->site));
    app('mailer')->raw('Both of you.', static fn ($m) => $m->to('real.seedcheck@gmail.com')->cc('invented.seedcheck@email.com')->subject('Both'));

    expect(m35Delivered())->toBe(['real.seedcheck@gmail.com'])
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->where('row_id', $this->invented->id)->count())->toBe(1);

    // An address only a person holds who is not on the estate's books at all is somebody real, and is written to.
    m35Transport()->flush();
    Notification::route('mail', 'nobody.on.file.m35@example.net')->notifyNow(m35Message());
    expect(m35Delivered())->toBe(['nobody.on.file.m35@example.net']);
});

it('never says sent when nothing went: an invite and the console\'s test email to a seeded person say why', function () {
    tenancy()->initialize(Tenant::query()->findOrFail($this->site));

    $pending = fn (string $name, string $email): array => ['household_id' => $this->household->id, 'full_name' => $name, 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::PENDING, 'email' => $email];
    $invented = Seeding::run('phoenix:demo-seed', fn (): Resident => Resident::query()->create($pending('Invited Seedcheck', 'invited.seedcheck@email.com')));
    $real = Resident::query()->create($pending('Invited Real Seedcheck', 'invited.real.m35@gmail.com'));

    // The office's Send invite, to the invented member: nothing reaches the mail server, and the invite says so.
    $invite = app(ResidentInvites::class)->send($invented, 'Estate office');

    expect(m35Delivered())->toBe([])
        ->and($invite->sent_at)->toBeNull()
        ->and($invite->last_error)->toBe(InviteMail::SAMPLE_PERSON)
        ->and(ResidentInvites::outcome($invite)['status'])->toBe('not_sent')
        ->and(ResidentInvites::outcome($invite)['reason'])->toBe(InviteMail::SAMPLE_PERSON)
        ->and(DB::connection('mysql')->table('audit_log')->where('action', 'resident.invite_not_sent')->where('entity_id', (string) $invented->id)->exists())->toBeTrue();

    // The real one is sent, as before.
    $sent = app(ResidentInvites::class)->send($real, 'Estate office');
    expect($sent->sent_at)->not->toBeNull()
        ->and($sent->last_error)->toBeNull()
        ->and(m35Delivered())->toBe(['invited.real.m35@gmail.com']);

    // Platform settings → Email → send a test, to an invented person's address: refused in words, nothing handed over.
    m35Transport()->flush();
    $answer = app(MailSettings::class)->sendTest('invited.seedcheck@email.com');

    expect($answer['ok'])->toBeFalse()
        ->and($answer['message'])->toBe('Not sent: invited.seedcheck@email.com belongs only to a person the sample data invented, not a real one, so the platform does not email it. Send the test to an address of your own.')
        ->and(m35Delivered())->toBe([]);

    expect(app(MailSettings::class)->sendTest('invited.real.m35@gmail.com')['ok'])->toBeTrue()
        ->and(m35Delivered())->toBe(['invited.real.m35@gmail.com']);
});

it('never hands a seeded person\'s number to the SMS provider, and texts a real one', function () {
    $calls = new ArrayObject;
    $driver = new class($calls) implements SmsGateway
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(private readonly ArrayObject $calls) {}

        public function send(string $to, string $message): SmsResult
        {
            $this->calls[] = $to;

            return SmsResult::sent('fake', 'ref-1');
        }
    };
    $sms = new ScreenedSmsGateway($driver, 'fake');

    tenancy()->initialize(Tenant::query()->findOrFail($this->site));

    $dropped = $sms->send('+18765088090', 'A test text.');
    $sent = $sms->send('+18765088091', 'A test text.');

    expect($dropped->status)->toBe(SmsResult::SEEDED)
        ->and($dropped->delivered())->toBeFalse()
        ->and($sent->delivered())->toBeTrue()
        ->and($calls->getArrayCopy())->toBe(['+18765088091'])
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->where('channel', 'sms')->value('row_id'))->toBe($this->invented->id)
        // Every text the platform sends goes through the screen: it is where the driver is made.
        ->and(app(SmsGateway::class))->toBeInstanceOf(ScreenedSmsGateway::class);
});

it('reaches the real member only, at a panic in a household with a seeded member and a real one', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();

    $alertId = m35Panic($this);
    $answer = $this->answer;

    // One job, for the real member. The invented sister is left out — and counted as a message not sent.
    Bus::assertDispatchedTimes(SendPanicContactEmail::class, 1);
    Bus::assertDispatched(SendPanicContactEmail::class, fn (SendPanicContactEmail $job): bool => $job->contactId === $this->real->id && $job->kind === SendPanicContactEmail::MEMBER);
    expect($answer->json('email.household.recipients'))->toBe(1)
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->where('row_table', 'residents')->where('row_id', $this->invented->id)->value('message'))->toBe('Panic contact alerted');

    (new SendPanicContactEmail($alertId, $this->real->id, SendPanicContactEmail::MEMBER))->handle(app(HouseholdContactsMail::class));
    FacilitiesFixture::boot();

    Notification::assertSentOnDemandTimes(PanicContactAlerted::class, 1);
    Notification::assertSentOnDemand(PanicContactAlerted::class, fn (PanicContactAlerted $mail, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'real.seedcheck@gmail.com');

    $alert = DuressAlert::query()->findOrFail($alertId);
    expect(app(PanicEmailOutcome::class)->householdLine($alert))->toBe('1 emailed');
});

it('texts the real emergency contact only, and counts the seeded one as a text not sent', function () {
    Bus::fake([SendExpoPush::class, SendPanicSms::class, SendHouseholdContactsSms::class, SendPanicContactEmail::class]);
    Notification::fake();

    $contact = fn (string $name, string $phone): array => ['household_id' => $this->household->id, 'name' => $name, 'relationship' => 'cousin', 'phone' => $phone, 'created_at' => now(), 'updated_at' => now()];
    $invented = (int) Seeding::run('phoenix:demo-seed', static fn (): int => DB::connection('tenant')->table('household_emergency_contacts')->insertGetId($contact('Invented Cousin', '876 508 8092')));
    DB::connection('tenant')->table('household_emergency_contacts')->insert($contact('Real Cousin', '876 508 8093'));

    $alertId = m35Panic($this);

    // One person on file to text, not two; the seeded cousin is counted as a text not sent.
    expect((int) DuressAlert::query()->whereKey($alertId)->value('household_sms_recipients'))->toBe(1)
        ->and(DB::connection('mysql')->table('seeded_recipient_drops')->where('channel', 'sms')->where('row_table', 'household_emergency_contacts')->value('row_id'))->toBe($invented);

    // And the job, as the worker runs it, texts only the real cousin.
    $calls = new ArrayObject;
    $driver = new class($calls) implements SmsGateway
    {
        /** @param ArrayObject<int, string> $calls */
        public function __construct(private readonly ArrayObject $calls) {}

        public function send(string $to, string $message): SmsResult
        {
            $this->calls[] = $to;

            return SmsResult::sent('fake', 'ref-2');
        }
    };
    (new SendHouseholdContactsSms($alertId))->handle(app(HouseholdContactsSms::class), new ScreenedSmsGateway($driver, 'fake'));
    FacilitiesFixture::boot();

    expect($calls->getArrayCopy())->toBe(['+18765088093']);
});
