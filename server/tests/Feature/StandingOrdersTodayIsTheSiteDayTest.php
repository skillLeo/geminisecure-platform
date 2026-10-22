<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| "Takes effect today" is the site's today (bug hunt B106, the Gemini pages)
|--------------------------------------------------------------------------
|
| Both standing-orders forms defaulted and bounded the date with
| `new Date().toISOString()`, which is the UTC date whatever the browser's zone,
| and the service refused anything before `Carbon::today()` — UTC again. At
| 20:00 in Kingston on 30 September it is already 1 October in UTC, so the form
| opened on the 1st, would not let the office pick the 30th, and the server
| refused the 30th as backdating. A review done that evening was recorded as
| done on the 1st.
|
| The server now sends the day it means as `today`, read on the site's clock,
| and judges and records on the same clock.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    // 20:00 on 30 September in Kingston; 01:00 on 1 October in UTC.
    $this->travelTo(Carbon::parse('2026-10-01 01:00:00', 'UTC'));

    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->post = Post::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => 'Site Day Orders Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    Carbon::setTestNow();
});

it('sends the library form the site\'s today, and publishes orders taking effect on it', function () {
    $this->actingAs($this->director)
        ->get('/guards/standing-orders')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('today', '2026-09-30')
            // And each post carries its own estate's today, the earliest its orders may take effect.
            ->where('posts', fn ($posts) => (collect($posts)->firstWhere('id', $this->post->id)['today'] ?? null) === '2026-09-30'));

    $this->actingAs($this->director)
        ->post('/guards/standing-orders', [
            'category' => 'post_specific',
            'post_id' => $this->post->id,
            'body' => 'The barrier stays down between vehicles, and a following car waits its turn.',
            'effective_on' => '2026-09-30',
        ])
        ->assertSessionHasNoErrors();

    $set = DB::connection('mysql')->table('standing_order_sets')->where('post_id', $this->post->id)->first();

    expect($set->effective_on)->toStartWith('2026-09-30')
        ->and($set->reviewed_on)->toStartWith('2026-09-30');

    // Company-wide orders, on the operations room's clock.
    $this->actingAs($this->director)
        ->post('/guards/standing-orders', [
            'category' => 'general',
            'title' => 'Site Day General Orders',
            'summary' => 'Every post at every client',
            'body' => 'Every officer signs the occurrence book at the start and end of a shift.',
            'effective_on' => '2026-09-30',
        ])
        ->assertSessionHasNoErrors();

    // Yesterday on the site's clock is still refused as backdating.
    $this->actingAs($this->director)
        ->post('/guards/standing-orders', [
            'category' => 'general',
            'title' => 'Site Day Backdated Orders',
            'summary' => 'Every post at every client',
            'body' => 'These would instruct guards about a night already worked, which is refused.',
            'effective_on' => '2026-09-29',
        ])
        ->assertSessionHasErrors('orders');
});

it('sends one set\'s revise form the site\'s today, and revises and reviews on it', function () {
    $this->actingAs($this->director)
        ->post('/guards/standing-orders', [
            'category' => 'post_specific',
            'post_id' => $this->post->id,
            'body' => 'The barrier stays down between vehicles, and a following car waits its turn.',
            'effective_on' => '2026-09-30',
        ])
        ->assertSessionHasNoErrors();

    $setId = (int) DB::connection('mysql')->table('standing_order_sets')->where('post_id', $this->post->id)->value('id');

    $this->actingAs($this->director)
        ->get('/guards/standing-orders/'.$setId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('set.today', '2026-09-30'));

    $this->actingAs($this->director)
        ->post('/guards/standing-orders/'.$setId.'/revise', [
            'body' => 'The barrier stays down between vehicles. A following car waits, and is logged.',
            'effective_on' => '2026-09-30',
            'change_note' => 'Following cars are now logged.',
        ])
        ->assertSessionHasNoErrors();

    DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->update(['reviewed_on' => '2026-09-01']);

    $this->actingAs($this->director)
        ->post('/guards/standing-orders/'.$setId.'/reviewed')
        ->assertSessionHasNoErrors();

    expect(DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->value('reviewed_on'))
        ->toStartWith('2026-09-30');
});
