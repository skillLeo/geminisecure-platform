<?php

declare(strict_types=1);

use App\Services\Tracking\GuardTracking;
use App\Support\TrackingPolicyWords;

/*
|--------------------------------------------------------------------------
| The words a guard answered are held on the server, and the ones in force are the Guard App's own
|--------------------------------------------------------------------------
|
| Hassam, 12 October 2026: "the guard's consent record, showing what was agreed and when". The server kept only the
| version of the location policy a guard answered; the words lived in the Guard App alone. They are held here now,
| version by version, and the version in force is word for word the app's (checked when the mobile checkout is beside
| this one, or named by GS_MOBILE_CHECKOUT, and skipped, saying so, when it is not).
|
*/

it('holds the words of the version in force, of every version an officer could have answered, and of the next', function () {
    expect(TrackingPolicyWords::VERSIONS)->toHaveKey(GuardTracking::policyVersion())
        ->and(array_keys(TrackingPolicyWords::VERSIONS))->toBe(['2026-10-03', '2026-10-10', '2026-10-12']);
});

it('words 2026-10-12 as agreed: 2026-10-10 with exactly two sections changed, and no estate named (Hassam, 12 Oct)', function () {
    $before = TrackingPolicyWords::VERSIONS['2026-10-10'];
    $after = TrackingPolicyWords::VERSIONS['2026-10-12'];
    $section = static fn (array $words, string $heading): ?string => collect($words['sections'])->firstWhere('heading', $heading)['body'] ?? null;

    // Who at GeminiSecure sees it (the client: everyone with the live map keeps it), then the general rule, Hassam's two
    // sentences word for word; which sites it applies to is the app's to say, shift by shift.
    expect($section($after, 'Who sees it'))->toBe('GeminiSecure’s own staff can see where you are while you are on duty. The community you guard sees how many officers are on site and their names. At some sites, the estate’s own management can also see where you are while you are on duty. Your app will tell you, before each shift, whether that applies at the site you are working.')
        // The cadence the setting is bound to, chosen by the estate within it.
        ->and($section($after, 'What is recorded'))->toBe('Your phone’s location, how accurate it is, the time, and your battery level: every 45 to 90 seconds while you move, less often while you stand still or your battery is low, and each time you clock in, scan a gate pass, scan a checkpoint, raise a duress alert or clock out. The estate where you are on duty chooses how often, within those limits.');

    // Everything else is 2026-10-10's, word for word and in the same order.
    expect(array_column($after['sections'], 'heading'))->toBe(array_column($before['sections'], 'heading'))
        ->and([$after['title'], $after['lead'], $after['agree'], $after['refuse']])->toBe([$before['title'], $before['lead'], $before['agree'], $before['refuse']]);

    foreach (['Only during your shift', 'How long it is kept', 'Raising a concern'] as $heading) {
        expect($section($after, $heading))->toBe($section($before, $heading));
    }

    // No estate is ever named in the policy: a contract that changes would otherwise need a new version.
    $all = mb_strtolower(json_encode($after, JSON_UNESCAPED_UNICODE));
    expect(str_contains($all, 'phoenix'))->toBeFalse()
        ->and(str_contains($all, 'demo estate'))->toBeFalse();
});

it('fills in the retention as the app does, and holds nothing for words it never held', function () {
    $words = TrackingPolicyWords::of('2026-10-10', 30);

    expect(collect($words['sections'] ?? [])->firstWhere('heading', 'How long it is kept')['body'] ?? null)->toBe('Positions are deleted after 30 days.')
        ->and($words['agree'] ?? null)->toBe('I agree: share my location on duty')
        ->and(TrackingPolicyWords::of('2019-01-01', 90))->toBeNull();
});

it('is word for word the Guard App\'s own policy for the version in force', function () {
    $mobile = (string) (env('GS_MOBILE_CHECKOUT') ?: base_path('../geminisecure-mobile'));
    $file = $mobile.'/apps/guard/src/features/tracking/trackingPolicy.ts';

    if (! is_file($file)) {
        $this->markTestSkipped('The Guard App\'s location policy is not beside this checkout ('.$file.'). Set GS_MOBILE_CHECKOUT to check it.');
    }

    $ts = (string) file_get_contents($file);
    preg_match("/POLICY_VERSION = '([^']+)'/", $ts, $version);
    preg_match("/title: '([^']*)'/", $ts, $title);
    preg_match("/lead: '([^']*)'/", $ts, $lead);
    preg_match_all("/\\{ heading: '([^']*)', body: '([^']*)' \\}/", $ts, $sections, PREG_SET_ORDER);
    preg_match("/agree: '([^']*)'/", $ts, $agree);
    preg_match("/refuse: '([^']*)'/", $ts, $refuse);

    $theirs = [
        'title' => $title[1] ?? null,
        'lead' => $lead[1] ?? null,
        'sections' => array_map(static fn (array $m): array => ['heading' => $m[1], 'body' => $m[2]], $sections),
        'agree' => $agree[1] ?? null,
        'refuse' => $refuse[1] ?? null,
    ];

    // The app and the server ask about the same words, and the server holds those words exactly.
    expect($version[1] ?? null)->toBe(GuardTracking::policyVersion())
        ->and(TrackingPolicyWords::VERSIONS[GuardTracking::policyVersion()])->toBe($theirs);
});
