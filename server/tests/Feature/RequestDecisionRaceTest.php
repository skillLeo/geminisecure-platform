<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Dispatch\RequestInbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Two dispatchers deciding one request — the race half of bug hunt B002
|--------------------------------------------------------------------------
|
| "Two dispatchers on the same queue is normal, and the second one must not
| silently overwrite the first." That was the comment. The code read the row
| with a plain SELECT, so both of them saw `pending`; the second UPDATE then
| waited on the first one's row lock, matched on the id alone and overwrote the
| decision, and both wrote an audit entry. The guard saw whichever landed last.
|
| A RACE NEEDS TWO CONNECTIONS AND A ROW BOTH CAN SEE. So the request is
| COMMITTED here, outside any transaction, and a second connection plays the
| other dispatcher. The decision under test runs inside a transaction this file
| rolls back, and `afterEach` deletes the committed rows, so nothing is left
| behind — and nothing is written to the append-only audit log for good.
|
| The other dispatcher is put in the one place the defect lives: after this
| decision has read the row and before it has written it. Their write is a
| plain, conditional UPDATE — the most a competing decision can do — with a
| one-second lock wait, so a lock held by the decision under test turns it away
| in a second instead of hanging the suite for fifty.
|
*/

beforeEach(function () {
    $suffix = random_int(100000, 999999);

    $this->estate = 'decisionrace'.$suffix;

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $this->estate,
        'name' => 'Decision Race Estate',
        'status' => 'active',
        'demo' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->guard = Guard::create([
        'full_name' => 'Renae Race',
        'employee_number' => 'GS-R'.$suffix,
        'psra_number' => 'PSRA-R'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $this->estate,
        'leave_entitlement_days' => 14,
    ]);

    $this->request = GuardRequest::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'kind' => GuardRequest::LEAVE,
        'subject' => 'Vacation',
        'starts_on' => now()->addDays(5)->toDateString(),
        'ends_on' => now()->addDays(12)->toDateString(),
        'status' => 'pending',
    ]);

    // The other dispatcher's own connection, as their own request would have.
    config(['database.connections.decision_race' => config('database.connections.mysql')]);
    DB::purge('decision_race');
    DB::connection('decision_race')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // Everything from here on is this test's, and is rolled back.
    DB::connection('mysql')->beginTransaction();

    $role = Role::findOrCreate('test.decision_race_'.$suffix, 'web');
    $role->forceFill(['console' => Console::Gemini->value])->save();
    $role->givePermissionTo(Permission::findOrCreate('gemini.dispatch.update', 'web'));

    $this->dispatcher = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $this->dispatcher->syncRoles([$role->name]);
    $this->dispatcher = $this->dispatcher->fresh();
});

afterEach(function () {
    while (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }

    DB::connection('decision_race')->disconnect();

    // The committed half. The guard's requests go with it (cascade).
    DB::connection('mysql')->table('guard_requests')->where('guard_id', $this->guard->id)->delete();
    DB::connection('mysql')->table('guards')->where('id', $this->guard->id)->delete();
    DB::connection('mysql')->table('tenants')->where('id', $this->estate)->delete();
});

it('lets exactly one of two simultaneous decisions land, and tells the other what the first one decided', function () {
    $id = $this->request->id;
    $competitor = ['won' => null, 'error' => null];

    /*
     * THE OTHER DISPATCHER PRESSES DENY in the gap between this decision's read of the row
     * and its write. Fired once, on this decision's UPDATE of the request.
     */
    DB::connection('mysql')->beforeExecuting(function (string $sql) use ($id, &$competitor): void {
        if ($competitor['won'] !== null || ! str_starts_with(strtolower(ltrim($sql)), 'update `guard_requests`')) {
            return;
        }

        try {
            $changed = DB::connection('decision_race')->update(
                "update guard_requests set status = 'denied', decision_note = 'No cover that week.', decided_at = now(), updated_at = now() where id = ? and status = 'pending'",
                [$id],
            );

            $competitor['won'] = $changed === 1;
        } catch (QueryException $blocked) {
            // 1205, lock wait timeout: the decision under test holds the row. That is the fix.
            $competitor['won'] = false;
            $competitor['error'] = $blocked->errorInfo[1] ?? null;
        }
    });

    $ours = null;
    $ourReason = '';

    try {
        app(RequestInbox::class)->decide($this->dispatcher, $id, ['decision' => 'approved']);
        $ours = true;
    } catch (RuntimeException $refused) {
        $ours = false;
        $ourReason = $refused->getMessage();
    }

    expect($competitor['won'])->not->toBeNull('the other dispatcher never got to press their button');

    /*
     * THE DEFECT: both decisions "won". The competitor's denial was committed, then this
     * decision's UPDATE — matching on the id alone — overwrote it with an approval and
     * audited it, so the guard was told yes while a dispatcher had said no.
     */
    expect($competitor['won'] && $ours)->toBeFalse('both decisions landed: the second silently overwrote the first')
        ->and($competitor['won'] || $ours)->toBeTrue('neither decision landed');

    $status = DB::connection('mysql')->table('guard_requests')->where('id', $id)->value('status');
    $audits = AuditEntry::query()->where('entity_type', 'guard_request')->where('entity_id', (string) $id)->count();

    if ($ours) {
        // This decision held the row, so the other dispatcher waited and was turned away.
        expect($status)->toBe('approved')
            ->and($audits)->toBe(1)
            ->and($competitor['error'])->toBe(1205);
    } else {
        // The other dispatcher's landed first, and this one is told so rather than overwriting it.
        expect($status)->toBe('denied')
            ->and($audits)->toBe(0)
            ->and($ourReason)->toContain('someone else');
    }
});
