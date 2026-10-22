<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\DuressAlert;
use App\Models\DuressAlertMessage;
use App\Models\Estate\Household;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M50 §4.2 #2 — "Message dispatch…" is a real, two-way channel
|--------------------------------------------------------------------------
|
| The panic screen drew a message box and a reply bubble, and a tap said "not available". Now the resident writes to
| the control room on their own open panic; the control room reads it on the alert's page and replies; the reply is on
| the resident's screen at once and pushed as an urgent panic update. "Sent" and "Delivered" are what each side was
| actually shown, a message the phone sends twice is one, and nothing is ever said to have gone to a closed alert.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    $manager = new DatabaseTransactionsManager(['mysql']);
    app()->instance('db.transactions', $manager);
    DB::connection('mysql')->setTransactionManager($manager);
    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();

    $this->site = FacilitiesFixture::ESTATE;
    $unit = FacilitiesFixture::scratchUnit('Lot 735');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Messages household', 'access_restricted' => false]);
    $resident = Tests\Support\HouseholdHolder::of($household, 'Mae Message');

    $this->account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'mae.message.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Mae Message', 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $this->account->createToken('resident-app:messages-install', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** One call as the resident's phone, held to its catalogue entry. */
function msgCall(TestCase $test, string $name, array $path = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $path[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'msg-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], '/api/v1/'.$uri, $body, $headers);
    $response->assertStatus($status ?? $entry['status']);

    if ($status === null) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

function msgPanic(TestCase $test): DuressAlert
{
    return DuressAlert::query()->findOrFail(msgCall($test, 'duress.store', body: ['mode' => 'audible'])->json('id'));
}

/** @return array<string, mixed> the alert page's props, as the dispatcher sees them */
function msgScreen(TestCase $test, User $user, DuressAlert $alert): array
{
    app('auth')->forgetGuards();
    $response = $test->actingAs($user)->get('/dispatch/alerts/'.$alert->id)->assertOk();
    FacilitiesFixture::boot();

    return $response->viewData('page')['props'];
}

function msgReply(TestCase $test, User $user, DuressAlert $alert, string $body): TestResponse
{
    app('auth')->forgetGuards();
    $screen = '/dispatch/alerts/'.$alert->id;
    $response = $test->actingAs($user)->from($screen)->post($screen.'/reply', ['body' => $body]);
    FacilitiesFixture::boot();

    return $response;
}

it('carries the resident\'s message to the control room, once, however often the phone sends it', function () {
    $alert = msgPanic($this);

    $first = msgCall($this, 'duress.messages.store', ['alert' => $alert->id], ['body' => "  The intruder is\n at the back door  ", 'client_key' => 'k-1']);
    expect($first->json('messages'))->toHaveCount(1)
        ->and($first->json('messages.0'))->toMatchArray(['from' => 'you', 'body' => "The intruder is\n at the back door", 'seen_at' => null, 'client_key' => 'k-1']);

    // The queue sends it again after a lost signal: the same message, not a second.
    msgCall($this, 'duress.messages.store', ['alert' => $alert->id], ['body' => 'The intruder is at the back door', 'client_key' => 'k-1']);
    expect(DuressAlertMessage::query()->where('duress_alert_id', $alert->id)->count())->toBe(1);

    // The control room's page shows it — and drawing the page is what makes it "Delivered" on the phone.
    $thread = msgScreen($this, $this->dispatcher, $alert)['residentThread'];
    expect($thread['messages'])->toHaveCount(1)
        ->and($thread['messages'][0])->toMatchArray(['from' => 'resident', 'name' => 'Mae Message'])
        ->and($thread['canReply'])->toBeTrue()
        ->and($thread['closed'])->toBeFalse();

    expect(msgCall($this, 'duress.show', ['alert' => $alert->id])->json('messages.0.seen_at'))->not->toBeNull();
    expect(AuditEntry::query()->where('action', 'panic.message_from_resident')->where('entity_id', (string) $alert->id)->count())->toBe(1);
});

it('puts the control room\'s reply on the resident\'s screen, pushed as an urgent update, and says when it was seen', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);
    DevicePushToken::query()->create([
        'tenant_id' => $this->site, 'principal_type' => DevicePushToken::RESIDENT, 'principal_id' => $this->account->id, 'app' => AppMatrix::RESIDENT,
        'expo_token' => 'ExponentPushToken[msgresidentxxxxxxxxxxx]', 'platform' => 'android', 'muted' => [],
        // Quiet hours all day long: an urgent panic update comes through them all the same.
        'quiet_from' => '00:00', 'quiet_to' => '23:59', 'last_registered_at' => now(),
    ]);

    $alert = msgPanic($this);
    msgReply($this, $this->dispatcher, $alert, 'Stay inside and lock the door. An officer is coming.')->assertRedirect('/dispatch/alerts/'.$alert->id);

    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => $job->title === 'Message from the control room' && $job->data === ['type' => 'panic_update', 'id' => (string) $alert->id, 'kind' => 'message']);

    // Before the phone reads it, the console says "Sent"; the phone's read makes it "Delivered".
    expect(msgScreen($this, $this->dispatcher, $alert)['residentThread']['messages'][0]['seen_at'])->toBeNull();

    $read = msgCall($this, 'duress.show', ['alert' => $alert->id]);
    expect($read->json('messages.0'))->toMatchArray(['from' => 'dispatch', 'body' => 'Stay inside and lock the door. An officer is coming.', 'client_key' => null]);
    expect(msgScreen($this, $this->dispatcher, $alert)['residentThread']['messages'][0]['seen_at'])->not->toBeNull();
});

it('refuses a message to a closed alert, in words that say what to do instead — on both sides', function () {
    $alert = msgPanic($this);
    $alert->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();

    $refused = msgCall($this, 'duress.messages.store', ['alert' => $alert->id], ['body' => 'Are you still there?', 'client_key' => 'k-9'], 409);
    expect($refused->json('error.code'))->toBe('alert_closed')->and($refused->json('error.message'))->toContain('call 119');

    msgReply($this, $this->dispatcher, $alert, 'Hello?');
    expect(DuressAlertMessage::query()->where('duress_alert_id', $alert->id)->count())->toBe(0)
        ->and(msgScreen($this, $this->dispatcher, $alert)['residentThread']['closed'])->toBeTrue();
});

it('writes only on the account\'s own panic, never an empty one, and never a resident thread on an officer\'s duress', function () {
    $alert = msgPanic($this);

    $otherUnit = FacilitiesFixture::scratchUnit('Lot 736');
    $otherHousehold = Household::query()->firstOrCreate(['unit_id' => $otherUnit->id], ['name' => 'Other household', 'access_restricted' => false]);
    $other = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'other.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Other Person', 'resident_id' => Tests\Support\HouseholdHolder::of($otherHousehold, 'Other Person')->id,
        'unit_id' => $otherUnit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $other->createToken('resident-app:other-install', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    msgCall($this, 'duress.messages.store', ['alert' => $alert->id], ['body' => 'Not my alert'], 404);

    $this->token = $this->account->createToken('resident-app:messages-install-2', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
    msgCall($this, 'duress.messages.store', ['alert' => $alert->id], ['body' => "   \n  "], 422);

    $duress = DuressAlert::query()->create([
        'tenant_id' => $this->site, 'kind' => 'duress', 'status' => 'open', 'server_time' => now(), 'device_time' => now(),
        'idempotency_key' => 'msg-duress-'.bin2hex(random_bytes(4)),
    ]);
    expect(msgScreen($this, $this->dispatcher, $duress)['residentThread'])->toBeNull();
});
