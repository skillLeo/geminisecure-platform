<?php

declare(strict_types=1);

use App\Live\LiveTypes;
use App\Live\Registry;

/*
|--------------------------------------------------------------------------
| Every type the server can send, for the catalogue the phones are generated from
|--------------------------------------------------------------------------
|
| The phones register a handler per type. A handler for a type no class emits can never run, so
| the catalogue carries App\Live\LiveTypes::all() and the phones' tests hold every handler to it.
| This proves the list is what the event classes emit: collected from every class the Registry
| discovers, each type once, sorted, in the grammar LiveBus accepts.
|
*/

beforeEach(fn () => Registry::reset());
afterEach(fn () => Registry::reset());

it('holds every step of a panic, a pass, an approval and a gate decision', function () {
    $types = LiveTypes::all();

    expect($types)->toContain('alert.raised', 'alert.resolved', 'alert.false_alarm', 'alert.reach')
        ->toContain('pass.issued', 'pass.cancelled', 'pass.used', 'pass.admitted')
        ->toContain('approval.requested', 'approval.answered', 'approval.expired')
        ->toContain('gate.admit', 'gate.deny')
        ->toContain('access.changed', 'live.resync', 'tile.changed');
});

it('names each type once, sorted, in the grammar LiveBus accepts', function () {
    $types = LiveTypes::all();

    $sorted = $types;
    sort($sorted);

    expect($types)->toBe($sorted)
        ->and($types)->toBe(array_values(array_unique($types)));

    foreach ($types as $type) {
        expect($type)->toMatch('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/');
    }
});

it('comes from every discovered class, so a new event class brings its types with it', function () {
    $classes = Registry::events();
    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $own = method_exists($class, 'types') ? $class::types() : [constant($class.'::TYPE')];

        expect($own)->not->toBeEmpty()
            ->and(LiveTypes::all())->toContain(...$own);
    }
});
