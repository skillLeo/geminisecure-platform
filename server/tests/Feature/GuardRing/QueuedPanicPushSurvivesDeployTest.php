<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\DevicePushToken;
use App\Services\Push\ExpoPush;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A PANIC PUSH QUEUED BEFORE A DEPLOY STILL GOES AFTER IT — the guard ring §6.3, G2
|--------------------------------------------------------------------------
|
| `SendExpoPush` is not edited by the ring, on purpose. A promoted constructor parameter
| added to it would unserialize UNINITIALIZED in a job written by the release before —
| a `site_alert` waiting out its 10/60/300 s backoff across `queue:restart` — and reading
| it throws on every retry: that panic push is lost at the deploy. The fixture is
| `serialize()` of the job exactly as a342c56 built one; any later change to the class's
| shape fails here, instead of losing a panic in production.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    Http::preventStrayRequests();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

it('runs a SendExpoPush serialized by the release before, and sends exactly today\'s message', function () {
    config(['push.enabled' => true, 'push.receipt_delay_seconds' => 0]);

    foreach ([9001, 9002] as $i => $id) {
        DB::connection('mysql')->table('device_push_tokens')->where('id', $id)->delete();
        DevicePushToken::query()->forceCreate([
            'id' => $id, 'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => DevicePushToken::GUARD, 'principal_id' => 800 + $i,
            'app' => AppMatrix::GUARD, 'access_token_id' => null, 'expo_token' => 'ExponentPushToken[deployfixture'.$i.'xxxxxxx]',
            'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
        ]);
    }

    Http::fake(['exp.host/--/api/v2/push/send' => Http::response(['data' => [['status' => 'ok', 'id' => 'a'], ['status' => 'ok', 'id' => 'b']]])]);

    $job = unserialize((string) file_get_contents(base_path('tests/Fixtures/queue/send-expo-push-a342c56.txt')));

    expect($job)->toBeInstanceOf(SendExpoPush::class);

    $job->handle(app(ExpoPush::class));

    $sent = collect(Http::recorded())->flatMap(fn (array $pair): array => $pair[0]->data())->values();

    expect($sent)->toHaveCount(2)
        ->and($sent[0])->toBe([
            'to' => 'ExponentPushToken[deployfixture0xxxxxxx]',
            'title' => 'Panic alert',
            'body' => 'At Lot 47. Dispatch has it — respond as your orders say.',
            'data' => ['type' => 'site_alert', 'id' => '4812', 'kind' => 'panic'],
            'sound' => 'default',
            'channelId' => 'safety',
            'priority' => 'high',
            'interruptionLevel' => 'time-sensitive',
        ]);
});

it('keeps SendExpoPush\'s constructor to the seven parameters a queued panic push was written with', function () {
    $parameters = (new ReflectionMethod(SendExpoPush::class, '__construct'))->getParameters();

    expect(array_map(fn (ReflectionParameter $p): string => $p->getName(), $parameters))
        ->toBe(['tokenIds', 'title', 'body', 'data', 'channel', 'urgent', 'ttl']);
});
