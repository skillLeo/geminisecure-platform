<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Estate\ResidentInvites;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A household's invites are read once, not once per member
|--------------------------------------------------------------------------
|
| Every member row carries what became of their invite (`invite`), in the app's member lists and on the
| office's household page. Each row read its own invite: one query per person, on every poll of the home
| screen. Now the household's latest invites are read in ONE query, and every row says exactly what the
| one-at-a-time read said.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: this file's own lot, and everybody it adds is taken back.
|
*/

function readOnceClear(Household $household): void
{
    $people = Resident::withRemoved()->where('household_id', $household->id)->pluck('id');
    DB::connection('tenant')->table('resident_invites')->whereIn('resident_id', $people)->delete();
    DB::connection('tenant')->table('residents')->whereIn('id', $people)->delete();
}

/** Run a request, counting the queries that read `resident_invites`. */
function readOnceCount(callable $request): int
{
    $count = 0;

    DB::listen(static function (QueryExecuted $query) use (&$count): void {
        if (str_contains($query->sql, 'resident_invites')) {
            $count++;
        }
    });

    $request();

    return $count;
}

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $this->unit = FacilitiesFixture::scratchUnit('Lot 8888');
    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Read-once household', 'access_restricted' => false]);
    readOnceClear($this->household);

    $this->primary = new Resident;
    $this->primary->forceFill(['household_id' => $this->household->id, 'full_name' => 'Primary Once', 'relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED])->save();

    // Five members: never invited; invited once; invited twice (the second row is the one that counts); cancelled; lapsed.
    $this->members = [];

    foreach (['Never Once', 'Sent Once', 'Twice Once', 'Cancelled Once', 'Lapsed Once'] as $name) {
        $member = new Resident;
        $member->forceFill(['household_id' => $this->household->id, 'full_name' => $name, 'relationship' => 'child', 'is_primary' => false, 'status' => Resident::PENDING, 'email' => Str::slug($name).'@once.test'])->save();
        $this->members[$name] = $member;
    }

    $invite = function (Resident $member, array $fields): void {
        $row = new ResidentInvite(['resident_id' => $member->id, 'unit_id' => $this->unit->id, 'channels' => 'email']);
        $row->forceFill(['token' => (string) Str::uuid(), 'email' => $member->email, 'attempts' => 1, 'last_attempt_at' => Carbon::now()->subHour(), ...$fields])->save();
    };

    $invite($this->members['Sent Once'], ['sent_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->addDays(14)]);
    $invite($this->members['Twice Once'], ['sent_at' => null, 'last_error' => 'The first try was refused.', 'expires_at' => Carbon::now()->addDays(14), 'cancelled_at' => Carbon::now()->subMinutes(30)]);
    $invite($this->members['Twice Once'], ['sent_at' => Carbon::now()->subMinutes(5), 'expires_at' => Carbon::now()->addDays(14)]);
    $invite($this->members['Cancelled Once'], ['sent_at' => Carbon::now()->subHour(), 'expires_at' => Carbon::now()->addDays(14), 'cancelled_at' => Carbon::now()->subMinutes(10)]);
    $invite($this->members['Lapsed Once'], ['sent_at' => Carbon::now()->subDays(20), 'expires_at' => Carbon::now()->subDays(6)]);

    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'primary.'.bin2hex(random_bytes(3)).'@once.test',
        'full_name' => 'Primary Once', 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(), 'resident_id' => $this->primary->id, 'unit_id' => $this->unit->id,
    ]);
    $this->token = $account->createToken('resident-app:once', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    readOnceClear($this->household);
});

it('reads a household\'s invites in one query, and every member says what the one-at-a-time read said', function () {
    // What each row must say — the single read, person by person.
    $expected = [];

    foreach ([$this->primary, ...array_values($this->members)] as $person) {
        $expected[$person->id] = ResidentInvites::outcome(app(ResidentInvites::class)->latestFor($person));
    }

    expect(collect($expected)->pluck('status')->sort()->values()->all())->toBe(['cancelled', 'expired', 'none', 'none', 'sent', 'sent']);

    /*
     * ONE METHOD FOR IT: M11's `latestForEach(list<Resident>)` (`m10/m11-n1-estate` 41accda), which reads the same
     * MAX(id) in one statement for the same household page. This lane uses it rather than a second method of its
     * own, so the two branches merge to the same text and ResidentInvitesAreSentTest's case for it still holds.
     */
    $each = app(ResidentInvites::class)->latestForEach([$this->primary, ...array_values($this->members)]);

    foreach ($expected as $id => $outcome) {
        expect(ResidentInvites::outcome($each[$id] ?? null))->toBe($outcome, "latestForEach, resident {$id}");
    }

    expect(method_exists(ResidentInvites::class, 'latestForMany'))->toBeFalse('a second method doing latestForEach\'s read');

    foreach (['members.index' => '/api/v1/household-members', 'me.household' => '/api/v1/me/household'] as $name => $uri) {
        $response = null;

        $reads = readOnceCount(function () use (&$response, $uri): void {
            app('auth')->forgetGuards();
            $response = $this->withToken($this->token)->getJson($uri)->assertOk();
        });
        FacilitiesFixture::boot();

        $rows = collect($response->json($name === 'members.index' ? 'items' : 'members'));

        expect($reads)->toBe(1, "[{$name}] read resident_invites {$reads} times for six members")
            ->and($rows)->toHaveCount(6);

        foreach ($rows as $row) {
            expect($row['invite'])->toBe($expected[$row['id']], "[{$name}] {$row['full_name']}");
        }
    }

    // The office's household page: the same one read, the same answers.
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $page = null;

    $reads = readOnceCount(function () use (&$page, $manager): void {
        $page = $this->actingAs($manager)->get(FacilitiesFixture::url('/residents/'.$this->unit->slug()))->assertOk();
    });
    FacilitiesFixture::boot();

    expect($reads)->toBe(1, "the household page read resident_invites {$reads} times for six people");

    // The office's copy is `officeOutcome()`: the same answer, plus the real reason a refused invite did not go.
    $office = [];

    foreach ([$this->primary, ...array_values($this->members)] as $person) {
        $office[$person->id] = ResidentInvites::officeOutcome(app(ResidentInvites::class)->latestFor($person));
    }

    $page->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('people', function ($people) use ($office): bool {
        foreach ($people as $person) {
            if ($person['invite'] !== $office[$person['id']]) {
                return false;
            }
        }

        return count($people) === 6;
    }));
});
