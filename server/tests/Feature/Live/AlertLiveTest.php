<?php

declare(strict_types=1);

use App\Live\Channels;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Services\Dispatch\AlertIntake;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every step of an alert reaches every screen showing it (M11 §2, DESIGN.md §2.4 A)
|--------------------------------------------------------------------------
|
| The client's named defect: resolving a panic in the control room moved nothing else. The resolve wrote the
| row and broadcast nothing, so a second dispatcher, the alert's page, the estate's Security page and bar, the
| resident's panic screen and the guards' alert screen all waited for their polls. Written against that: before
| this change, resolve() recorded no live event at all.
|
| Each step now records ONE event to the live log (the relay publishes it): the Gemini dispatch feed and the
| estate's copy, the alert's own page, the estate's Security feed, the resident who raised it, and — after the
| raise — the guards it concerns. The event is a reference: which alert, which step. Nothing about who or where.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();

    $this->estate = FacilitiesFixture::ESTATE;
    $this->intake = app(AlertIntake::class);
    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** The live events one alert has recorded, in order: type => the channels it named. */
function alertEvents(int $alertId): array
{
    return DB::connection('mysql')->table('live_events')
        ->where('entity', 'alert')->where('entity_id', (string) $alertId)
        ->orderBy('id')
        ->get(['type', 'channels', 'mode', 'summary', 'tenant_id'])
        ->map(static fn (object $row): array => ['type' => $row->type, 'channels' => json_decode((string) $row->channels, true), 'mode' => $row->mode, 'summary' => $row->summary, 'tenant' => $row->tenant_id])
        ->all();
}

/** An officer of this estate, with no shift: only the steps' own rules put them in an audience. */
function alertLiveGuard(string $tenant, string $number): Guard
{
    $post = Post::create(['tenant_id' => $tenant, 'name' => 'Live Gate '.$number, 'type' => 'gate', 'is_active' => true]);

    return Guard::create([
        'full_name' => 'Officer '.$number, 'employee_number' => $number, 'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => $tenant, 'post_id' => $post->id,
    ]);
}

function residentPanic(object $test, array $extra = []): DuressAlert
{
    return $test->intake->record(...['tenantId' => $test->estate, 'kind' => 'panic', 'raisedByName' => 'Rae Live', 'unitReference' => 'Lot 9', 'raisedByAccountId' => 424242, 'fromPanicButton' => true, ...$extra]);
}

it('the raise tells every console, the estate\'s Security page, the alert\'s own page and the resident — and not the guards, whom the push and the ring reach', function () {
    $alert = residentPanic($this);
    $events = alertEvents($alert->id);

    expect($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('alert.raised')
        ->and($events[0]['mode'])->toBe('ref')
        ->and($events[0]['summary'])->toBeNull()
        ->and($events[0]['channels'])->toEqualCanonicalizing([
            'gemini.dispatch',
            Channels::geminiFeed($this->estate, 'dispatch'),
            Channels::geminiRecord($this->estate, 'dispatch', 'alert', $alert->id),
            Channels::estateFeed($this->estate, 'security'),
            Channels::resident($this->estate, 424242),
        ]);
});

it('an idempotent retry of the same press records nothing more', function () {
    $first = residentPanic($this, ['idempotencyKey' => 'live-alert-retry-1']);
    $again = residentPanic($this, ['idempotencyKey' => 'live-alert-retry-1']);

    expect($again->id)->toBe($first->id)
        ->and(alertEvents($first->id))->toHaveCount(1);
});

it('acknowledge, resolve and a false alarm each record their own step — the resolve the client named first of all', function () {
    $alert = residentPanic($this);
    $this->intake->acknowledge($alert, $this->dispatcher);
    $this->intake->resolve($alert, $this->dispatcher, 'Resident safe.', false, 'genuine_security');

    $other = residentPanic($this);
    $this->intake->resolve($other, $this->dispatcher, 'Pocket press.', true, 'false_alarm');

    expect(array_column(alertEvents($alert->id), 'type'))->toBe(['alert.raised', 'alert.acknowledged', 'alert.resolved'])
        ->and(array_column(alertEvents($other->id), 'type'))->toBe(['alert.raised', 'alert.false_alarm']);

    // The resolve reaches exactly the screens the raise did, and the resident's own panic screen among them.
    $resolved = alertEvents($alert->id)[2];
    expect($resolved['channels'])->toContain(Channels::estateFeed($this->estate, 'security'))
        ->and($resolved['channels'])->toContain(Channels::geminiRecord($this->estate, 'dispatch', 'alert', $alert->id))
        ->and($resolved['channels'])->toContain(Channels::resident($this->estate, 424242));
});

it('a second acknowledge, or a resolve of a closed alert, records nothing: the step already happened', function () {
    $alert = residentPanic($this);
    $this->intake->acknowledge($alert, $this->dispatcher);
    $this->intake->acknowledge($alert, $this->dispatcher);
    $this->intake->resolve($alert, $this->dispatcher, null, false, 'genuine_security');
    $this->intake->resolve($alert, $this->dispatcher, null, true, 'false_alarm');

    expect(array_column(alertEvents($alert->id), 'type'))->toBe(['alert.raised', 'alert.acknowledged', 'alert.resolved']);
});

it('a step that rolls back tells nobody', function () {
    $alert = residentPanic($this);

    try {
        DB::connection('mysql')->transaction(function () use ($alert): void {
            $this->intake->resolve($alert, $this->dispatcher, null, false, 'genuine_security');

            throw new RuntimeException('the write that owns it failed');
        });
    } catch (RuntimeException) {
    }

    expect(array_column(alertEvents($alert->id), 'type'))->toBe(['alert.raised']);
});

it('after the raise the responding officer hears every step on their own channel; a simulated alert reaches no guard', function () {
    $officer = alertLiveGuard($this->estate, 'GS-LIVE-1');

    $alert = residentPanic($this);
    $alert->forceFill(['responding_guard_id' => $officer->id])->saveQuietly();
    $this->intake->resolve($alert, $this->dispatcher, null, false, 'genuine_security');

    expect(last(alertEvents($alert->id))['channels'])->toContain(Channels::guard($this->estate, $officer->id));

    $drill = residentPanic($this, ['isSimulated' => true]);
    $drill->forceFill(['responding_guard_id' => $officer->id])->saveQuietly();
    $this->intake->resolve($drill, $this->dispatcher, null, false, 'genuine_security');

    $guardChannels = array_filter(last(alertEvents($drill->id))['channels'], static fn (string $c): bool => str_starts_with($c, 'guard.'));
    expect($guardChannels)->toBe([]);
});

it('a SILENT duress sends nothing to the phone of the guard who raised it; an audible one does', function () {
    $raiser = alertLiveGuard($this->estate, 'GS-LIVE-2');
    $mine = Channels::guard($this->estate, $raiser->id);

    $silent = $this->intake->record(tenantId: $this->estate, kind: 'duress', guardId: $raiser->id, fromPanicButton: true);
    $silent->forceFill(['mode' => 'silent'])->save();
    $this->intake->acknowledge($silent, $this->dispatcher);
    $this->intake->resolve($silent, $this->dispatcher, null, false, 'genuine_security');

    foreach (alertEvents($silent->id) as $event) {
        expect($event['channels'])->not->toContain($mine);
    }

    $audible = $this->intake->record(tenantId: $this->estate, kind: 'duress', guardId: $raiser->id, fromPanicButton: true);
    $audible->forceFill(['mode' => 'audible'])->save();
    $this->intake->acknowledge($audible, $this->dispatcher);

    expect(alertEvents($audible->id)[0]['channels'])->not->toContain($mine) // not on the raise: its mode is not known yet
        ->and(last(alertEvents($audible->id))['channels'])->toContain($mine);
});

it('the log keeps no personal data about the alert: no name, no lot, no place', function () {
    $alert = residentPanic($this, ['latitude' => 18.0179, 'longitude' => -76.8099]);
    $this->intake->resolve($alert, $this->dispatcher, 'Resident Rae Live is safe at Lot 9.', false, 'genuine_security');

    $raw = json_encode(DB::connection('mysql')->table('live_events')->where('entity', 'alert')->where('entity_id', (string) $alert->id)->get()->all());

    expect($raw)->not->toContain('Rae Live')
        ->and($raw)->not->toContain('Lot 9')
        ->and($raw)->not->toContain('18.0179')
        ->and($raw)->not->toContain('is safe');
});
