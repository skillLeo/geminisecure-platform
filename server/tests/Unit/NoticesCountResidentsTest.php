<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The notices bar says what it counts: people (M69; Hassam, 10 October 2026)
|--------------------------------------------------------------------------
|
| Board 32's seen bar counts receipts person by person over the residents in a notice's audience (`Notices::board()`),
| where every other count beside it is households. Its words now say so: "1 of 456 residents".
|
*/

use App\Services\Estate\Notices;

$root = dirname(__DIR__, 2);

it('never shows a notice somebody has read as 0%: below one percent it reads "<1" (GV-8)', function (): void {
    expect(Notices::seenPercent(1, 456))->toBe('<1')
        ->and(Notices::seenPercent(3, 400))->toBe('<1')
        ->and(Notices::seenPercent(0, 456))->toBe(0)
        ->and(Notices::seenPercent(4, 400))->toBe(1)
        ->and(Notices::seenPercent(5, 456))->toBe(1)
        ->and(Notices::seenPercent(456, 456))->toBe(100)
        ->and(Notices::seenPercent(0, 0))->toBe(0);
});

/*
 * HASSAM'S WHOLE RULE (10 October, 05:45): exactly 0 read, "0%"; above 0 and below 1%, "<1%"; 1% to 99%, the rounded
 * whole number; above 99% and not all, ">99%"; every single one, "100%". A notice one household has not read must never
 * read as 100%, for the same reason one that one household HAS read must not read as 0%.
 */
it('never shows a notice somebody has not read as 100%: above 99% and not all it reads ">99"', function (): void {
    expect(Notices::seenPercent(455, 456))->toBe('>99')
        ->and(Notices::seenPercent(991, 1000))->toBe('>99')
        ->and(Notices::seenPercent(999, 1000))->toBe('>99')
        ->and(Notices::seenPercent(990, 1000))->toBe(99)
        ->and(Notices::seenPercent(985, 1000))->toBe(99)
        ->and(Notices::seenPercent(1000, 1000))->toBe(100)
        ->and(Notices::seenPercent(1, 1))->toBe(100)
        // The bar is drawn from the true share, never from the words: 455 of 456 is 99.78% of the bar, not all of it.
        ->and(Notices::seenShare(455, 456))->toBe(99.78);
});

it('draws and sorts the bar by the true share, never by the words beside it (GV-8)', function () use ($root): void {
    expect(Notices::seenShare(1, 456))->toBe(0.22)
        ->and(Notices::seenShare(0, 456))->toBe(0.0)
        ->and(Notices::seenShare(456, 456))->toBe(100.0)
        ->and(Notices::seenShare(0, 0))->toBe(0.0)
        ->and(Notices::sorts()['seen'])->toBe('seen_share');

    // "<1%" is not a width: a bar told it would draw full, the opposite of what it means.
    $vue = (string) file_get_contents($root.'/resources/js/Pages/Estate/Governance/Notices.vue');
    $service = (string) file_get_contents($root.'/app/Services/Estate/Notices.php');

    expect($vue)->toContain('<i :style="{ width: `${notice.seen_share}%` }"></i>')
        ->and($vue)->not->toContain('width: `${notice.seen_pct}%`')
        ->and($service)->toContain("'seen_pct' => self::seenPercent(\$seen, \$audience),")
        ->and($service)->toContain("'seen_share' => self::seenShare(\$seen, \$audience),");
});

it('names the people the notices bar counts as residents', function () use ($root): void {
    $vue = (string) file_get_contents($root.'/resources/js/Pages/Estate/Governance/Notices.vue');

    expect($vue)->toContain('`${notice.seen_count} of ${notice.audience_size} residents have read this · ${notice.seen_pct}%`')
        ->and($vue)->toContain('`${notice.seen_count} of ${notice.audience_size} residents`')
        ->and($vue)->not->toContain('`${notice.seen_count} of ${notice.audience_size}`"');
});
