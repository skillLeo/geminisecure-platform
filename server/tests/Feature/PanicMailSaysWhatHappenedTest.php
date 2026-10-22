<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertDelivery;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\SiteEmergencyNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PanicRaisedAtSite;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Dispatch\AlertIntake;
use App\Support\ConsoleHome;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| THE PANIC MAIL GOES ON THE PANIC QUEUE, SAYS WHAT THE PUSH REACHED, AND ITS
| BUTTON OPENS SOMETHING FOR WHOEVER READS IT (bug hunt B087, B089/B099, B093)
|--------------------------------------------------------------------------
|
|   B087  `PanicRaisedAtSite` was pinned to the central CONNECTION and left on the
|         default QUEUE — behind every bulk notice on `default`, while its own
|         docblock, M10 §1.3 and the hand-off record all said "the panic queue".
|
|   B089  The mail read "NOBODY ON DUTY … No guard was clocked on at this site"
|   B099  whenever no handset was pushed — with officers on shift whose handsets
|         could not be pushed, with push switched off, and for a lone officer's own
|         duress. It now says which of those it was, as the hand-off record does,
|         and it counts handsets as handsets: two phones are not two officers.
|
|   B093  "Open the alert" linked every recipient to the Gemini dispatch screen,
|         which no estate console user can open. It now opens the recipient's own
|         console, and a recipient who can open neither is offered no button.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // The after-commit fan-out runs inside `create()`, as it does in production.
    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();

    $this->site = FacilitiesFixture::ESTATE;
    $this->post = Post::create(['tenant_id' => $this->site, 'name' => 'Honest Mail Gate', 'type' => 'gate', 'is_active' => true]);

    Tenant::query()->findOrFail($this->site)->forceFill(['demo' => false, 'timezone' => 'America/Jamaica'])->save();
    SiteTime::flush();
    config(['services.sms.driver' => 'log']);

    // Nobody on shift, nobody rostered, nobody to mail, no numbers — each test adds its own.
    Shift::query()->where('tenant_id', $this->site)->delete();
    Guard::query()->where('tenant_id', $this->site)->update(['status' => 'inactive', 'device_id' => null]);
    EstateAssignment::query()->where('tenant_id', $this->site)->update(['is_active' => false]);
    SiteEmergencyNumber::query()->where('tenant_id', $this->site)->delete();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
    SiteTime::flush();
    FacilitiesFixture::boot();
});

/* ── helpers ─────────────────────────────────────────────────────────── */

function honestMailHead(TestCase $test): User
{
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id, 'tenant_id' => $test->site,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id, 'is_active' => true,
    ]);

    return $head->fresh();
}

/** An officer clocked on at the site, with a handset enrolled — and this many push addresses. */
function honestMailOfficer(TestCase $test, string $name, int $pushTokens): Guard
{
    $guard = Guard::create([
        'full_name' => $name, 'employee_number' => 'GS-HM-'.bin2hex(random_bytes(3)), 'psra_number' => 'PSRA-HM-'.bin2hex(random_bytes(3)),
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $test->site, 'post_id' => $test->post->id,
    ]);

    app(DeviceEnrolment::class)->enrol($guard, $name.' handset');

    for ($index = 1; $index <= $pushTokens; $index++) {
        DevicePushToken::query()->create([
            'tenant_id' => $test->site, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => $guard->id, 'app' => AppMatrix::GUARD,
            'access_token_id' => null, 'expo_token' => 'ExponentPushToken[honest'.$index.bin2hex(random_bytes(6)).']', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
        ]);
    }

    $day = SiteTime::today($test->site);

    Shift::withoutEvents(static fn (): Shift => Shift::create([
        'tenant_id' => $test->site, 'guard_id' => $guard->id, 'post_id' => $test->post->id,
        'rostered_start' => $day->copy()->setTime(0, 0), 'rostered_end' => $day->copy()->setTime(23, 59),
        'actual_start' => Carbon::now()->subHour(), 'status' => 'on_duty',
    ]));

    return $guard->fresh();
}

function honestMailPanic(TestCase $test, ?Guard $raisedBy = null): DuressAlert
{
    return app(AlertIntake::class)->record(
        tenantId: $test->site,
        kind: $raisedBy === null ? 'panic' : 'duress',
        guardId: $raisedBy?->id,
        raisedByName: $raisedBy === null ? 'Rae Honest' : null,
        unitReference: $raisedBy === null ? 'Lot 47' : null,
    );
}

/* ── B087 · the panic queue ──────────────────────────────────────────── */

it('queues the alert mail on the panic queue, on the central connection, exactly as the hand-off record says', function () {
    Queue::fake();

    // A mailer that sends (the suite's `array` one writes the record as "delivered to nobody").
    config(['mail.default' => 'smtp']);

    $head = honestMailHead($this);
    $alert = honestMailPanic($this);

    $mails = array_values(array_filter(
        Queue::pushedJobs()[SendQueuedNotifications::class] ?? [],
        static fn (array $pushed): bool => $pushed['job']->notification instanceof PanicRaisedAtSite,
    ));

    expect($mails)->toHaveCount(1)
        ->and($mails[0]['job']->notifiables->pluck('id')->all())->toBe([$head->id])
        ->and($mails[0]['job']->connection)->toBe(config('queue.central_connection', 'central'))
        ->and($mails[0]['queue'])->toBe(config('push.urgent_queue'));

    // …which is what the record has always claimed, and is now true.
    $leg = DuressAlertDelivery::query()->where('duress_alert_id', $alert->id)
        ->where('destination', DuressAlertDelivery::GEMINI_HEAD_OF_SECURITY)->where('channel', DuressAlertDelivery::EMAIL)->firstOrFail();

    expect($leg->note)->toBe('The alert mail to 1 person, on the panic queue.');
});

/* ── B089 / B099 · what the push reached, said as it was ─────────────── */

/** The mail exactly as this person would receive it. */
function honestMailFor(User $recipient): MailMessage
{
    $mail = null;

    Notification::assertSentTo($recipient, PanicRaisedAtSite::class, function (PanicRaisedAtSite $notification) use ($recipient, &$mail): bool {
        $mail = $notification->toMail($recipient);

        return true;
    });

    expect($mail)->toBeInstanceOf(MailMessage::class);

    return $mail;
}

function honestMailText(MailMessage $mail): string
{
    return implode("\n", [(string) $mail->subject, (string) $mail->greeting, ...$mail->introLines, ...$mail->outroLines]);
}

it('does not tell a Head of Security nobody was on duty when an officer is on shift but no handset of theirs could be pushed', function () {
    Notification::fake();
    config(['push.enabled' => true]);
    Bus::fake([SendExpoPush::class]);

    $head = honestMailHead($this);
    honestMailOfficer($this, 'Officer Tokenless', pushTokens: 0);

    $alert = honestMailPanic($this);
    expect($alert->fresh()->alerted_handsets)->toBe(0);

    $mail = honestMailFor($head);
    $text = honestMailText($mail);

    expect((string) $mail->subject)->toStartWith('NO HANDSET REACHED: ')
        ->and($text)->not->toContain('NOBODY ON DUTY')
        ->and($text)->not->toContain('No guard was clocked on')
        ->and($text)->toContain('1 officer is on shift or rostered today, but no handset of theirs could be pushed');
});

it('does not tell a Head of Security nobody was on duty when push is switched off with officers on shift', function () {
    Notification::fake();
    config(['push.enabled' => false]);

    $head = honestMailHead($this);
    honestMailOfficer($this, 'Officer One', pushTokens: 1);
    honestMailOfficer($this, 'Officer Two', pushTokens: 1);

    honestMailPanic($this);
    $mail = honestMailFor($head);
    $text = honestMailText($mail);

    expect((string) $mail->subject)->not->toStartWith('NOBODY ON DUTY')
        ->and($text)->not->toContain('No guard was clocked on')
        ->and($text)->toContain('2 officers are on shift or rostered today, but no handset of theirs could be pushed');
});

it('does not tell a Head of Security no guard was clocked on about the duress of the one officer who is', function () {
    Notification::fake();

    $head = honestMailHead($this);
    $lone = honestMailOfficer($this, 'Officer Alone', pushTokens: 1);

    honestMailPanic($this, raisedBy: $lone);
    $mail = honestMailFor($head);
    $text = honestMailText($mail);

    expect((string) $mail->subject)->toStartWith('NO OTHER OFFICER ON DUTY: ')
        ->and($text)->not->toContain('NOBODY ON DUTY')
        ->and($text)->not->toContain('No guard was clocked on')
        ->and($text)->toContain('No other officer is on shift or rostered today');
});

it('still says NOBODY ON DUTY when that is the truth', function () {
    Notification::fake();

    $head = honestMailHead($this);
    honestMailPanic($this);
    $mail = honestMailFor($head);

    expect((string) $mail->subject)->toStartWith('NOBODY ON DUTY: ')
        ->and(honestMailText($mail))->toContain('No guard was clocked on at this site and no officer was rostered for today, so no handset was alerted.');
});

it('counts the handsets it pushed as handsets, and never as that many officers', function () {
    Notification::fake();
    config(['push.enabled' => true]);
    Bus::fake([SendExpoPush::class]);

    $head = honestMailHead($this);
    honestMailOfficer($this, 'Officer Two Phones', pushTokens: 2);

    $alert = honestMailPanic($this);
    expect($alert->fresh()->alerted_handsets)->toBe(2);

    $mail = honestMailFor($head);
    $text = honestMailText($mail);

    expect((string) $mail->subject)->toBe('A panic alert at Phoenix Park')
        ->and($text)->toContain('Pushed to 2 handsets of the 1 officer on shift or rostered today.')
        ->and($text)->not->toContain('2 officer');
});

/* ── B093 · a button that opens something ────────────────────────────── */

it('links each recipient to the alert in their own console, and follows the link as them', function () {
    Notification::fake();

    $head = honestMailHead($this);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $estateHead = FacilitiesFixture::viewer(Role::ESTATE_HEAD_OF_SECURITY);

    $alert = honestMailPanic($this);

    // Gemini's Head of Security: the alert's own response screen.
    $gemini = honestMailFor($head);
    expect($gemini->actionText)->toBe('Open the alert')
        ->and($gemini->actionUrl)->toBe(url('/dispatch/alerts/'.$alert->id));

    $this->actingAs($head)->get($gemini->actionUrl)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Gemini/Dispatch/Alert'));
    FacilitiesFixture::boot();

    // The estate's own people: their estate's Security screen, on their estate's own host.
    foreach ([$president, $estateHead] as $member) {
        $mail = honestMailFor($member);

        expect($mail->actionUrl)->toBe(ConsoleHome::estateUrl($this->site).'/security')
            ->and($mail->actionText)->toBe('Open the estate\'s Security screen');

        app('auth')->forgetGuards();
        $this->actingAs($member)->get($mail->actionUrl)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Security/Index'));
        FacilitiesFixture::boot();
    }
});

it('offers no button to a recipient whose console cannot open the alert at all', function () {
    Notification::fake();

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    honestMailPanic($this);

    $mail = honestMailFor($treasurer);

    expect($mail->actionUrl)->toBeNull()
        ->and($mail->actionText)->toBeNull()
        ->and(honestMailText($mail))->not->toContain('Open the alert');

    // Which is the truth: the estate's Security screen is closed to them.
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('security'))->assertForbidden();
    FacilitiesFixture::boot();
});
