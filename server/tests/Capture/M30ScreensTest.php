<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\PatrolCheckpoint;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Gemini\ShiftPlanner;
use App\Services\Gemini\WeekCopy;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M30 §14: the pages, as the client will see them, for the screenshots
|--------------------------------------------------------------------------
|
| NOT PART OF THE SUITE: this folder is in no testsuite of phpunit.xml, and the
| file does nothing unless asked (`M30_CAPTURE=1`). It renders the classic pages
| and the new ones through the application's own kernel, as a signed-in Director
| would receive them, in one sample client built here inside a transaction that
| is rolled back. Nobody signs in to anything: the pages are rendered in-process,
| as every feature test renders them.
|
| It writes each page's HTML, and the planner's answers for the shift panel's
| states, to M30_CAPTURE_DIR (storage/framework/testing/m30-capture by default),
| with a manifest. `node tools/m30/screens.mjs` then opens those files in a
| browser with the built assets, offline, and takes the screenshots.
|
*/

uses(TestCase::class);

const M30_SHOTS = 'm30shots';

beforeEach(function () {
    if (getenv('M30_CAPTURE') !== '1') {
        $this->markTestSkipped('Only when capturing the M30 screenshots: M30_CAPTURE=1.');
    }

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    if (DB::connection('mysql')->transactionLevel() > 0) {
        DB::connection('mysql')->rollBack();
    }
    SiteTime::flush();
});

it('renders the classic and the new workforce pages for the screenshots', function () {
    FacilitiesFixture::platform();

    $out = getenv('M30_CAPTURE_DIR') ?: storage_path('framework/testing/m30-capture');
    if (! is_dir($out)) {
        mkdir($out, 0777, true);
    }

    DB::connection('mysql')->table('tenants')->where('id', M30_SHOTS)->delete();
    DB::connection('mysql')->table('tenants')->insert(['id' => M30_SHOTS, 'name' => 'Phoenix Park Village 1', 'status' => 'active', 'timezone' => 'America/Jamaica', 'created_at' => now(), 'updated_at' => now()]);
    SiteTime::flush();

    $post = static fn (string $name, ?string $also, string $type, ?float $lat, ?float $lng, int $radius = 60): Post => Post::query()->create([
        'tenant_id' => M30_SHOTS, 'name' => $name, 'also_called' => $also, 'type' => $type, 'is_active' => true,
        'latitude' => $lat, 'longitude' => $lng, 'geofence_radius_m' => $radius,
    ]);

    $main = $post('Main Gate', 'Gate 1', 'gate', 18.0063, -76.7442, 80);
    $back = $post('Back Gate', 'Gate 2', 'gate', 18.0049, -76.7471, 60);
    $phase5 = $post('Phase 5 Gate', 'Gate 5', 'gate', null, null);
    $patrol = $post('Vehicle Patrol', null, 'patrol', 18.0057, -76.7455, 40);

    $n = 0;
    $guard = static function (string $name, array $extra = []) use (&$n): Guard {
        $n++;

        return Guard::query()->create([
            'full_name' => $name, 'employee_number' => sprintf('GS-%03d', 100 + $n), 'psra_number' => sprintf('PSRA-%05d', 40000 + $n),
            'psra_expires_on' => '2027-06-23', 'employment_type' => 'full_time', 'status' => 'active', 'tenant_id' => M30_SHOTS, ...$extra,
        ]);
    };

    $andre = $guard('Andre Campbell');
    $kadian = $guard('Kadian Walters');
    $shanice = $guard('Shanice Clarke');
    $tashana = $guard('Tashana Gordon');
    $damion = $guard('Damion Reid');
    $marlon = $guard('Marlon Brown');
    $jermaine = $guard('Jermaine Thompson');
    $chambers = $guard('Andre Chambers', ['psra_expires_on' => Carbon::now('America/Jamaica')->addDays(9)->toDateString()]);
    $guard('Ricardo Facey', ['status' => 'suspended']);
    $guard('Kemoy Allen');
    $guard('Sasha Bailey');

    $director = User::factory()->create(['name' => 'Screens Director', 'email' => 'm30-screens-'.uniqid().'@example.com', 'console' => Console::Gemini->value, 'status' => 'active']);
    $director->syncRoles([Role::DIRECTOR]);
    $director = $director->fresh();

    // This week, Monday first, at the client.
    $zone = 'America/Jamaica';
    $now = Carbon::now();
    $monday = Carbon::now($zone)->startOfWeek(Carbon::MONDAY)->startOfDay();

    $shift = static function (Post $at, Carbon $day, string $from, string $to, ?Guard $who, string $duty = 'post') use ($zone, $now, $director): Shift {
        $start = Carbon::parse($day->toDateString().' '.$from, $zone);
        $end = Carbon::parse($day->toDateString().' '.$to, $zone);
        if ($end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        $ended = $end->lessThanOrEqualTo($now);
        $running = $start->lessThanOrEqualTo($now) && $end->greaterThan($now);

        return Shift::query()->create([
            'tenant_id' => M30_SHOTS,
            'guard_id' => $who?->id,
            'post_id' => $at->id,
            'duty' => $duty,
            'rostered_start' => $start->copy()->utc(),
            'rostered_end' => $end->copy()->utc(),
            'actual_start' => $who !== null && ($ended || $running) ? $start->copy()->addMinutes(4)->utc() : null,
            'actual_end' => $who !== null && $ended ? $end->copy()->addMinutes(2)->utc() : null,
            'status' => $who === null ? Shift::OPEN : ($ended ? 'completed' : ($running ? 'on_duty' : Shift::ROSTERED)),
            'posted_by_id' => $director->id,
            'posted_by_name' => (string) $director->name,
        ]);
    };

    // Two weeks: this one and the next, so the next week has room to plan in.
    $claimable = null;
    for ($i = 0; $i < 14; $i++) {
        $day = $monday->copy()->addDays($i);
        $weekday = $day->dayOfWeekIso;
        $weekend = $weekday >= 6;

        $shift($main, $day, '06:00', '18:00', $weekend ? $marlon : $andre);
        $shift($main, $day, '18:00', '06:00', $weekday <= 4 ? $kadian : $jermaine);
        $open = $shift($back, $day, '06:00', '18:00', $weekday === 3 ? null : $shanice);
        $claimable ??= $i >= 7 && $weekday === 3 && $open->status === Shift::OPEN ? $open : null;
        $shift($back, $day, '18:00', '06:00', $weekday === 6 ? null : $tashana);
        $shift($phase5, $day, '06:00', '18:00', $i < 7 && $weekday <= 3 ? $damion : ($i < 7 ? null : $damion));

        if ($weekday % 2 === 1 && $i < 7) {
            $shift($patrol, $day, '20:00', '04:00', $chambers, 'patrol');
        }
    }

    // Damion Reid's approved leave, Thursday to Sunday of this week; his shifts those days are left with no guard.
    GuardRequest::query()->create(['tenant_id' => M30_SHOTS, 'guard_id' => $damion->id, 'kind' => 'leave', 'subject' => 'vacation', 'starts_on' => $monday->copy()->addDays(3)->toDateString(), 'ends_on' => $monday->copy()->addDays(6)->toDateString(), 'reason' => 'family', 'status' => 'approved']);

    // Shanice Clarke asks for next Tuesday and Wednesday off; it waits for a decision (§8.2).
    GuardRequest::query()->create(['tenant_id' => M30_SHOTS, 'guard_id' => $shanice->id, 'kind' => 'leave', 'subject' => 'vacation', 'starts_on' => $monday->copy()->addWeek()->addDay()->toDateString(), 'ends_on' => $monday->copy()->addWeek()->addDays(2)->toDateString(), 'reason' => 'a family wedding', 'status' => 'pending']);

    // Two guards have asked, from the Guard App, for next Wednesday's open day shift at the Back Gate: the one J2 fills.
    $open = $claimable ?? Shift::query()->where('tenant_id', M30_SHOTS)->where('status', Shift::OPEN)->orderBy('rostered_start')->first();
    if ($open !== null) {
        foreach ([$marlon, $jermaine] as $asker) {
            DB::connection('mysql')->table('shift_claims')->insert(['shift_id' => $open->id, 'guard_id' => $asker->id, 'status' => 'pending', 'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // The estate's patrol: it starts at the Main Gate, and tours six checkpoints, four of them placed.
    DB::connection('mysql')->table('estate_sites')->insert(['tenant_id' => M30_SHOTS, 'patrol_post_id' => $main->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Front entrance', 'Phase 1', 18.0058, -76.7448], ['Pool deck', 'Phase 2', 18.0049, -76.7432], ['Basketball court', 'Phase 2', 18.0041, -76.7445], ['Playground', 'Phase 3', null, null], ['Service gate', 'Phase 3', 18.0045, -76.7462], ['Church gate', null, null, null]] as $i => [$label, $phase, $lat, $lng]) {
        PatrolCheckpoint::query()->create(['tenant_id' => M30_SHOTS, 'post_id' => null, 'label' => $label, 'phase' => $phase, 'code' => PatrolCheckpoint::newCode(), 'sequence' => $i + 1, 'is_active' => true, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => PatrolCheckpoint::DEFAULT_RADIUS_M]);
    }

    $pages = [
        'classic-roster' => '/guards/roster',
        // The classic roster on next Wednesday, where its open shift is assigned (J2).
        'classic-roster-day' => '/guards/roster?date='.$monday->copy()->addWeek()->addDays(2)->toDateString(),
        'classic-sites' => '/guards/sites?estate='.M30_SHOTS,
        'schedule' => '/guards/schedule?client='.M30_SHOTS,
        'schedule-next' => '/guards/schedule?client='.M30_SHOTS.'&week='.$monday->copy()->addWeek()->toDateString(),
        'places-estate' => '/guards/places?client='.M30_SHOTS.'&step=estate',
        'places-gates' => '/guards/places?client='.M30_SHOTS.'&step=gates',
        'places-patrol' => '/guards/places?client='.M30_SHOTS.'&step=patrol',
        'places-settings' => '/guards/places?client='.M30_SHOTS.'&step=settings',
    ];

    $manifest = ['client' => M30_SHOTS, 'pages' => [], 'answers' => [], 'posts' => ['main' => $main->id, 'back' => $back->id, 'phase5' => $phase5->id, 'patrol' => $patrol->id], 'guards' => ['shanice' => $shanice->id, 'kemoy' => Guard::query()->where('full_name', 'Kemoy Allen')->value('id')]];

    foreach ($pages as $name => $url) {
        $html = $this->actingAs($director)->get($url)->assertOk()->getContent();
        file_put_contents($out.'/'.$name.'.html', $html);
        $manifest['pages'][$name] = ['url' => $url, 'file' => $name.'.html'];
    }

    // The shift panel's states, as the planner answers them: the form the browser will send, and the answer.
    $nextTuesday = $monday->copy()->addWeek()->addDay()->toDateString();
    $nextMonday = $monday->copy()->addWeek()->toDateString();
    $planner = app(ShiftPlanner::class);
    $forms = [
        'panel-open' => ['post_id' => $phase5->id, 'date' => $nextTuesday, 'starts' => '18:00', 'ends' => '06:00', 'duty' => 'post', 'guard_id' => null, 'repeat' => 'once', 'until' => $nextTuesday, 'days' => []],
        'panel-guard' => ['post_id' => $phase5->id, 'date' => $nextTuesday, 'starts' => '18:00', 'ends' => '06:00', 'duty' => 'post', 'guard_id' => Guard::query()->where('full_name', 'Kemoy Allen')->value('id'), 'repeat' => 'once', 'until' => $nextTuesday, 'days' => []],
        'panel-cross' => ['post_id' => $main->id, 'date' => $nextTuesday, 'starts' => '18:00', 'ends' => '06:00', 'duty' => 'post', 'guard_id' => null, 'repeat' => 'once', 'until' => $nextTuesday, 'days' => []],
        'panel-repeat' => ['post_id' => $phase5->id, 'date' => $nextMonday, 'starts' => '18:00', 'ends' => '06:00', 'duty' => 'post', 'guard_id' => null, 'repeat' => 'days', 'until' => $monday->copy()->addWeeks(3)->subDay()->toDateString(), 'days' => [1, 3, 5]],
    ];

    // The panel opened on a shift (§5.2): next Tuesday's day shift at the Main Gate, Andre Campbell on it.
    $target = Shift::query()->where('post_id', $main->id)->where('rostered_start', Carbon::parse($nextTuesday.' 06:00', $zone)->utc())->firstOrFail();
    $local = SiteTime::local($target->rostered_start, M30_SHOTS);
    $manifest['views'][(string) $target->id] = $planner->view($director, $target);
    $manifest['change'] = ['shift' => $target->id, 'label' => 'Main Gate, '.mb_strtoupper($local->format('D')).' '.$local->format('j M')];

    $kemoy = (int) Guard::query()->where('full_name', 'Kemoy Allen')->value('id');
    $asIs = ['shift_id' => $target->id, 'post_id' => $main->id, 'date' => $nextTuesday, 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'guard_id' => $andre->id, 'repeat' => 'once', 'reason' => ''];
    $forms['change-open'] = $asIs;
    $forms['change-guard'] = [...$asIs, 'guard_id' => $kemoy];
    $forms['change-reason'] = [...$asIs, 'guard_id' => $kemoy, 'reason' => 'Andre asked to swap'];

    // Putting a guard on an open shift (J2): next Wednesday's day shift at the Back Gate, which two guards asked for.
    $nextWednesday = $monday->copy()->addWeek()->addDays(2)->toDateString();
    $openOne = Shift::query()->where('post_id', $back->id)->whereNull('guard_id')->where('rostered_start', Carbon::parse($nextWednesday.' 06:00', $zone)->utc())->firstOrFail();
    $manifest['views'][(string) $openOne->id] = $planner->view($director, $openOne);
    $manifest['assign'] = ['label' => 'Back Gate, '.mb_strtoupper(Carbon::parse($nextWednesday)->format('D')).' '.Carbon::parse($nextWednesday)->format('j M')];
    $openForm = ['shift_id' => $openOne->id, 'post_id' => $back->id, 'date' => $nextWednesday, 'starts' => '06:00', 'ends' => '18:00', 'duty' => 'post', 'guard_id' => null, 'repeat' => 'once', 'reason' => ''];
    $forms['assign-open'] = $openForm;
    $forms['assign-guard'] = [...$openForm, 'guard_id' => $kemoy];

    // Dragged (§4.3): to the Vehicle Patrol's Tuesday, free; and to the Back Gate's Wednesday, which has a shift then.
    $forms['drag-ok'] = [...$asIs, 'post_id' => $patrol->id];
    $forms['drag-refused'] = [...$asIs, 'post_id' => $back->id, 'date' => $nextWednesday];

    foreach ($forms as $name => $form) {
        $manifest['answers'][$name] = ['form' => $form, 'answer' => $planner->check($director, $form)];
    }

    // "Copy last week into this week" (§4.6), from the week on screen in schedule-next: this week into next.
    $manifest['copy'] = app(WeekCopy::class)->plan($director, M30_SHOTS, $nextMonday);

    file_put_contents($out.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    expect(count($manifest['pages']))->toBe(count($pages));
});
