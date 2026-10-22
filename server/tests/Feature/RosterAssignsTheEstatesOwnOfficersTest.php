<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Services\Gemini\Roster;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| An open shift is filled by an officer who can stand it (bug hunt B005, B071)
|--------------------------------------------------------------------------
|
| A shift belongs to a post, and a post to one estate. The officer's handset
| reaches only the shifts of the estate they are posted at — `ResolveDevice`
| builds the context from the guard's own `tenant_id`, and clock-in answers 404
| for any other estate's shift. So an officer from Estate A put on Estate B's
| gate made the coverage board read "covered" for a post nobody could clock in
| to, and the roster's picker made that the default: it preselected the
| platform's alphabetically first officer for every row.
|
| The same assign also took an officer who has left, one whose licence is not on
| file, and — from a site-scoped Head of Security — an officer at an estate the
| role cannot see, whose name the success message then repeated.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** An open shift at the fixture estate, the day after tomorrow on its own clock. */
function ownEstateOpenShift(string $name = 'Own Estate Gate'): Shift
{
    $post = Post::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE,
        'name' => $name,
        'type' => 'gate',
        'is_active' => true,
    ]);

    $start = SiteTime::today(FacilitiesFixture::ESTATE)->addDays(2)->addHours(7);

    return app(Roster::class)->postOpenShift(
        $post,
        $start->toDateTimeString(),
        $start->copy()->addHours(12)->toDateTimeString(),
        FacilitiesFixture::geminiViewer(Role::DIRECTOR),
    );
}

/** @param  array<string, mixed>  $overrides */
function ownEstateOfficer(array $overrides = []): Guard
{
    static $n = 0;
    $n++;

    return Guard::query()->create([
        'full_name' => 'Own Estate Officer '.$n,
        'employee_number' => 'GS-OEO-'.$n.'-'.uniqid(),
        'psra_number' => 'PSRA-OEO-'.$n.'-'.uniqid(),
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        ...$overrides,
    ]);
}

it('refuses an officer posted at another estate, or at none, because their handset could never clock in to the shift', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $shift = ownEstateOpenShift();

    $elsewhere = ownEstateOfficer(['tenant_id' => 'ownestate-elsewhere']);
    $unposted = ownEstateOfficer(['tenant_id' => null]);

    foreach ([$elsewhere, $unposted] as $officer) {
        $this->actingAs($director)
            ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $officer->id])
            ->assertSessionHasErrors('guard_id');

        expect($shift->fresh()->guard_id)->toBeNull()
            ->and($shift->fresh()->status)->toBe(Shift::OPEN);
    }

    // The service refuses it too, so no other caller can roster across estates.
    expect(fn () => app(Roster::class)->assign($shift->fresh(), $elsewhere, $director))
        ->toThrow(DomainException::class);

    // An officer who is posted there is put on it.
    $own = ownEstateOfficer();

    $this->actingAs($director)
        ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $own->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($shift->fresh()->guard_id)->toBe($own->id)
        ->and($shift->fresh()->status)->toBe(Shift::ROSTERED);
});

it('does not let a site-scoped Head of Security pull in an officer they cannot see, nor tells them that officer\'s name', function () {
    $head = FacilitiesFixture::geminiViewer(Role::HEAD_OF_SECURITY);

    EstateAssignment::create([
        'user_id' => $head->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        'role_id' => Role::named(Role::HEAD_OF_SECURITY)->id,
        'is_active' => true,
    ]);

    $shift = ownEstateOpenShift();
    $hidden = ownEstateOfficer(['tenant_id' => 'ownestate-outofscope', 'full_name' => 'Nobody Heard Of Them']);

    $response = $this->actingAs($head->fresh())
        ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $hidden->id]);

    $response->assertSessionHasErrors('guard_id');

    expect(session('errors')->first('guard_id'))->not->toContain('Nobody Heard Of Them')
        ->and((string) session('success'))->not->toContain('Nobody Heard Of Them')
        ->and($shift->fresh()->guard_id)->toBeNull();
});

it('refuses an officer who has left, whose record says their licence expired, or whose licence has no expiry on file', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $shift = ownEstateOpenShift();

    $refused = [
        ownEstateOfficer(['status' => 'inactive']),
        ownEstateOfficer(['status' => 'licence_expired']),
        ownEstateOfficer(['psra_expires_on' => null]),
    ];

    foreach ($refused as $officer) {
        $this->actingAs($director)
            ->post('/guards/roster/shifts/'.$shift->id.'/assign', ['guard_id' => $officer->id])
            ->assertSessionHasErrors('guard_id');

        expect($shift->fresh()->guard_id)->toBeNull();
    }
});

it('offers each open shift only the officers of its own estate who can stand it, and preselects nobody — through the panel the roster opens', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);
    $shift = ownEstateOpenShift();

    $own = ownEstateOfficer(['full_name' => 'Zed Own Estate']);
    $elsewhere = ownEstateOfficer(['tenant_id' => 'ownestate-elsewhere', 'full_name' => 'Aaron Elsewhere']);
    $left = ownEstateOfficer(['status' => 'inactive']);
    $undated = ownEstateOfficer(['psra_expires_on' => null]);
    $lapsed = ownEstateOfficer(['psra_expires_on' => now()->subMonth()->toDateString()]);

    // M35 §2: the classic roster's Assign opens the schedule's shift panel, so the roster hands no officer list of its
    // own — the row says which estate it is at, and the panel asks the server who can stand it.
    $this->actingAs($director)
        ->get('/guards/roster?date='.$shift->rostered_start->toDateString())
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($shift) {
            $row = collect($page->toArray()['props']['day']['rows'])->firstWhere('id', $shift->id);

            expect($row['tenant_id'])->toBe(FacilitiesFixture::ESTATE)
                ->and($page->toArray()['props'])->not->toHaveKey('officers')
                ->and($page->toArray()['props']['shiftPanels'])->toHaveKey(FacilitiesFixture::ESTATE);
        });

    // Opened on the shift, the panel reads it: nobody is on it, so nobody is chosen for the person assigning.
    $read = $this->actingAs($director)->getJson('/guards/schedule/shifts/'.$shift->id)->assertOk()->json();
    expect($read['form']['guard_id'])->toBeNull();

    // And who can stand it is the server's answer: the estate's own officer free; nobody the server would refuse free.
    $from = SiteTime::local($shift->rostered_start, FacilitiesFixture::ESTATE);
    $to = SiteTime::local($shift->rostered_end, FacilitiesFixture::ESTATE);
    $answer = $this->actingAs($director)->postJson('/guards/schedule/check', [
        'shift_id' => $shift->id, 'post_id' => $shift->post_id, 'date' => $from->toDateString(),
        'starts' => $from->format('H:i'), 'ends' => $to->format('H:i'), 'duty' => 'post', 'guard_id' => null, 'repeat' => 'once',
    ])->assertOk()->json();
    $guards = collect($answer['guards'])->keyBy('id');

    expect($guards[$own->id]['free'] ?? null)->toBeTrue();

    foreach ([$elsewhere, $left, $undated, $lapsed] as $refused) {
        expect(! $guards->has($refused->id) || $guards[$refused->id]['free'] === false)
            ->toBeTrue($refused->full_name.' is offered as free, and the server would refuse them');
    }
});
