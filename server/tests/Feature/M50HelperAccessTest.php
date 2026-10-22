<?php

declare(strict_types=1);

use App\Api\AccessRules;
use App\Api\AppMatrix;
use App\Jobs\Push\SendExpoPush;
use App\Models\AuditEntry;
use App\Models\DevicePushToken;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\ResidentAccount;
use App\Models\Role;
use App\Services\Passes\VisitorPasses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Dependents and Helpers, by the estate's own rules (the user, 8 Oct 2026)
|--------------------------------------------------------------------------
|
| "Every rule in one settings place so I can change any of them later from admin without a rebuild." The estate's
| console sets what a Dependent and a Helper may each do; every check follows it at once; what nobody can change
| (panic, a Member, the primary's powers) does not move. A Helper's e-Pass works only in their days and hours — on the
| phone and at the gate — a household can pause them at once, and the household is told when they come in.
|
| The estate database is shared by the run and not rolled back: Lot 9541 is this file's, and the estate's rules are
| put back to none after every test.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['services.resident_app.access_levels' => true]);
    AccessRules::forget();
    EstateSetting::current()->forceFill(['resident_access_rules' => null])->save();

    $unit = FacilitiesFixture::scratchUnit('Lot 9541');
    $this->unit = $unit;
    $this->household = Household::query()->firstOrCreate(['unit_id' => $unit->id], ['name' => 'Rules household', 'access_restricted' => false]);
    $this->primary = Tests\Support\HouseholdHolder::of($this->household, 'Pria Primary');
    $this->helper = Resident::query()->firstOrCreate(['household_id' => $this->household->id, 'full_name' => 'Hope Helper'], ['relationship' => 'domestic_staff', 'is_primary' => false]);
    $this->helper->forceFill(['access_level' => AppMatrix::HELPER, 'access_days' => null, 'access_from' => null, 'access_to' => null, 'access_until' => null, 'access_paused_at' => null, 'removed_at' => null])->save();
    $this->dependent = Resident::query()->firstOrCreate(['household_id' => $this->household->id, 'full_name' => 'Dee Dependent'], ['relationship' => 'child', 'is_primary' => false]);
    $this->dependent->forceFill(['access_level' => AppMatrix::DEPENDENT, 'removed_at' => null])->save();
    // The estate's database outlives each test and the central signing keys do not: an e-Pass row left by an earlier
    // test was signed with a key that is gone. Every test here starts with none.
    VisitorPass::query()->where('unit_id', $unit->id)->where('category', VisitorPasses::RESIDENT)->where('status', VisitorPass::ACTIVE)->update(['status' => VisitorPass::CANCELLED]);

    $make = function (Resident $r, string $who): array {
        $account = ResidentAccount::query()->create([
            'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => $who.'.'.bin2hex(random_bytes(3)).'@example.com',
            'full_name' => $r->full_name, 'resident_id' => $r->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
        ]);

        return [$account, $account->createToken('resident-app:'.$who, AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken];
    };
    [$this->primaryAccount, $this->primaryToken] = $make($this->primary, 'pria');
    [$this->helperAccount, $this->helperToken] = $make($this->helper, 'hope');
    [$this->dependentAccount, $this->dependentToken] = $make($this->dependent, 'dee');
});

afterEach(function () {
    Carbon::setTestNow();
    inRulesEstate(static fn () => EstateSetting::current()->forceFill(['resident_access_rules' => null])->save());
    AccessRules::forget();
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

/** In the estate's own tenancy, as every request and every job that reads the rules runs. */
function inRulesEstate(callable $fn): mixed
{
    tenancy()->end();
    FacilitiesFixture::boot();

    return tenancy()->find(FacilitiesFixture::ESTATE)->run($fn);
}

/** A fresh sign-in for an account at the test's own clock — a phone's session is open thirty minutes from its last use. */
function freshToken(ResidentAccount $account): string
{
    return $account->createToken('resident-app:fresh-'.bin2hex(random_bytes(3)), AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;
}

function rulesCall(TestCase $test, string $token, string $method, string $uri, array $body = []): TestResponse
{
    app('auth')->forgetGuards();
    $headers = $method === 'GET' ? [] : ['Idempotency-Key' => 'rules-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
    $response = $test->withToken($token)->json($method, '/api/v1/'.$uri, $body, $headers);
    FacilitiesFixture::boot();
    AccessRules::forget();

    return $response;
}

function saveRules(array $changes): void
{
    $by = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    inRulesEstate(static function () use ($changes, $by): void {
        $board = AccessRules::board();
        $input = [
            'dependent' => collect($board['groups'])->mapWithKeys(static fn (array $g): array => [$g['key'] => $g['dependent']])->all(),
            'helper' => collect($board['groups'])->mapWithKeys(static fn (array $g): array => [$g['key'] => $g['helper']])->all(),
            'helper_days' => $board['helper_days'], 'helper_from' => $board['helper_from'], 'helper_to' => $board['helper_to'], 'entry' => $board['entry'],
        ];
        AccessRules::save(array_replace_recursive($input, $changes), $by);
    });
    FacilitiesFixture::boot();
}

it('opens and closes what a Dependent may do by the estate\'s rule, at once — and nothing it says moves panic or a Member', function () {
    // The platform's default: a Dependent sends no visitor passes.
    expect(rulesCall($this, $this->dependentToken, 'GET', 'visitor-passes')->json('error.code'))->toBe('not_for_your_access_level');

    saveRules(['dependent' => ['visitor_passes' => true]]);
    rulesCall($this, $this->dependentToken, 'GET', 'visitor-passes')->assertOk();
    expect(collect(rulesCall($this, $this->dependentToken, 'GET', 'me')->json('access.may'))->get('visitor_passes'))->toBeTrue();

    // Closing notices for a Dependent closes them; a Member is never narrowed, whatever the rules say.
    saveRules(['dependent' => ['notices' => false], 'helper' => ['notices' => false]]);
    expect(rulesCall($this, $this->dependentToken, 'GET', 'notices')->json('error.code'))->toBe('not_for_your_access_level');
    rulesCall($this, $this->primaryToken, 'GET', 'notices')->assertOk();

    // Panic is in no group: no rule reaches it, for anybody.
    expect(AccessRules::groupOf('duress.store'))->toBeNull()
        ->and(AppMatrix::levelAllows(AppMatrix::HELPER, 'duress.store'))->toBeTrue()
        ->and(AccessRules::groupOf('members.store'))->toBeNull();
});

it('never lets a level pay what it may not see: paying needs seeing the dues', function () {
    saveRules(['helper' => ['payments' => true, 'dues' => false]]);
    expect(inRulesEstate(static fn (): bool => AccessRules::allows(AppMatrix::HELPER, 'payments')))->toBeFalse();

    saveRules(['helper' => ['payments' => true, 'dues' => true]]);
    expect(inRulesEstate(static fn (): bool => AccessRules::allows(AppMatrix::HELPER, 'payments')))->toBeTrue();
});

it('is set on the estate console by a role that may configure Settings, audited; read by one that may only view', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    app('auth')->forgetGuards();
    $page = $this->actingAs($admin)->get(FacilitiesFixture::url('/settings/resident-access'))->assertOk();
    FacilitiesFixture::boot();
    $props = $page->viewData('page')['props'];
    expect(collect($props['groups'])->pluck('key')->all())->toContain('visitor_passes', 'payments', 'notices')
        ->and($props['canEdit'])->toBeTrue()
        ->and($props['helper_from'])->toBe('07:00');

    $body = [
        'dependent' => collect($props['groups'])->mapWithKeys(static fn (array $g): array => [$g['key'] => $g['dependent']])->all(),
        'helper' => collect($props['groups'])->mapWithKeys(static fn (array $g): array => [$g['key'] => $g['helper']])->all(),
        'helper_days' => [1, 2, 3, 4, 5], 'helper_from' => '08:00', 'helper_to' => '16:00', 'entry' => ['helper' => 'members', 'dependent' => 'primary'],
    ];
    app('auth')->forgetGuards();
    $this->actingAs($admin)->post(FacilitiesFixture::url('/settings/resident-access'), $body)->assertSessionHasNoErrors();
    FacilitiesFixture::boot();
    AccessRules::forget();

    expect(inRulesEstate(static fn (): array => AccessRules::board()))->toMatchArray(['helper_days' => [1, 2, 3, 4, 5], 'helper_from' => '08:00', 'helper_to' => '16:00'])
        ->and(inRulesEstate(static fn (): string => AccessRules::entryRecipients(AppMatrix::HELPER)))->toBe('members')
        ->and(AuditEntry::query()->where('action', 'estate.resident_access_rules_changed')->exists())->toBeTrue();
    FacilitiesFixture::boot();

    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    app('auth')->forgetGuards();
    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/settings/resident-access'), $body)->assertForbidden();
    FacilitiesFixture::boot();
});

it('gives a Helper an e-Pass only inside their days and hours, on the phone and at the gate', function () {
    // Saturday 17 October 2026, noon in Kingston: inside the default Mon–Sat, 7 AM – 7 PM. (The pass row lives two
    // days, so Sunday — a day off — and Monday morning both fall inside it.)
    Carbon::setTestNow(Carbon::parse('2026-10-17T17:00:00Z'));
    $pass = rulesCall($this, freshToken($this->helperAccount), 'GET', 'e-pass')->assertOk();
    expect($pass->json('hours'))->toBe('Your e-Pass works Mon–Sat, 7:00 AM – 7:00 PM.');
    // No half hour after 7 PM is signed.
    foreach ($pass->json('slots') as $slot) {
        $local = Carbon::parse($slot['valid_from'])->setTimezone('America/Jamaica');
        expect($local->format('H:i') >= '07:00' && $local->format('H:i') < '19:00')->toBeTrue("a slot at {$local->format('D H:i')}");
    }
    expect(rulesCall($this, freshToken($this->primaryAccount), 'GET', 'e-pass')->json('hours'))->toBeNull();

    // The gate, online, on the typed code — which the row's two-day token would admit at any hour.
    $verdicts = inRulesEstate(function (): array {
        $row = VisitorPass::query()->where('category', VisitorPasses::RESIDENT)->where('issued_by_resident_id', $this->helper->id)->where('status', VisitorPass::ACTIVE)->latest('id')->firstOrFail();
        $passes = app(VisitorPasses::class);
        $at = static fn (string $utc): array => $passes->verifyOnline(FacilitiesFixture::ESTATE, $row->code, Carbon::parse($utc));

        // Saturday noon, inside; 8 PM, after their hours; Sunday 10 AM, not one of their days; Monday 6 AM, before them.
        return ['noon' => $at('2026-10-17T17:00:00Z'), 'late' => $at('2026-10-18T01:00:00Z'), 'sunday' => $at('2026-10-18T15:00:00Z'), 'early' => $at('2026-10-19T11:00:00Z')];
    });
    FacilitiesFixture::boot();

    expect($verdicts['noon']['verdict'])->toBe('valid')
        ->and($verdicts['late']['verdict'])->toBe('expired')
        ->and($verdicts['early']['verdict'])->toBe('not_yet_valid')
        ->and($verdicts['early']['pass']->valid_from->copy()->setTimezone('America/Jamaica')->format('D H:i'))->toBe('Mon 07:00')
        ->and($verdicts['sunday']['verdict'])->toBe('not_yet_valid')
        ->and($verdicts['sunday']['pass']->valid_from->copy()->setTimezone('America/Jamaica')->format('D H:i'))->toBe('Mon 07:00');
});

it('lets the primary set a Helper\'s own hours and pause them at once — their e-Pass is cancelled, at every gate', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-12T17:00:00Z'));
    $this->helperToken = freshToken($this->helperAccount);
    $this->primaryToken = freshToken($this->primaryAccount);
    $this->dependentToken = freshToken($this->dependentAccount);
    rulesCall($this, $this->helperToken, 'GET', 'e-pass')->assertOk();

    // Only the primary; only for a Helper.
    expect(rulesCall($this, $this->dependentToken, 'PATCH', 'household-members/'.$this->helper->id.'/access-window', ['days' => [1], 'from' => '08:00', 'to' => '12:00', 'paused' => false])->status())->toBe(403);
    expect(rulesCall($this, $this->primaryToken, 'PATCH', 'household-members/'.$this->dependent->id.'/access-window', ['days' => [1], 'from' => '08:00', 'to' => '12:00', 'paused' => false])->json('error.code'))->toBe('not_a_helper');

    $set = rulesCall($this, $this->primaryToken, 'PATCH', 'household-members/'.$this->helper->id.'/access-window', ['days' => [1, 3, 5], 'from' => '08:00', 'to' => '12:00', 'paused' => false])->assertOk();
    expect($set->json('access_window.line'))->toBe('Mon, Wed, Fri, 8:00 AM – 12:00 PM');

    $paused = rulesCall($this, $this->primaryToken, 'PATCH', 'household-members/'.$this->helper->id.'/access-window', ['days' => [1, 3, 5], 'from' => '08:00', 'to' => '12:00', 'paused' => true])->assertOk();
    expect($paused->json('access_window.paused'))->toBeTrue()
        ->and(VisitorPass::query()->where('category', VisitorPasses::RESIDENT)->where('issued_by_resident_id', $this->helper->id)->where('status', VisitorPass::ACTIVE)->exists())->toBeFalse();
    expect(rulesCall($this, $this->helperToken, 'GET', 'e-pass')->json('error.code'))->toBe('access_paused');
    expect(AuditEntry::query()->where('action', 'household.helper_access_changed')->where('entity_id', (string) $this->helper->id)->count())->toBe(2);

    // Resumed: an e-Pass again, inside their own hours.
    rulesCall($this, $this->primaryToken, 'PATCH', 'household-members/'.$this->helper->id.'/access-window', ['days' => [1, 3, 5], 'from' => '08:00', 'to' => '12:00', 'paused' => false, 'until' => '2026-10-11'])->assertOk();
    expect(rulesCall($this, $this->helperToken, 'GET', 'e-pass')->json('error.code'))->toBe('access_ended');
});

it('tells the household when a Helper comes in or goes out on their e-Pass — to whom the estate\'s rule says', function () {
    config(['push.enabled' => true, 'push.coalesce_seconds' => 0]);
    Bus::fake([SendExpoPush::class]);
    DevicePushToken::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'principal_type' => DevicePushToken::RESIDENT, 'principal_id' => $this->primaryAccount->id, 'app' => AppMatrix::RESIDENT,
        'expo_token' => 'ExponentPushToken[rulesprimaryxxxxxxxxxx]', 'platform' => 'android', 'muted' => [], 'last_registered_at' => now(),
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-12T17:00:00Z'));
    $admit = function (Resident $person, Carbon $at): void {
        inRulesEstate(function () use ($person, $at): void {
            $passes = app(VisitorPasses::class);
            $passes->admitted($passes->residentPass(FacilitiesFixture::ESTATE, $this->unit, $person->id, $person->full_name), $at, 'Main Gate', 'Guard Whyte');
        });
        FacilitiesFixture::boot();
    };

    $admit($this->helper, Carbon::now());
    Bus::assertDispatched(SendExpoPush::class, static fn (SendExpoPush $job): bool => $job->title === 'Hope Helper came in' && $job->body === 'Main Gate · 12:00 PM' && $job->data['type'] === 'household_entry');

    // A Dependent's entries tell nobody by default; the estate can choose otherwise.
    Bus::fake([SendExpoPush::class]);
    $admit($this->dependent, Carbon::now()->addMinute());
    Bus::assertNotDispatched(SendExpoPush::class);

    saveRules(['entry' => ['helper' => 'nobody']]);
    $admit($this->helper, Carbon::now()->addMinutes(2));
    Bus::assertNotDispatched(SendExpoPush::class);
});

it('refuses the same person added twice — by email, or by name and phone — and takes back somebody who was removed (M54 §2)', function () {
    $tag = bin2hex(random_bytes(3));
    $person = ['full_name' => 'Twice Tester', 'relationship' => 'relative', 'phone' => '876 555 0177', 'email' => "twice.{$tag}@example.com", 'invite' => false];

    $first = rulesCall($this, $this->primaryToken, 'POST', 'household-members', $person)->assertCreated();

    // The same email, in other letters: refused, and nobody added.
    $again = rulesCall($this, $this->primaryToken, 'POST', 'household-members', [...$person, 'full_name' => 'Another Name', 'email' => strtoupper($person['email'])]);
    expect($again->status())->toBe(409)
        ->and($again->json('error.code'))->toBe('member_exists')
        ->and($again->json('error.message'))->toBe('Twice Tester is already in your household.');

    // The same name and phone at another address: refused too.
    expect(rulesCall($this, $this->primaryToken, 'POST', 'household-members', [...$person, 'full_name' => ' twice  TESTER ', 'email' => "other.{$tag}@example.com"])->json('error.code'))
        ->toBe('member_exists');

    expect(Resident::query()->where('household_id', $this->household->id)->where('full_name', 'Twice Tester')->count())->toBe(1);

    // Removed, they may be added back.
    Resident::query()->whereKey($first->json('id'))->firstOrFail()->forceFill(['removed_at' => now()])->save();
    rulesCall($this, $this->primaryToken, 'POST', 'household-members', $person)->assertCreated();
});

it('shows a Helper\'s phone and email to the primary resident and the Helper only — never to a Member or a Dependent (row 20)', function () {
    $this->helper->forceFill(['phone' => '+1 876 555 0190', 'email' => 'hope.helper@example.com'])->save();
    $member = Resident::query()->firstOrCreate(['household_id' => $this->household->id, 'full_name' => 'Max Member'], ['relationship' => 'spouse', 'is_primary' => false]);
    $member->forceFill(['access_level' => AppMatrix::MEMBER, 'removed_at' => null, 'phone' => '+1 876 555 0191', 'email' => 'max.member@example.com'])->save();
    $memberToken = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'max.'.bin2hex(random_bytes(3)).'@example.com',
        'full_name' => $member->full_name, 'resident_id' => $member->id, 'unit_id' => $this->unit->id, 'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ])->createToken('resident-app:max', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $helperRow = fn (string $token, string $uri, string $list): array => collect(rulesCall($this, $token, 'GET', $uri)->assertOk()->json($list))->firstWhere('id', $this->helper->id);

    // The primary resident: both, on the household screen and the members list.
    expect($helperRow($this->primaryToken, 'me/household', 'members'))->toMatchArray(['phone' => '+1 876 555 0190', 'email' => 'hope.helper@example.com'])
        ->and($helperRow($this->primaryToken, 'household-members', 'items'))->toMatchArray(['phone' => '+1 876 555 0190', 'email' => 'hope.helper@example.com']);

    // A Member and a Dependent: the Helper is listed, with neither.
    foreach ([$memberToken, $this->dependentToken] as $token) {
        expect($helperRow($token, 'me/household', 'members'))->toMatchArray(['full_name' => 'Hope Helper', 'phone' => null, 'email' => null])
            ->and($helperRow($token, 'household-members', 'items'))->toMatchArray(['phone' => null, 'email' => null]);
    }

    // Nobody else's are hidden: a Member's own details still reach a Dependent.
    expect(collect(rulesCall($this, $this->dependentToken, 'GET', 'me/household')->json('members'))->firstWhere('id', $member->id))->toMatchArray(['phone' => '+1 876 555 0191', 'email' => 'max.member@example.com']);

    // The Helper reads their own.
    expect(rulesCall($this, $this->helperToken, 'GET', 'me')->assertOk()->json('resident'))->toMatchArray(['phone' => '+1 876 555 0190', 'email' => 'hope.helper@example.com']);
});
