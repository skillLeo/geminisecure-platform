<?php

declare(strict_types=1);

use App\Models\Estate\DunningNotice;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\Role;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\OwnEstate;

/*
|--------------------------------------------------------------------------
| Hardship and dispute — board 6 (12 §1)
|--------------------------------------------------------------------------
|
| THE RULING: "a reason and a committee minute reference, it suppresses
| automated dunning, and it does not change collection behaviour." The last
| two are not in tension, and this suite holds both at once: the automated
| queue skips the household, and every figure about the debt is identical to
| the cent before and after.
|
| AN ESTATE OF ITS OWN (M11 B5). The flags, the dunning notices and the runs
| below are written to `collectionflagtest`, which no other file uses, and the
| 450-lot estate they need is built there. This file used to build it over
| `FacilitiesFixture`'s SHARED estate, truncating the estate every other
| facilities file stands on in the middle of the process, and leaving its
| notices behind for them. `Tests\Support\OwnEstate` has the whole account.
|
*/

/** This file's own estate: its subdomain, its tenant id and its database suffix. */
function collectionFlagEstate(): OwnEstate
{
    static $estate = null;

    return $estate ??= new OwnEstate('collectionflagtest', 'CFT', 'Collection Flag Test Estate');
}

beforeEach(function () {
    collectionFlagEstate()->boot();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    // The tenant database is not rolled back, so this suite clears what it made.
    UnitCollectionFlag::query()->delete();
});

it('flags a household without moving one cent of what it owes, and lifts the flag again', function () {
    $treasurer = collectionFlagEstate()->viewer(Role::TREASURER);
    $assistant = collectionFlagEstate()->viewer(Role::ESTATE_ADMIN_ASSISTANT);

    $unit = Unit::query()->where('reference', 'Lot 63')->firstOrFail();
    $dues = app(Dues::class);

    $before = [
        'balance' => $dues->balanceOf($unit)->getMinorAmount()->toInt(),
        'board' => $dues->unitBoard($unit),
        'arrears' => $dues->arrearsBoard('', false),
    ];

    $this->actingAs($treasurer)
        ->get(collectionFlagEstate()->url('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canFlag', true)
            ->where('flag', null)
            ->has('flagKinds'));

    // Entry on Dues & ledger prepares the case; the committee agrees it.
    $this->actingAs($assistant)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute', 'reason' => 'x', 'minute_reference' => 'Min. 1',
        ])
        ->assertForbidden();

    // A reason and a minute are both required, and each refusal says why.
    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute', 'reason' => 'The August charge is disputed.', 'minute_reference' => '',
        ])
        ->assertSessionHasErrors('minute_reference');

    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute',
            'reason' => 'The household says the August maintenance charge was raised twice.',
            'minute_reference' => 'Min. 2026-09-03 §4',
        ])
        ->assertRedirect(collectionFlagEstate()->url('/finance/units/'.$unit->id));

    collectionFlagEstate()->boot();

    $flag = UnitCollectionFlag::query()->where('unit_id', $unit->id)->sole();

    expect($flag->kind)->toBe(UnitCollectionFlag::DISPUTE)
        ->and($flag->minute_reference)->toBe('Min. 2026-09-03 §4')
        ->and($flag->raised_by_name)->toBe($treasurer->name)
        ->and($flag->isInForce())->toBeTrue();

    /*
     * THE DEBT HAS NOT MOVED. Not the balance, not the ageing strip, not one
     * figure on the arrears board. A flag that quietly shrank a balance would
     * be a write-off nobody voted for.
     */
    $withoutFlags = static fn (array $board): array => [
        ...$board,
        'rows' => array_map(static fn (array $row): array => array_diff_key($row, ['flag' => true]), $board['rows']),
    ];

    expect($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before['balance'])
        ->and($dues->unitBoard($unit)['strip'])->toBe($before['board']['strip'])
        ->and($dues->unitBoard($unit)['bucket'])->toBe($before['board']['bucket'])
        ->and($withoutFlags($dues->arrearsBoard('', false)))->toBe($withoutFlags($before['arrears']));

    // A second flag on top of the first would read as two episodes at once.
    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'hardship', 'reason' => 'Also this.', 'minute_reference' => 'Min. 5',
        ])
        ->assertSessionHasErrors('reason');

    // A lift needs a reason AND a minute, as the raise did (13 A4).
    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag/'.$flag->id.'/lift'), [
            'lifted_reason' => 'The duplicate charge was found and reversed.',
        ])
        ->assertSessionHasErrors('lifted_minute_reference');

    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag/'.$flag->id.'/lift'), [
            'lifted_minute_reference' => 'Min. 2026-10-01 §3',
        ])
        ->assertSessionHasErrors('lifted_reason');

    collectionFlagEstate()->boot();

    expect(UnitCollectionFlag::query()->findOrFail($flag->id)->isInForce())->toBeTrue();

    // Lifted, the row stays — an episode keeps its end.
    $this->actingAs($treasurer)
        ->post(collectionFlagEstate()->url('/finance/units/'.$unit->id.'/flag/'.$flag->id.'/lift'), [
            'lifted_reason' => 'The duplicate charge was found and reversed.',
            'lifted_minute_reference' => 'Min. 2026-10-01 §3',
        ])
        ->assertRedirect();

    collectionFlagEstate()->boot();

    $flag = UnitCollectionFlag::query()->findOrFail($flag->id);

    expect($flag->isInForce())->toBeFalse()
        ->and($flag->lifted_by_name)->toBe($treasurer->name)
        ->and($flag->lifted_reason)->toBe('The duplicate charge was found and reversed.')
        ->and($flag->lifted_minute_reference)->toBe('Min. 2026-10-01 §3')
        ->and($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before['balance']);
});

it('takes a flagged household out of the automated queue and leaves the deliberate send alone', function () {
    $treasurer = collectionFlagEstate()->viewer(Role::TREASURER);
    $collections = app(Collections::class);

    $queue = $collections->automatedQueue();

    // Somebody in arrears to flag. Every estate on the seed has one.
    $unitId = $queue['due'][0] ?? null;

    expect($unitId)->not->toBeNull()
        ->and($queue['suppressed'])->toBe([]);

    $unit = Unit::query()->findOrFail($unitId);

    $collections->flag($unit, UnitCollectionFlag::HARDSHIP, 'Lost employment; paying what they can.', 'Min. 2026-08-06 §2', $treasurer);

    $after = $collections->automatedQueue();

    expect($after['due'])->not->toContain($unitId)
        ->and(count($after['due']))->toBe(count($queue['due']) - 1)
        ->and(collect($after['suppressed'])->pluck('unit_id')->all())->toBe([$unitId])
        ->and($after['suppressed'][0]['headline'])->toBe('Hardship, under Min. 2026-08-06 §2');

    /*
     * A DELIBERATE SEND IS STILL ALLOWED, and that is the ruling's own shape:
     * what a flag stops is the machine sending a final demand at 09:00 on
     * Tuesday. A person pressing "Send reminder now" is making a decision with
     * their name against it, and the estate is allowed to make it.
     */
    $notice = $collections->send($unit, DunningTemplate::query()->where('is_active', true)->orderBy('stage')->firstOrFail(), $treasurer);

    expect($notice->unit_id)->toBe($unit->id);
});

/* ------------------------------------------------------------------ */
/* 13 A4 · the automated run that the flag suppresses */
/* ------------------------------------------------------------------ */

it('runs the ladder: sends the step a household has reached, once, never to a flagged one, and again after a lift', function () {
    $collections = app(Collections::class);
    $treasurer = collectionFlagEstate()->viewer(Role::TREASURER);
    $today = Carbon::today();

    $oldest = app(Dues::class)->oldestOpenChargeDates($today);
    $firstStep = DunningTemplate::query()->where('is_active', true)->orderBy('days_overdue')->firstOrFail();

    // Two households that have reached the ladder's first step and are not on a
    // plan, with no notice yet in this episode.
    $reached = Unit::query()
        ->whereIn('id', $collections->automatedQueue()['due'])
        ->orderBy('id')
        ->get()
        ->filter(fn (Unit $unit): bool => isset($oldest[$unit->id])
            && (int) $oldest[$unit->id]->diffInDays($today, absolute: false) >= $firstStep->days_overdue
            && ! $collections->isProtected($unit))
        ->take(2)
        ->values();

    expect($reached)->toHaveCount(2);

    [$chased, $flagged] = [$reached[0], $reached[1]];

    DunningNotice::query()->whereIn('unit_id', [$chased->id, $flagged->id])->delete();

    $collections->flag($flagged, UnitCollectionFlag::HARDSHIP, 'Lost employment; paying what they can.', 'Min. 2026-08-06 §2', $treasurer);

    /*
     * THE ARREARS LIST GAINS THE FLAG ITSELF, on the row, and nothing else — so a
     * treasurer deciding who to chase by hand can see the committee protected
     * this household.
     */
    $rows = collect(app(Dues::class)->arrearsBoard('', false)['rows']);

    expect($rows->firstWhere('id', $flagged->id)['flag'])->toBe([
        'kind' => UnitCollectionFlag::HARDSHIP,
        'label' => 'Hardship',
        'headline' => 'Hardship, under Min. 2026-08-06 §2',
    ])
        ->and($rows->firstWhere('id', $chased->id)['flag'])->toBeNull();

    // A DRY RUN SENDS NOTHING, and says what it would have.
    $before = DunningNotice::query()->count();
    $dry = $collections->runAutomated($today, dryRun: true);

    expect(DunningNotice::query()->count())->toBe($before)
        ->and(collect($dry['sent'])->pluck('unit')->all())->toContain((string) $chased->reference)
        ->and(collect($dry['sent'])->pluck('unit')->all())->not->toContain((string) $flagged->reference);

    $run = $collections->runAutomated($today);

    // The highest step the household's days overdue has reached, signed as the run.
    $overdue = (int) $oldest[$chased->id]->diffInDays($today, absolute: false);
    $expected = DunningTemplate::query()
        ->where('is_active', true)
        ->where('days_overdue', '<=', $overdue)
        ->orderByDesc('days_overdue')
        ->orderByDesc('stage')
        ->firstOrFail();

    $notice = DunningNotice::query()->where('unit_id', $chased->id)->sole();

    expect($notice->stage)->toBe($expected->stage)
        ->and($notice->sent_by_name)->toBe(Collections::AUTOMATED_SENDER)
        ->and($notice->delivery_state)->toBe(DunningNotice::QUEUED)
        ->and($run['suppressed'])->toBeGreaterThanOrEqual(1)

        // THE FLAG SUPPRESSED IT. This is what 12 §1 promised and nothing enforced.
        ->and(DunningNotice::query()->where('unit_id', $flagged->id)->exists())->toBeFalse();

    // Run again the same morning: nothing new for either household.
    $collections->runAutomated($today);

    expect(DunningNotice::query()->where('unit_id', $chased->id)->count())->toBe(1)
        ->and(DunningNotice::query()->where('unit_id', $flagged->id)->exists())->toBeFalse();

    // Lifted under a minute, the household is chased on the next run.
    $collections->liftFlag($collections->flagFor($flagged), 'Employment resumed; the committee agreed to resume.', 'Min. 2026-10-01 §3', $treasurer);
    $collections->runAutomated($today);

    expect(DunningNotice::query()->where('unit_id', $flagged->id)->count())->toBe(1);
});

it('refuses a lift without a minute in the service too, not only at the form', function () {
    $collections = app(Collections::class);
    $treasurer = collectionFlagEstate()->viewer(Role::TREASURER);
    $unit = Unit::query()->where('reference', 'Lot 63')->firstOrFail();

    $flag = $collections->flag($unit, UnitCollectionFlag::DISPUTE, 'Disputed charge.', 'Min. 1', $treasurer);

    expect(fn () => $collections->liftFlag($flag, 'Resolved.', '', $treasurer))->toThrow(DomainException::class, 'minute')
        ->and(fn () => $collections->liftFlag($flag, '', 'Min. 2', $treasurer))->toThrow(DomainException::class, 'Say why');
});

it('schedules the run for nine every morning on each estate\'s own clock, and runs it from the command line', function () {
    Artisan::call('schedule:list');
    $listed = Artisan::output();

    // Every fifteen minutes, taking each estate once its OWN clock reaches 09:00 (M11 §4.2, LocalSchedule):
    // TwoEstatesAcrossDaylightSavingTest proves the hour in Kingston and New York. `schedule:list` pads each
    // cron field to the widest entry listed, so the expression is matched field by field.
    expect($listed)->toContain('dunning:run')
        ->and($listed)->toMatch('#\*/15\s+\*\s+\*\s+\*\s+\*\s+php artisan dunning:run --at=09:00#');

    expect(Artisan::call('dunning:run', ['--estate' => collectionFlagEstate()->key, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('would be sent');

    collectionFlagEstate()->boot();
});
