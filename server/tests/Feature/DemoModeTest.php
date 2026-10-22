<?php

declare(strict_types=1);

use App\Api\ApiError;
use App\Http\Middleware\EndSessionOfInactiveAccount;
use App\Models\Guard;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\ClockInFence;
use App\Services\Dispatch\SupervisorOverride;
use App\Services\Estate\DemoCardPayments;
use App\Services\ResidentApp\ResidentSignIn;
use App\Services\Reviewer\ReviewerMode;
use App\Support\DemoMode;
use App\Support\DemonstrationAccounts;

/*
|--------------------------------------------------------------------------
| THE DEMONSTRATION SWITCH, OFF — the launch posture (M16 §1.2)
|--------------------------------------------------------------------------
|
| The whole suite runs as a demonstration server (phpunit.xml sets DEMO_MODE
| and REVIEW_MODE on), so every other file exercises the seeded estates, the
| reviewer accounts and the test-card checkout. THIS file turns both switches
| OFF — the posture a launched system ships with — and proves that the entire
| demonstration surface is then refused by the SERVER, not merely hidden:
|
|   1. the test-card checkout does not exist;
|   2. no seeded console or resident account can sign in;
|   3. the app-store reviewer's four bypasses grant nothing, and there is no
|      demonstration estate to resolve one into;
|   4. and — the reconciliation that makes §1.2 and §7 both true — the reviewer's
|      OWN address is not a demonstration address, so re-opening the review window
|      (and nothing else) brings the reviewer back without bringing the demo
|      surface with it.
*/

beforeEach(function () {
    // The launch default, made explicit: both off, whatever the suite's env sets.
    config(['demo.enabled' => false, 'demo.review' => false]);
});

/* ─────────────────────────────────────────────────────────────────────────
 | 1. The test-card checkout does not exist
 ───────────────────────────────────────────────────────────────────────── */

it('turns the test-card checkout off when the demonstration switch is off, whatever the card toggle says', function () {
    // The client's own toggle is ON, exactly as a demo server leaves it …
    config(['payments.demo_card' => true]);

    // … and the checkout is still gone, because the outer launch lock is shut.
    expect(DemoCardPayments::enabled())->toBeFalse();

    // Only with the demonstration switch ON does the client's toggle decide.
    config(['demo.enabled' => true]);
    expect(DemoCardPayments::enabled())->toBeTrue();

    config(['payments.demo_card' => false]);
    expect(DemoCardPayments::enabled())->toBeFalse();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 2. No seeded account signs in
 ───────────────────────────────────────────────────────────────────────── */

it('refuses a seeded console account at the door and on every later request when the switch is off', function () {
    foreach (['director@geminisecurity.test', 'everton-president@phoenixparkvillage1.demo.geminisl.com', 'clifton-head-of-security@demo.geminisl.com'] as $seeded) {
        $user = (new User)->forceFill(['email' => $seeded, 'status' => 'active']);

        expect(EndSessionOfInactiveAccount::refusal($user))
            ->toBe('Demonstration accounts are not available on this system.', "[{$seeded}] was not refused");
    }

    // A real committee member on a real domain is untouched — they sign in like anybody.
    $real = (new User)->forceFill(['email' => 'omar@geminisl.com', 'status' => 'active']);
    expect(EndSessionOfInactiveAccount::refusal($real))->toBeNull();

    // And on a demonstration server the seeded account is active like any other.
    config(['demo.enabled' => true]);
    $seededUser = (new User)->forceFill(['email' => 'director@geminisecurity.test', 'status' => 'active']);
    expect(EndSessionOfInactiveAccount::refusal($seededUser))->toBeNull();
});

it('refuses a seeded resident address at the sign-in code endpoint, before it touches the database', function () {
    $signIn = app(ResidentSignIn::class);
    $estate = new Tenant(['id' => 'anyestate']);

    // The refusal is the second line of requestCode/verify — thrown before any query — so an
    // unsaved tenant with no database is enough to reach it.
    foreach (['president@phoenixparkvillage1.demo.geminisl.com', 'manager@seaview.test'] as $seeded) {
        expect(fn () => $signIn->requestCode($estate, 'email', $seeded))
            ->toThrow(ApiError::class);

        try {
            $signIn->verify($estate, 'email', $seeded, '000000');
            $this->fail("[{$seeded}] was not refused by verify()");
        } catch (ApiError $e) {
            expect($e->errorCode)->toBe('demo_account_unavailable')
                ->and($e->status)->toBe(422);
        }
    }
});

/* ─────────────────────────────────────────────────────────────────────────
 | 3. The reviewer's bypasses grant nothing while the window is closed
 ───────────────────────────────────────────────────────────────────────── */

it('answers false for a flagged account until a review window is open', function () {
    $guard = (new Guard)->forceFill(['reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::GUARD_CODE)]);
    $account = (new ResidentAccount)->forceFill(['reviewer' => true, 'reviewer_code_hash' => ReviewerMode::hash(ReviewerMode::RESIDENT_CODE)]);

    // Both switches off (the launch default): the flag grants nothing, and the static code
    // is nothing to anybody.
    expect(ReviewerMode::isReviewer($guard))->toBeFalse()
        ->and(ReviewerMode::isReviewer($account))->toBeFalse()
        ->and(ReviewerMode::codeMatches($guard, ReviewerMode::GUARD_CODE))->toBeFalse()
        ->and(ReviewerMode::codeMatches($account, ReviewerMode::RESIDENT_CODE))->toBeFalse()
        ->and(ReviewerMode::estate())->toBeNull();

    // A review window opened for a store submission — and only then — brings the flag to life.
    config(['demo.review' => true]);
    expect(ReviewerMode::isReviewer($guard))->toBeTrue()
        ->and(ReviewerMode::codeMatches($guard, ReviewerMode::GUARD_CODE))->toBeTrue();

    // A demonstration server allows it too, with no window needed.
    config(['demo.review' => false, 'demo.enabled' => true]);
    expect(ReviewerMode::isReviewer($account))->toBeTrue();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 4. The reconciliation: §1.2 (no seeded sign-in) and §7 (a reviewer must
 |    sign in) are both true, because the reviewer's address is not a
 |    demonstration address.
 ───────────────────────────────────────────────────────────────────────── */

it('keeps the reviewer address out of the demonstration-address net, so re-opening the window is all it takes', function () {
    // The seeded addresses ARE demonstration addresses — caught by §1.2's gate.
    expect(DemonstrationAccounts::isDemonstrationAddress('director@geminisecurity.test'))->toBeTrue()
        ->and(DemonstrationAccounts::isDemonstrationAddress('everton-president@phoenixparkvillage1.demo.geminisl.com'))->toBeTrue()
        ->and(DemonstrationAccounts::isDemonstrationAddress('clifton-head-of-security@demo.geminisl.com'))->toBeTrue();

    // The reviewer's own address is NOT — so §1.2's demo-address refusal never touches the
    // store-review path, which is gated by its own window (§7) instead.
    expect(DemonstrationAccounts::isDemonstrationAddress(ReviewerMode::RESIDENT_EMAIL))->toBeFalse();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 4b. An unsurveyed post refuses a clock-in at launch (M16 §4)
 ───────────────────────────────────────────────────────────────────────── */

it('refuses a clock-in at a post with no surveyed position when the switch is off, and offers the override', function () {
    // Launch posture (the switch is off): a null fence is refused, never guessed.
    $verdict = ClockInFence::judge(null, 18.0179, -76.8099, 20);

    expect($verdict['outcome'])->toBe(ClockInFence::NO_GEOFENCE)
        ->and($verdict['allowed'])->toBeFalse()
        ->and($verdict['override_reason'])->toBe(SupervisorOverride::REASON_POSITION_UNCONFIRMED);

    expect(ClockInFence::sentence($verdict, 'Back Gate'))
        ->toContain('no surveyed position')
        ->and(ClockInFence::sentence($verdict, 'Back Gate'))->toContain('supervisor');

    // Demo server: the shift still starts and is marked for the office (D-178), so a
    // half-surveyed demonstration is never stuck.
    config(['demo.enabled' => true]);
    $lenient = ClockInFence::judge(null, 18.0179, -76.8099, 20);

    expect($lenient['allowed'])->toBeTrue()
        ->and($lenient['override_reason'])->toBeNull();
});

/* ─────────────────────────────────────────────────────────────────────────
 | 4c. No public page promises a demonstration, whatever the switches (M17, M24)
 ───────────────────────────────────────────────────────────────────────── */

it('mentions a demonstration community on no public page, even with every switch on', function () {
    // M24 (D-223): the /apps page that used to say so while a review window was open is gone, and nothing replaced it.
    config(['legal.demo_community' => true, 'demo.review' => true, 'demo.enabled' => true]);

    foreach (['/sign-in', '/sign-in/resident', '/legal/privacy', '/legal/terms', '/account/delete'] as $uri) {
        expect((string) $this->get($uri)->assertOk()->getContent())->not->toMatch('/demonstration community/i');
    }
    $this->get('/apps')->assertStatus(410);
});

/* ─────────────────────────────────────────────────────────────────────────
 | 5. The helper itself
 ───────────────────────────────────────────────────────────────────────── */

it('reads the two switches independently, and a demonstration server always allows a reviewer', function () {
    config(['demo.enabled' => false, 'demo.review' => false]);
    expect(DemoMode::enabled())->toBeFalse()
        ->and(DemoMode::reviewWindowOpen())->toBeFalse()
        ->and(DemoMode::reviewerAllowed())->toBeFalse();

    config(['demo.review' => true]);
    expect(DemoMode::enabled())->toBeFalse()
        ->and(DemoMode::reviewerAllowed())->toBeTrue();

    config(['demo.review' => false, 'demo.enabled' => true]);
    expect(DemoMode::reviewerAllowed())->toBeTrue();
});
