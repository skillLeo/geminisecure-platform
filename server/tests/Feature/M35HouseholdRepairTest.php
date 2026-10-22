<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Services\Estate\OnePrimaryIndex;
use App\Services\Passes\VisitorPasses;
use App\Support\Seeded\Seeding;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Household 92, put right by the office (M35 §6)
|--------------------------------------------------------------------------
|
| The user's household on production has two primaries — the seeded Andrea Fletcher, and the user — and five members
| the user added while trying the Resident App. Two commands, each the user's to run:
|
|   residents:repair-primaries --household --keep   keeps the user as primary; Andrea stays, an ordinary member. It
|                                                   writes the residents table and the audit log, and nothing else —
|                                                   no charge, no payment, no ledger line.
|   residents:remove-members                        takes the five test entries off, exactly as the app's own Remove
|                                                   does (the same code), a dry run unless --confirm, and never a
|                                                   primary or a person a seeder invented.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and everybody it adds is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    Notification::fake();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 9491');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Repair household', 'access_restricted' => false]);
    m35hForget($this);

    // As production holds household 92: the seeded primary first, the person who really lives there second — both
    // primary, written past the model, since the model now refuses the pair. And with the one-primary index lifted
    // (M47 §14a), as production has none while its pair stands; put back after each test.
    OnePrimaryIndex::drop('tenant');
    $this->seededPrimary = (int) Seeding::run('phoenix:demo-seed', fn (): int => (int) DB::connection('tenant')->table('residents')->insertGetId([
        'household_id' => $this->household->id, 'full_name' => 'Seeded Holder', 'relationship' => 'owner', 'is_primary' => true,
        'status' => 'verified', 'seeded_at' => now(), 'created_at' => now()->subDays(20), 'updated_at' => now(),
    ]));
    $this->realPrimary = (int) DB::connection('tenant')->table('residents')->insertGetId([
        'household_id' => $this->household->id, 'full_name' => 'Real Holder', 'relationship' => 'owner', 'is_primary' => true,
        'status' => 'verified', 'created_at' => now()->subDays(10), 'updated_at' => now(),
    ]);

    $member = fn (string $name): Resident => tap(new Resident, fn (Resident $r) => $r->forceFill(['household_id' => $this->household->id, 'full_name' => $name, 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::PENDING])->save());

    // One test entry with everything a removal ends: an app account signed in, an open invite with a pending claim and
    // the account behind it, and an e-pass of their own.
    $this->first = $member('Test Entry One');
    $this->firstAccount = m35hAccount(['resident_id' => $this->first->id, 'status' => ResidentAccount::ACTIVE, 'unit_id' => $this->unit->id]);
    $this->firstAccount->createToken('resident-app:m35h', ['*']);
    $invite = tap(new ResidentInvite, fn (ResidentInvite $i) => $i->forceFill(['resident_id' => $this->first->id, 'unit_id' => $this->unit->id, 'token' => (string) Str::uuid(), 'channels' => 'email', 'email' => 'test.entry.one@example.org', 'sent_at' => now(), 'expires_at' => now()->addDays(14)])->save());
    $this->claim = tap(new UnitClaim, fn (UnitClaim $c) => $c->forceFill(['unit_id' => $this->unit->id, 'household_id' => $this->household->id, 'claim_type' => UnitClaim::TYPE_MEMBER, 'submitted_name' => 'Test Entry One', 'match_result' => 'none', 'status' => UnitClaim::PENDING, 'invite_id' => $invite->id])->save());
    $this->claimAccount = m35hAccount(['status' => ResidentAccount::PENDING, 'claim_id' => $this->claim->id]);
    $this->pass = (int) DB::connection('tenant')->table('visitor_passes')->insertGetId([
        'pass_id' => (string) Str::uuid(), 'unit_id' => $this->unit->id, 'household_id' => $this->household->id,
        'issued_by_resident_id' => $this->first->id, 'issued_by_name' => 'Test Entry One', 'category' => VisitorPasses::RESIDENT,
        'visitor_name' => 'Test Entry One', 'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(), 'nonce' => str_repeat('a', 32),
        'key_version' => 1, 'token' => 'm35h', 'code' => 'MTHR'.random_int(1000, 9999), 'status' => VisitorPass::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // A second whose access the office had already revoked: removed from the household, it stays revoked (G1).
    $this->second = $member('Test Entry Two');
    $this->revoked = m35hAccount(['resident_id' => $this->second->id, 'status' => ResidentAccount::REVOKED, 'unit_id' => $this->unit->id]);

    // And one the household removed already.
    $this->gone = $member('Test Entry Gone');
    $this->gone->forceFill(['removed_at' => now()->subDay()])->save();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    m35hForget($this);
    OnePrimaryIndex::ensure('tenant');
});

function m35hForget(object $test): void
{
    $people = Resident::withRemoved()->where('household_id', $test->household->id)->pluck('id');

    DB::connection('tenant')->table('visitor_passes')->where('unit_id', $test->unit->id)->delete();
    DB::connection('tenant')->table('unit_claims')->where('unit_id', $test->unit->id)->delete();
    DB::connection('tenant')->table('resident_invites')->where('unit_id', $test->unit->id)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $people)->delete();
}

/** @param  array<string, mixed>  $extra */
function m35hAccount(array $extra): ResidentAccount
{
    return tap(new ResidentAccount, static fn (ResidentAccount $a) => $a->forceFill([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'm35h.'.bin2hex(random_bytes(4)).'@example.org',
        'full_name' => 'Repair account', 'verified_at' => now(), ...$extra,
    ])->save());
}

/**
 * Run a command and answer what it printed, its exit code, and every table it wrote to — by watching every statement
 * on every connection, so "it writes only the residents table" is what the database was told, not what the code says.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string, 2: list<string>}
 */
function m35hRun(string $command, array $options): array
{
    $written = [];
    $listening = true;

    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$written, &$listening): void {
        if ($listening && preg_match('/^\s*(?:insert(?:\s+ignore)?\s+into|update|delete\s+from|replace\s+into)\s+`?(\w+)`?/i', $query->sql, $m) === 1) {
            $written[] = $query->connectionName.'.'.$m[1];
        }
    });

    try {
        $code = Artisan::call($command, $options);
    } finally {
        $listening = false;
    }

    $said = Artisan::output();
    FacilitiesFixture::boot();

    $written = array_values(array_unique($written));
    sort($written);

    return [$code, $said, $written];
}

it('repair-primaries --household --keep keeps the one named, leaves the seeded primary an ordinary member, and writes only the residents table and the audit log', function () {
    $options = ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $this->household->id, '--keep' => (string) $this->realPrimary];

    // The dry run reads and writes nothing at all.
    [$code, $said, $written] = m35hRun('residents:repair-primaries', [...$options, '--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($said)->toContain('DRY RUN')->toContain('would keep #'.$this->realPrimary.' Real Holder')->toContain('would demote #'.$this->seededPrimary.' Seeded Holder')
        ->and($written)->toBe([]);

    // The real run, as the user will paste it: no --confirm (the command has none — naming a household is the choice).
    [$code, $said, $written] = m35hRun('residents:repair-primaries', $options);

    expect($code)->toBe(0)
        ->and($said)->toContain('kept #'.$this->realPrimary)->toContain('demoted #'.$this->seededPrimary)->toContain('chosen by --keep')
        // Every table the database was told to write: the residents table, and one audit entry. Not a charge, a
        // payment, a journal or a ledger line.
        ->and($written)->toBe(['mysql.audit_log', 'tenant.residents']);

    $seeded = Resident::withRemoved()->findOrFail($this->seededPrimary);
    expect($seeded->is_primary)->toBeFalse()
        // An ordinary member still: not removed, not deleted, the name as it was.
        ->and($seeded->removed_at)->toBeNull()
        ->and($seeded->full_name)->toBe('Seeded Holder')
        ->and(Resident::query()->findOrFail($this->realPrimary)->is_primary)->toBeTrue()
        ->and(Resident::query()->where('household_id', $this->household->id)->where('is_primary', true)->pluck('id')->all())->toBe([$this->realPrimary]);

    $after = json_decode((string) DB::connection('mysql')->table('audit_log')->where('action', 'residents.primary_repaired')->where('entity_id', (string) $this->household->id)->value('after'), true);
    expect($after['kept'])->toBe($this->realPrimary)->and($after['demoted'])->toBe([$this->seededPrimary]);
});

it('remove-members says what it would do and changes nothing; with --confirm it does what the app\'s Remove does, audited, and writes only what a removal writes', function () {
    $options = [
        '--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $this->household->id,
        '--member' => $this->first->id.','.$this->second->id, '--reason' => 'Test entries made while trying the Resident App',
    ];

    [$code, $said, $written] = m35hRun('residents:remove-members', $options);

    expect($code)->toBe(0)
        ->and($said)->toContain('DRY RUN — nothing is changed.')
        ->toContain('would remove #'.$this->first->id.' Test Entry One (child;')
        ->toContain('2 Resident App accounts signed out and suspended (1 active now), 1 pending claim rejected, 1 e-pass cancelled, their open invite cancelled')
        ->toContain('would remove #'.$this->second->id.' Test Entry Two (child;')
        ->toContain('0 Resident App accounts signed out and suspended, 0 pending claims rejected, 0 e-passes cancelled, no open invite')
        ->toContain('stays: #'.$this->seededPrimary.' Seeded Holder (primary) (sample data)')
        ->toContain('stays: #'.$this->realPrimary.' Real Holder (primary)')
        ->toContain('In all: 2 people would be removed; 2 Resident App accounts would be suspended (1 active now); 1 claim would be rejected; 1 e-pass would be cancelled.')
        ->toContain('To do it: the same command with --confirm.')
        // Nothing an address would show.
        ->not->toContain('@')
        ->and($written)->toBe([]);

    expect(Resident::query()->whereKey($this->first->id)->exists())->toBeTrue()
        ->and($this->firstAccount->fresh()->status)->toBe(ResidentAccount::ACTIVE);

    [$code, $said, $written] = m35hRun('residents:remove-members', [...$options, '--confirm' => true]);

    expect($code)->toBe(0)
        ->and($said)->toContain('Done: 2 people removed from household '.$this->household->id)
        // What a removal writes, and nothing else: no charge, no payment, no journal, no household. Besides the rows it
        // changes: the suspended accounts' phones stop being pushed to (`device_push_tokens`, `PushServiceProvider`),
        // and every console showing the household is told live (`live_events`, both databases' outboxes) — as the
        // app's own Remove does.
        ->and($written)->toBe([
            'mysql.audit_log', 'mysql.device_push_tokens', 'mysql.live_events', 'mysql.personal_access_tokens', 'mysql.resident_accounts',
            'tenant.live_events', 'tenant.resident_invites', 'tenant.residents', 'tenant.unit_claims', 'tenant.visitor_passes',
        ]);

    // Removed, not deleted — and everything the app's Remove ends, ended.
    $first = Resident::withRemoved()->findOrFail($this->first->id);
    expect($first->removed_at)->not->toBeNull()
        ->and($first->getAttribute('removed_by_resident_id'))->toBeNull()
        ->and(Resident::query()->whereKey($this->first->id)->exists())->toBeFalse()
        ->and($this->firstAccount->fresh()->status)->toBe(ResidentAccount::SUSPENDED)
        ->and($this->firstAccount->fresh()->tokens()->count())->toBe(0)
        ->and($this->claim->fresh()->status)->toBe(UnitClaim::REJECTED)
        ->and($this->claim->fresh()->decision_reason)->toBe('Removed from the household by the estate office before approval.')
        ->and($this->claimAccount->fresh()->status)->toBe(ResidentAccount::SUSPENDED)
        ->and(DB::connection('tenant')->table('visitor_passes')->where('id', $this->pass)->value('status'))->toBe(VisitorPass::CANCELLED)
        ->and(ResidentInvite::query()->where('resident_id', $this->first->id)->whereNull('cancelled_at')->exists())->toBeFalse()
        // The office's revocation stands.
        ->and($this->revoked->fresh()->status)->toBe(ResidentAccount::REVOKED)
        ->and(Resident::withRemoved()->findOrFail($this->second->id)->removed_at)->not->toBeNull()
        // The primaries, the seeded one among them, are untouched.
        ->and(Resident::query()->where('household_id', $this->household->id)->pluck('id')->sort()->values()->all())->toBe([$this->seededPrimary, $this->realPrimary]);

    $entries = DB::connection('mysql')->table('audit_log')->where('action', 'household.member_removed')->whereIn('entity_id', [(string) $this->first->id, (string) $this->second->id])->get();
    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        $after = json_decode((string) $entry->after, true);
        expect($after['via'])->toBe('residents:remove-members')
            ->and($after['reason'])->toBe('Test entries made while trying the Resident App')
            ->and($after['removed_by_resident_id'])->toBeNull()
            ->and((string) $entry->actor_name)->toContain('(residents:remove-members)');
    }

    // Run again: they are removed already, so it refuses, and nothing more is written.
    [$code, $said, $written] = m35hRun('residents:remove-members', [...$options, '--confirm' => true]);
    expect($code)->toBe(2)->and($said)->toContain('was removed already')->and($written)->toBe([]);
});

it('remove-members refuses a primary, a person a seeder invented, somebody of another household and one removed already — and then changes nothing for anybody', function () {
    $named = fn (array $ids): array => m35hRun('residents:remove-members', [
        '--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $this->household->id,
        '--member' => implode(',', $ids), '--reason' => 'Test', '--confirm' => true,
    ]);

    $seededMember = (int) Seeding::run('phoenix:demo-seed', fn (): int => (int) DB::connection('tenant')->table('residents')->insertGetId([
        'household_id' => $this->household->id, 'full_name' => 'Seeded Member', 'relationship' => 'child', 'is_primary' => false,
        'status' => 'verified', 'seeded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));

    foreach ([
        [$this->realPrimary, 'is a primary resident'],
        [$seededMember, 'is sample data, not a person'],
        [999999, 'is not a member of household '.$this->household->id],
        [$this->gone->id, 'was removed already'],
    ] as [$refused, $words]) {
        [$code, $said, $written] = $named([$this->first->id, $refused]);

        expect($code)->toBe(2)
            ->and($said)->toContain($words)->toContain('Nothing was changed.')
            ->and($written)->toBe([]);
    }

    expect(Resident::query()->whereKey($this->first->id)->exists())->toBeTrue()
        ->and(Resident::query()->whereKey($seededMember)->exists())->toBeTrue();

    // And what it is told must be whole: an estate, a household, who, and why.
    [$code, $said] = m35hRun('residents:remove-members', ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $this->household->id, '--member' => (string) $this->first->id]);
    expect($code)->toBe(2)->and($said)->toContain('and why');

    [$code, $said] = m35hRun('residents:remove-members', ['--estate' => FacilitiesFixture::ESTATE, '--household' => (string) $this->household->id, '--member' => 'Asdfg', '--reason' => 'Test']);
    expect($code)->toBe(2)->and($said)->toContain('is not an id');
});

it('the app\'s own Remove still ends what it ended before, now through the same code the office uses', function () {
    // The register's primary is the one the app reads; the seeded holder is demoted as the repair would, so one primary.
    DB::connection('tenant')->table('residents')->where('id', $this->seededPrimary)->update(['is_primary' => false]);

    $holder = m35hAccount(['resident_id' => $this->realPrimary, 'status' => ResidentAccount::ACTIVE, 'unit_id' => $this->unit->id, 'device_uid' => 'm35h-holder', 'device_platform' => 'android']);
    $token = $holder->createToken('resident-app:m35h-holder', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $this->withToken($token)
        ->json('DELETE', '/api/v1/household-members/'.$this->first->id, [], ['Idempotency-Key' => 'm35h-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String()])
        ->assertOk()
        ->assertJson(['id' => $this->first->id, 'removed' => true]);
    FacilitiesFixture::boot();

    expect(Resident::withRemoved()->findOrFail($this->first->id)->getAttribute('removed_by_resident_id'))->toBe($this->realPrimary)
        ->and($this->firstAccount->fresh()->status)->toBe(ResidentAccount::SUSPENDED)
        ->and($this->claim->fresh()->decision_reason)->toBe('Removed from the household by the primary resident before approval.')
        ->and($this->claimAccount->fresh()->status)->toBe(ResidentAccount::SUSPENDED)
        ->and(DB::connection('tenant')->table('visitor_passes')->where('id', $this->pass)->value('status'))->toBe(VisitorPass::CANCELLED);

    // The entry the app always wrote, key for key (a JSON column keeps no key order, so neither does the comparison).
    $after = json_decode((string) DB::connection('mysql')->table('audit_log')->where('action', 'household.member_removed')->where('entity_id', (string) $this->first->id)->value('after'), true);
    $expected = [
        'removed_by_resident_id' => $this->realPrimary,
        'by_account_id' => $holder->id,
        'via' => 'resident_app',
        'claims_rejected' => 1,
        'resident_passes_cancelled' => 1,
    ];
    ksort($after);
    ksort($expected);
    expect($after)->toBe($expected);
});
