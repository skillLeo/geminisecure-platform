<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Deciding a guard's request stays inside the decider's scope — bug hunt B002
|--------------------------------------------------------------------------
|
| `POST /dispatch/requests/{id}` took the id from the URL and nothing else. The
| inbox that draws the Approve button was narrowed to the viewer's estates; the
| write behind it was not, so a Head of Security assigned to one estate could
| approve or deny the leave of an officer at any other estate by typing its
| number — ids are sequential — and the audit entry was filed under that estate.
|
| What this file holds:
|
|   1  a request at an estate outside the decider's scope is refused, nothing is
|      written, and the refusal is word for word the one a request that does not
|      exist gets, so the endpoint is no oracle for which ids are real;
|   2  the same Head of Security still decides the requests at their own estate
|      (the refusal is the scope, not a broken route);
|   3  a platform-wide role still decides anywhere;
|   4  the demonstration estate's requests are refused to everybody, which the
|      inbox's own `scoped()` has always promised and the write never applied.
|
| The race half of B002 needs committed rows and a second connection, so it has a
| file of its own: RequestDecisionRaceTest.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $suffix = random_int(100000, 999999);

    $this->mine = 'decidemine'.$suffix;
    $this->theirs = 'decidetheirs'.$suffix;
    $this->demo = 'decidedemo'.$suffix;

    foreach ([$this->mine => false, $this->theirs => false, $this->demo => true] as $id => $demo) {
        DB::connection('mysql')->table('tenants')->insert([
            'id' => $id,
            'name' => 'Decide '.$id,
            'status' => 'active',
            'demo' => $demo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $this->head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $this->head->id,
        'tenant_id' => $this->mine,
        'role_id' => Role::query()->where('name', Role::HEAD_OF_SECURITY)->firstOrFail()->id,
        'is_active' => true,
    ]);

    $this->head = $this->head->fresh();
    $this->director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** A pending vacation request from an officer posted at this estate. */
function decisionRequest(string $tenantId): GuardRequest
{
    $suffix = random_int(100000, 999999);

    $guard = Guard::create([
        'full_name' => 'Officer '.$suffix,
        'employee_number' => 'GS-D'.$suffix,
        'psra_number' => 'PSRA-D'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $tenantId,
        'leave_entitlement_days' => 14,
    ]);

    return GuardRequest::create([
        'tenant_id' => $tenantId,
        'guard_id' => $guard->id,
        'kind' => GuardRequest::LEAVE,
        'subject' => 'Vacation',
        'starts_on' => now()->addDays(5)->toDateString(),
        'ends_on' => now()->addDays(12)->toDateString(),
        'status' => 'pending',
    ]);
}

/** How many audit entries name this request. */
function decisionAudits(int $requestId): int
{
    return AuditEntry::query()
        ->where('entity_type', 'guard_request')
        ->where('entity_id', (string) $requestId)
        ->count();
}

/**
 * The sentence the decide endpoint answered with, or null.
 *
 * @param  array<string, string>  $input
 */
function decisionRefusal(User $actor, int $requestId, array $input): ?string
{
    $response = test()->actingAs($actor)
        ->from('/dispatch/requests')
        ->post('/dispatch/requests/'.$requestId, $input);

    $response->assertRedirect('/dispatch/requests');

    /*
     * The session is stored as JSON, and saving it at the end of the request leaves the error
     * bag in its stored shape rather than as a ViewErrorBag. Either shape is read the same way.
     */
    $errors = session('errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first('decision') ?: null;
    }

    return is_array($errors) ? ($errors['default']['messages']['decision'][0] ?? null) : null;
}

it('refuses a Head of Security a request at an estate outside their scope, exactly as one that does not exist, and writes nothing', function () {
    $elsewhere = decisionRequest($this->theirs);

    $refused = decisionRefusal($this->head, $elsewhere->id, ['decision' => 'approved']);

    expect($refused)->toBe('That request no longer exists.')
        ->and($elsewhere->fresh()->status)->toBe('pending')
        ->and($elsewhere->fresh()->decided_by)->toBeNull()
        ->and(decisionAudits($elsewhere->id))->toBe(0);

    // A denial is refused the same way — it is the same hole with a note on it.
    expect(decisionRefusal($this->head, $elsewhere->id, ['decision' => 'denied', 'note' => 'No cover that week.']))
        ->toBe('That request no longer exists.')
        ->and($elsewhere->fresh()->status)->toBe('pending');

    // NO ORACLE: an id nobody has used gets the identical answer.
    $unused = (int) DB::connection('mysql')->table('guard_requests')->max('id') + 1000;

    expect(decisionRefusal($this->head, $unused, ['decision' => 'approved']))->toBe($refused);
});

it('still lets that Head of Security decide the requests at their own estate', function () {
    $own = decisionRequest($this->mine);

    expect(decisionRefusal($this->head, $own->id, ['decision' => 'approved']))->toBeNull()
        ->and($own->fresh()->status)->toBe('approved')
        ->and($own->fresh()->decided_by)->toBe($this->head->id)
        ->and(decisionAudits($own->id))->toBe(1);

    $entry = AuditEntry::query()->where('entity_type', 'guard_request')->where('entity_id', (string) $own->id)->firstOrFail();

    expect($entry->tenant_id)->toBe($this->mine)
        ->and($entry->after['guard'])->toBe($own->officer->full_name);
});

it('lets a platform-wide role decide at any estate', function () {
    $elsewhere = decisionRequest($this->theirs);

    expect(decisionRefusal($this->director, $elsewhere->id, ['decision' => 'denied', 'note' => 'Two already off.']))->toBeNull()
        ->and($elsewhere->fresh()->status)->toBe('denied')
        ->and(decisionAudits($elsewhere->id))->toBe(1);
});

it('refuses everybody a request at the demonstration estate, which the inbox never shows them', function () {
    $invented = decisionRequest($this->demo);

    // The inbox the button lives on leaves it out…
    $inbox = app(App\Services\Dispatch\RequestInbox::class)->forViewer($this->director);

    expect(array_column($inbox['leave'], 'id'))->not->toContain($invented->id);

    // …and so, now, does the write.
    expect(decisionRefusal($this->director, $invented->id, ['decision' => 'approved']))->toBe('That request no longer exists.')
        ->and($invented->fresh()->status)->toBe('pending')
        ->and(decisionAudits($invented->id))->toBe(0);
});
