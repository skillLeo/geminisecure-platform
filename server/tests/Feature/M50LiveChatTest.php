<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\Estate\Household;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Models\SupportChat;
use App\Models\SupportChatMessage;
use App\Models\User;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Support\LiveChat;
use Illuminate\Foundation\Testing\DatabaseTransactionsManager;
use Illuminate\Support\Carbon;
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
| M50 §4.3 — "Live chat" on Help & support is answered by Gemini Security
|--------------------------------------------------------------------------
|
| Board 39 draws "Live chat · typically replies in 5 min", and a tap said live chat was not available. Now a resident
| writes, the control room reads it on Dispatch → Live chat (and in the bell), answers, and the answer is on the
| resident's screen and pushed. "Delivered" is what each side was shown; a message the phone sends twice is one; a
| resolved conversation opens again with the next message; and "typically replies in" is measured, never promised.
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
    // Every conversation of the shared central database is this run's to measure from a clean slate.
    SupportChatMessage::query()->delete();
    SupportChat::query()->delete();

    $unit = FacilitiesFixture::scratchUnit('Lot 9521');
    $household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Chat household', 'access_restricted' => false]);
    $resident = Tests\Support\HouseholdHolder::of($household, 'Cleo Chat');
    $this->account = ResidentAccount::query()->create([
        'tenant_id' => $this->site, 'channel' => 'email', 'destination' => 'cleo.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => 'Cleo Chat', 'resident_id' => $resident->id, 'unit_id' => $unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $this->token = $this->account->createToken('resident-app:chat-install', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->dispatcher = FacilitiesFixture::geminiViewer(Role::DISPATCHER);
});

afterEach(function () {
    Carbon::setTestNow();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** One call as the resident's phone, held to its catalogue entry. */
function chatCall(TestCase $test, string $name, array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $headers = $entry['write'] ? ['Idempotency-Key' => 'chat-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->json($entry['method'], '/api/v1/'.$entry['uri'], $body, $headers);
    $response->assertStatus($status ?? $entry['status']);

    if ($status === null) {
        ApiContract::assertMatches($response, $name);
    }

    FacilitiesFixture::boot();

    return $response;
}

/** @return array<string, mixed> Dispatch → Live chat's props, as this member of staff sees them */
function chatScreen(TestCase $test, User $user, ?int $chat = null): array
{
    app('auth')->forgetGuards();
    $response = $test->actingAs($user)->get('/dispatch/chat'.($chat === null ? '' : '?chat='.$chat))->assertOk();
    FacilitiesFixture::boot();

    return $response->viewData('page')['props'];
}

function chatAnswer(TestCase $test, User $user, int $chat, string $body): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->actingAs($user)->from('/dispatch/chat?chat='.$chat)->post('/dispatch/chat/'.$chat.'/reply', ['body' => $body]);
    FacilitiesFixture::boot();

    return $response;
}

it('carries the resident\'s message to the control room once, however often the phone sends it — waiting, and in the bell', function () {
    expect(chatCall($this, 'support.chat.show')->json())->toMatchArray(['id' => null, 'open' => true, 'messages' => [], 'answered_by' => 'Gemini Security']);

    $sent = chatCall($this, 'support.chat.messages.store', ['body' => "  How do I add my\n helper?  ", 'client_key' => 'k-1']);
    expect($sent->json('message'))->toMatchArray(['from' => 'you', 'body' => "How do I add my\n helper?", 'seen_at' => null, 'client_key' => 'k-1']);
    // The queue sends it again after a lost signal: the same message, not a second.
    chatCall($this, 'support.chat.messages.store', ['body' => 'How do I add my helper?', 'client_key' => 'k-1']);
    $chat = SupportChat::query()->sole();
    expect(SupportChatMessage::query()->where('support_chat_id', $chat->id)->count())->toBe(1)
        ->and($chat->waiting_since)->not->toBeNull();

    // The control room's list has it at the top, waiting, with one unread; the bell has it too.
    $list = chatScreen($this, $this->dispatcher);
    expect($list['chats'][0])->toMatchArray(['id' => $chat->id, 'resident' => 'Cleo Chat', 'unread' => 1])
        ->and($list['chats'][0]['waiting_since'])->not->toBeNull()
        ->and($list['sourceBadge'])->toMatchArray(['source' => 'resident'])
        ->and(collect($list['sections'])->firstWhere('active', true)['label'])->toBe('Live chat');
    $bell = app(PlatformNotifications::class)->attention($this->dispatcher)['items'];
    expect(collect($bell)->firstWhere('href', '/dispatch/chat?chat='.$chat->id)['title'] ?? null)->toBe('Live chat waiting: Cleo Chat');

    // Opening it is the control room being shown it: "Delivered" on the phone from now on.
    expect(chatScreen($this, $this->dispatcher, $chat->id)['thread']['messages'][0])->toMatchArray(['from' => 'resident', 'name' => 'Cleo Chat']);
    expect(chatCall($this, 'support.chat.show')->json('messages.0.seen_at'))->not->toBeNull();
});

it('puts the answer on the resident\'s screen, pushed and never muted, and says on the console when it was seen', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 300]);
    Bus::fake([SendExpoPush::class]);
    DevicePushToken::query()->create([
        'tenant_id' => $this->site, 'principal_type' => DevicePushToken::RESIDENT, 'principal_id' => $this->account->id, 'app' => AppMatrix::RESIDENT,
        'expo_token' => 'ExponentPushToken[chatresidentxxxxxxxxxx]', 'platform' => 'android', 'muted' => ['notices', 'visitors', 'maintenance', 'payments'],
        'last_registered_at' => now(),
    ]);

    chatCall($this, 'support.chat.messages.store', ['body' => 'Is the pool open on Sunday?', 'client_key' => 'k-2']);
    $chat = SupportChat::query()->sole();

    chatAnswer($this, $this->dispatcher, $chat->id, 'Yes — 9 AM to 6 PM on Sunday.')->assertRedirect('/dispatch/chat?chat='.$chat->id);

    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => $job->title === 'Gemini Security replied' && $job->data === ['type' => 'support_reply', 'id' => (string) $chat->id]);
    expect($chat->refresh()->waiting_since)->toBeNull()
        ->and(AuditEntry::query()->where('action', 'support.chat_answered')->where('entity_id', (string) $chat->id)->count())->toBe(1);

    // "Sent" on the console until the phone reads it; the phone's read makes it "Delivered".
    expect(collect(chatScreen($this, $this->dispatcher, $chat->id)['thread']['messages'])->last()['seen_at'])->toBeNull();
    $read = chatCall($this, 'support.chat.show');
    expect($read->json('messages.1'))->toMatchArray(['from' => 'support', 'body' => 'Yes — 9 AM to 6 PM on Sunday.', 'client_key' => null])
        ->and($read->json('messages.1.name'))->toBeString();
    expect(collect(chatScreen($this, $this->dispatcher, $chat->id)['thread']['messages'])->last()['seen_at'])->not->toBeNull();
});

it('a resolved conversation leaves the waiting list, and the resident\'s next message opens it again', function () {
    chatCall($this, 'support.chat.messages.store', ['body' => 'Thanks, that is all.', 'client_key' => 'k-3']);
    $chat = SupportChat::query()->sole();

    app('auth')->forgetGuards();
    $this->actingAs($this->dispatcher)->post('/dispatch/chat/'.$chat->id.'/resolve')->assertRedirect('/dispatch/chat?chat='.$chat->id);
    FacilitiesFixture::boot();
    expect($chat->refresh()->status)->toBe(SupportChat::RESOLVED)->and($chat->waiting_since)->toBeNull();
    expect(chatCall($this, 'support.chat.show')->json('open'))->toBeFalse();

    expect(chatCall($this, 'support.chat.messages.store', ['body' => 'One more thing…', 'client_key' => 'k-4'])->json('chat.open'))->toBeTrue();
    expect($chat->refresh()->status)->toBe(SupportChat::OPEN)->and($chat->waiting_since)->not->toBeNull();
});

it('measures "typically replies in" from real answers, and says nothing of speed until there are five', function () {
    $chats = app(LiveChat::class);
    expect($chats->typicalReplyMinutes())->toBeNull()
        ->and(chatCall($this, 'support.chat.show')->json('typical_reply_minutes'))->toBeNull();

    // Five residents, answered after 2, 3, 4, 6 and 30 minutes: the median is 4.
    foreach ([2, 3, 4, 6, 30] as $i => $minutes) {
        $chat = SupportChat::query()->create(['tenant_id' => $this->site, 'resident_account_id' => 900000 + $i, 'status' => SupportChat::OPEN, 'last_message_at' => now()]);
        $asked = now()->subHours(2);
        SupportChatMessage::query()->create(['support_chat_id' => $chat->id, 'author' => SupportChatMessage::RESIDENT, 'body' => 'Question', 'created_at' => $asked, 'updated_at' => $asked]);
        SupportChatMessage::query()->create(['support_chat_id' => $chat->id, 'author' => SupportChatMessage::STAFF, 'user_id' => $this->dispatcher->id, 'body' => 'Answer', 'created_at' => $asked->copy()->addMinutes($minutes), 'updated_at' => $asked->copy()->addMinutes($minutes)]);
    }

    expect($chats->typicalReplyMinutes())->toBe(4)
        ->and(chatCall($this, 'support.chat.show')->json('typical_reply_minutes'))->toBe(4);
});

it('lets a role that may only watch read the conversations, never answer them; nothing empty is sent', function () {
    chatCall($this, 'support.chat.messages.store', ['body' => 'Hello?', 'client_key' => 'k-5']);
    $chat = SupportChat::query()->sole();

    $assistant = FacilitiesFixture::geminiViewer(Role::ADMIN_ASSISTANT);
    $screen = chatScreen($this, $assistant, $chat->id);
    expect($screen['canReply'])->toBeFalse()->and($screen['thread']['messages'])->toHaveCount(1);
    chatAnswer($this, $assistant, $chat->id, 'Hi')->assertForbidden();

    chatAnswer($this, $this->dispatcher, $chat->id, '   ')->assertSessionHasErrors('body');
    expect(SupportChatMessage::query()->where('author', SupportChatMessage::STAFF)->count())->toBe(0);
    expect(chatCall($this, 'support.chat.messages.store', ['body' => "  \n "], 422)->json('error.code'))->toBeString();
    chatCall($this, 'support.chat.messages.store', ['body' => str_repeat('a', 1001)], 422);
});
