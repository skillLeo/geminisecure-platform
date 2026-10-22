<?php

declare(strict_types=1);

use App\Live\Tiles\TileRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Live\Fixtures\Tiles\ScopedCountTile;

/*
|--------------------------------------------------------------------------
| Tiles: one cached number per audience, never one audience's number for another (DESIGN.md §4.4)
|--------------------------------------------------------------------------
|
| A count on a dashboard is re-read when an event says it moved, by every open screen at once. So it
| is cached, but under WHO is looking: a scoped Head of Security's count of open alerts is not the
| Director's, and a cache keyed on the tile alone would show one of them the other's number.
|
*/

beforeEach(function () {
    TileRegistry::useDirectory(base_path('tests/Live/Fixtures/Tiles'), 'Tests\\Live\\Fixtures\\Tiles');
    ScopedCountTile::$computes = 0;
    ScopedCountTile::$scopes = [];

    DB::connection('mysql')->beginTransaction();

    $this->head = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    $this->director = User::factory()->create(['console' => 'gemini', 'status' => 'active']);

    ScopedCountTile::$scopes = [$this->head->email => 'sites:3,7', $this->director->email => 'all'];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    TileRegistry::reset();
});

it('never gives a scoped Head of Security the Director\'s cached count, or the other way round', function () {
    $director = TileRegistry::read('scoped_count', $this->director);
    $head = TileRegistry::read('scoped_count', $this->head);

    expect($director['open'])->toBe(12)
        ->and($head['open'])->toBe(3)
        ->and(ScopedCountTile::$computes)->toBe(2);

    // Each audience is then served its own entry, without computing again.
    expect(TileRegistry::read('scoped_count', $this->head))->toBe($head)
        ->and(TileRegistry::read('scoped_count', $this->director))->toBe($director)
        ->and(ScopedCountTile::$computes)->toBe(2);

    // A second viewer of the SAME scope shares the Head's entry: that is what the key is for.
    $colleague = User::factory()->create(['console' => 'gemini', 'status' => 'active']);
    ScopedCountTile::$scopes[$colleague->email] = 'sites:3,7';

    expect(TileRegistry::read('scoped_count', $colleague))->toBe($head)
        ->and(ScopedCountTile::$computes)->toBe(2);
});

it('computes afresh for everybody once an event bumps the tile', function () {
    TileRegistry::read('scoped_count', $this->head);
    TileRegistry::read('scoped_count', $this->director);

    TileRegistry::bump('scoped_count');

    expect(TileRegistry::read('scoped_count', $this->head)['computed'])->toBe(3)
        ->and(TileRegistry::read('scoped_count', $this->director)['computed'])->toBe(4)
        ->and(TileRegistry::read('scoped_count', $this->head)['computed'])->toBe(3);
});

it('answers GET /live/tiles per key, null alike for a tile the viewer may not see and one that does not exist', function () {
    $response = $this->actingAs($this->head)->getJson('/live/tiles?'.http_build_query(['keys' => ['scoped_count', 'no_such_tile']]))->assertOk();

    expect($response->json('tiles'))->toBe(['scoped_count' => ['open' => 3, 'computed' => 1], 'no_such_tile' => null])
        ->and($response->json('as_of'))->toMatch('/\+00:00$/');

    $this->head->forceFill(['status' => 'suspended'])->save();
    app('auth')->forgetGuards();

    $refused = $this->actingAs($this->head->fresh())->getJson('/live/tiles?'.http_build_query(['keys' => ['scoped_count']]));

    // A suspended account is signed out before the tile is asked (the web group's own rule), or refused by it.
    expect($refused->status() === 401 || $refused->json('tiles.scoped_count') === null)->toBeTrue();

    expect($this->actingAs($this->director)->getJson('/live/tiles?keys[]=Not-A-Key')->status())->toBe(422);
});
