<?php

declare(strict_types=1);

use App\Console\Commands\ApplyCentralAppendOnlyGrants;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Support\GrantedTables;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The gate log is append-only — both locks (Q-M43)
|--------------------------------------------------------------------------
|
| `gate_events` is the ONLY record that an admission happened: who was let into
| an estate, by which officer, on what basis, at what hour. It was called
| append-only everywhere it was discussed and it was not one. It sat in
| `GrantedTables::CENTRAL_MUTABLE`, so `gs_app` held UPDATE and DELETE on it, and
| it carried none of the triggers that hold `audit_log`, the two platform journal
| tables, `journals`, `journal_lines`, `ballot_receipts` and `ballot_marks`.
|
| Nothing in the application had ever used that privilege. `Services\Dispatch\
| GateLog` only inserts, and hands back the row it already wrote when a handset
| retries. So this took away a privilege nobody was exercising, on the one record
| an insurer or a court asks for.
|
| THE TWO HALVES, AND WHICH ONE THIS FILE CAN HONESTLY PROVE.
|
|   THE TRIGGER, proven here for real. `BEFORE UPDATE` and `BEFORE DELETE` on
|   `gate_events` refuse the write for EVERY connection, including this suite's —
|   which connects as the schema owner `gs_owner` (phpunit.xml) and holds every
|   privilege on every table. That is exactly why the trigger exists: the grant
|   protects the application, the trigger protects the table.
|
|   THE GRANT, asserted as a declaration rather than observed. This suite
|   structurally cannot watch `gs_app` be refused, because it never connects as
|   `gs_app` — see the note at the head of `GrantsCoverageTest`. What is asserted
|   here is that `gate_events` is DECLARED append-only and is no longer declared
|   mutable, which is what `grants:append-only` reads to decide what to withhold.
|   The server-side half is the PASS line that command prints, run after the
|   migration.
|
| AND THE TWO TABLES THAT MUST NOT BE SWEPT UP WITH IT. `duress_alerts` and
| `security_incidents` look like the same kind of record and are not: an alert is
| acknowledged, cancelled and resolved after it is raised, and an incident is
| worked and closed. Locking either would leave a control room that can never stop
| flashing. They are asserted mutable, and asserted to carry no trigger, in the
| same file — because the next person to read the list will wonder why one gate
| table went and two alert tables stayed.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console. Each test runs in a transaction and leaves nothing.
|
*/

uses(DatabaseTransactions::class);

/** An estate row written straight to the table — the shape GateActivityFeedTest uses. */
function lockedEstate(): string
{
    $id = 'lock'.random_int(100000, 999999);

    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => 'Append-only Test Estate',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function () {
    $this->estate = lockedEstate();

    $this->post = Post::create([
        'tenant_id' => $this->estate,
        'name' => 'Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $suffix = random_int(100000, 999999);

    $this->guard = Guard::create([
        'full_name' => 'Marcus Whyte',
        'employee_number' => 'GS-L'.$suffix,
        'psra_number' => 'PSRA-L'.$suffix,
        'psra_expires_on' => now()->addDays(365)->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => $this->estate,
        'post_id' => $this->post->id,
    ]);

    $this->admission = fn (): GateEvent => GateEvent::create([
        'tenant_id' => $this->estate,
        'guard_id' => $this->guard->id,
        'guard_name' => $this->guard->full_name,
        'post_id' => $this->post->id,
        'post_name' => $this->post->name,
        'verdict' => GateEvent::ADMIT,
        'category' => 'guest',
        'subject' => 'Marcia James · Lot 47',
        'basis' => 'QR pass',
        'occurred_at' => now()->subMinutes(2),
        'is_simulated' => true,
    ]);
});

/*
 * FIRST, AND IT IS NOT A FORMALITY. The whole risk of this change is that
 * withholding two privileges breaks the write nobody could afford to lose. A gate
 * that cannot record an admission is worse than a gate log that can be edited.
 */
it('still records an admission', function () {
    $event = ($this->admission)();

    expect($event->exists)->toBeTrue()
        ->and($event->verdict)->toBe(GateEvent::ADMIT)
        ->and(GateEvent::whereKey($event->id)->exists())->toBeTrue();
});

it('refuses to update a gate event', function () {
    $event = ($this->admission)();

    /*
     * Through the query builder rather than the model, so this is the DATABASE
     * refusing and not Eloquent declining. A guarantee that only holds while
     * everybody writes through one class is not a guarantee — the same reasoning
     * as DoubleEntryLedgerTest's raw inserts.
     */
    expect(fn () => DB::connection('mysql')
        ->table('gate_events')
        ->where('id', $event->id)
        ->update(['verdict' => GateEvent::DENY]))
        ->toThrow(QueryException::class, 'gate_events is append-only');

    // And the admission still says what the officer decided.
    expect(GateEvent::whereKey($event->id)->value('verdict'))->toBe(GateEvent::ADMIT);
});

it('refuses to delete a gate event', function () {
    $event = ($this->admission)();

    expect(fn () => DB::connection('mysql')
        ->table('gate_events')
        ->where('id', $event->id)
        ->delete())
        ->toThrow(QueryException::class, 'gate_events is append-only');

    expect(GateEvent::whereKey($event->id)->exists())->toBeTrue();
});

/*
 * The subtler one: an edit that goes through the model rather than round it. A
 * later screen written in good faith — "mark this event reviewed" — must not be
 * able to reach the row either.
 */
it('refuses an edit made through the model', function () {
    $event = ($this->admission)();

    expect(fn () => $event->forceFill(['subject' => 'rewritten'])->save())
        ->toThrow(QueryException::class, 'gate_events is append-only');

    expect(GateEvent::whereKey($event->id)->value('subject'))->toBe('Marcia James · Lot 47');
});

it('carries both triggers on the table itself', function () {
    $triggers = DB::connection('mysql')
        ->table('information_schema.TRIGGERS')
        ->where('TRIGGER_SCHEMA', DB::connection('mysql')->getDatabaseName())
        ->where('EVENT_OBJECT_TABLE', 'gate_events')
        ->pluck('EVENT_MANIPULATION')
        ->map(static fn ($event): string => strtoupper((string) $event))
        ->all();

    expect($triggers)->toContain('UPDATE')
        ->and($triggers)->toContain('DELETE');
});

/*
 * The grant half, as a declaration. `grants:append-only` reads exactly this list
 * to decide which tables `gs_app` keeps UPDATE and DELETE on, and this suite
 * connects as the owner so it can never watch the refusal itself.
 */
it('declares the gate log append-only and no longer mutable', function () {
    expect(GrantedTables::CENTRAL_APPEND_ONLY)->toContain('gate_events')
        ->and(GrantedTables::CENTRAL_MUTABLE)->not->toContain('gate_events')
        ->and(ApplyCentralAppendOnlyGrants::APPEND_ONLY_TABLES)->toContain('gate_events');
});

/*
 * THE LINE THE CHANGE STOPS AT.
 *
 * An alert is raised and then acknowledged, cancelled or resolved; an incident is
 * opened and then worked and closed. Both are normal, daily writes by the control
 * room, and both are UPDATEs. If a future reviewer promotes these two "for
 * consistency", a dispatcher loses the ability to close anything — so the
 * expectation is written down here, beside the change that would have tempted
 * them, rather than left as a sentence in a comment.
 */
it('leaves the alert tables mutable, with no trigger on either', function () {
    foreach (['duress_alerts', 'security_incidents'] as $table) {
        expect(GrantedTables::CENTRAL_MUTABLE)->toContain($table)
            ->and(GrantedTables::CENTRAL_APPEND_ONLY)->not->toContain($table);

        $triggers = DB::connection('mysql')
            ->table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection('mysql')->getDatabaseName())
            ->where('EVENT_OBJECT_TABLE', $table)
            ->count();

        expect($triggers)->toBe(0, "{$table} has grown a trigger; an alert nobody can resolve is a control room that never stops flashing");
    }
});

/*
 * A control-room write proved end to end rather than by the shape of the list: an
 * open alert can still be moved to resolved after this change.
 */
it('still lets an alert be resolved', function () {
    $id = DB::connection('mysql')->table('duress_alerts')->insertGetId([
        'tenant_id' => $this->estate,
        'kind' => 'panic',
        'status' => 'open',
        'server_time' => now(),
        'is_simulated' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('mysql')->table('duress_alerts')->where('id', $id)->update([
        'status' => 'resolved',
        'updated_at' => now(),
    ]);

    expect(DB::connection('mysql')->table('duress_alerts')->where('id', $id)->value('status'))
        ->toBe('resolved');
});
