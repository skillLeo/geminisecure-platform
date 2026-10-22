<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\ResidentAccount;
use App\Services\Estate\ResidentInvites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\Support\QueryBudget;

/*
|--------------------------------------------------------------------------
| A household's members come back with their invites from one read — M11 A2
|--------------------------------------------------------------------------
|
| Household invites (24 September) put each member's invite on the Resident App's member
| list and household screen, and each member asked for their own: a query a member, on
| two of the app's most-opened reads. They are read together now.
|
| The oracle is `ResidentInvites::latestFor()`, the one-member read the screens used: for
| every member — never invited, invited once, cancelled and invited again (two rows, the
| later one counts), claimed, lapsed, refused by the mail server — the list must carry
| exactly `outcome(latestFor(member))`, in the same order, and ask the same number of
| queries for nine members as for three.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and every member it adds
| is taken back.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8872');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Budget household', 'access_restricted' => false]);

    $primary = Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Budget Primary')->first();
    if ($primary === null) {
        $primary = new Resident;
        $primary->forceFill(['household_id' => $this->household->id, 'full_name' => 'Budget Primary', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();
    }
    $this->household->residents()->whereKeyNot($primary->id)->update(['is_primary' => false]);
    $primary->forceFill(['is_primary' => true])->save();
    $this->primary = $primary;

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'budget.'.bin2hex(random_bytes(3)).'@invites.test',
        'full_name' => 'Budget Primary', 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        'resident_id' => $primary->id, 'unit_id' => $this->unit->id, 'device_uid' => 'budget-install-1', 'device_platform' => 'android',
    ]);
    $this->token = $account->createToken('resident-app:budget-install-1', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    $added = Resident::withRemoved()->where('household_id', $this->household->id)->whereKeyNot($this->primary->id)->pluck('id');
    DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $added)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $added)->delete();
});

/** Members in every state an invite can leave them in, `$rounds` of each. */
function membersInEveryInviteState(Household $household, int $unitId, int $rounds): void
{
    $db = DB::connection('tenant');
    $now = Carbon::now();

    $invite = static function (int $resident, array $fields) use ($db, $unitId, $now): void {
        $db->table('resident_invites')->insert([
            'resident_id' => $resident, 'unit_id' => $unitId, 'token' => (string) Str::uuid(), 'channels' => 'email',
            'email' => 'member'.$resident.'@invites.test', 'expires_at' => $now->copy()->addDays(14), 'invited_by_name' => 'Budget Primary',
            'attempts' => 1, 'last_attempt_at' => $now->copy()->subHour(), 'sent_at' => $now->copy()->subHour(),
            'created_at' => $now, 'updated_at' => $now, ...$fields,
        ]);
    };

    for ($r = 1; $r <= $rounds; $r++) {
        $member = static function (string $name) use ($household, $r): int {
            $resident = new Resident;
            $resident->forceFill([
                'household_id' => $household->id, 'full_name' => $name.' '.$r, 'relationship' => 'child', 'is_primary' => false,
                'status' => Resident::PENDING, 'email' => strtolower(str_replace(' ', '.', $name)).$r.'@invites.test',
            ])->save();

            return (int) $resident->id;
        };

        $member('Never Invited');
        $invite($member('Sent Once'), []);

        // Cancelled, then invited again: two rows, and the second is the one the screen describes.
        $again = $member('Invited Again');
        $invite($again, ['cancelled_at' => $now->copy()->subHours(2), 'last_attempt_at' => $now->copy()->subHours(3)]);
        $invite($again, ['attempts' => 2, 'last_attempt_at' => $now->copy()->subMinutes(10)]);

        $invite($member('Cancelled'), ['cancelled_at' => $now->copy()->subMinutes(40)]);
        $invite($member('Claimed'), ['claimed_at' => $now->copy()->subMinutes(30)]);
        $invite($member('Lapsed'), ['expires_at' => $now->copy()->subDay()]);
        $invite($member('Refused'), ['sent_at' => null, 'last_error' => 'Nothing answered at that host and port.']);
    }
}

function memberRead(object $test, string $name): TestResponse
{
    app('auth')->forgetGuards();
    $response = $test->withToken($test->token)->getJson('/api/v1/'.Catalogue::endpoints()[$name]['uri'])->assertOk();
    ApiContract::assertMatches($response, $name);
    FacilitiesFixture::boot();

    return $response;
}

it('sends every member\'s invite exactly as the one-member read describes it, in the same order', function (): void {
    membersInEveryInviteState($this->household, $this->unit->id, 2);

    // The oracle: the register's own order, and each member's invite read on its own.
    $invites = app(ResidentInvites::class);
    $expected = $this->household->residents()->orderByDesc('is_primary')->orderBy('full_name')->get()
        ->map(static fn (Resident $r): array => ['id' => $r->id, 'invite' => ResidentInvites::outcome($invites->latestFor($r))])
        ->all();

    foreach (['members.index' => 'items', 'me.household' => 'members'] as $endpoint => $key) {
        $sent = collect(memberRead($this, $endpoint)->json($key))
            ->map(static fn (array $m): array => ['id' => $m['id'], 'invite' => $m['invite']])
            ->all();

        expect($sent)->toBe(json_decode((string) json_encode($expected), true), "{$endpoint} moved an invite");
    }

    // The comparison is only a proof if every state was sent.
    expect(collect($expected)->pluck('invite.status')->unique()->sort()->values()->all())
        ->toBe(['cancelled', 'claimed', 'expired', 'none', 'not_sent', 'sent']);
});

it('reads the invites once, for three members or for nine', function (): void {
    $count = function (string $endpoint): array {
        memberRead($this, $endpoint);
        QueryBudget::asANewRequest();

        $m = QueryBudget::measure(fn () => memberRead($this, $endpoint));

        return [
            // The token's last-used stamp is the clock's doing, not the endpoint's (see `AppApiQueryBudgetTest`).
            'count' => count(array_filter($m['statements'], static fn (array $s): bool => ! str_starts_with(strtolower(ltrim($s['sql'])), 'update `personal_access_tokens` set `last_used_at`'))),
            'invite_reads' => count(array_filter($m['statements'], static fn (array $s): bool => str_contains($s['sql'], 'resident_invites'))),
        ];
    };

    membersInEveryInviteState($this->household, $this->unit->id, 1);
    $few = ['members.index' => $count('members.index'), 'me.household' => $count('me.household')];

    membersInEveryInviteState($this->household, $this->unit->id, 2);
    $many = ['members.index' => $count('members.index'), 'me.household' => $count('me.household')];

    foreach ($few as $endpoint => $measured) {
        expect($measured['invite_reads'])->toBe(1, "{$endpoint} read invites more than once")
            ->and($many[$endpoint]['invite_reads'])->toBe(1)
            ->and($many[$endpoint]['count'])->toBe($measured['count'], "{$endpoint} asked more of nine members than of three");
    }
});
