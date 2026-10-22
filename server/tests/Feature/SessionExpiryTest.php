<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Guard;
use App\Models\PersonalAccessToken;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Support\SessionWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiContract;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Signing out, and the thirty-minute idle window — M6, Q-M42
|--------------------------------------------------------------------------
|
| The client's ruling, verbatim: "Automatically logs him out within 30 minutes."
|
| Two things were wrong and both are fixed here. "Sign out" signed nobody out of
| the server — the phone dropped its own copy and the row lived for ever — and no
| token expired at all, so a token ever copied off a handset worked for ever.
|
| THE WINDOW SLIDES, and these tests are the argument for that reading. A request
| inside the window renews it, so a guard who is working is never interrupted; an
| absolute thirty minutes would have signed the same guard out twenty-four times
| in a twelve-hour watch, each time needing a one-time enrolment code from an
| office that is shut. See `App\Support\SessionWindow`, DECISIONS D-M126, and the
| hazard that survives it, Q-M46.
|
| The fixtures are this file's own — deliberately the smallest thing that can hold
| a token, so nothing here depends on an estate database or on which suite ran
| before it. Every request below carries a REAL bearer token, because
| `Sanctum::actingAs()` sets the guard's user directly and never reaches the hook
| under test.
|
*/

function idleEstate(): string
{
    $id = 'idletest';

    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Idle Window Estate', 'data' => json_encode(['name' => 'Idle Window Estate']), 'created_at' => now(), 'updated_at' => now()],
    );

    return $id;
}

function idleGuard(): Guard
{
    static $n = 0;
    $n++;

    return Guard::create([
        'full_name' => 'Idle Test Guard '.$n,
        'employee_number' => 'GS-IDLE-'.$n,
        'psra_number' => 'PSRA-IDLE-'.$n,
        'psra_expires_on' => now()->addYear(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => idleEstate(),
    ]);
}

/** A guard with a handset enrolled, and the bearer token it speaks with. */
function idleHandset(): array
{
    $guard = idleGuard();

    return ['guard' => $guard, 'token' => $guard->createToken('Idle test handset', AppMatrix::abilitiesFor(AppMatrix::GUARD))->plainTextToken];
}

/**
 * A Resident App sign-in.
 *
 * `resident_id` and `unit_id` are set because `ResidentAccount::isActive()` reads all
 * three, and an account that is not active is reconciled against its ESTATE's database
 * on every request — which this file deliberately does not have. Nothing below reads
 * either id; they are the stamp that says "this claim was approved", nothing more.
 */
function idleResident(): array
{
    static $n = 0;
    $n++;

    $account = ResidentAccount::query()->create([
        'tenant_id' => idleEstate(),
        'channel' => 'email',
        'destination' => 'idle.'.$n.'@example.com',
        'full_name' => 'Idle Test Resident '.$n,
        'resident_id' => $n,
        'unit_id' => $n,
        'status' => ResidentAccount::ACTIVE,
        'verified_at' => now(),
    ]);

    return ['account' => $account, 'token' => $account->createToken('resident-app:idle', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
}

/** The Guard App dashboard's own poll — a read that touches central tables only. */
function idlePoll(Tests\TestCase $test, string $token): Illuminate\Testing\TestResponse
{
    app('auth')->forgetGuards();

    return $test->withToken($token)->getJson('/api/v1/shifts/me/current');
}

/* ── The arithmetic, on its own ─────────────────────────────────────────── */

it('counts the window from the last request, not from the moment the token was issued', function () {
    $issued = Carbon::parse('2026-09-21T01:00:00Z');

    // Never presented: its issue time stands in for a last use it does not have.
    expect(SessionWindow::lastTouched(null, $issued)->toIso8601String())->toBe($issued->toIso8601String());

    // Presented: the LAST use wins, however old the token itself is.
    $used = $issued->copy()->addHours(6);
    expect(SessionWindow::lastTouched($used, $issued)->toIso8601String())->toBe($used->toIso8601String());

    /*
     * THE SLIDING READING, STATED AS A NUMBER. A token issued at 01:00 and used at 07:00
     * is alive at 07:29 and dead at 07:30 — six and a half hours after it was minted.
     * Under an ABSOLUTE thirty minutes it would have died at 01:30, mid-watch.
     */
    expect(SessionWindow::expired($used, $used->copy()->addMinutes(29), 30))->toBeFalse()
        ->and(SessionWindow::expired($used, $used->copy()->addMinutes(30), 30))->toBeTrue()
        ->and(SessionWindow::expired($used, $used->copy()->addMinutes(31), 30))->toBeTrue()
        ->and(SessionWindow::closesAt($used, 30)->toIso8601String())->toBe($used->copy()->addMinutes(30)->toIso8601String());

    // A row with no timestamps at all is a damaged record, not evidence of idleness.
    expect(SessionWindow::expired(null, Carbon::now(), 30))->toBeFalse();

    // The ruling's number is what ships, and a nonsensical setting cannot turn it into "expire everything".
    expect(SessionWindow::minutes())->toBe(SessionWindow::DEFAULT_MINUTES)->toBe(30);

    config(['api.session_idle_minutes' => 0]);
    expect(SessionWindow::minutes())->toBe(30);

    config(['api.session_idle_minutes' => 45]);
    expect(SessionWindow::minutes())->toBe(45);
});

/* ── The window, through the API ────────────────────────────────────────── */

it('refuses a token after thirty idle minutes, and says it TIMED OUT rather than that somebody revoked it', function () {
    $h = idleHandset();

    idlePoll($this, $h['token'])->assertOk();

    // Twenty-nine minutes of silence: still the same session, nobody is interrupted.
    $this->travel(29)->minutes();
    idlePoll($this, $h['token'])->assertOk();

    // …and thirty-one from THAT request is over.
    $this->travel(31)->minutes();
    $refused = idlePoll($this, $h['token']);

    $refused->assertUnauthorized()->assertJsonPath('error.code', SessionWindow::ERROR_CODE);

    /*
     * THE CODE IS THE POINT, NOT THE STATUS. `unauthenticated` already means "the office
     * revoked this handset, or it was re-bound to another phone", and the Guard App answers
     * that with "ask for a new enrolment code". A session that merely went quiet is a
     * different fact and gets its own code so the apps can say a different, true thing.
     */
    expect($refused->json('error.code'))->not->toBe('unauthenticated')
        ->and($refused->json('error.message'))->toContain('30 minutes')
        // Read at 2 a.m. on a duress screen: it has to say that nothing was lost.
        ->and($refused->json('error.message'))->toContain('nothing you saved on this phone has been lost');
});

it('gives the same answer every time afterwards, and leaves the row alone so the phone can still be ALERTED', function () {
    $h = idleHandset();
    idlePoll($this, $h['token'])->assertOk();

    $this->travel(31)->minutes();

    // Twice, because the apps poll: the second request must not become "unknown token".
    idlePoll($this, $h['token'])->assertUnauthorized()->assertJsonPath('error.code', SessionWindow::ERROR_CODE);
    idlePoll($this, $h['token'])->assertUnauthorized()->assertJsonPath('error.code', SessionWindow::ERROR_CODE);

    /*
     * AND THE TOKEN ROW IS STILL THERE. `device_push_tokens` rows carry the id of the API
     * token they were registered with, and `PushNotifier` drops any address whose token has
     * gone. Deleting on a TIMEOUT would silence a guard's handset for the site's panic and
     * duress alerts — the opposite of what anyone wants at a gate at 2 a.m. A deliberate
     * sign-out does delete it; see the walk below.
     */
    expect(PersonalAccessToken::query()->where('tokenable_id', $h['guard']->id)->where('tokenable_type', $h['guard']->getMorphClass())->count())->toBe(1);

    // And it never comes back: an expired token cannot renew itself by being presented.
    $this->travel(1)->minutes();
    idlePoll($this, $h['token'])->assertUnauthorized();
});

/** A shift of this guard's at a gate, clocked in an hour ago. */
function idleShift(Guard $guard): Shift
{
    $post = Post::create(['tenant_id' => $guard->tenant_id, 'name' => 'Idle Test Gate '.$guard->id, 'type' => 'gate', 'is_active' => true]);

    return Shift::create([
        'tenant_id' => $guard->tenant_id, 'guard_id' => $guard->id, 'post_id' => $post->id,
        'rostered_start' => now()->subHour(), 'rostered_end' => now()->addHours(11),
        'actual_start' => now()->subHour(), 'status' => 'on_duty',
    ]);
}

/*
 * M22 §1.3, found on the emulator: a guard's phone that sent nothing for half an hour — no signal on a perimeter walk,
 * a send stuck on a dead connection — was signed out in the middle of the watch. Every position it had queued was
 * refused, on-duty sharing stopped, and nothing told anybody. A twelve-hour night shift cannot depend on somebody
 * opening the app every half hour.
 */
it('keeps a guard on shift signed in for the whole shift, however long the phone says nothing', function () {
    $h = idleHandset();
    idleShift($h['guard']);

    expect(idlePoll($this, $h['token'])->assertOk()->json('shift'))->not->toBeNull();

    // Four and a half hours with nothing sent from the phone — nine idle windows over — and it is still signed in.
    $this->travel(270)->minutes();
    idlePoll($this, $h['token'])->assertOk();
    $this->travel(200)->minutes();
    idlePoll($this, $h['token'])->assertOk();
});

it('runs the window from the clock-out once the shift is over, as for any other phone', function () {
    $h = idleHandset();
    $shift = idleShift($h['guard']);
    idlePoll($this, $h['token'])->assertOk();

    // Two hours of silence, then the supervisor clocks the guard out from the console.
    $this->travel(120)->minutes();
    $shift->forceFill(['actual_end' => now(), 'status' => 'completed'])->save();

    // Twenty-nine minutes after the clock-out: still the same session…
    $this->travel(29)->minutes();
    expect(Shift::onDutyUntil((int) $h['guard']->id)?->timestamp)->toBe($shift->refresh()->actual_end->timestamp);
    $this->travel(2)->minutes();

    // …thirty-one after it, over: said as a timed-out session, never as a revocation.
    idlePoll($this, $h['token'])->assertUnauthorized()->assertJsonPath('error.code', SessionWindow::ERROR_CODE);
});

it('does not stretch anybody else\'s window: a guard off duty, and every resident, idle out at thirty minutes', function () {
    $h = idleHandset();
    idlePoll($this, $h['token'])->assertOk();
    $this->travel(31)->minutes();
    idlePoll($this, $h['token'])->assertUnauthorized()->assertJsonPath('error.code', SessionWindow::ERROR_CODE);

    // The arithmetic: a guard's time on duty only ever moves the last touch LATER.
    $used = Carbon::parse('2026-09-21T07:00:00Z');
    expect(SessionWindow::lastTouched($used, null, $used->copy()->subHour())->toIso8601String())->toBe($used->toIso8601String())
        ->and(SessionWindow::lastTouched($used, null, $used->copy()->addHour())->toIso8601String())->toBe($used->copy()->addHour()->toIso8601String())
        ->and(SessionWindow::lastTouched($used, null, null)->toIso8601String())->toBe($used->toIso8601String());
});

it('closes a resident\'s window on exactly the same clock', function () {
    /*
     * One rule for both apps. A resident's phone is personal and a guard's is issued, and
     * the client did not split them — so neither does this. (Whether they SHOULD be split
     * is Q-M46, which is the client's to answer, not ours to pre-empt.)
     *
     * Walked on `DELETE /session` itself because it is the one authenticated endpoint both
     * apps share that reads no estate database — this file has no estate to read.
     */
    $fresh = idleResident();

    app('auth')->forgetGuards();
    $this->withToken($fresh['token'])->deleteJson('/api/v1/session')->assertOk();

    $idle = idleResident();

    $this->travel(31)->minutes();

    app('auth')->forgetGuards();
    $this->withToken($idle['token'])->deleteJson('/api/v1/session')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', SessionWindow::ERROR_CODE);
});

/* ── A real sign-out ────────────────────────────────────────────────────── */

it('destroys the calling token on the server, so the button tells the truth', function () {
    $h = idleHandset();

    idlePoll($this, $h['token'])->assertOk();

    app('auth')->forgetGuards();
    $out = $this->withToken($h['token'])->deleteJson('/api/v1/session');

    $out->assertOk()->assertJsonPath('signed_out', true);
    ApiContract::assertMatches($out, 'auth.sign_out');

    // GONE FROM THE SERVER, not merely from the phone. This is the whole of Q-M42 (a).
    expect(PersonalAccessToken::query()->where('tokenable_id', $h['guard']->id)->where('tokenable_type', $h['guard']->getMorphClass())->count())->toBe(0);

    // A copy of that token — a backup, a cable, a sold phone — is now worth nothing.
    idlePoll($this, $h['token'])->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

    // Signing out twice is not an error; it is a request with no token, answered honestly.
    app('auth')->forgetGuards();
    $this->withToken($h['token'])->deleteJson('/api/v1/session')->assertUnauthorized();
});

it('ends ONE sign-in and nothing else', function () {
    $mine = idleHandset();
    $theirs = idleHandset();

    app('auth')->forgetGuards();
    $this->withToken($mine['token'])->deleteJson('/api/v1/session')->assertOk();

    idlePoll($this, $mine['token'])->assertUnauthorized();
    idlePoll($this, $theirs['token'])->assertOk();
});

it('lets a Resident App token sign out too, on the same endpoint', function () {
    $r = idleResident();

    app('auth')->forgetGuards();
    $out = $this->withToken($r['token'])->deleteJson('/api/v1/session');

    $out->assertOk()->assertJsonPath('signed_out', true);
    ApiContract::assertMatches($out, 'auth.sign_out');

    expect(PersonalAccessToken::query()->where('tokenable_id', $r['account']->id)->where('tokenable_type', $r['account']->getMorphClass())->count())->toBe(0);
});

it('is reachable by a token that can only READ — the demonstration session must be able to leave', function () {
    /*
     * "Explore a demo community" hands a reviewer a token with every `:write` ability
     * struck out (`AppMatrix::readOnlyAbilitiesFor`). Had sign-out been put behind a write
     * ability, that session could never have ended. It is behind `alerts:read`, which is
     * the one ability EVERY token this platform mints carries.
     */
    $account = idleResident()['account'];
    $readOnly = $account->createToken('resident-app:demo', AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    expect(AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT))->toContain('alerts:read')
        ->and(AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT))->not->toContain('alerts:write')
        ->and(AppMatrix::abilitiesFor(AppMatrix::GUARD))->toContain('alerts:read');

    app('auth')->forgetGuards();
    $this->withToken($readOnly)->deleteJson('/api/v1/session')->assertOk()->assertJsonPath('signed_out', true);
});

it('refuses a sign-out with no token at all', function () {
    $this->deleteJson('/api/v1/session')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('refuses a sign-out on a token that has already timed out, and says which it was', function () {
    $h = idleHandset();
    idlePoll($this, $h['token'])->assertOk();

    $this->travel(31)->minutes();

    app('auth')->forgetGuards();
    $this->withToken($h['token'])->deleteJson('/api/v1/session')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', SessionWindow::ERROR_CODE);

    /*
     * The app signs the person out of the PHONE either way — the session is over, and that
     * is what the screen says. What it must not do is report this as a failure to sign out.
     */
});
