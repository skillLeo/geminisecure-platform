<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Estate\EstateStructure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M32 F2 and F6: the contracted limit, and which lots are free (4 October 2026)
|--------------------------------------------------------------------------
|
| F2: "2,000 of 2,000 contracted units in use", and a sixth phase still went in. A full estate now takes no new phase,
| lots or none, and the screen says where it stands before anybody starts. The contract is raised in the Gemini console,
| as a contract change with a reason.
|
| F6: "Lots from 90 to 100" was answered only after submitting. The form now says which lots are in use and free,
| suggests the next free block, and says what a typed range is before it is sent.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: every phase and lot this file adds is taken back. The subscription row is
| the platform's, rolled back with the transaction.
|
*/

const M32_PHASES = ['M32 Fits', 'M32 Raised', 'M32 Suggested', 'M32 Over', 'M32 Unlimited', 'M32 Overlap'];

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $this->plan = Plan::query()->firstOrCreate(
        ['key' => 'm32-contract-test'],
        ['name' => 'M32 Contract Test', 'price_per_unit_minor' => 340_00, 'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 98],
    );
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    DB::connection('tenant')->table('units')->whereIn('block', M32_PHASES)->delete();
    DB::connection('tenant')->table('estate_phases')->whereIn('name', M32_PHASES)->delete();
});

/** The fixture estate's contract, set to its units plus `$room`; null takes the figure away. */
function m32Contract(?int $room): int
{
    FacilitiesFixture::boot();
    $units = Unit::query()->count();

    Subscription::query()->updateOrCreate(
        ['tenant_id' => FacilitiesFixture::ESTATE],
        ['plan_id' => test()->plan->id, 'unit_count' => $room === null ? 0 : $units + $room, 'status' => 'active'],
    );

    return $units;
}

/** A free numbered range of `$count` lots, above every lot the shared estate has. */
function m32FreeRange(int $count): array
{
    FacilitiesFixture::boot();
    $highest = (int) Unit::query()->get(['reference'])
        ->map(static fn (Unit $u): int => preg_match('/^lot\s+(\d+)$/i', (string) $u->reference, $m) === 1 ? (int) $m[1] : 0)
        ->max();

    return [$highest + 1, $highest + $count];
}

function m32AddPhase(object $test, string $name, ?array $range): TestResponse
{
    return $test->actingAs($test->manager)->post(FacilitiesFixture::url('/estate/phases'), [
        'name' => $name, 'block_count' => 1,
        ...($range === null ? [] : ['lot_from' => $range[0], 'lot_to' => $range[1]]),
    ]);
}

/* ── F2 ──────────────────────────────────────────────────────────────────────────────────────────────────────── */

it('creates a phase that fits the contract', function () {
    $units = m32Contract(5);

    m32AddPhase($this, 'M32 Fits', m32FreeRange(3))->assertSessionHasNoErrors()->assertRedirect();
    FacilitiesFixture::boot();

    expect(Unit::query()->where('block', 'M32 Fits')->count())->toBe(3)
        ->and(Unit::query()->count())->toBe($units + 3);
});

it('refuses, on the server, a phase that would pass the contract, naming the three numbers', function () {
    $units = m32Contract(3);

    m32AddPhase($this, 'M32 Over', m32FreeRange(10))->assertSessionHasErrors('name');
    $message = session('errors')->first('name');

    // M78 §1: no "contract" on the page; the figure is the one the estate is allowed.
    expect($message)->toContain('allowed '.number_format($units + 3).' units')
        ->toContain(number_format($units).' are in use')
        ->toContain('3 are left')
        ->not->toContain('contract');

    FacilitiesFixture::boot();
    expect(Phase::query()->where('name', 'M32 Over')->exists())->toBeFalse();
});

it('refuses any new phase on a full estate, lots or none, with the three numbers', function () {
    $units = m32Contract(0);

    m32AddPhase($this, 'M32 Over', null)->assertSessionHasErrors('name');
    expect(session('errors')->first('name'))->toContain('Your estate is full')
        ->toContain('allowed '.number_format($units).' units')
        ->toContain(number_format($units).' are in use')
        ->toContain('0 are left')
        ->not->toContain('contract');

    FacilitiesFixture::boot();
    expect(Phase::query()->where('name', 'M32 Over')->exists())->toBeFalse();
});

it('shows the numbers at the top of the screen and disables Add phase on a full estate, with one line why', function () {
    $units = m32Contract(0);

    // M78 §3: the line under the figures is Hassam's, and the add controls say "No lots left."
    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('standing.contracted', $units)
            ->where('standing.units', $units)
            ->where('standing.register', $units)
            ->where('standing.left', 0)
            ->where('standing.full', true)
            ->where('standing.sentence', 'Your estate is full. Please contact your service provider to add more.')
            ->where('standing.add_refusal', 'No lots left.'));

    // Room left: the last line says how much.
    m32Contract(12);
    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('standing.left', 12)->where('standing.full', false)
            ->where('standing.sentence', 'You can add up to 12 more units.')->where('standing.add_refusal', null));

    // Both controls that open the form are off while the estate is full (or built from a site plan, M57.5 §4.1). M78 §3:
    // the sentence that stood beside the topbar's button is gone; the add tile says why in one line.
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Structure/Index.vue'));
    expect(substr_count($page, ':disabled="!mayAdd"'))->toBe(2)
        ->and($page)->toContain('const mayAdd = computed(() => props.canCreate && !props.standing.full && !props.standing.plan_refusal)')
        ->and(str_contains($page, 'class="str-full-note"'))->toBeFalse()
        ->and(str_contains($page, '{{ standing.add_refusal }}'))->toBeTrue();
});

it('lets a phase refused on a full estate in once the Gemini console raises the contract, with the reason on the audit log', function () {
    $units = m32Contract(0);
    $range = m32FreeRange(5);

    m32AddPhase($this, 'M32 Raised', $range)->assertSessionHasErrors('name');

    // Raised in the Gemini console, as a contract change: refused without a reason, saved with one.
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $change = ['plan_id' => $this->plan->id, 'units' => $units + 10, 'guards' => 0, 'term_months' => null];

    $this->actingAs($director)->from('/clients/'.FacilitiesFixture::ESTATE.'/plan')
        ->post('/clients/'.FacilitiesFixture::ESTATE.'/plan', $change)
        ->assertSessionHasErrors('reason');
    expect(Subscription::query()->where('tenant_id', FacilitiesFixture::ESTATE)->value('unit_count'))->toBe($units);

    $this->actingAs($director)->from('/clients/'.FacilitiesFixture::ESTATE.'/plan')
        ->post('/clients/'.FacilitiesFixture::ESTATE.'/plan', [...$change, 'reason' => 'Phase 6 signed with the committee'])
        ->assertSessionHasNoErrors();

    $entry = AuditEntry::query()->where('action', 'client.plan_changed')->where('entity_id', FacilitiesFixture::ESTATE)->latest('id')->firstOrFail();
    expect($entry->before['unit_count'])->toBe($units)
        ->and($entry->after['unit_count'])->toBe($units + 10)
        ->and($entry->after['reason'])->toBe('Phase 6 signed with the committee')
        ->and($entry->actor_name)->toBe($director->name);

    // The phase refused a moment ago goes in now.
    m32AddPhase($this, 'M32 Raised', $range)->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Unit::query()->where('block', 'M32 Raised')->count())->toBe(5);
});

it('treats an estate with no contracted number as unlimited, and says so', function () {
    m32Contract(null);

    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('standing.contracted', null)
            ->where('standing.left', null)
            ->where('standing.full', false)
            // M78 §2: the figures say it ("Not set", "No limit"), so no sentence repeats them.
            ->where('standing.sentence', '')
            ->where('standing.add_refusal', null));

    m32AddPhase($this, 'M32 Unlimited', m32FreeRange(2))->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Unit::query()->where('block', 'M32 Unlimited')->count())->toBe(2);
});

/* ── F6 ──────────────────────────────────────────────────────────────────────────────────────────────────────── */

it('names the free ranges correctly for an estate with gaps in its numbering', function () {
    // Lots 1 to 1998 and 2000 in use, 1999 free: the M32 description's own example.
    $numbered = array_fill_keys(range(1, 1998), 'Phase 1') + [2000 => 'Phase 5'];
    $panel = EstateStructure::panel($numbered, ['Lot 1999A'], 71.4, null);

    expect($panel['used_label'])->toBe('Lot 1 to Lot 1998, and Lot 2000')
        ->and($panel['free_label'])->toBe('Lot 1999, and anything from Lot 2001 upward')
        ->and($panel['others'])->toBe('1 lot without a plain number: Lot 1999A');

    // Many gaps: the first three, "and N more", and everything above the highest.
    $gappy = [2 => 'A', 4 => 'A', 6 => 'A', 8 => 'A', 10 => 'A', 12 => 'A'];
    expect(EstateStructure::panel($gappy, [], null, null)['free_label'])->toBe('Lot 1, Lot 3, Lot 5, 3 more gaps, and anything from Lot 13 upward')
        ->and(EstateStructure::panel($gappy, [], null, null)['used_label'])->toBe('Lot 2, Lot 4, Lot 6, and 3 more');

    // An estate with no numbered lot yet.
    expect(EstateStructure::panel([], [], null, null)['free_label'])->toBe('Anything from Lot 1 upward');
});

it('suggests the next free block of the size the blocks imply, never more than the contract has room for', function () {
    $numbered = array_fill_keys(range(1, 100), 'Phase 1');

    // Fifty lots a block: four blocks is two hundred, from the lot after the highest.
    expect(EstateStructure::panel($numbered, [], 50.0, null, 4)['suggested'])->toBe(['from' => 101, 'to' => 300])
        ->and(EstateStructure::panel($numbered, [], 50.0, 30, 4)['suggested'])->toBe(['from' => 101, 'to' => 130])
        ->and(EstateStructure::panel($numbered, [], 50.0, 0, 4)['suggested'])->toBeNull()
        ->and(EstateStructure::panel($numbered, [], 50.0, 0, 4)['suggested_label'])->toBe('Nothing to suggest: no lots left.');
});

it('fills a suggested range with Use these that then validates clean and is created', function () {
    m32Contract(4);

    $suggested = null;
    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate?blocks=1'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$suggested) {
            $page->where('lots.blocks', 1)->where('lots.suggested', function ($s) use (&$suggested) {
                $suggested = $s instanceof Collection ? $s->all() : ($s === null ? null : (array) $s);

                return $s !== null;
            });
        });

    // Capped by the contract: four lots at most.
    expect($suggested['to'] - $suggested['from'] + 1)->toBeLessThanOrEqual(4);

    // The form's line says it is free before it is sent…
    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate?blocks=1&from='.$suggested['from'].'&to='.$suggested['to']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lots.check.ok', true)->where('lots.check.line', fn (string $line) => str_ends_with($line, 'All free.')));

    // …and it is created as typed.
    m32AddPhase($this, 'M32 Suggested', [$suggested['from'], $suggested['to']])->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    expect(Unit::query()->where('block', 'M32 Suggested')->count())->toBe($suggested['to'] - $suggested['from'] + 1);
});

it('names an overlapping range before it is sent, and the server refuses it on submit', function () {
    m32Contract(null);
    FacilitiesFixture::scratchUnit('Lot 77701');

    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate?blocks=1&from=77700&to=77703'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lots.check.ok', false)->where('lots.check.line', 'Lot 77701 is already in Phase 9.'));

    m32AddPhase($this, 'M32 Overlap', [77700, 77703])->assertSessionHasErrors('name');
    expect(session('errors')->first('name'))->toContain('1 of those lots already exist — Lot 77701');
});

it('refuses a range that fits the numbering but breaks the contract, with F2\'s message, and says so before it is sent', function () {
    $units = m32Contract(2);
    $range = m32FreeRange(5);

    $this->actingAs($this->manager)->get(FacilitiesFixture::url('/estate?blocks=1&from='.$range[0].'&to='.$range[1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lots.check.ok', false)->where('lots.check.line', fn (string $line) => str_ends_with($line, 'but only 2 more are allowed.')));

    m32AddPhase($this, 'M32 Over', $range)->assertSessionHasErrors('name');
    expect(session('errors')->first('name'))->toContain('allowed '.number_format($units + 2).' units')->toContain('2 are left');
});
