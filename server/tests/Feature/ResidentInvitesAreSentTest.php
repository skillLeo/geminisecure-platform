<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Notifications\ResidentInviteSent;
use App\Services\Estate\ResidentInvites;
use App\Services\Estate\Residents;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| An invite to the Resident App is SENT, and says whether it went
|--------------------------------------------------------------------------
|
| The user, 24 September 2026: "when a household member is invited he gets no email, it's a bug of mail
| sending". Nothing sent it: the Resident App's "add a household member" saved the member and stopped, and the
| office's Add resident wrote an invite row whose own docblock said "there is no mail driver on this path yet".
| The app's result screen said "Sent … Confirmed" all the same.
|
| Now an invite is emailed through the platform's own mail path, `sent_at` is stamped only when the mail server
| accepted it, a refusal is kept in words, and Resend waits 1, then 2, then 5 minutes.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and every member it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8871');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Fletcher household', 'access_restricted' => false]);

    $primary = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Invite Andrea')->first();
    if ($primary === null) {
        $primary = new Resident;
        $primary->forceFill(['household_id' => $this->household->id, 'full_name' => 'Invite Andrea', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }
    $this->household->residents()->whereKeyNot($primary->id)->update(['is_primary' => false]);
    $primary->forceFill(['is_primary' => true])->save();
    $this->primary = $primary;

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'andrea.'.bin2hex(random_bytes(3)).'@invites.test',
        'full_name' => 'Invite Andrea', 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'resident_id' => $primary->id, 'unit_id' => $this->unit->id, 'device_uid' => 'invite-install-1', 'device_platform' => 'android',
    ]);
    $this->token = $account->createToken('resident-app:invite-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    // Everybody this file added: the household's members other than its own primary, and their invites.
    $added = Resident::withRemoved()->where('household_id', $this->household->id)->whereKeyNot($this->primary->id)->pluck('id');
    DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $added)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $added)->delete();
});

/** @param array<string, scalar> $params @param array<string, mixed> $body */
function inviteCall(TestCase $test, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'inv-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], $uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('emails the invite when the primary adds a member, to that address, saying which home and how to sign in', function () {
    $email = 'soft.dev.'.bin2hex(random_bytes(3)).'@invites.test';

    $added = inviteCall($this, 'members.store', [], ['full_name' => 'Soft Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => $email]);

    expect($added->json('status'))->toBe('pending')
        ->and($added->json('invite.status'))->toBe('sent')
        ->and($added->json('invite.email'))->toBe($email)
        ->and($added->json('invite.sent_at'))->not->toBeNull()
        ->and($added->json('invite.attempts'))->toBe(1)
        ->and($added->json('invite.resend_after'))->not->toBeNull(); // the timer has started

    Notification::assertSentOnDemand(ResidentInviteSent::class, function (ResidentInviteSent $n, array $channels, object $notifiable) use ($email): bool {
        $mail = $n->toMail($notifiable);
        $text = implode(' ', array_map('strval', [...$mail->introLines, ...$mail->outroLines]));

        // The home is NOT named (stage 2, DESIGN INV-14): whoever holds the address has not proven it yet.
        return ($notifiable->routes['mail'] ?? null) === $email
            && $n->memberName === 'Soft Dev'
            && $n->invitedBy === 'Invite Andrea'
            && ! str_contains($text, 'Lot 8871')
            // With the link that opens the app on the address (M18G §5) the step says "sign in with <address>".
            && (str_contains($text, 'sign in with this email address, '.$email) || str_contains($text, 'sign in with '.$email))
            && str_contains($text, 'one-time code')
            && str_contains($text, 'there is no form to fill in');
    });
});

it('says it was NOT sent when the mail server refuses, in words, and never stamps sent_at', function () {
    // The real mail channel, not the fake: `Notification::fake()` replaced the container's manager as well.
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);

    $added = inviteCall($this, 'members.store', [], ['full_name' => 'Refused Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'refused.'.bin2hex(random_bytes(3)).'@invites.test']);

    expect($added->json('invite.status'))->toBe('not_sent')
        ->and($added->json('invite.sent_at'))->toBeNull()
        ->and($added->json('invite.reason'))->toContain('Nothing answered at that host and port');

    $invite = ResidentInvite::query()->where('resident_id', $added->json('id'))->firstOrFail();
    expect($invite->sent_at)->toBeNull()->and($invite->attempts)->toBe(1)->and($invite->last_error)->toContain('Nothing answered');
});

it('resends only when the timer has run out — 1 minute, then 2, then 5 — with a fresh token each time', function () {
    // From now, not a fixed date: the whole walk is eight minutes, well inside the session's idle window.
    $now = Carbon::now()->startOfSecond();
    Carbon::setTestNow($now);

    $added = inviteCall($this, 'members.store', [], ['full_name' => 'Timer Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'timer.'.bin2hex(random_bytes(3)).'@invites.test']);
    $id = $added->json('id');
    $firstToken = ResidentInvite::query()->where('resident_id', $id)->value('token');

    // Straight away: refused, saying when.
    $wait = inviteCall($this, 'members.invite', ['member' => $id], [], 409);
    expect($wait->json('error.code'))->toBe('invite_wait')->and($wait->json('error.message'))->toContain('You can send it again at');
    // The estate's own time and no zone after it (Law 5 as decided, DR-039): "… again at 7:18 PM.", never "7:18 PM JA.".
    expect(preg_match('/ again at \d{1,2}:\d{2} [AP]M\.$/', (string) $wait->json('error.message')))->toBe(1, (string) $wait->json('error.message'));

    // After a minute: it goes, as the second send, with a new token.
    Carbon::setTestNow($now->copy()->addSeconds(61));
    $second = inviteCall($this, 'members.invite', ['member' => $id]);
    expect($second->json('invite.status'))->toBe('sent')->and($second->json('invite.attempts'))->toBe(2);
    expect(ResidentInvite::query()->where('resident_id', $id)->value('token'))->not->toBe($firstToken);

    // Now it is two minutes.
    Carbon::setTestNow($now->copy()->addSeconds(61 + 90));
    inviteCall($this, 'members.invite', ['member' => $id], [], 409);
    Carbon::setTestNow($now->copy()->addSeconds(61 + 121));
    expect(inviteCall($this, 'members.invite', ['member' => $id])->json('invite.attempts'))->toBe(3);

    // And then five.
    Carbon::setTestNow($now->copy()->addSeconds(61 + 121 + 240));
    inviteCall($this, 'members.invite', ['member' => $id], [], 409);
    Carbon::setTestNow($now->copy()->addSeconds(61 + 121 + 301));
    expect(inviteCall($this, 'members.invite', ['member' => $id])->json('invite.attempts'))->toBe(4);

    expect(ResidentInvites::COOLDOWN_SECONDS)->toBe([60, 120, 300])
        ->and(ResidentInvites::cooldownAfter(9))->toBe(300);

    // One row for the person, however often it was sent: never two live invites.
    expect(ResidentInvite::query()->where('resident_id', $id)->count())->toBe(1);
});

it('is the primary resident\'s alone, and a cancelled or removed member\'s invite stops standing', function () {
    $added = inviteCall($this, 'members.store', [], ['full_name' => 'Cancel Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'cancel.'.bin2hex(random_bytes(3)).'@invites.test']);
    $id = $added->json('id');

    $this->primary->forceFill(['is_primary' => false])->save();
    inviteCall($this, 'members.invite', ['member' => $id], [], 403);
    inviteCall($this, 'members.invite_cancel', ['member' => $id], [], 403);
    $this->primary->forceFill(['is_primary' => true])->save();

    expect(inviteCall($this, 'members.invite_cancel', ['member' => $id])->json('invite.status'))->toBe('cancelled');

    $removed = inviteCall($this, 'members.store', [], ['full_name' => 'Removed Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'removed.'.bin2hex(random_bytes(3)).'@invites.test']);
    inviteCall($this, 'members.destroy', ['member' => $removed->json('id')]);
    expect(ResidentInvite::query()->where('resident_id', $removed->json('id'))->value('cancelled_at'))->not->toBeNull();
});

it('an app that predates invites still has them sent when it gives an email, and may say not to', function () {
    $quiet = inviteCall($this, 'members.store', [], ['full_name' => 'Quiet Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'quiet.'.bin2hex(random_bytes(3)).'@invites.test', 'invite' => false]);
    expect($quiet->json('invite.status'))->toBe('none');
    Notification::assertNothingSent();
});

it('the office\'s Add resident sends it too, and a Pending resident can be sent it again', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $email = 'office.'.bin2hex(random_bytes(3)).'@invites.test';

    // Lot 8871 has a household already, so the office says what the person is (M32 F1): a member of it.
    $resident = app(Residents::class)->add($this->unit->block ?? 'Phase 1', 'Lot 8871', 'Office Invitee', $email, null, 'invite', false, $manager, householdChoice: 'member');

    $invite = ResidentInvite::query()->where('resident_id', $resident->id)->latest('id')->firstOrFail();
    expect(ResidentInvites::outcome($invite)['status'])->toBe('sent')->and($invite->email)->toBe($email)->and($invite->invited_by_name)->toBe($manager->name);
    Notification::assertSentOnDemand(ResidentInviteSent::class, fn (ResidentInviteSent $n, array $c, object $to): bool => ($to->routes['mail'] ?? null) === $email && $n->invitedBy === $manager->name);
});

it('the office can resend it from the household page, not faster than the timer, and sees what became of it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $email = 'page.'.bin2hex(random_bytes(3)).'@invites.test';
    $resident = app(Residents::class)->add($this->unit->block ?? 'Phase 1', 'Lot 8871', 'Page Invitee', $email, null, 'invite', false, $manager, householdChoice: 'member');
    $slug = $this->unit->slug();
    $path = FacilitiesFixture::url('/residents/'.$slug.'/people/'.$resident->id.'/invite');

    // The household page lists them with their invite as it stands, and whether this viewer may resend.
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/'.$slug))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canInvite', true)
            ->where('people', fn ($people) => collect($people)->contains(fn ($p) => $p['id'] === $resident->id && $p['is_pending'] === true && $p['invite']['status'] === 'sent')));

    // Just sent: refused, with when.
    $this->actingAs($manager)->post($path)->assertSessionHasErrors('invite');

    // A minute on: it goes, and the message says where.
    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    $this->actingAs($manager)->post($path)->assertRedirect()->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'Invite emailed to '.$email));
    expect(ResidentInvite::query()->where('resident_id', $resident->id)->value('attempts'))->toBe(2);

    // And cancelled from the same page.
    $this->actingAs($manager)->post($path.'/cancel')->assertRedirect();
    expect(ResidentInvites::outcome(app(ResidentInvites::class)->latestFor($resident))['status'])->toBe('cancelled');
});

it('reads every member\'s latest invite in one query, exactly the one latestFor() reads for each (M11 A2)', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $service = app(ResidentInvites::class);
    $residents = app(Residents::class);

    // One sent once; one sent, cancelled and sent again (two rows, the second the latest); one never invited.
    $once = $residents->add($this->unit->block ?? 'Phase 1', 'Lot 8871', 'Once Invitee', 'once.'.bin2hex(random_bytes(3)).'@invites.test', null, 'invite', false, $manager, householdChoice: 'member');
    $twice = $residents->add($this->unit->block ?? 'Phase 1', 'Lot 8871', 'Twice Invitee', 'twice.'.bin2hex(random_bytes(3)).'@invites.test', null, 'invite', false, $manager, householdChoice: 'member');
    $service->cancel($twice, $manager->name, $manager);
    $service->send($twice->fresh(), $manager->name, null, $manager);
    $never = $residents->add($this->unit->block ?? 'Phase 1', 'Lot 8871', 'Never Invitee', null, null, 'manual', false, $manager, householdChoice: 'member');

    expect(ResidentInvite::query()->where('resident_id', $twice->id)->count())->toBe(2);

    $members = Resident::query()->where('household_id', $this->household->id)->orderBy('id')->get()->all();
    $expected = [];

    foreach ($members as $member) {
        if (($latest = $service->latestFor($member)) !== null) {
            $expected[$member->id] = $latest->id;
        }
    }

    DB::connection('tenant')->enableQueryLog();
    DB::connection('tenant')->flushQueryLog();
    $each = $service->latestForEach($members);
    $reads = count(DB::connection('tenant')->getQueryLog());
    DB::connection('tenant')->disableQueryLog();

    expect(array_map(static fn (ResidentInvite $invite): int => $invite->id, $each))->toEqual($expected)
        ->and($each)->not->toHaveKey($never->id)
        ->and($each[$twice->id]->cancelled_at)->toBeNull()
        ->and($reads)->toBe(1)
        ->and($service->latestForEach([]))->toBe([]);

    // And the household page draws each member's invite from it, as `latestFor()` would.
    $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))->assertOk()
        ->assertInertia(fn ($page) => $page->where('people', function ($people) use ($service, $members): bool {
            foreach ($members as $member) {
                $row = collect($people)->firstWhere('id', $member->id);

                // The office's copy carries one more key, `office_reason` (stage 2); the rest is the outcome exactly.
                if ($row === null || Arr::except($row['invite'], 'office_reason') !== ResidentInvites::outcome($service->latestFor($member))) {
                    return false;
                }
            }

            return true;
        }));
});

it('says in the office\'s message after Add resident whether the invite went', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $email = 'flash.'.bin2hex(random_bytes(3)).'@invites.test';

    // Nothing is optional (M18C): a phone for everybody, and an email or the tick that says there is none.
    $this->actingAs($manager)->post(FacilitiesFixture::url('/residents'), [
        'phase' => $this->unit->block ?? 'Phase 1', 'lot' => 'Lot 8871', 'full_name' => 'Flash Invitee', 'email' => $email, 'phone' => '876 555 0142', 'verification' => 'invite',
        // Lot 8871 has a household already (M32 F1): the person joins it as a member.
        'household_choice' => 'member',
    ])->assertRedirect()->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'as Pending') && str_contains($s, 'Invite emailed to '.$email.' just now'));

    // Somebody with no email cannot be invited at all now (M18C §3.2): the form says so before anything is added,
    // where it used to add them and report that no invite went.
    $this->actingAs($manager)->post(FacilitiesFixture::url('/residents'), [
        'phase' => $this->unit->block ?? 'Phase 1', 'lot' => 'Lot 8871', 'full_name' => 'No Address Invitee', 'phone' => '876 555 0143', 'no_email' => true, 'verification' => 'invite',
        'household_choice' => 'member',
    ])->assertRedirect()->assertSessionHasErrors(['verification' => 'Somebody with no email cannot be sent an invite. Vouch for them instead.'])->assertSessionMissing('success');
});

it('says a person the office vouched for went on the register as Active, never as Pending', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    // Seen on the local app (M98 final pass, 12 Oct): "Mark verified now" said "added … as Pending" of a verified person.
    $this->actingAs($manager)->post(FacilitiesFixture::url('/residents'), [
        'phase' => $this->unit->block ?? 'Phase 1', 'lot' => 'Lot 8871', 'full_name' => 'Vouched Invitee', 'phone' => '876 555 0144', 'no_email' => true, 'verification' => 'manual',
        'household_choice' => 'member',
    ])->assertRedirect()->assertSessionHas('success', fn (string $s): bool => str_contains($s, 'Vouched Invitee added to')
        && str_contains($s, ' as Active. You vouched for them, so no invite is needed.')
        && ! str_contains($s, 'Pending'));

    expect(Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Vouched Invitee')->value('status'))->toBe(Resident::VERIFIED);
});

it('on a production server whose mail goes nowhere, says mail is not set up and who can set it up', function () {
    Notification::swap(new ChannelManager(app()));
    config(['mail.default' => 'log']);
    $was = app()->environment();
    app()->instance('env', 'production');

    try {
        $added = inviteCall($this, 'members.store', [], ['full_name' => 'Nowhere Dev', 'relationship' => 'child', 'phone' => '876 555 0100', 'email' => 'nowhere.'.bin2hex(random_bytes(3)).'@invites.test']);
    } finally {
        app()->instance('env', $was);
    }

    expect($added->json('invite.status'))->toBe('not_sent')
        ->and($added->json('invite.reason'))->toBe(ResidentInvites::NOT_SET_UP)
        ->and($added->json('invite.sent_at'))->toBeNull();
});
