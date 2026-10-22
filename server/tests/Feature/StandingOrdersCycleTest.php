<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Standing orders — the acknowledgement cycle (12 §2, item 28)
|--------------------------------------------------------------------------
|
| Published at version 1; acknowledged from the handset AGAINST THE VERSION
| READ; revised to the next version, which every guard on post acknowledges
| afresh. Every version's text is kept. A review that changes nothing resets
| nobody. Company-wide orders are written by a role that covers every client.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => 'Cycle Test Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->guard = Guard::create([
        'full_name' => 'Cycle Test Officer',
        'employee_number' => 'GS-CYC-1',
        'psra_number' => 'PSRA-CYC-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('publishes, is acknowledged against the version read, and asks again on revision', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $accountant = FacilitiesFixture::geminiViewer(Role::ACCOUNTANT);

    $orders = [
        'category' => 'post_specific',
        'post_id' => $this->post->id,
        'body' => 'The barrier stays down between vehicles. No exceptions for a following car.',
        // The POST's estate's today, which is what `StandingOrders::today()` compares with — the server's UTC date is
        // already tomorrow from 7 PM in Kingston, when UTC "yesterday" is still today at the gate.
        'effective_on' => SiteTime::today((string) $this->post->tenant_id)->toDateString(),
    ];

    $this->actingAs($accountant)->post('/guards/standing-orders', $orders)->assertForbidden();

    // No backdating: orders do not instruct guards about shifts already worked.
    $this->actingAs($director)
        ->post('/guards/standing-orders', [...$orders, 'effective_on' => SiteTime::today((string) $this->post->tenant_id)->subDay()->toDateString()])
        ->assertSessionHasErrors('orders');

    $this->actingAs($director)->post('/guards/standing-orders', $orders)->assertSessionHasNoErrors();

    $setId = (int) DB::connection('mysql')->table('standing_order_sets')->where('post_id', $this->post->id)->value('id');

    expect($setId)->toBeGreaterThan(0)
        ->and(DB::connection('mysql')->table('standing_order_versions')->where('standing_order_set_id', $setId)->where('version', 1)->exists())->toBeTrue();

    // One current set per post, so guards acknowledge one version rather than two sets.
    $this->actingAs($director)->post('/guards/standing-orders', $orders)->assertSessionHasErrors('orders');

    /* The handset. */
    Sanctum::actingAs($this->guard, AppMatrix::abilitiesFor(AppMatrix::GUARD));

    $index = $this->getJson('/api/v1/standing-orders')->assertOk()->json();
    $mine = collect($index['orders'])->firstWhere('id', $setId);

    /*
     * INVARIANT 2, AS AN ALLOWLIST. A guard-reachable payload names every key it
     * may carry, and a key added later fails here until somebody decides it is
     * safe. Orders are text and version numbers; none of these can hold money.
     */
    expect(array_keys($index))->toBe(['orders']);

    foreach ($index['orders'] as $order) {
        expect(array_keys($order))->toBe(['id', 'title', 'version', 'effective_on', 'body', 'requires_acknowledgement', 'acknowledged']);
    }

    expect($mine['requires_acknowledgement'])->toBeTrue()
        ->and($mine['acknowledged'])->toBeFalse()
        ->and($mine['version'])->toBe(1);

    /*
     * ACKNOWLEDGED BY VERSION ID (13 D2). The site's current orders name each
     * version's own id, and that id is what the handset signs.
     */
    $current = $this->getJson('/api/v1/sites/'.FacilitiesFixture::ESTATE.'/standing-orders/current')->assertOk();
    ApiContract::assertMatches($current, 'orders.current');

    $v1 = (int) collect($current->json('items'))->firstWhere('set_id', $setId)['version_id'];
    $headers = static fn (string $key): array => ['Idempotency-Key' => $key, 'X-Device-Time' => now()->toIso8601String()];

    $ack = $this->postJson('/api/v1/standing-orders/'.$v1.'/acknowledge', [], $headers('ack-v1'))->assertOk();
    ApiContract::assertMatches($ack, 'orders.acknowledge');

    expect($ack->json('version'))->toBe(1)->and($ack->json('version_id'))->toBe($v1);

    // The same acknowledgement twice is one acknowledgement — replayed, or recorded once.
    $this->postJson('/api/v1/standing-orders/'.$v1.'/acknowledge', [], $headers('ack-v1'))->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson('/api/v1/standing-orders/'.$v1.'/acknowledge', [], $headers('ack-v1-again'))->assertOk();

    expect(DB::connection('mysql')->table('standing_order_acknowledgements')->where('standing_order_set_id', $setId)->count())->toBe(1);

    /* Revision. */
    $this->actingAs($director)
        ->post('/guards/standing-orders/'.$setId.'/revise', [
            'body' => $orders['body'],
            'effective_on' => $orders['effective_on'],
            'change_note' => 'Nothing',
        ])
        ->assertSessionHasErrors('orders');

    $this->actingAs($director)
        ->post('/guards/standing-orders/'.$setId.'/revise', [
            'body' => $orders['body']."\nContractors are logged out as well as in.",
            'effective_on' => $orders['effective_on'],
            'change_note' => 'Contractors are now logged out.',
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($director)
        ->get('/guards/standing-orders/'.$setId)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Operations/StandingOrder')
            ->where('set.version', 2)
            ->has('set.versions', 2)
            ->where('set.guards_on_post.0.acknowledged', false)
            ->where('set.guards_on_post.0.line', 'Acknowledged version 1 only — not the version in force')
            ->where('set.acknowledgements.0.current', false));

    Sanctum::actingAs($this->guard, AppMatrix::abilitiesFor(AppMatrix::GUARD));

    // The version READ. A revision published while the screen was open is not signed unseen.
    $v2 = (int) DB::connection('mysql')->table('standing_order_versions')->where('standing_order_set_id', $setId)->where('version', 2)->value('id');

    $this->postJson('/api/v1/standing-orders/'.$v1.'/acknowledge', [], $headers('ack-v1-stale'))->assertStatus(409)->assertJsonPath('error.code', 'orders_changed');
    $this->postJson('/api/v1/standing-orders/'.$v2.'/acknowledge', [], $headers('ack-v2'))->assertOk();

    expect(collect($this->getJson('/api/v1/standing-orders')->json('orders'))->firstWhere('id', $setId)['acknowledged'])->toBeTrue();

    /* A review that changes nothing resets nobody. */
    $this->actingAs($director)->post('/guards/standing-orders/'.$setId.'/reviewed')->assertSessionHasNoErrors();

    expect((int) DB::connection('mysql')->table('standing_order_sets')->where('id', $setId)->value('version'))->toBe(2);
});

it('keeps company-wide orders with roles that cover every client, and another client\'s orders out of scope', function () {
    $headOfSecurity = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $this->actingAs($headOfSecurity)
        ->post('/guards/standing-orders', [
            'category' => 'general',
            'title' => 'Scoped General Orders',
            'summary' => 'Applied everywhere',
            'body' => 'Report for duty in full uniform with your licence.',
            'effective_on' => SiteTime::today()->toDateString(),
        ])
        ->assertSessionHasErrors('orders');

    $this->actingAs($director)->post('/guards/standing-orders', [
        'category' => 'post_specific',
        'post_id' => $this->post->id,
        'body' => 'Open for deliveries between 07:00 and 18:00 only.',
        'effective_on' => SiteTime::today((string) $this->post->tenant_id)->toDateString(),
    ]);

    $setId = (int) DB::connection('mysql')->table('standing_order_sets')->where('post_id', $this->post->id)->value('id');

    // A site-scoped role with no assignment here: the same 404 as an id nobody has.
    $this->actingAs($headOfSecurity)->get('/guards/standing-orders/'.$setId)->assertNotFound();

    // A handset without the ability is refused at the token.
    Sanctum::actingAs($this->guard, [AppMatrix::ability('alerts', AppMatrix::WRITE)]);

    $version = (int) DB::connection('mysql')->table('standing_order_versions')->where('standing_order_set_id', $setId)->value('id');

    $this->postJson('/api/v1/standing-orders/'.$version.'/acknowledge', [], ['Idempotency-Key' => 'no-ability', 'X-Device-Time' => now()->toIso8601String()])->assertForbidden();
});
