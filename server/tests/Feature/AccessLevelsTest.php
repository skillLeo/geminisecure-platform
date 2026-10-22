<?php

declare(strict_types=1);

use App\Api\AccessLevels;
use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Http\Middleware\Api\EnforceAccessLevel;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Notifications\ResidentInviteSent;
use App\Services\Audit\AuditLogger;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Governance;
use App\Services\Push\PushAudience;
use App\Services\Push\PushMessage;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Access levels in a household — Member, Dependent, Helper
|--------------------------------------------------------------------------
|
| The user asked how a resident adds their family "and assigns them app credentials, like son, wife,
| father" (24 September 2026). Before this, a son and a housekeeper signed in to the same household could
| both pay its dues, issue its visitor passes and cast its one ballot.
|
| The SERVER is the rule: every Resident App endpoint has a row in `AppMatrix::LEVELS`, the level is
| checked on every request against the register entry as it stands, a refusal says who to ask, and panic
| is never removed from anybody. This file pins the decided matrix cell by cell, over HTTP.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lots, and everybody it puts on them is
| taken back afterwards.
|
*/

/**
 * THE DECIDED MATRIX — [Member, Dependent, Helper] for every Resident App endpoint. Written out here, not
 * read from `AppMatrix::LEVELS`, so a change to the table is a change to this test as well.
 *
 * @return array<string, array{0: bool, 1: bool, 2: bool}>
 */
function lvlExpected(): array
{
    $all = [true, true, true];
    $md = [true, true, false];
    $m = [true, false, false];

    return [
        'auth.sign_out' => $all, 'push.register' => $all, 'push.unregister' => $all, 'auth.claim_unit' => $all,
        'me.show' => $all, 'me.delete_account' => $all, 'panic.contacts' => $all, 'live.config' => $all, 'live.auth' => $all, 'live.catch_up' => $all,
        // A person's own signed-in phones (26 Sep): theirs to see and end, at any level.
        'me.sessions' => $all, 'me.sessions.end' => $all, 'me.sessions.end_others' => $all,
        // "Face ID / fingerprint" on their own phone (M49): everybody's, whatever their level.
        'me.device_key.store' => $all, 'me.device_key.destroy' => $all,
        // How the news reaches them — Email on board 20 (M50 §4.2 #4): their own, whatever their level.
        'me.notifications.email.show' => $all, 'me.notifications.email.update' => $all,
        'me.photo' => $all, 'me.photo.store' => $all, 'me.photo.remove' => $all, 'me.shown' => $all,
        // A copy of everything held about them — "Download my data" on board 33 (M50 §4.3): theirs, whatever their level.
        'me.data_export' => $all,
        // Live chat with Gemini Security on board 39 (M50 §4.3): asking for help is everybody's.
        'support.contact' => $all, 'support.chat.show' => $all, 'support.chat.messages.store' => $all,
        'alerts.store' => $all, 'duress.store' => $all, 'duress.cancel' => $all, 'duress.show' => $all, 'duress.messages.store' => $all, 'duress.audio.store' => $all,
        'me.update' => $all, 'epass.show' => $all,
        'me.household' => $md, 'members.index' => $md,
        'members.store' => $m, 'members.invite' => $m, 'members.invite_cancel' => $m, 'members.level' => $m, 'members.access' => $m, 'members.destroy' => $m,
        'vehicles.index' => $md, 'vehicles.store' => $m, 'vehicles.destroy' => $m, 'contacts.index' => $md, 'contacts.store' => $m, 'contacts.update' => $m, 'contacts.destroy' => $m,
        // A vehicle changed in place, and the household's pets (M50 §5.3): read and kept as the cars are.
        'vehicles.update' => $m, 'pets.index' => $md, 'pets.store' => $m, 'pets.update' => $m, 'pets.destroy' => $m,
        'visitor_passes.index' => $m, 'visitor_passes.store' => $m, 'visitor_passes.update' => $m, 'visitor_passes.cancel' => $m, 'visitor_passes.share' => $m,
        'approvals.respond' => $m,
        'invoices.index' => $m, 'households.balance' => $m, 'households.statement' => $m, 'payments.index' => $m, 'payments.receipt' => $m,
        'invoices.pay_intent' => $m, 'payments.card' => $m, 'payments.intents.store' => $m, 'payments.intents.show' => $m, 'autopay.show' => $m, 'autopay.store' => $m, 'autopay.destroy' => $m,
        // Saved test cards (M50 §4.2 #3): whoever may pay the household's money.
        'cards.index' => $m, 'cards.store' => $m, 'cards.default' => $m, 'cards.destroy' => $m,
        'tickets.index' => $md, 'tickets.store' => $md, 'tickets.media' => $md,
        'tickets.confirm_fixed' => $md, 'tickets.still_a_problem' => $md, 'tickets.comment' => $md,
        'notices.index' => $md, 'notices.attachment' => $md, 'notices.read' => $md,
        // The minutes of a held meeting (M21 §1.4) are read by a Member, like the meeting they record.
        'meetings.index' => $m, 'meetings.minutes' => $m, 'meetings.rsvp' => $m,
        'elections.index' => $m, 'elections.eligibility' => $m, 'elections.ballot' => $m, 'elections.results' => $m,
        'amenities.index' => $m, 'bookings.index' => $m, 'bookings.store' => $m, 'bookings.cancel' => $m, 'bookings.pay_card' => $m,
        'bookings.quote' => $m, 'bookings.book' => $m,
    ];
}

function lvlHousehold(Unit $unit, string $name): Household
{
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => $name, 'access_restricted' => false]);
    $household->forceFill(['name' => $name, 'access_restricted' => false])->save();
    lvlClear($household);

    return $household;
}

function lvlPerson(Household $household, string $name, string $relationship, string $level, bool $primary = false, string $status = Resident::VERIFIED): Resident
{
    $person = new Resident;
    $person->forceFill([
        'household_id' => $household->id, 'full_name' => $name, 'relationship' => $relationship,
        'is_primary' => $primary, 'access_level' => $level, 'status' => $status,
    ])->save();

    return $person;
}

function lvlAccount(?Resident $person, Unit $unit, string $status = ResidentAccount::ACTIVE, ?int $residentId = null): ResidentAccount
{
    return ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'levels.'.bin2hex(random_bytes(5)).'@levels.test',
        'full_name' => $person->full_name ?? 'Levels Nobody', 'status' => $status, 'verified_at' => now(),
        'resident_id' => $status === ResidentAccount::PENDING ? null : ($residentId ?? $person?->id),
        'unit_id' => $status === ResidentAccount::PENDING ? null : $unit->id,
    ]);
}

/** A fresh sign-in for an account — every call its own token, so a sign-out in one cell cannot spoil the next. */
function lvlToken(ResidentAccount $account, bool $readOnly = false): string
{
    return $account->createToken('resident-app:levels', $readOnly ? AppMatrix::readOnlyAbilitiesFor(AppMatrix::RESIDENT) : AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

/**
 * A request as the phone makes it. Every path parameter not given is `1` — the level is decided before any
 * record is looked up. `$keys` false leaves Idempotency-Key and X-Device-Time off a write, so a write the level
 * lets through stops at the key (`422 idempotency_key_required`) and nothing runs.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function lvlRaw(TestCase $test, string $token, string $name, array $params = [], bool $keys = true, array $body = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) ($params[$m[1]] ?? 1), $entry['uri']);
    $headers = $keys && $entry['write'] ? ['Idempotency-Key' => 'lvl-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], $uri, $body, $headers);

    FacilitiesFixture::boot();

    return $response;
}

/**
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function lvlCall(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $response = lvlRaw($test, $token, $name, $params, true, $body);
    $response->assertStatus($status ?? Catalogue::endpoints()[$name]['status']);
    ApiContract::assertMatches($response, $name);

    return $response;
}

/** Everything this file put on a household's lot, taken back. */
function lvlClear(Household $household): void
{
    $people = Resident::withRemoved()->where('household_id', $household->id)->pluck('id');

    DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $people)->delete();
    VisitorPass::query()->where('unit_id', $household->unit_id)->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $household->unit_id)->delete();
    DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $people)->delete();
}

/** @return array{0: Ballot, 1: list<BallotOption>} one open election, one seat, two candidates */
function lvlBallot(string $title): array
{
    $ballot = new Ballot;
    $ballot->forceFill([
        'year' => (int) now()->year, 'title' => $title, 'kind' => Ballot::ELECTION, 'stage' => Ballot::VOTING_OPEN,
        'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(2), 'eligible_households' => 5,
    ])->save();

    $position = new BallotPosition;
    $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Estate Executive', 'seat_count' => 1, 'scope' => 'estate', 'sort_order' => 1])->save();

    $options = [];

    foreach (['Andre Levels', 'Simone Levels'] as $i => $label) {
        $option = new BallotOption;
        $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label, 'sort_order' => $i])->save();
        $options[] = $option;
    }

    return [$ballot, $options];
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Notification::fake();

    // Levels as they are once switched on (`AccessLevels::open`); T-LVL-10 walks them as they ship — closed.
    config(['services.resident_app.access_levels' => true]);

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8881');
    $this->household = lvlHousehold($this->unit, 'Levels household');
    $this->households = [$this->household];

    $this->primary = lvlPerson($this->household, 'Andrea Levels', 'owner', AppMatrix::MEMBER, primary: true);
    $this->primaryAccount = lvlAccount($this->primary, $this->unit);

    $this->people = [];
    $this->accounts = [];

    foreach (AppMatrix::LEVELS_ALL as $level) {
        $this->people[$level] = lvlPerson($this->household, ucfirst($level).' Levels', 'relative', $level);
        $this->accounts[$level] = lvlAccount($this->people[$level], $this->unit);
    }
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    foreach ($this->households as $household) {
        lvlClear($household);
    }
});

/* ── T-LVL-1 · the table is the whole truth ───────────────────────────── */

it('has a level row for every Resident App endpoint and for nothing else, and the rows are the decided matrix (T-LVL-1)', function () {
    $reachable = collect(Catalogue::endpoints())
        ->filter(fn (array $e): bool => in_array(AppMatrix::RESIDENT, $e['apps'], true) && $e['capability'] !== null)
        ->keys()->sort()->values()->all();

    expect(collect(array_keys(AppMatrix::LEVELS))->sort()->values()->all())->toBe($reachable);

    foreach (AppMatrix::LEVELS as $name => $levels) {
        expect($levels)->not->toBeEmpty("[{$name}] reaches nobody")
            ->and(array_values(array_diff($levels, AppMatrix::LEVELS_ALL)))->toBe([], "[{$name}] names a level that does not exist");
    }

    $decided = lvlExpected();
    ksort($decided);
    $table = array_map(static fn (array $levels): array => array_map(static fn (string $level): bool => in_array($level, $levels, true), AppMatrix::LEVELS_ALL), AppMatrix::LEVELS);
    ksort($table);

    expect($table)->toBe($decided);

    // Panic is never removed, and the primary's powers stay the primary's: nobody but a Member reaches them.
    foreach (['alerts.store', 'duress.store', 'duress.cancel', 'duress.show', 'panic.contacts', 'epass.show'] as $panic) {
        expect(AppMatrix::LEVELS[$panic])->toBe(AppMatrix::LEVELS_ALL, "[{$panic}]");
    }
});

/* ── T-LVL-2 · every cell, over HTTP, before any record is read ───────── */

it('answers each level exactly as the matrix says, before any record is looked at (T-LVL-2)', function (string $name) {
    $entry = Catalogue::endpoints()[$name];
    [$member, $dependent, $helper] = lvlExpected()[$name];

    foreach ([AppMatrix::MEMBER => $member, AppMatrix::DEPENDENT => $dependent, AppMatrix::HELPER => $helper] as $level => $allowed) {
        // Refused cells carry every header, so nothing but the level can be what refuses them.
        $response = lvlRaw($this, lvlToken($this->accounts[$level]), $name, [], ! $allowed || ! $entry['write']);
        // A refusal is always JSON. A file endpoint (`me.data_export`) answers an allowed level with the file itself,
        // which has no code — and must then be the file, not some other failure.
        $json = str_contains((string) $response->headers->get('Content-Type'), 'json');
        $code = $json ? $response->json('error.code') : null;
        if (! $json) {
            expect($response->status())->toBe(200, "[{$name}] as a {$level} answered neither JSON nor its file");
        }

        if ($allowed) {
            expect($code)->not->toBe('not_for_your_access_level', "[{$name}] refused a {$level}: ".$response->getContent());

            if ($entry['write']) {
                /*
                 * Past its level, a write with no key stops at the key and nothing runs. The panic button's legacy
                 * `alerts.store` takes its key optionally at the route, so its empty body stops it one step later,
                 * at validation — still before anything is raised.
                 */
                expect($code)->toBe(($entry['legacy'] ?? false) ? 'validation_failed' : 'idempotency_key_required', "[{$name}] as a {$level} should pass its level and stop before it runs: ".$response->getContent());
            }

            continue;
        }

        // Who to ask: for managing the household's members, the one person who may do it; for anything else — reading
        // the member list included, which Members and Dependents may do — who may change the level.
        $ask = str_starts_with($name, 'members.') && $name !== 'members.index'
            ? 'Your access in the Levels household is '.AccessLevels::label($level).' — only the primary resident, Andrea Levels, can do this.'
            : 'Your access in the Levels household is '.AccessLevels::label($level).' — ask Andrea Levels, or the estate office, if that should change.';

        expect($response->status())->toBe(403, "[{$name}] as a {$level}: ".$response->getContent())
            ->and($code)->toBe('not_for_your_access_level', "[{$name}] as a {$level}")
            ->and((string) $response->json('error.message'))->toContain($ask);
    }
})->with(array_keys(lvlExpected()));

it('tells a Helper refused the member list what would help — a level change — not that only the primary may read it', function () {
    $refused = lvlRaw($this, lvlToken($this->accounts[AppMatrix::HELPER]), 'members.index');

    expect($refused->status())->toBe(403)
        ->and($refused->json('error.code'))->toBe('not_for_your_access_level')
        ->and((string) $refused->json('error.message'))->toBe('The household\'s members, vehicles and emergency contacts are for its Members and Dependents. Your access in the Levels household is Helper — ask Andrea Levels, or the estate office, if that should change.');

    // Changing the list is still the primary's alone, and said so.
    expect((string) lvlRaw($this, lvlToken($this->accounts[AppMatrix::HELPER]), 'members.store')->json('error.message'))
        ->toBe('Adding, inviting and removing the household\'s members, and changing their access, is for the household\'s primary resident. Your access in the Levels household is Helper — only the primary resident, Andrea Levels, can do this.');
});

it('lets an active Helper read their own profile and reach account deletion, which the app stores require (T-LVL-2, G6)', function () {
    $helper = $this->accounts[AppMatrix::HELPER];

    expect(lvlCall($this, lvlToken($helper), 'me.show')->json('access.level'))->toBe(AppMatrix::HELPER);

    // The level passes, and the confirmation word stops it: nothing is deleted.
    $refused = lvlRaw($this, lvlToken($helper), 'me.delete_account', [], true, ['confirm' => 'no']);

    expect($refused->status())->toBe(422)
        ->and($refused->json('error.code'))->toBe('confirm_required')
        ->and(ResidentAccount::query()->find($helper->id)?->deleted_at)->toBeNull();
});

/* ── T-LVL-3 · panic is never removed; an unlisted endpoint fails closed ─ */

it('never takes panic or the person\'s own e-pass from anybody, and fails closed on an endpoint with no row (T-LVL-3)', function () {
    // Inside a Helper's e-pass hours (Monday to Saturday, 7 AM to 7 PM Jamaica), so the case does not read the clock.
    $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'America/Jamaica'));

    foreach (AppMatrix::LEVELS_ALL as $level) {
        $token = lvlToken($this->accounts[$level]);

        expect(lvlCall($this, $token, 'duress.store', [], ['mode' => 'silent'])->json('kind'))->toBe('panic', $level)
            ->and(lvlCall($this, $token, 'alerts.store', [], ['kind' => 'panic'])->json('status'))->toBe('open', $level)
            ->and(lvlCall($this, $token, 'epass.show')->json('category'))->toBe('resident', $level);
    }

    Exceptions::fake();

    $panicLike = 'lvl.unlisted_alert_'.bin2hex(random_bytes(3));
    $other = 'lvl.unlisted_'.bin2hex(random_bytes(3));

    // An endpoint a merge forgot to give a row: panic's capability is still open to everybody…
    expect(AppMatrix::levelAllows(AppMatrix::HELPER, $panicLike, 'alerts'))->toBeTrue()
        // …and anything else is refused, even to a Member, rather than opened to every level by omission.
        ->and(AppMatrix::levelAllows(AppMatrix::MEMBER, $other, 'dues'))->toBeFalse()
        ->and(AppMatrix::levelAllows(AppMatrix::MEMBER, $other, 'dues'))->toBeFalse()
        ->and(AppMatrix::levelAllows(AppMatrix::MEMBER, $other, null))->toBeFalse();

    // Reported, once per process for each — the forgotten row gets written.
    $reports = static fn (string $endpoint): int => collect(Exceptions::reported())->filter(static fn (Throwable $e): bool => str_contains($e->getMessage(), '['.$endpoint.']'))->count();

    expect($reports($other))->toBe(1)
        ->and($reports($panicLike))->toBe(1);
});

it('refuses a real request to an endpoint with no row in words that are true: reported, and nobody told who was not (T-LVL-3)', function () {
    Exceptions::fake();

    // A Resident App route a merge forgot to give a row: it carries `level`, and the Catalogue has no entry for it.
    $name = 'lvl.unlisted_probe_'.bin2hex(random_bytes(3));
    Route::middleware(['auth:sanctum', 'device:'.AppMatrix::RESIDENT, 'level'])
        ->get('/api/v1/'.$name, static fn () => response()->json(['reached' => true]))
        ->name('api.v1.'.$name);

    app('auth')->forgetGuards();
    $response = $this->withToken(lvlToken($this->accounts[AppMatrix::MEMBER]))->getJson('/api/v1/'.$name);
    FacilitiesFixture::boot();

    expect($response->status())->toBe(403)
        ->and($response->json('error.code'))->toBe('not_for_your_access_level')
        // `reportUnlisted` writes to the platform's error log and to nobody at the estate — so the words say that.
        ->and($response->json('error.message'))->toBe('This part of the app isn\'t open to any access level yet. It has been reported so it can be fixed.')
        ->and(EnforceAccessLevel::UNLISTED)->not->toContain('estate office')
        ->and(collect(Exceptions::reported())->filter(static fn (Throwable $e): bool => str_contains($e->getMessage(), '['.$name.']'))->count())->toBe(1);
});

/* ── T-LVL-4 · never widened by an error ──────────────────────────────── */

it('makes the primary a Member whatever the column says, a missing register entry a Helper, and a pending account still pending (T-LVL-4)', function () {
    DB::connection('tenant')->table('residents')->where('id', $this->primary->id)->update(['access_level' => AppMatrix::HELPER]);

    // Everything a Helper is refused, the primary reaches.
    foreach (lvlExpected() as $name => [, , $helper]) {
        if ($helper) {
            continue;
        }

        $write = Catalogue::endpoints()[$name]['write'];
        $response = lvlRaw($this, lvlToken($this->primaryAccount), $name, [], ! $write);

        expect($response->json('error.code'))->not->toBe('not_for_your_access_level', "[{$name}] refused the primary: ".$response->getContent());
    }

    expect(lvlCall($this, lvlToken($this->primaryAccount), 'me.show')->json('access.level'))->toBe(AppMatrix::MEMBER);

    // An active account whose register entry is nowhere is the narrowest level — and still has panic.
    $lost = lvlAccount(null, $this->unit, residentId: 987_654_321);
    $token = lvlToken($lost);

    expect(lvlCall($this, $token, 'me.show')->json('access.level'))->toBe(AppMatrix::HELPER)
        ->and(lvlRaw($this, $token, 'me.household')->json('error.code'))->toBe('not_for_your_access_level')
        ->and(lvlRaw($this, $token, 'panic.contacts')->status())->toBe(200);

    // Refused in the words of the household the account belongs to, even with its register entry gone.
    expect((string) lvlRaw($this, $token, 'invoices.index')->json('error.message'))
        ->toBe('Dues and payments are for the household\'s Members. Your access in the Levels household is Helper — ask Andrea Levels, or the estate office, if that should change.');

    // A pending account still reaches the pending routes only, exactly as before levels.
    $pending = lvlAccount(null, $this->unit, ResidentAccount::PENDING);
    $pendingToken = lvlToken($pending);

    expect(lvlRaw($this, $pendingToken, 'epass.show')->json('error.code'))->toBe('account_pending')
        ->and(lvlCall($this, $pendingToken, 'me.show')->json('access'))->toBeNull();
});

/* ── T-LVL-5 · voting is never widened ────────────────────────────────── */

it('refuses a Dependent or a Helper the ballot and leaves the household its one paper, cast by a Member (T-LVL-5)', function () {
    [$ballot, $options] = lvlBallot('Levels Election '.bin2hex(random_bytes(3)));

    expect(app(Governance::class)->eligibility($this->unit)['eligible'])->toBeTrue('Lot 8881 is this file\'s own; nothing should have put it in arrears');

    foreach ([AppMatrix::DEPENDENT, AppMatrix::HELPER] as $level) {
        $refused = lvlRaw($this, lvlToken($this->accounts[$level]), 'elections.ballot', ['ballot' => $ballot->id], true, ['option_ids' => [$options[0]->id]]);

        expect($refused->status())->toBe(403)->and($refused->json('error.code'))->toBe('not_for_your_access_level', $level);
    }

    // A Member who is not the primary casts the household's paper…
    lvlCall($this, lvlToken($this->accounts[AppMatrix::MEMBER]), 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[0]->id]]);

    // …and it is the UNIT's: the primary cannot cast a second one.
    $again = lvlRaw($this, lvlToken($this->primaryAccount), 'elections.ballot', ['ballot' => $ballot->id], true, ['option_ids' => [$options[1]->id]]);

    expect($again->status())->toBe(409)
        ->and(lvlCall($this, lvlToken($this->primaryAccount), 'elections.eligibility', ['ballot' => $ballot->id])->json('has_voted'))->toBeTrue();
});

/* ── T-LVL-6 · who changes a level, and the record of it ──────────────── */

it('lets only the primary change a member\'s level, never their own, on the next request, audited as the account that did it (T-LVL-6)', function () {
    $primaryToken = lvlToken($this->primaryAccount);
    $dependent = $this->people[AppMatrix::DEPENDENT];
    $dependentToken = lvlToken($this->accounts[AppMatrix::DEPENDENT]);

    $otherUnit = FacilitiesFixture::scratchUnit('Lot 8882');
    $otherHousehold = lvlHousehold($otherUnit, 'Elsewhere household');
    $this->households[] = $otherHousehold;
    $stranger = lvlPerson($otherHousehold, 'Stranger Levels', 'relative', AppMatrix::MEMBER);

    expect(lvlCall($this, $primaryToken, 'members.level', ['member' => $this->primary->id], ['access_level' => AppMatrix::HELPER], 409)->json('error.code'))->toBe('primary_is_member')
        ->and(lvlCall($this, lvlToken($this->accounts[AppMatrix::MEMBER]), 'members.level', ['member' => $dependent->id], ['access_level' => AppMatrix::HELPER], 403)->json('error.code'))->toBe('not_primary')
        ->and(lvlCall($this, $dependentToken, 'members.level', ['member' => $dependent->id], ['access_level' => AppMatrix::MEMBER], 403)->json('error.code'))->toBe('not_for_your_access_level')
        ->and(lvlCall($this, $primaryToken, 'members.level', ['member' => $stranger->id], ['access_level' => AppMatrix::HELPER], 404)->json('error.code'))->toBe('not_found')
        ->and(lvlCall($this, $primaryToken, 'members.level', ['member' => $dependent->id], ['access_level' => 'boss'], 422)->json('error.code'))->toBe('validation_failed');

    expect(Resident::query()->findOrFail($dependent->id)->access_level)->toBe(AppMatrix::DEPENDENT)
        ->and(Resident::query()->findOrFail($stranger->id)->access_level)->toBe(AppMatrix::MEMBER);

    // Before: the Dependent reads the household.
    expect(lvlRaw($this, $dependentToken, 'me.household')->status())->toBe(200);

    $changed = lvlCall($this, $primaryToken, 'members.level', ['member' => $dependent->id], ['access_level' => AppMatrix::HELPER]);

    expect($changed->json('access_level'))->toBe(AppMatrix::HELPER)
        ->and(Resident::query()->findOrFail($dependent->id)->access_level)->toBe(AppMatrix::HELPER);

    // The very next request, on the same sign-in: refused — and nobody was signed out.
    expect(lvlRaw($this, $dependentToken, 'me.household')->json('error.code'))->toBe('not_for_your_access_level')
        ->and(lvlCall($this, $dependentToken, 'me.show')->json('access.level'))->toBe(AppMatrix::HELPER);

    $entry = AuditEntry::query()->where('action', 'household.member_level_changed')->where('entity_id', (string) $dependent->id)->latest('id')->firstOrFail();

    expect($entry->actor_name)->toBe('Andrea Levels')
        ->and($entry->actor_role)->toBe('Resident App')
        ->and($entry->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($entry->before)->toBe(['access_level' => AppMatrix::DEPENDENT])
        ->and($entry->after)->toMatchArray(['access_level' => AppMatrix::HELPER, 'by' => 'Andrea Levels', 'by_resident_id' => $this->primary->id, 'via' => 'resident_app']);

    // Adding a member with a level stores it; without one, Member; with a made-up one, refused.
    $kid = lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Kid Levels', 'relationship' => 'child', 'phone' => '876 555 0100', 'no_email' => true, 'access_level' => AppMatrix::DEPENDENT]);

    expect($kid->json('access_level'))->toBe(AppMatrix::DEPENDENT)
        ->and(Resident::query()->findOrFail($kid->json('id'))->access_level)->toBe(AppMatrix::DEPENDENT)
        ->and(lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Spouse Levels', 'relationship' => 'spouse', 'phone' => '876 555 0101', 'no_email' => true])->json('access_level'))->toBe(AppMatrix::MEMBER)
        ->and(lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Boss Levels', 'relationship' => 'other', 'phone' => '876 555 0102', 'no_email' => true, 'access_level' => 'boss'], 422)->json('error.code'))->toBe('validation_failed');

    // An invite sets the level as it goes…
    $email = 'nanny.'.bin2hex(random_bytes(3)).'@levels.test';
    $nanny = lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Nanny Levels', 'relationship' => 'domestic_staff', 'phone' => '876 555 0103', 'email' => $email, 'invite' => false]);
    expect($nanny->json('invite.status'))->toBe('none');

    $invited = lvlCall($this, $primaryToken, 'members.invite', ['member' => $nanny->json('id')], ['access_level' => AppMatrix::HELPER]);

    expect($invited->json('access_level'))->toBe(AppMatrix::HELPER)
        ->and($invited->json('invite.status'))->toBe('sent');
    Notification::assertSentOnDemand(ResidentInviteSent::class, static fn (ResidentInviteSent $n, array $c, object $to): bool => ($to->routes['mail'] ?? null) === $email);

    // …and an invite refused by the timer changes nothing: a 409 means nothing happened.
    $wait = lvlCall($this, $primaryToken, 'members.invite', ['member' => $nanny->json('id')], ['access_level' => AppMatrix::MEMBER], 409);

    expect($wait->json('error.code'))->toBe('invite_wait')
        ->and(Resident::query()->findOrFail($nanny->json('id'))->access_level)->toBe(AppMatrix::HELPER);
});

it('names a Resident App actor in the audit log as the estate\'s register has them, whatever their account is called, with the account beside it', function () {
    $primaryToken = lvlToken($this->primaryAccount);

    // Every level may rename its own account — to anything at all.
    lvlCall($this, $primaryToken, 'me.update', [], ['full_name' => 'Estate Office']);
    expect(ResidentAccount::query()->findOrFail($this->primaryAccount->id)->full_name)->toBe('Estate Office');

    $member = $this->people[AppMatrix::MEMBER];
    lvlCall($this, $primaryToken, 'members.level', ['member' => $member->id], ['access_level' => AppMatrix::DEPENDENT]);
    $kid = lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Kid Actor', 'relationship' => 'child', 'phone' => '876 555 0104', 'no_email' => true, 'access_level' => AppMatrix::HELPER]);
    lvlCall($this, $primaryToken, 'members.destroy', ['member' => $kid->json('id')]);

    $of = static fn (string $action, int $id): AuditEntry => AuditEntry::query()->where('action', $action)->where('entity_id', (string) $id)->latest('id')->firstOrFail();
    $kidId = (int) $kid->json('id');

    foreach ([$of('household.member_level_changed', $member->id), $of('household.member_added', $kidId), $of('household.member_removed', $kidId)] as $entry) {
        expect($entry->actor_name)->toBe('Andrea Levels', "{$entry->action}: the register's name, not the account's")
            ->and($entry->actor_role)->toBe('Resident App', $entry->action)
            ->and($entry->after['by_account_id'] ?? null)->toBe($this->primaryAccount->id, "{$entry->action}: which sign-in did it");
    }

    // Adding a member is recorded, with the level the primary chose for them then — `set()` never sees a creation.
    // (Every key, and nothing else; the JSON column keeps its own key order.)
    expect($of('household.member_added', $kidId)->after)->toEqual([
        'household_id' => $this->household->id, 'relationship' => 'child', 'access_level' => AppMatrix::HELPER,
        'by' => 'Andrea Levels', 'by_resident_id' => $this->primary->id, 'by_account_id' => $this->primaryAccount->id, 'via' => 'resident_app',
    ]);
});

it('names a Resident App account whose register entry it cannot read by its own name, marked as the account\'s', function () {
    $record = function (ResidentAccount $as): AuditEntry {
        $this->actingAs($as);

        try {
            return app(AuditLogger::class)->record('household.actor_probe', tenantId: FacilitiesFixture::ESTATE);
        } finally {
            app('auth')->forgetGuards();
        }
    };

    // Renamed to anything: only the register's name is the estate's word.
    ResidentAccount::query()->whereKey($this->primaryAccount->id)->update(['full_name' => 'Estate Office']);
    $renamed = ResidentAccount::query()->findOrFail($this->primaryAccount->id);
    $lost = lvlAccount(null, $this->unit, residentId: 987_654_321);

    // Inside the account's own estate — as every Resident App request is (`device` opens it).
    tenancy()->initialize(Tenant::query()->findOrFail(FacilitiesFixture::ESTATE));

    try {
        $named = $record($renamed);
        $unfound = $record($lost);
    } finally {
        tenancy()->end();
        FacilitiesFixture::boot();
    }

    expect($named->actor_name)->toBe('Andrea Levels')
        ->and($named->actor_role)->toBe('Resident App')
        // No register entry to read: the account's own name, marked as the account's.
        ->and($unfound->actor_name)->toBe('Levels Nobody (Resident App account #'.$lost->id.')');

    // Outside its estate the register is not read at all, and the name says whose it is.
    expect($record($renamed)->actor_name)->toBe('Estate Office (Resident App account #'.$renamed->id.')');
});

/* ── T-LVL-7 · push by level; the guard told when nobody can answer ───── */

it('pushes each level only what its screens may open, never filters panic, and tells the guard when nobody can answer (T-LVL-7)', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 0]);
    Bus::fake([SendExpoPush::class]);

    $site = FacilitiesFixture::ESTATE;
    $accounts = ['primary' => $this->primaryAccount, ...$this->accounts];
    $phones = [];

    foreach ($accounts as $who => $account) {
        $phones[$who] = (int) DevicePushToken::query()->create([
            'tenant_id' => $site, 'principal_type' => DevicePushToken::RESIDENT, 'principal_id' => $account->id, 'app' => AppMatrix::RESIDENT,
            'expo_token' => 'ExponentPushToken[lvl'.$who.bin2hex(random_bytes(6)).']', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
        ])->id;
    }

    $sentTo = static fn (string $type): array => collect(Bus::dispatched(SendExpoPush::class))
        ->filter(static fn (SendExpoPush $job): bool => ($job->data['type'] ?? null) === $type)
        ->flatMap(static fn (SendExpoPush $job): array => $job->tokenIds)
        ->sort()->values()->all();
    $phonesOf = static fn (string ...$who): array => collect($who)->map(static fn (string $w): int => $phones[$w])->sort()->values()->all();

    $push = app(PushNotifier::class);

    $push->toUnit($site, $this->unit->id, new PushMessage('dues_reminder', 'A message about your account', 'Open the app to see your statement.', $this->unit->id));
    FacilitiesFixture::boot();
    $push->toUnit($site, $this->unit->id, new PushMessage('approval_request', 'Someone is at the gate for you', 'A visitor is at Main Gate.', 9));
    FacilitiesFixture::boot();
    $push->toEstateResidents($site, new PushMessage('notice', 'New notice from your estate', 'Water lock-off', 77), [$this->unit->id]);
    FacilitiesFixture::boot();
    $push->toAccount($site, $this->accounts[AppMatrix::HELPER]->id, new PushMessage('panic_update', 'Help is on the way', 'An officer was dispatched.', 5, 'dispatched'));
    FacilitiesFixture::boot();

    expect($sentTo('dues_reminder'))->toBe($phonesOf('primary', AppMatrix::MEMBER))
        ->and($sentTo('approval_request'))->toBe($phonesOf('primary', AppMatrix::MEMBER))
        ->and($sentTo('notice'))->toBe($phonesOf('primary', AppMatrix::MEMBER, AppMatrix::DEPENDENT))
        ->and($sentTo('panic_update'))->toBe($phonesOf(AppMatrix::HELPER));

    // (G16) Every resident push type is mapped to the endpoint its screen reads, or is one never filtered.
    foreach (PushTypes::TYPES as $type => $spec) {
        if ($spec['app'] !== AppMatrix::RESIDENT) {
            continue;
        }

        $endpoint = PushAudience::endpointFor($type);

        expect($endpoint !== null || in_array($type, PushAudience::NEVER_FILTERED, true))->toBeTrue("resident push type [{$type}] has no access-level mapping")
            ->and($endpoint === null || array_key_exists($endpoint, AppMatrix::LEVELS))->toBeTrue("[{$type}] maps to [{$endpoint}], which has no level row");
    }

    // A type nobody mapped goes to Members only, and is reported once.
    Exceptions::fake();
    $madeUp = 'lvl_made_up_'.bin2hex(random_bytes(3));
    $ids = collect($accounts)->map(static fn (ResidentAccount $a): int => (int) $a->id)->values()->all();

    expect(app(PushAudience::class)->allowed($site, $madeUp, $ids))->toBe([(int) $this->primaryAccount->id, (int) $this->accounts[AppMatrix::MEMBER]->id]);
    app(PushAudience::class)->allowed($site, $madeUp, $ids);
    FacilitiesFixture::boot();

    expect(collect(Exceptions::reported())->filter(static fn (Throwable $e): bool => str_contains($e->getMessage(), '['.$madeUp.']'))->count())->toBe(1);

    // A register that cannot be read sends as before levels existed, and says so.
    Tenant::withoutEvents(static function (): void {
        (new Tenant)->forceFill(['id' => 'lvlnodb', 'name' => 'Levels (no database)', 'receipt_prefix' => 'LVX', 'status' => 'active'])->save();
    });
    $ghost = ResidentAccount::query()->create([
        'tenant_id' => 'lvlnodb', 'channel' => 'email', 'destination' => 'ghost.'.bin2hex(random_bytes(3)).'@levels.test',
        'status' => ResidentAccount::ACTIVE, 'resident_id' => 1, 'unit_id' => 1, 'verified_at' => now(),
    ]);

    expect(app(PushAudience::class)->allowed('lvlnodb', 'dues_reminder', [(int) $ghost->id]))->toBe([(int) $ghost->id]);
    FacilitiesFixture::boot();

    // The walk-up: a household whose only app user is a Helper is asked by nobody, and the guard is told at once.
    $post = Post::create(['tenant_id' => $site, 'name' => 'Levels Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Levels Guard', 'employee_number' => 'GS-LVL-1', 'psra_number' => 'PSRA-LVL-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $site, 'post_id' => $post->id,
    ]);
    $guardToken = app(DeviceEnrolment::class)->enrol($guard, 'Levels handset')['token'];

    $helperUnit = FacilitiesFixture::scratchUnit('Lot 8884');
    $helperHousehold = lvlHousehold($helperUnit, 'Helper-only household');
    $this->households[] = $helperHousehold;
    lvlPerson($helperHousehold, 'Absent Primary', 'owner', AppMatrix::MEMBER, primary: true);
    lvlAccount(lvlPerson($helperHousehold, 'Only Helper', 'domestic_staff', AppMatrix::HELPER), $helperUnit);

    $ask = function (string $unit) use ($guardToken): TestResponse {
        app('auth')->forgetGuards();
        $response = $this->withToken($guardToken)->postJson('/api/v1/gate/approvals', ['unit' => $unit, 'visitor_name' => 'Courier — Levels', 'id_type' => "Driver's licence", 'id_number' => 'D1234567', 'purpose' => 'Delivery', 'no_vehicle' => true], [
            'Idempotency-Key' => 'lvl-gate-'.bin2hex(random_bytes(6)), 'X-Device-Time' => now()->toIso8601String(),
        ])->assertCreated();
        ApiContract::assertMatches($response, 'gate.approvals.store');
        FacilitiesFixture::boot();

        return $response;
    };

    expect($ask((string) $this->unit->reference)->json('household_can_answer'))->toBeTrue()
        ->and($ask((string) $helperUnit->reference)->json('household_can_answer'))->toBeFalse();
});

/* ── T-LVL-8 · the phone is told what the server will allow ───────────── */

it('tells each person what their level may do and who to ask, and shows the gate only to those who may answer it (T-LVL-8)', function () {
    $decided = lvlExpected();

    foreach (AppMatrix::LEVELS_ALL as $i => $level) {
        $access = lvlCall($this, lvlToken($this->accounts[$level]), 'me.show')->json('access');

        expect($access['level'])->toBe($level)
            ->and($access['level_label'])->toBe(ucfirst($level))
            ->and($access['is_primary'])->toBeFalse()
            ->and($access['primary_name'])->toBe('Andrea Levels');

        foreach (AccessLevels::MAY as $key => $probe) {
            expect($access['may'][$key])->toBe($key === 'manage_members' ? false : $decided[$probe][$i], "{$level} may.{$key}");
        }
    }

    $primary = lvlCall($this, lvlToken($this->primaryAccount), 'me.show')->json('access');

    expect($primary['levels_open'])->toBeTrue()
        ->and($primary['is_primary'])->toBeTrue()
        ->and($primary['level'])->toBe(AppMatrix::MEMBER)
        ->and($primary['may'])->toBe(array_fill_keys(array_keys(AccessLevels::MAY), true));

    // Somebody at the gate: a Member is shown them, a Dependent is not — they cannot answer.
    $walkUp = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
        'unit_id' => $this->unit->id, 'household_id' => $this->household->id, 'guard_id' => 1, 'guard_name' => 'Kemar Reid', 'post_name' => 'Main Gate',
        'visitor_name' => 'Courier — Levels', 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(collect(lvlCall($this, lvlToken($this->accounts[AppMatrix::MEMBER]), 'me.household')->json('pending_approvals'))->pluck('id')->all())->toContain($walkUp);

    $dependentView = lvlCall($this, lvlToken($this->accounts[AppMatrix::DEPENDENT]), 'me.household');

    expect($dependentView->json('pending_approvals'))->toBe([])
        ->and(collect($dependentView->json('members'))->firstWhere('id', $this->people[AppMatrix::HELPER]->id)['access_level'])->toBe(AppMatrix::HELPER);

    // (G9) A household with nobody primary names nobody to ask.
    $loneUnit = FacilitiesFixture::scratchUnit('Lot 8883');
    $loneHousehold = lvlHousehold($loneUnit, 'Lone household');
    $this->households[] = $loneHousehold;
    $lone = lvlAccount(lvlPerson($loneHousehold, 'Lone Helper', 'domestic_staff', AppMatrix::HELPER), $loneUnit);

    $loneAccess = lvlCall($this, lvlToken($lone), 'me.show')->json('access');

    expect($loneAccess['level'])->toBe(AppMatrix::HELPER)
        ->and($loneAccess['primary_name'])->toBeNull();

    // And a refusal there says so, without naming anybody who cannot help.
    expect((string) lvlRaw($this, lvlToken($lone), 'invoices.index')->json('error.message'))
        ->toBe('Dues and payments are for the household\'s Members. Your access in the Lone household is Helper — ask the estate office if that should change.');
});

it('describes a Helper as seeing the household\'s emergency contacts, which panic gives every level, and nothing else of it', function () {
    $sentence = AccessLevels::describe(AppMatrix::HELPER);

    // `panic.contacts` answers a Helper the household's contacts' names, relationships and numbers (T-LVL-9)…
    expect(AppMatrix::LEVELS['panic.contacts'])->toContain(AppMatrix::HELPER)
        // …so the sentence the office reads before making somebody a Helper says so, and claims nothing it cannot.
        ->and($sentence)->toContain('the household\'s emergency contacts')
        ->and($sentence)->toContain('Nothing else about the household')
        ->and($sentence)->not->toContain('Nothing about the household');

    // The office sees the same sentence under the level it is choosing.
    expect(collect(AccessLevels::options())->firstWhere('value', AppMatrix::HELPER)['sentence'])->toBe($sentence);
});

/* ── T-LVL-10 · closed until the Resident App that reads panic.contacts is out ── */

it('ships with levels closed: nobody can be given less than Member, every writer says why, and every request is still checked (T-LVL-10)', function () {
    /*
     * THE DEPLOY ORDER (review of W-LEVELS). The Resident App in the field takes the panic screen's "text / call my
     * emergency contacts" people only from `me.household`, which a Helper is refused; only the next release reads
     * `panic.contacts`. A Helper made before it would find that sheet empty — and while no SMS provider is connected
     * it is the only way a household's contacts hear of a panic. So the switch is OFF unless a server turns it on.
     */
    expect((require base_path('config/services.php'))['resident_app']['access_levels'])->toBeFalse('nothing in this environment sets RESIDENT_APP_ACCESS_LEVELS')
        // …and a server set up from the example environment ships it off too.
        ->and((string) file_get_contents(base_path('.env.example')))->toMatch('/^RESIDENT_APP_ACCESS_LEVELS=false$/m');
    config(['services.resident_app.access_levels' => false]);

    expect(AccessLevels::open())->toBeFalse();

    $primaryToken = lvlToken($this->primaryAccount);
    $member = $this->people[AppMatrix::MEMBER];
    $count = fn (): int => Resident::query()->where('household_id', $this->household->id)->count();

    // The phone is told, so it does not offer the choice.
    expect(lvlCall($this, $primaryToken, 'me.show')->json('access.levels_open'))->toBeFalse();

    $email = 'closed.'.bin2hex(random_bytes(3)).'@levels.test';
    $invitee = lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Closed Invitee', 'relationship' => 'domestic_staff', 'phone' => '876 555 0105', 'email' => $email, 'invite' => false]);
    expect($invitee->json('access_level'))->toBe(AppMatrix::MEMBER);

    foreach ([AppMatrix::DEPENDENT, AppMatrix::HELPER] as $level) {
        // Adding at that level: refused, and nobody added.
        $before = $count();
        $added = lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Closed '.$level, 'relationship' => 'domestic_staff', 'phone' => '876 555 0106', 'no_email' => true, 'access_level' => $level], 409);

        expect($added->json('error.code'))->toBe('access_levels_not_open', $level)
            ->and($added->json('error.message'))->toBe(AccessLevels::NOT_OPEN)
            ->and($count())->toBe($before);

        // Changing to it: refused, and nothing changed.
        expect(lvlCall($this, $primaryToken, 'members.level', ['member' => $member->id], ['access_level' => $level], 409)->json('error.code'))->toBe('access_levels_not_open')
            ->and(Resident::query()->findOrFail($member->id)->access_level)->toBe(AppMatrix::MEMBER);

        // Inviting with it: refused before anything is sent.
        expect(lvlCall($this, $primaryToken, 'members.invite', ['member' => $invitee->json('id')], ['access_level' => $level], 409)->json('error.code'))->toBe('access_levels_not_open')
            ->and(Resident::query()->findOrFail($invitee->json('id'))->access_level)->toBe(AppMatrix::MEMBER);
    }

    Notification::assertNothingSent();

    // Each of the three says so in its catalogue entry, which the phone is generated from.
    foreach (['members.store', 'members.invite', 'members.level'] as $name) {
        expect(array_key_exists('409 access_levels_not_open', Catalogue::endpoints()[$name]['errors']))->toBeTrue("[{$name}] does not list 409 access_levels_not_open");
    }

    // The one writer refuses it too — the office's household page, and every caller to come (a claim approval).
    expect(fn () => AccessLevels::set($member, AppMatrix::HELPER, 'The estate office', null, 'office'))->toThrow(DomainException::class, AccessLevels::NOT_OPEN);

    // Member is always open: an add as a Member, and a level held from before taken back to Member.
    expect(lvlCall($this, $primaryToken, 'members.store', [], ['full_name' => 'Closed Member', 'relationship' => 'spouse', 'phone' => '876 555 0107', 'no_email' => true, 'access_level' => AppMatrix::MEMBER])->json('access_level'))->toBe(AppMatrix::MEMBER)
        ->and(lvlCall($this, $primaryToken, 'members.level', ['member' => $this->people[AppMatrix::DEPENDENT]->id], ['access_level' => AppMatrix::MEMBER])->json('access_level'))->toBe(AppMatrix::MEMBER);

    // And the matrix is still the rule on every request: a Helper's level held from before is still a Helper's — with panic.
    $helperToken = lvlToken($this->accounts[AppMatrix::HELPER]);

    expect(lvlRaw($this, $helperToken, 'invoices.index')->json('error.code'))->toBe('not_for_your_access_level')
        ->and(lvlRaw($this, $helperToken, 'panic.contacts')->status())->toBe(200)
        ->and(lvlCall($this, $helperToken, 'me.show')->json('access.level'))->toBe(AppMatrix::HELPER);
});

/* ── T-LVL-9 · panic's contacts, at every level ───────────────────────── */

it('gives every level the household\'s emergency contacts for the panic screen, and nothing more about the household (T-LVL-9)', function () {
    $contacts = DB::connection('tenant')->table('household_emergency_contacts');
    $now = now();

    // Added in this order, which is not the alphabet's: the panic sheet keeps the household's order.
    $contacts->insert(['household_id' => $this->household->id, 'name' => 'Marcia Levels', 'relationship' => 'spouse', 'phone' => '876-555-0148', 'created_at' => $now, 'updated_at' => $now]);
    $contacts->insert(['household_id' => $this->household->id, 'name' => 'Karl Levels', 'relationship' => null, 'phone' => '+1 876 555 0149', 'created_at' => $now, 'updated_at' => $now]);

    $otherUnit = FacilitiesFixture::scratchUnit('Lot 8882');
    $otherHousehold = lvlHousehold($otherUnit, 'Elsewhere household');
    $this->households[] = $otherHousehold;
    $contacts->insert(['household_id' => $otherHousehold->id, 'name' => 'Stranger Contact', 'relationship' => 'friend', 'phone' => '876-555-0999', 'created_at' => $now, 'updated_at' => $now]);

    $expected = [
        ['name' => 'Marcia Levels', 'relationship' => 'spouse', 'phone' => '876-555-0148'],
        ['name' => 'Karl Levels', 'relationship' => null, 'phone' => '+1 876 555 0149'],
    ];

    foreach (AppMatrix::LEVELS_ALL as $level) {
        expect(lvlCall($this, lvlToken($this->accounts[$level]), 'panic.contacts')->json('items'))->toBe($expected, $level);
    }

    // The rest of the household stays closed to a Helper.
    expect(lvlRaw($this, lvlToken($this->accounts[AppMatrix::HELPER]), 'contacts.index')->json('error.code'))->toBe('not_for_your_access_level')
        ->and(lvlRaw($this, lvlToken($this->accounts[AppMatrix::HELPER]), 'me.household')->json('error.code'))->toBe('not_for_your_access_level');

    // A pending account has no household to read; a read-only (demonstration) token may read it.
    $pending = lvlAccount(null, $this->unit, ResidentAccount::PENDING);

    expect(lvlRaw($this, lvlToken($pending), 'panic.contacts')->json('error.code'))->toBe('account_pending')
        ->and(lvlCall($this, lvlToken($this->accounts[AppMatrix::HELPER], readOnly: true), 'panic.contacts')->json('items'))->toBe($expected);
});
