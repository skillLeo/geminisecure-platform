<?php

declare(strict_types=1);

use App\Models\Guard;
use App\Models\Post;
use App\Services\Gemini\GuardWorkforce;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/*
|--------------------------------------------------------------------------
| The un-rosterable state, and the open compliance case
|--------------------------------------------------------------------------
|
| Boards super-admin-19 and super-admin-23 are the SAME profile screen drawn on
| two guards. The second one is not a styling variant: Devon Palmer's PSRA
| licence has lapsed, so his record is in two states a compliant guard's is not.
|
| The Build Spec is unambiguous about the first — "Expired licence sets
| un-rosterable: a hard block, not a warning" — and the failure mode it guards
| against is silent. A screen that quietly kept offering to reassign an
| unlicensed officer would look completely normal, and would be helping someone
| post a guard who cannot legally stand a gate. So the block is asserted here,
| not left to be seen.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/**
 * A guard whose licence expires (or expired) $days from today — the SITE's today, which is what
 * `Guard::licenceDaysLeft()` counts from. The server's UTC date is already tomorrow from 7 PM in
 * Kingston, and "14 days ago" then read "13 days ago".
 */
function guardWithLicence(int $days, string $status = 'active', ?int $postId = null): Guard
{
    $tenantId = $postId === null ? null : 'testestate';

    return Guard::create([
        'full_name' => 'Test Officer '.$days,
        'employee_number' => 'GS-T'.abs($days).random_int(1000, 9999),
        'psra_number' => 'PSRA-T'.abs($days).random_int(1000, 9999),
        'psra_expires_on' => SiteTime::today((string) $tenantId)->addDays($days)->toDateString(),
        'employment_type' => 'full_time',
        'status' => $status,
        'phone' => '876-555-0000',
        'hired_on' => now()->subYears(2)->toDateString(),
        'tenant_id' => $tenantId,
        'post_id' => $postId,
    ]);
}

beforeEach(function () {
    // Resolved, not constructed. GuardWorkforce takes an AuditLogger because
    // every write in it has to leave a trace, and a dependency the container
    // hands over is one this test could assert against later. Newing it up bare
    // would mean this file needing an edit each time that list grows.
    $this->workforce = app(GuardWorkforce::class);
});

it('blocks a guard with a lapsed licence from being rostered', function () {
    $profile = $this->workforce->profile(guardWithLicence(-14));

    expect($profile['rosterable'])->toBeFalse()

        // The board's own danger treatment for the hero, not a colour of ours.
        ->and($profile['hero_class'])->toBe('suspended')

        // "Licence expires" on a licence that already expired reads as a
        // promise the record cannot keep.
        ->and($profile['stats'][1]['label'])->toBe('Licence expired')

        ->and($profile['blocked_reason'])->toContain('cannot legally be on post');
});

it('opens a compliance case on a lapsed licence and none on a current one', function () {
    $lapsed = $this->workforce->profile(guardWithLicence(-14));
    $current = $this->workforce->profile(guardWithLicence(210));

    expect($lapsed['compliance_case'])->not->toBeNull()
        ->and($lapsed['compliance_case']['headline'])->toContain('14 days ago')
        ->and($lapsed['compliance_case']['detail'])->toContain('cannot legally be on active duty')
        ->and($lapsed['compliance_href'])->not->toBeNull()

        ->and($current['compliance_case'])->toBeNull()
        ->and($current['compliance_href'])->toBeNull()
        ->and($current['rosterable'])->toBeTrue()
        ->and($current['hero_class'])->toBeNull()
        ->and($current['stats'][1]['label'])->toBe('Licence expires');
});

it('does not block a licence that is merely expiring soon', function () {
    // A licence with three weeks left still licenses the officer TODAY. It is a
    // date to plan around, which is what the register next door is for, and
    // blocking on it would take a legally deployable guard off post early.
    $profile = $this->workforce->profile(guardWithLicence(21));

    expect($profile['rosterable'])->toBeTrue()
        ->and($profile['compliance_case'])->toBeNull()
        ->and($profile['hero_class'])->toBeNull();
});

it('blocks a guard whose licence has no expiry on file', function () {
    $guard = guardWithLicence(30);
    $guard->forceFill(['psra_expires_on' => null])->save();

    $profile = $this->workforce->profile($guard);

    // Not knowing is not the same as being fine. Gemini cannot produce a valid
    // licence for this officer either way.
    expect($profile['rosterable'])->toBeFalse()
        ->and($profile['compliance_case']['headline'])->toContain('no expiry date on file')
        ->and($profile['stats'][1]['label'])->toBe('Licence expiry');
});

it('leads the timeline with the case, and renames the panel that holds it', function () {
    $post = Post::create(['tenant_id' => 'testestate', 'name' => 'Service Gate', 'type' => 'gate']);

    $lapsed = guardWithLicence(-14, postId: $post->id);
    $current = guardWithLicence(210, postId: $post->id);

    $lapsedProfile = $this->workforce->profile($lapsed);
    $lapsedHistory = $this->workforce->deploymentHistory($lapsed);

    $currentProfile = $this->workforce->profile($current);
    $currentHistory = $this->workforce->deploymentHistory($current);

    expect($lapsedProfile['history_head'])->toBe('Deployment & compliance history')

        // The lapse leads. Somebody opening this record has to know the officer
        // cannot legally be on post BEFORE they read which post that is.
        ->and($lapsedHistory[0]['title'])->toBe('PSRA licence expired')
        ->and($lapsedHistory[0]['danger'])->toBeTrue()
        ->and(collect($lapsedHistory)->pluck('title')->implode(' | '))->toContain('Service Gate')

        // A compliant guard's timeline is unchanged: the posting leads, because
        // where they stand is the thing being read.
        ->and($currentProfile['history_head'])->toBe('Deployment history')
        ->and($currentHistory[0]['title'])->toContain('Service Gate')
        ->and($currentHistory[0]['danger'])->toBeFalse();
});

it('says a blocked guard is off the pay run rather than asserting a rule', function () {
    $history = $this->workforce->deploymentHistory(guardWithLicence(-14));

    $payroll = collect($history)->first(
        fn (array $row): bool => str_contains($row['title'], 'pay run')
    );

    // Read from the run's own payslips, not stated as policy: this test builds
    // no payslip for the guard, so the row has to say so.
    expect($payroll)->not->toBeNull()
        ->and($payroll['title'])->toContain('pay run');
});

it('never fabricates an hourly rate for the hero the board draws one on', function () {
    // The board's third hero stat is "$425/hr Standard rate". No such column
    // existed when this screen was built, and a plausible number in a slot no
    // table can confirm is worse than an honest empty one.
    $profile = $this->workforce->profile(guardWithLicence(-14));

    expect($profile['stats'][2]['label'])->toBe('Last gross pay')
        ->and($profile['stats'][2]['value'])->toBe('No payslip yet');
});
