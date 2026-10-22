<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Shift;
use App\Services\Dispatch\PostCoverage;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScaleFixture;
use Tests\Support\ScaleVolume;

/*
|--------------------------------------------------------------------------
| The coverage board at volume draws the rows it drew before — M11 A2
|--------------------------------------------------------------------------
|
| The post coverage board filtered the whole post list once per estate and the whole
| day's shifts once per post, and worked each window's two moments out afresh for every
| shift and every cell. At twice the operational volume that was half a second of a page
| a dispatcher keeps open. The posts and shifts are now grouped once, and each window is
| worked out once per zone.
|
| The oracle is the old construction, written out here with the board's own row builder:
| for every estate, the posts whose estate it is, and for every post, the shifts on it, in
| list order — exactly the two filters the board used to run. The board's rows must be the
| oracle's, row for row, on a platform of twelve clients, thirty-six posts and the day's
| shifts.
|
*/

beforeEach(function (): void {
    ScaleFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('draws every coverage row exactly as the per-row filters did, at volume', function (): void {
    $v = ScaleVolume::build();
    $director = $v['director'];

    $board = (new PostCoverage)->forViewer($director);

    // The old construction, on a fresh board, through the board's own private builders.
    $old = new PostCoverage;
    $call = static fn (string $method, mixed ...$args): mixed => (new ReflectionMethod($old, $method))->invoke($old, ...$args);

    $day = Carbon::parse(SiteTime::today()->format('Y-m-d'), 'UTC')->startOfDay();
    $estates = $call('estates', $director);
    $posts = $call('posts', $estates->pluck('id')->all());
    $shifts = $call('shiftsOn', $estates->pluck('id')->all(), $day);
    (new ReflectionProperty($old, 'boardPostIds'))->setValue($old, array_map('intval', $posts->modelKeys()));

    $rows = [];

    foreach ($estates as $estate) {
        $key = (string) $estate->getTenantKey();
        $here = $posts->filter(fn (Post $post): bool => $post->tenant_id === $key)->values();

        if ($here->isEmpty()) {
            $rows[] = $call('postlessRow', $estate);

            continue;
        }

        foreach ($here as $post) {
            $rows[] = $call('postRow', $estate, $post, $shifts->filter(fn (Shift $shift): bool => $shift->post_id === $post->id), $day);
        }
    }

    $labels = collect($rows)->pluck('cells')->flatten(1)->pluck('label')->unique()->values()->all();

    expect($board['rows'])->toBe($rows)
        // The comparison is only a proof if the board had rows of more than one kind to draw.
        ->and($labels)->toContain('Covered')
        ->and(count($labels))->toBeGreaterThan(1)
        ->and(collect($rows)->pluck('assigned')->filter(static fn (string $a): bool => str_starts_with($a, 'Unassigned'))->count())->toBeGreaterThan(0);

    // And each window, remembered once per zone, is the window worked out afresh.
    $fresh = new PostCoverage;
    $windows = (new ReflectionClassConstant(PostCoverage::class, 'WINDOWS'))->getValue();

    foreach (['America/Jamaica', 'UTC', 'Asia/Kolkata', 'America/New_York'] as $zone) {
        foreach ($windows as $hours) {
            $remembered = (new ReflectionMethod($fresh, 'windowOn'))->invoke($fresh, $day, $hours, $zone);
            $again = (new ReflectionMethod($fresh, 'windowOn'))->invoke($fresh, $day, $hours, $zone);
            $worked = (new ReflectionMethod(PostCoverage::class, 'window'))->invoke(null, $day, $hours, $zone);

            expect([$remembered[0]->toIso8601String(), $remembered[1]->toIso8601String()])->toBe([$worked[0]->toIso8601String(), $worked[1]->toIso8601String()])
                ->and([$again[0]->toIso8601String(), $again[1]->toIso8601String()])->toBe([$worked[0]->toIso8601String(), $worked[1]->toIso8601String()])
                // A copy each time: what one caller does to a moment reaches no other.
                ->and($again[0])->not->toBe($remembered[0]);
        }
    }
});
