<?php

declare(strict_types=1);

use App\Mail\ResilientSmtpTransport;
use App\Models\Role;
use App\Services\Platform\MailFailures;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An email is tried again before the platform gives up, and a give-up is where an admin sees it — M12 §5.1
|--------------------------------------------------------------------------
|
| "25 Sep 18:58 UTC, connection to Hostinger timed out … a code that never arrives locks someone out. Add a retry
| with backoff, and surface failures where an admin can see them rather than only in the log."
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A sign-in code, as the platform sends one. */
function retryMessage(): Email
{
    return (new Email)->from('noreply@geminisl.com')->to('someone.real@gmail.com')->subject('Your GeminiSecure sign-in code')->text('123456');
}

/** A transport that fails the first `$failures` times it is asked, then sends — and says how it was built each time. */
function flakyRelay(int $failures, array &$built): Closure
{
    return static function (bool $ipv4) use ($failures, &$built): TransportInterface {
        $built[] = $ipv4;
        $turn = count($built);

        return new class($turn <= $failures) implements TransportInterface
        {
            public function __construct(private readonly bool $fail) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->fail) {
                    throw new TransportException('Connection could not be established with host "ssl://smtp.hostinger.com:465": stream_socket_client(): Unable to connect (Connection timed out)');
                }

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'flaky://';
            }
        };
    };
}

it('tries again — by the relay\'s IPv4 address — and a send that succeeds the second time is not a failure', function () {
    $built = [];
    $slept = [];
    $givenUp = 0;

    $transport = new ResilientSmtpTransport(
        ['host' => 'smtp.hostinger.com', 'port' => 465, 'ipv4_first' => false],
        static function () use (&$givenUp): void { $givenUp++; },
        flakyRelay(1, $built),
        static function (int $seconds) use (&$slept): void { $slept[] = $seconds; },
    );

    expect($transport->send(retryMessage()))->toBeInstanceOf(SentMessage::class)
        ->and($built)->toBe([false, true])
        ->and($slept)->toBe([1])
        ->and($givenUp)->toBe(0)
        ->and(DB::connection('mysql')->table('mail_failures')->count())->toBe(0);
});

it('never keeps a code: a failed sign-in email\'s subject is recorded with its digits dotted out (M18G)', function () {
    $message = (new Email)->from('noreply@geminisl.com')->to('someone.real@gmail.com')->subject('444450 is your Phoenix Park Village 1 sign-in code')->text('444450');

    app(MailFailures::class)->record($message, null, 3, new TransportException('Connection timed out'), 'smtp.hostinger.com:465');

    expect(DB::connection('mysql')->table('mail_failures')->sole()->subject)->toBe('•••••• is your Phoenix Park Village 1 sign-in code')
        ->and(MailFailures::withoutCodes('Invoice INV-2026-0042 for $1,240.00'))->toBe('Invoice INV-2026-0042 for $1,240.00')
        ->and(app(MailFailures::class)->recent()[0]['subject'])->not->toContain('444450');
});

it('goes by IPv4 from the FIRST attempt unless told otherwise, so a dead IPv6 route cannot eat the whole budget (M18G)', function () {
    $built = [];

    $transport = new ResilientSmtpTransport(
        ['host' => 'smtp.hostinger.com', 'port' => 465],
        static function (): void {},
        flakyRelay(0, $built),
        static function (int $seconds): void {},
    );

    expect($transport->send(retryMessage()))->toBeInstanceOf(SentMessage::class)
        ->and($built)->toBe([true])
        ->and(config('mail.mailers.smtp.ipv4_first'))->toBeTrue();
});

it('gives up after three attempts, records it — masked — and still throws, so the caller says it was not sent', function () {
    $built = [];
    $slept = [];

    $transport = new ResilientSmtpTransport(
        ['host' => 'smtp.hostinger.com', 'port' => 465, 'ipv4_first' => false],
        static function (...$failure): void { app(MailFailures::class)->record(...$failure); },
        flakyRelay(99, $built),
        static function (int $seconds) use (&$slept): void { $slept[] = $seconds; },
    );

    expect(fn () => $transport->send(retryMessage()))->toThrow(TransportException::class);

    expect($built)->toBe([false, true, true])->and($slept)->toBe(ResilientSmtpTransport::BACKOFF);

    $row = DB::connection('mysql')->table('mail_failures')->sole();
    expect($row->subject)->toBe('Your GeminiSecure sign-in code')
        ->and($row->recipients)->toBe('so***@gm***.com')
        ->and((int) $row->attempts)->toBe(3)
        ->and($row->relay)->toBe('smtp.hostinger.com:465')
        ->and($row->reason)->toContain('The relay did not answer in time')
        ->and($row->reason)->not->toContain('someone.real')
        ->and($row->reason)->not->toContain('123456');
});

it('keeps its whole retry inside what a waiting phone allows', function () {
    // Three tries and their waits must fit before the Resident App gives up at fifteen seconds.
    expect(array_sum(ResilientSmtpTransport::BACKOFF))->toBeLessThan(ResilientSmtpTransport::BUDGET_SECONDS)
        ->and(ResilientSmtpTransport::BUDGET_SECONDS)->toBeLessThan(15);
});

it('is the platform\'s smtp transport itself, whatever relay the settings name', function () {
    config(['mail.mailers.smtp.host' => 'smtp.hostinger.com', 'mail.mailers.smtp.port' => 465]);
    Mail::purge('smtp');

    $transport = Mail::mailer('smtp')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ResilientSmtpTransport::class)
        ->and((string) $transport)->toBe('smtp+retry://smtp.hostinger.com:465');
});

it('keeps the record of who was not sent an email for good', function () {
    app(MailFailures::class)->record(retryMessage(), null, 3, new TransportException('Connection timed out'), 'smtp.hostinger.com:465');
    $id = DB::connection('mysql')->table('mail_failures')->value('id');

    expect(fn () => DB::connection('mysql')->table('mail_failures')->where('id', $id)->update(['subject' => 'tidied']))->toThrow(QueryException::class);
    expect(fn () => DB::connection('mysql')->table('mail_failures')->where('id', $id)->delete())->toThrow(QueryException::class);
});

it('lists what could not be sent on Email settings, and says so on the bell', function () {
    app(MailFailures::class)->record(retryMessage(), null, 3, new TransportException('Connection timed out'), 'smtp.hostinger.com:465');
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($director)->get('/settings/email')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Gemini/Settings/Email')
        ->has('failures', 1)
        ->where('failures.0.subject', 'Your GeminiSecure sign-in code')
        ->where('failures.0.recipients', 'so***@gm***.com')
        ->where('failures.0.attempts', 3));

    $bell = app(\App\Services\Gemini\PlatformNotifications::class)->attention($director);
    $item = collect($bell['items'])->firstWhere('href', '/settings/email');

    expect($item)->not->toBeNull()
        ->and($item['title'])->toBe('1 email could not be sent in the last day')
        ->and($item['detail'])->toBe('Latest: "Your GeminiSecure sign-in code" to so***@gm***.com');
});

it('records a resident\'s sign-in code the relay would not take, and the answer still says it was not sent', function () {
    // A relay that is not there: every attempt is refused at once. No waiting between tries in a test.
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    Mail::extend('smtp', static fn (array $config): ResilientSmtpTransport => new ResilientSmtpTransport(
        $config,
        static function (...$failure): void { app(MailFailures::class)->record(...$failure); },
        null,
        static function (): void {},
    ));
    Mail::purge('smtp');

    $answer = $this->postJson('/api/v1/auth/otp/request', [
        'estate' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'locked.out.'.bin2hex(random_bytes(3)).'@example.com',
    ]);

    expect($answer->status())->toBe(503)->and($answer->json('error.code'))->toBe('mail_unavailable');

    $row = DB::connection('mysql')->table('mail_failures')->orderByDesc('id')->first();
    expect($row)->not->toBeNull()
        ->and((int) $row->attempts)->toBe(3)
        ->and($row->recipients)->toMatch('/^lo\*\*\*@ex\*\*\*\.com$/');
});
