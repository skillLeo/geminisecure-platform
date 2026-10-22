<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Role;
use App\Services\Estate\Residents;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Board 34 files a resident at the unit as the estate writes it (B041)
|--------------------------------------------------------------------------
|
| A reference is whatever the estate calls the unit: "Lot 47" at Phoenix Park,
| "OV-1A" at Ocean View, "A-12" or "14B" from the unit import. The add-resident
| service put "Lot " in front of whatever was typed, so an estate whose units are
| not lots could never add a resident at all — typing "A-12" looked for "Lot
| A-12" — and the refusal told the office to add a unit that was already there.
| The screen's preview did the same, and promised an address no estate has.
|
| THE ESTATE'S DATABASE IS COMMITTED AND SHARED, so every unit here is a scratch
| lot of this file's own, in the fixture's scratch block.
|
*/

const ADD_RESIDENT_PHASE = 'Phase 9';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Post board 34's form. */
function addResidentTyped(object $test, object $admin, string $phase, string $typed, string $name): mixed
{
    return $test->actingAs($admin)
        ->post(FacilitiesFixture::url('/residents'), [
            'phase' => $phase,
            'lot' => $typed,
            'full_name' => $name,
            // Nothing is optional (M18C): a number and an address for everybody put on the register.
            'phone' => '876 555 0123',
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'verification' => 'manual',
            // These lots gather people as the file runs, and an occupied lot asks what the person is (M32 F1): a member
            // here, which is what this file's adds always were. On an empty lot the answer is not needed and changes nothing.
            'household_choice' => 'member',
        ]);
}

/** Whether somebody of that name is now on that unit's household. */
function addResidentFiledAt(string $name, int $unitId): bool
{
    FacilitiesFixture::boot();

    return Resident::query()
        ->where('full_name', $name)
        ->whereHas('household', fn ($q) => $q->where('unit_id', $unitId))
        ->exists();
}

it('files a resident at a unit whose reference is not "Lot N", typed exactly as the estate writes it', function () {
    $unit = FacilitiesFixture::scratchUnit('OV-7B', ADD_RESIDENT_PHASE);

    addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, 'OV-7B', 'Ocean View Resident')
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(addResidentFiledAt('Ocean View Resident', $unit->id))->toBeTrue();
});

it('matches the reference whatever its capitals, and still reads a bare number as the lot it names', function () {
    $hyphen = FacilitiesFixture::scratchUnit('OV-8C', ADD_RESIDENT_PHASE);
    $lot = FacilitiesFixture::scratchUnit('Lot 9071', ADD_RESIDENT_PHASE);

    foreach ([['ov-8c', 'Lowercase Typist', $hyphen], ['9071', 'Bare Number Typist', $lot], ['lot 9071', 'Lowercase Lot Typist', $lot]] as [$typed, $name, $unit]) {
        addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, $typed, $name)->assertSessionHasNoErrors();

        expect(addResidentFiledAt($name, $unit->id))->toBeTrue($typed.' should have filed '.$name.' at '.$unit->reference);
    }
});

it('prefers the unit typed exactly over a "Lot" of the same name', function () {
    $bare = FacilitiesFixture::scratchUnit('Q-5', ADD_RESIDENT_PHASE);
    $lot = FacilitiesFixture::scratchUnit('Lot Q-5', ADD_RESIDENT_PHASE);

    foreach ([['Q-5', 'Exact Typist', $bare], ['Lot Q-5', 'Lot Typist', $lot]] as [$typed, $name, $unit]) {
        addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, $typed, $name)->assertSessionHasNoErrors();

        expect(addResidentFiledAt($name, $unit->id))->toBeTrue();
    }
});

it('refuses a unit the estate does not have, naming what was typed rather than a lot nobody typed', function () {
    FacilitiesFixture::scratchUnit('OV-9D', ADD_RESIDENT_PHASE);

    addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, 'OV-404', 'Nobody Filed')->assertSessionHasErrors('lot');

    $message = (string) session('errors')->first('lot');

    expect($message)->toContain('"OV-404"')
        ->and($message)->not->toContain('Lot OV-404');

    // A unit in another phase is not this phase's unit.
    addResidentTyped($this, $this->admin, 'Phase 1', 'OV-9D', 'Wrong Phase')->assertSessionHasErrors('lot');

    FacilitiesFixture::boot();

    expect(Resident::query()->whereIn('full_name', ['Nobody Filed', 'Wrong Phase'])->exists())->toBeFalse();
});

/**
 * Board 34's answer for the unit field, as the page asks for it: `lotLookup()`.
 *
 * @param  array<string, string>  $headers  for this request only
 */
function addResidentLookup(object $test, object $admin, string $phase, string $typed, array $headers = []): array
{
    $props = [];

    $test->actingAs($admin)
        ->get(FacilitiesFixture::url('/residents/new?'.http_build_query(['phase' => $phase, 'lot' => $typed])), $headers)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props) {
            $page->component('Estate/Residents/New')->missing('units');
            $props = $page->toArray()['props']['lot'];
        });

    return $props;
}

it('tells the add-resident screen the unit the register will file at, so its preview names the real one', function () {
    FacilitiesFixture::scratchUnit('OV-7B', ADD_RESIDENT_PHASE);

    $exact = addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, 'OV-7B');

    expect($exact['phase'])->toBe(ADD_RESIDENT_PHASE)
        ->and($exact['typed'])->toBe('OV-7B')
        ->and($exact['resolved'])->toBe('OV-7B')
        ->and($exact['suggestions'][0])->toBe('OV-7B');

    // A Phoenix Park lot, typed as a bare number, is the lot it names — and suggested first.
    $bare = addResidentLookup($this, $this->admin, 'Phase 2', '47');

    expect($bare['resolved'])->toBe('Lot 47')
        ->and($bare['suggestions'][0])->toBe('Lot 47')
        ->and(count($bare['suggestions']))->toBeLessThanOrEqual(Residents::LOT_SUGGESTIONS)
        ->and($bare['placeholder'])->not->toBeNull();

    // Nothing typed: the phase's first units as suggestions and its first as the placeholder, never the phase whole.
    $empty = addResidentLookup($this, $this->admin, 'Phase 2', '');

    expect($empty['resolved'])->toBeNull()
        ->and($empty['suggestions'])->not->toBeEmpty()
        ->and(count($empty['suggestions']))->toBeLessThanOrEqual(Residents::LOT_SUGGESTIONS)
        ->and($empty['placeholder'])->toBe($empty['suggestions'][0]);

    // No phase asked: the answer is for the form's first phase, the one the select opens on.
    $unasked = addResidentLookup($this, $this->admin, '', '');
    FacilitiesFixture::boot();

    expect($unasked['phase'])->toBe((string) (Phase::query()->orderBy('sequence')->value('name') ?? ''));

    // A phase with no unit in it resolves nothing and has no placeholder to offer.
    $none = addResidentLookup($this, $this->admin, 'No such phase', 'Lot 47');

    expect($none['phase'])->toBe('No such phase')
        ->and($none['resolved'])->toBeNull()
        ->and($none['suggestions'])->toBe([])
        ->and($none['placeholder'])->toBeNull();
});

it('resolves on the screen exactly what the register files at, for every way of typing a unit', function () {
    $hyphen = FacilitiesFixture::scratchUnit('OV-8C', ADD_RESIDENT_PHASE);
    $lot = FacilitiesFixture::scratchUnit('Lot 9071', ADD_RESIDENT_PHASE);
    $bare = FacilitiesFixture::scratchUnit('Q-5', ADD_RESIDENT_PHASE);
    $lotQ = FacilitiesFixture::scratchUnit('Lot Q-5', ADD_RESIDENT_PHASE);
    FacilitiesFixture::scratchUnit('OV-9D', ADD_RESIDENT_PHASE);

    foreach ([
        ['ov-8c', $hyphen], ['OV-8C', $hyphen], ['9071', $lot], ['lot 9071', $lot], ['Lot 9071', $lot],
        ['Q-5', $bare], ['Lot Q-5', $lotQ], ['OV-404', null], ['  ov-8c  ', $hyphen],
    ] as $i => [$typed, $unit]) {
        $answer = addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, $typed);

        expect($answer['resolved'])->toBe($unit?->reference, 'the screen, for "'.$typed.'"');

        // And the register files there — or refuses — exactly as the screen said.
        $name = 'Screen Typist '.$i;
        $posted = addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, $typed, $name);

        if ($unit === null) {
            $posted->assertSessionHasErrors('lot');
        } else {
            $posted->assertSessionHasNoErrors();
            expect(addResidentFiledAt($name, $unit->id))->toBeTrue('the register, for "'.$typed.'"');
        }
    }

    // A unit in another phase is not this phase's unit, on the screen as in the register.
    expect(addResidentLookup($this, $this->admin, 'Phase 1', 'OV-9D')['resolved'])->toBeNull();

    // A "%" or "_" typed is only itself, not a pattern that matches every unit in the phase.
    foreach (['%', '_'] as $character) {
        $suggested = addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, $character)['suggestions'];

        expect($suggested)->not->toContain('OV-8C')
            ->and(array_filter($suggested, static fn (string $reference): bool => ! str_contains($reference, $character)))->toBe([]);
    }
});

/*
 * The page tells which of its lookups an answer is for by a number it sends in a header,
 * because it cannot tell by comparing `typed` with its field: Laravel's TrimStrings strips
 * characters off the ends that JavaScript's trim() keeps (M11 A2 fix-up). The page's half
 * is tests/js/estate-add-resident.test.mjs.
 */
it('hands the page back the number of the lookup it answers, and nothing for any other request', function () {
    FacilitiesFixture::scratchUnit('Lot 9072', ADD_RESIDENT_PHASE);

    expect(addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, 'Lot 9072', ['X-Lot-Question' => '7'])['question'])->toBe(7)
        ->and(addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, 'Lot 9072', ['X-Lot-Question' => '123456789'])['question'])->toBe(123456789);

    // A first load, or a refused post coming back, is no lookup of the page's; nor is anything but a plain number.
    foreach ([[], ['X-Lot-Question' => ''], ['X-Lot-Question' => 'seven'], ['X-Lot-Question' => '-1'], ['X-Lot-Question' => '1e3'], ['X-Lot-Question' => '1234567890']] as $headers) {
        expect(addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, 'Lot 9072', $headers)['question'])
            ->toBeNull('for '.json_encode($headers));
    }
});

it('reads a lot pasted with an invisible character as the register does, and files it there', function () {
    $unit = FacilitiesFixture::scratchUnit('Lot 9073', ADD_RESIDENT_PHASE);

    // Str::INVISIBLE_CHARACTERS that JavaScript's trim() keeps: a zero-width space, a left-to-right mark, a soft hyphen, a word joiner.
    foreach (["Lot 9073\u{200B}", "\u{200E}Lot 9073", "Lot 9073\u{00AD}", "\u{2060}Lot 9073\u{2060}"] as $i => $typed) {
        $answer = addResidentLookup($this, $this->admin, ADD_RESIDENT_PHASE, $typed);

        expect($answer['typed'])->toBe('Lot 9073', 'the register reads '.json_encode($typed).' as the unit')
            ->and($answer['resolved'])->toBe('Lot 9073');

        // And the post, trimmed by the same middleware, files where the screen said.
        $name = 'Pasted Typist '.$i;

        addResidentTyped($this, $this->admin, ADD_RESIDENT_PHASE, $typed, $name)->assertSessionHasNoErrors();

        expect(addResidentFiledAt($name, $unit->id))->toBeTrue('the register, for '.json_encode($typed));
    }
});
