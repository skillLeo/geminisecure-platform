<?php

declare(strict_types=1);

use App\Live\Channels;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Every console page carries its live prop; the sign-in page carries none (M11 Part C, W0)
|--------------------------------------------------------------------------
|
| The shells start the live client with `live` and mount the line that says whether the screen is live. The
| prop's channels are decided for the viewer by the registry the socket's grants use, so a page never offers a
| channel its viewer would be refused. A page with no bindings of its own says, honestly, that it does not
| update by itself (DESIGN.md §6.4) — that is every page until its screen is wired.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** @return array<string, mixed>|null the page's `live` prop */
function livePropOf(object $test, object $viewer, string $url): ?array
{
    $live = null;

    $test->actingAs($viewer)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$live): AssertableInertia {
        $live = $page->toArray()['props']['live'] ?? null;

        return $page;
    });

    return $live;
}

it('an estate console page holds the viewer\'s own channel and the estate\'s panic feed, and binds nothing yet', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $live = livePropOf($this, $president, FacilitiesFixture::url('/residents'));

    $names = array_column($live['shell'], 'name');

    expect($names)->toContain(Channels::user($president->id))
        ->and($names)->toContain(Channels::estateFeed(FacilitiesFixture::ESTATE, 'security'))
        ->and($live['principal'])->toBe('u-'.$president->id)
        ->and($live['bindings'])->toBe([])
        ->and($live['kind'])->toBe('data')
        ->and($live['heads'])->toHaveKey('c');
});

it('a Gemini console page holds the dispatch feed for a Director, who reaches every estate', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $live = livePropOf($this, $director, '/dashboard');

    expect(array_column($live['shell'], 'name'))->toContain(Channels::geminiFanIn('dispatch'))
        ->and($live['bindings'])->toBe([]);
});

it('never offers a channel its viewer would be refused: an estate role without Security is not given the panic feed', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    if ($treasurer->can('estate.security.view')) {
        $this->markTestSkipped('The Treasurer holds Security in this matrix, so this case is not here to make.');
    }

    $live = livePropOf($this, $treasurer, FacilitiesFixture::url('/residents'));

    expect(array_column($live['shell'], 'name'))->not->toContain(Channels::estateFeed(FacilitiesFixture::ESTATE, 'security'));
});

it('the sign-in page has no live prop, so no socket is ever opened for a visitor', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('live', null));
});
