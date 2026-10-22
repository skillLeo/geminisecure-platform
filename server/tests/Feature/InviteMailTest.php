<?php

declare(strict_types=1);

use App\Models\Invitation;
use App\Models\Role;
use App\Notifications\InvitationSent;
use App\Services\Estate\ResidentInvites;
use App\Services\InvitationNotSent;
use App\Services\Invitations;
use App\Support\InviteMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as MailNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| One delivery path for every invitation (SPEC l.26, DESIGN §4.1, G10 — T-MAIL-2)
|--------------------------------------------------------------------------
|
| Stage 1 made resident invites honest: sent synchronously, "sent" only when the mail server took it, a
| refusal in words, and "mail is not set up" on a production server whose mailer delivers nowhere. Committee,
| staff and onboarding invitations went another way — `notify`, inside the transaction — so a refusal was a
| server error page and a resend that failed had already killed the link. The rule is now ONE class,
| `InviteMail`, and `Invitations` goes through it; this file holds the rule itself.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
});

/** The real mail channel, pointed at a port nothing listens on: the relay that refuses. */
function inviteMailRefusingRelay(): void
{
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
    app('mail.manager')->forgetMailers();
}

/** Run `$then` as a production server whose mailer is `log` — mail that goes nowhere. */
function inviteMailOnProductionLog(callable $then): mixed
{
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'log']);
    app('mail.manager')->forgetMailers();
    $was = app()->environment();
    app()->instance('env', 'production');

    try {
        return $then();
    } finally {
        app()->instance('env', $was);
    }
}

function inviteMailNote(): MailNotification
{
    return new class extends MailNotification
    {
        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('An invitation')->line('Hello.');
        }
    };
}

it('says "not set up" on a production server whose mail goes nowhere, and tries nothing', function () {
    Notification::fake();

    $answer = inviteMailOnProductionLog(fn () => InviteMail::deliver('nobody@invitemail.test', inviteMailNote()));

    expect($answer)->toBe(InviteMail::NOT_SET_UP)
        // Stage 1's own sentence, so a resident invite and a committee invitation say it the same way.
        ->and(InviteMail::NOT_SET_UP)->toBe(ResidentInvites::NOT_SET_UP);
});

it('answers a relay that refuses with the refusal in words, and logs that sentence — never the exception\'s own text', function () {
    inviteMailRefusingRelay();
    Log::spy();

    $answer = InviteMail::deliver('refused@invitemail.test', inviteMailNote(), ['invitation' => 42]);

    expect($answer)->toContain('Nothing answered at that host and port');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($answer): bool {
        return $message === 'An invitation was not delivered.'
            && $context === ['invitation' => 42, 'reason' => $answer]
            && ! str_contains(json_encode($context), '127.0.0.1')
            && ! str_contains(json_encode($context), 'refused@invitemail.test');
    })->once();
});

it('answers null when the mail server took it, and sends synchronously even a message that would otherwise queue', function () {
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'array']);
    app('mail.manager')->forgetMailers();
    Queue::fake();

    $queued = new class extends MailNotification implements ShouldQueue
    {
        use Queueable;

        /** @return list<string> */
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('Queued unless sent now')->line('Hello.');
        }
    };

    expect(InviteMail::deliver('taken@invitemail.test', $queued))->toBeNull();

    // Accepted by the mailer in this request, not left on a queue to be "sent" later.
    Queue::assertNothingPushed();
    $sent = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
    expect($sent)->toHaveCount(1)
        ->and($sent->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('taken@invitemail.test');
});

it('is the path Invitations::invite and resend take: "not set up" on production, the relay\'s words when it refuses', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $invitations = app(Invitations::class);

    // Not set up: the invitation is saved, unsent, and the reason is InviteMail's.
    $unsent = inviteMailOnProductionLog(function () use ($invitations, $admin): ?InvitationNotSent {
        try {
            $invitations->invite('prod.log@invitemail.test', Role::named(Role::SECRETARY), FacilitiesFixture::ESTATE, $admin);
        } catch (InvitationNotSent $e) {
            return $e;
        }

        return null;
    });

    expect($unsent)->not->toBeNull()
        ->and($unsent->reason)->toBe(InviteMail::NOT_SET_UP)
        ->and($unsent->state())->toBe('not_set_up');

    $row = Invitation::query()->where('email', 'prod.log@invitemail.test')->sole();
    expect($row->sent_at)->toBeNull()->and($row->attempts)->toBe(1)->and($row->last_error)->toBe(InviteMail::NOT_SET_UP);

    // A resend a minute on, through the refusing relay: the relay's words.
    Carbon::setTestNow(now()->addSeconds(61));
    inviteMailRefusingRelay();

    try {
        $invitations->resend($row->fresh(), $admin);
        $this->fail('A refused resend must not read as sent.');
    } catch (InvitationNotSent $e) {
        expect($e->resend)->toBeTrue()
            ->and($e->reason)->toContain('Nothing answered at that host and port')
            ->and($e->state())->toBe('not_sent');
    }

    // And when the relay takes it, Invitations hands InviteMail the invitation's own email.
    Notification::fake();
    Carbon::setTestNow(now()->addSeconds(61));
    $sent = $invitations->resend($row->fresh(), $admin);

    expect($sent->sent_at)->not->toBeNull()->and($sent->last_error)->toBeNull();
    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail, array $channels, object $to): bool => ($to->routes['mail'] ?? null) === 'prod.log@invitemail.test'
        && str_contains($mail->url(), '/invitations/'.$sent->token));
});

it('is the path a resident\'s invite to the app takes too: "not set up" on production, the relay\'s words when it refuses (G10)', function () {
    FacilitiesFixture::boot();
    $unit = FacilitiesFixture::scratchUnit('Lot 8877');
    $household = App\Models\Estate\Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Invite mail household', 'access_restricted' => false]);
    $office = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $person = function (string $email) use ($household): App\Models\Estate\Resident {
        $resident = new App\Models\Estate\Resident;
        $resident->forceFill(['household_id' => $household->id, 'full_name' => 'Invite Mail Person', 'email' => $email, 'relationship' => 'child', 'is_primary' => false, 'status' => App\Models\Estate\Resident::PENDING])->save();

        return $resident;
    };
    $send = fn (App\Models\Estate\Resident $resident) => App\Models\Tenant::query()->findOrFail(FacilitiesFixture::ESTATE)
        ->run(fn () => app(ResidentInvites::class)->send($resident, $office->name, null, $office));

    try {
        // Not set up: nothing is tried, and the row says so.
        $unset = $person('resident.prod.log.'.bin2hex(random_bytes(3)).'@invitemail.test');
        $invite = inviteMailOnProductionLog(fn () => $send($unset));
        FacilitiesFixture::boot();

        expect($invite->sent_at)->toBeNull()
            ->and($invite->last_error)->toBe(InviteMail::NOT_SET_UP)
            ->and(ResidentInvites::outcome($invite)['status'])->toBe('not_sent');

        // The refusing relay: its words, and still no `sent_at`.
        inviteMailRefusingRelay();
        $refused = $send($person('resident.refused.'.bin2hex(random_bytes(3)).'@invitemail.test'));
        FacilitiesFixture::boot();

        expect($refused->sent_at)->toBeNull()
            ->and($refused->last_error)->toContain('Nothing answered at that host and port');
    } finally {
        FacilitiesFixture::boot();
        $made = App\Models\Estate\Resident::withRemoved()->where('household_id', $household->id)->pluck('id');
        DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $made)->delete();
        DB::connection('tenant')->table('residents')->whereIn('id', $made)->delete();
        DB::connection('tenant')->table('households')->where('id', $household->id)->delete();
    }
});
