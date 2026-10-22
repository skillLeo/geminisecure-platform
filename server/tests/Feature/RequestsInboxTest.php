<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\DispatchMessage;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\Dispatch\RequestInbox;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| The requests inbox, and what a decision on it costs
|--------------------------------------------------------------------------
|
| Board super-admin-16 approves and denies leave. Four things about it are worth
| asserting rather than looking at, because all four fail silently:
|
|   1. THE BALANCE IS THE DECISION. "Balance after: 6 days remaining" is the
|      fact the dispatcher is deciding on, and it is derived from the guard's
|      own entitlement and the leave they have already taken. A stored running
|      balance would drift; an entitlement read from a constant would refuse
|      leave a guard on a better contract has actually earned.
|
|   2. VACATION DEDUCTS AND SICK LEAVE DOES NOT. The two arrive as the same
|      `kind` and differ only by `subject`, so a rule written against the wrong
|      column deducts statutory sick days from an annual entitlement and nobody
|      notices until a guard is told they have no leave left.
|
|   3. A DENIAL NEEDS A REASON. A guard told no without one has a decision they
|      can neither act on nor appeal.
|
|   4. TWO DISPATCHERS ON ONE QUEUE. The second decision must not silently
|      overwrite the first.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/** An estate row, written straight to the table — see CrossClientRosterTest. */
function inboxEstate(string $name = 'Inbox Test Estate'): string
{
    $id = 'inbox'.random_int(100000, 999999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function inboxGuard(string $tenantId, string $name, int $entitlement = 14): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-I'.$suffix,
        'psra_number' => 'PSRA-I'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $tenantId,
        'leave_entitlement_days' => $entitlement,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function inboxRequest(Guard $guard, array $overrides = []): GuardRequest
{
    return GuardRequest::create([
        'tenant_id' => $guard->tenant_id,
        'guard_id' => $guard->id,
        'kind' => GuardRequest::LEAVE,
        'subject' => 'Vacation',
        'starts_on' => now()->addDays(5)->toDateString(),
        'ends_on' => now()->addDays(12)->toDateString(),
        'status' => 'pending',
        ...$overrides,
    ]);
}

/** A Gemini user holding exactly the dispatch permissions named. */
function inboxViewer(string ...$permissions): User
{
    $role = Role::findOrCreate('test.inbox_'.random_int(100000, 999999), 'web');
    $role->forceFill(['console' => Console::Gemini->value])->save();

    foreach ($permissions as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

beforeEach(function () {
    $this->inbox = new RequestInbox;
    $this->estate = inboxEstate();
    $this->dispatcher = inboxViewer('gemini.dispatch.view', 'gemini.dispatch.update');
});

it('states the balance a vacation approval would leave, from the guard own entitlement', function () {
    $guard = inboxGuard($this->estate, 'Renae Cross', entitlement: 14);
    inboxRequest($guard);

    $row = collect($this->inbox->forViewer($this->dispatcher)['leave'])
        ->firstWhere('title', 'Renae Cross — Vacation');

    // Eight days inclusive of both ends, off an entitlement of fourteen, with
    // nothing taken yet.
    expect($row['detail'])->toContain('8 days')
        ->and($row['detail'])->toContain('Balance after: 6 days remaining');
});

it('counts leave already approved this year against the balance', function () {
    $guard = inboxGuard($this->estate, 'Renae Cross', entitlement: 14);

    // Four days already taken, approved.
    inboxRequest($guard, [
        'starts_on' => now()->startOfYear()->addDays(30)->toDateString(),
        'ends_on' => now()->startOfYear()->addDays(33)->toDateString(),
        'status' => 'approved',
    ]);

    inboxRequest($guard);

    $row = collect($this->inbox->forViewer($this->dispatcher)['leave'])
        ->firstWhere('title', 'Renae Cross — Vacation');

    expect($row['detail'])->toContain('Balance after: 2 days remaining');
});

it('honours an entitlement above the statutory floor', function () {
    $guard = inboxGuard($this->estate, 'Marcia Grant', entitlement: 20);
    inboxRequest($guard);

    $row = collect($this->inbox->forViewer($this->dispatcher)['leave'])
        ->firstWhere('title', 'Marcia Grant — Vacation');

    // Twenty, not the fourteen a hardcoded floor would have used. A dispatcher
    // deciding against the floor would refuse leave this guard has earned.
    expect($row['detail'])->toContain('Balance after: 12 days remaining');
});

it('does not deduct sick leave from the annual entitlement', function () {
    $guard = inboxGuard($this->estate, 'Andre Simpson');

    inboxRequest($guard, [
        'subject' => 'Sick',
        'starts_on' => now()->subDays(5)->toDateString(),
        'ends_on' => now()->subDays(5)->toDateString(),
        'certificate_attached' => true,
    ]);

    $row = collect($this->inbox->forViewer($this->dispatcher)['leave'])
        ->firstWhere('title', 'Andre Simpson — Sick');

    // One day, singular, and the fact that matters instead of a balance is
    // whether the absence is evidenced.
    expect($row['detail'])->toContain('1 day')
        ->and($row['detail'])->not->toContain('1 days')
        ->and($row['detail'])->toContain('Medical certificate attached')
        ->and($row['detail'])->not->toContain('Balance after');
});

it('says plainly when sick leave has no certificate', function () {
    $guard = inboxGuard($this->estate, 'Andre Simpson');

    inboxRequest($guard, [
        'subject' => 'Sick',
        'starts_on' => now()->subDay()->toDateString(),
        'ends_on' => now()->subDay()->toDateString(),
        'certificate_attached' => false,
    ]);

    $row = collect($this->inbox->forViewer($this->dispatcher)['leave'])
        ->firstWhere('title', 'Andre Simpson — Sick');

    // Approving uncertified sick leave is a different decision from approving
    // certified, so the screen must not leave the reader to assume.
    expect($row['detail'])->toContain('No medical certificate');
});

it('splits leave from equipment on kind rather than on whether a date is set', function () {
    $guard = inboxGuard($this->estate, 'Kadeem Foster');

    inboxRequest($guard, [
        'kind' => GuardRequest::EQUIPMENT,
        'subject' => 'Raincoat',
        'starts_on' => null,
        'ends_on' => null,
        'quantity' => 1,
        'reason' => 'worn out',
    ]);

    $inbox = $this->inbox->forViewer($this->dispatcher);

    expect($inbox['leave'])->toHaveCount(0)
        ->and($inbox['equipment'])->toHaveCount(1)
        ->and($inbox['equipment'][0]['detail'])->toContain('Qty 1')
        ->and($inbox['equipment'][0]['detail'])->toContain('Reason: worn out');
});

it('holds only what is still waiting on a decision', function () {
    $guard = inboxGuard($this->estate, 'Marcus Whyte');

    inboxRequest($guard, ['status' => 'approved']);
    inboxRequest($guard, ['subject' => 'Bereavement', 'status' => 'denied']);

    // An inbox holds what is waiting. A decided request has left it, and
    // history is its own screen.
    expect($this->inbox->forViewer($this->dispatcher)['leave'])->toHaveCount(0);
});

it('lets a viewer read the queue and refuses to let them empty it', function () {
    $reader = inboxViewer('gemini.dispatch.view');

    $inbox = $this->inbox->forViewer($reader);

    expect($inbox['canDecide'])->toBeFalse()
        ->and($inbox['decideBlockedReason'])->toContain('Dispatch update access')
        ->and($this->inbox->forViewer($this->dispatcher)['canDecide'])->toBeTrue();
});

it('refuses a denial with no reason, and one that is only whitespace', function () {
    $request = inboxRequest(inboxGuard($this->estate, 'Renae Cross'));

    expect(fn () => $this->inbox->decide($this->dispatcher, $request->id, ['decision' => 'denied']))
        ->toThrow(ValidationException::class);

    // A space satisfies `required` and satisfies nobody reading the refusal.
    expect(fn () => $this->inbox->decide($this->dispatcher, $request->id, [
        'decision' => 'denied',
        'note' => '   ',
    ]))->toThrow(ValidationException::class);

    expect($request->fresh()->status)->toBe('pending');
});

it('approves without demanding a reason', function () {
    $request = inboxRequest(inboxGuard($this->estate, 'Renae Cross'));

    $this->inbox->decide($this->dispatcher, $request->id, ['decision' => 'approved']);

    expect($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->decided_by)->toBe($this->dispatcher->id)
        ->and($request->fresh()->decided_at)->not->toBeNull();
});

it('records who decided, and what they said, in the audit log', function () {
    $request = inboxRequest(inboxGuard($this->estate, 'Renae Cross'));

    $this->inbox->decide($this->dispatcher, $request->id, [
        'decision' => 'denied',
        'note' => 'Two guards already off that week.',
    ]);

    $entry = AuditEntry::query()
        ->where('entity_type', 'guard_request')
        ->where('entity_id', (string) $request->id)
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->action)->toBe('dispatch.request_denied')
        ->and($entry->actor_id)->toBe($this->dispatcher->id)
        ->and($entry->before['status'])->toBe('pending')
        ->and($entry->after['status'])->toBe('denied')
        ->and($entry->after['note'])->toBe('Two guards already off that week.');
});

it('keeps what the inbox decided, with who and why, and pages it', function () {
    // 12 §2, Wave 4. A decided request leaves the inbox and arrives here — and a
    // denial keeps its reason, because the inbox refuses one without it and a
    // history that dropped it would undo that on the screen read later.
    $denied = inboxRequest(inboxGuard($this->estate, 'Renae Cross'));
    $approved = inboxRequest(inboxGuard($this->estate, 'Marcus Whyte'));
    $waiting = inboxRequest(inboxGuard($this->estate, 'Kadeem Foster'));

    $this->inbox->decide($this->dispatcher, $denied->id, ['decision' => 'denied', 'note' => 'Two guards already off that week.']);
    $this->inbox->decide($this->dispatcher, $approved->id, ['decision' => 'approved']);

    $history = $this->inbox->history($this->dispatcher);
    $ids = array_column($history['rows'], 'id');

    expect($ids)->toContain($denied->id)
        ->and($ids)->toContain($approved->id)
        ->and($ids)->not->toContain($waiting->id);

    $row = collect($history['rows'])->firstWhere('id', $denied->id);

    expect($row['status'])->toBe('denied')
        ->and($row['note'])->toBe('Two guards already off that week.')
        ->and($row['decided_by'])->toBe($this->dispatcher->name)
        ->and($row['decided_at'])->not->toBe('');

    /*
     * AND "WHEN" IS THE ESTATE'S CLOCK, NOT THE SERVER'S (M4 §3.1). `decided_at` is a true UTC
     * instant — correct storage — and this board printed its raw digits, so a decision taken at
     * 9 AM in Kingston read back as 2 PM. The estate's zone is read by NAME, never a fixed −5.
     */
    $stored = $denied->fresh()->decided_at;
    $zone = SiteTime::zone($this->estate);

    expect($zone)->toBe('America/Jamaica')
        ->and($row['decided_at'])->toBe(SiteTime::fmt(SiteTime::local($stored, $this->estate), 'M j, Y g:i A'))
        ->and($row['decided_at'])->not->toBe($stored->copy()->utc()->format('M j, Y g:i A'));

    // Same gate as the queue: whoever may read the inbox may read what became of it.
    $this->withoutVite()->actingAs($this->dispatcher)
        ->get('/dispatch/requests/history')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Gemini/Dispatch/RequestHistory')->has('rows'));

    $stranger = inboxViewer();

    $this->actingAs($stranger)->get('/dispatch/requests/history')->assertForbidden();
});

it('pages the history by a cursor, so a decision taken while somebody is reading is never served twice', function () {
    /*
     * THE BUG THIS REPLACES. The list is newest-decision-first, so every new decision lands at the
     * TOP of it — and two dispatchers on one queue is the normal case (see the head of this file).
     * With `OFFSET 25`, a decision taken while a colleague was reading page one pushed every row
     * down by one, and the row that had been last on page one came back as the first row of page
     * two. The reader saw the same decision twice, on the only screen anybody reads later.
     *
     * A cursor names the last row they actually SAW, so the next page starts after it whatever
     * arrived above. Six rows over pages of two, with a seventh decided in the middle.
     */
    $guard = inboxGuard($this->estate, 'Paged Officer');
    $decided = [];

    foreach (range(1, 6) as $i) {
        $request = inboxRequest($guard, ['subject' => 'Sick']);
        $this->inbox->decide($this->dispatcher, $request->id, ['decision' => 'approved']);
        $decided[] = $request->id;
    }

    // All six share a second or two, so the id half of the cursor is doing real work here.
    $stamps = DB::connection('mysql')->table('guard_requests')->whereIn('id', $decided)->pluck('decided_at')->unique();
    expect($stamps->count())->toBeLessThanOrEqual(3);

    $first = $this->inbox->history($this->dispatcher, null, 2);
    expect($first['rows'])->toHaveCount(2)->and($first['next_before'])->toBeString();

    // A COLLEAGUE DECIDES ONE MORE, between the reader's page one and their page two.
    $jumped = inboxRequest($guard, ['subject' => 'Sick']);
    $this->inbox->decide($this->dispatcher, $jumped->id, ['decision' => 'denied', 'note' => 'No cover that week.']);

    $seen = collect($first['rows'])->pluck('id');
    $before = $first['next_before'];
    $pages = 1;

    while ($before !== null && $pages < 20) {
        $next = $this->inbox->history($this->dispatcher, $before, 2);
        $seen = $seen->concat(collect($next['rows'])->pluck('id'));
        $before = $next['next_before'];
        $pages++;
    }

    $mine = $seen->intersect($decided);

    expect($seen->duplicates())->toBeEmpty()                 // nothing read twice — the defect itself
        ->and($mine->count())->toBe(6)                       // …and nothing skipped for it either
        ->and($seen)->not->toContain($jumped->id);           // a decision taken above the reader stays above them

    // It is there for anybody starting again from the newest — it was never lost, only not re-served.
    expect(collect($this->inbox->history($this->dispatcher, null, 2)['rows'])->pluck('id'))->toContain($jumped->id);

    // Newest decision first, throughout the walk.
    $order = DB::connection('mysql')->table('guard_requests')->whereIn('id', $mine->all())
        ->orderByDesc('decided_at')->orderByDesc('id')->pluck('id');
    expect($mine->values()->all())->toBe($order->values()->all());

    // The end of the list says so with a null cursor rather than an empty page for ever.
    expect($before)->toBeNull();

    /*
     * AND THE SHAPE THIS REPLACED REALLY DID DUPLICATE — asserted here rather than by reverting the
     * service, so the defect is documented for good and no shared file has to be broken to show it.
     * This is the exact query `history()` used to run for page two: same order, same page size,
     * `OFFSET 25` in place of a cursor. Run against the estate as it stands NOW — one decision taken
     * since the reader's page one — it serves back a row they have already read.
     */
    $oldPageTwo = DB::connection('mysql')
        ->table('guard_requests')
        ->join('guards', 'guards.id', '=', 'guard_requests.guard_id')
        ->where('guard_requests.status', '!=', 'pending')
        ->whereIn('guard_requests.id', [...$decided, $jumped->id])
        ->orderByDesc('guard_requests.decided_at')
        ->orderByDesc('guard_requests.id')
        ->offset(2)
        ->limit(2)
        ->pluck('guard_requests.id');

    $readOnPageOne = collect($first['rows'])->pluck('id');

    expect($oldPageTwo->intersect($readOnPageOne))->not->toBeEmpty('the offset paging this replaced served a decision the reader had already seen');

    /*
     * A STALE OR HAND-TYPED CURSOR IS NOT AN ERROR ON A CONSOLE SCREEN. This is a page reached by a
     * link: a dispatcher whose bookmark has rotted is shown the newest decisions, never a refusal
     * they can do nothing with. (The Resident App's identical cursor answers 422, because a phone
     * sending a cursor it was never given has a bug worth surfacing.)
     */
    foreach ([null, '', 'not-a-cursor', base64_encode('nonsense'), str_repeat('x', 200)] as $rubbish) {
        expect(collect($this->inbox->history($this->dispatcher, $rubbish, 2)['rows'])->pluck('id')->all())
            ->toBe(collect($this->inbox->history($this->dispatcher, null, 2)['rows'])->pluck('id')->all());
    }

    // And the screen carries the cursor rather than a page number.
    $this->withoutVite()->actingAs($this->dispatcher)
        ->get('/dispatch/requests/history')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Gemini/Dispatch/RequestHistory')->has('rows')->has('next_before')->missing('page'));
});

it('refuses a second decision and names the outcome the first one reached', function () {
    $request = inboxRequest(inboxGuard($this->estate, 'Renae Cross'));

    $this->inbox->decide($this->dispatcher, $request->id, ['decision' => 'approved']);

    // The second dispatcher is told it was handled, not that it failed. A
    // silent overwrite would take a guard off post nobody meant to.
    expect(fn () => $this->inbox->decide($this->dispatcher, $request->id, ['decision' => 'denied', 'note' => 'No cover.']))
        ->toThrow(RuntimeException::class, 'already approved');

    expect($request->fresh()->status)->toBe('approved');
});

it('reads dispatch traffic rather than client correspondence', function () {
    $post = Post::create([
        'tenant_id' => $this->estate,
        'name' => 'Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $guard = inboxGuard($this->estate, 'Marcus Whyte');
    $guard->forceFill(['post_id' => $post->id])->save();

    DispatchMessage::create([
        'tenant_id' => $this->estate,
        'direction' => DispatchMessage::BROADCAST,
        'body' => 'Service Gate remains uncovered.',
        'sent_at' => now()->subHours(2),
    ]);

    DispatchMessage::create([
        'tenant_id' => $this->estate,
        'direction' => DispatchMessage::INBOUND,
        'guard_id' => $guard->id,
        'body' => 'Barrier arm sensor fixed.',
        'sent_at' => now()->subHours(3),
    ]);

    $messages = collect($this->inbox->forViewer($this->dispatcher)['messages']);

    $broadcast = $messages->firstWhere('broadcast', true);
    $inbound = $messages->firstWhere('broadcast', false);

    // A broadcast went to a post, not to a person, so it is not initialled with
    // the dispatcher who typed it.
    expect($broadcast['title'])->toBe('Broadcast — Inbox Test Estate, all guards')
        ->and($broadcast['initials'])->toBe('D')
        ->and($inbound['title'])->toBe('Marcus Whyte → Dispatch')
        ->and($inbound['initials'])->toBe('MW');
});
