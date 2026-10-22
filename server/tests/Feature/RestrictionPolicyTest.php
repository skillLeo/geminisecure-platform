<?php

declare(strict_types=1);

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Estate\Collections;
use App\Services\Restriction\RestrictionPolicy;
use Tests\Support\CollectionsFixture;

/**
 * Arrears restriction (D-024, D-025).
 *
 * These assertions are the reason the policy exists in one class. The
 * exemptions in particular must fail loudly if anyone ever edits them: a
 * misconfigured estate leaving an ambulance at a gate is not recoverable, so
 * the guarantee cannot rest on review.
 *
 * THE FIRST SECTION TOUCHES NO DATABASE, and that is deliberate. Every
 * household in it is constructed in memory, so the exemptions are proven on the
 * policy's own logic with nothing behind it that could be misconfigured into
 * making them pass. The payment-plan section below needs a real estate, because
 * a plan is a real row a real query has to find — but the two are kept apart so
 * the ambulance assertions can never come to depend on a fixture.
 */
beforeEach(function () {
    $this->policy = new RestrictionPolicy;

    $this->restricted = new Household(['name' => 'Restricted household', 'access_restricted' => true]);
    $this->clear = new Household(['name' => 'Clear household', 'access_restricted' => false]);

    $this->settings = new EstateSetting([
        'arrears_restriction_days' => 90,
        'arrears_notice_days' => 14,
        'arrears_restriction_enabled' => true,
    ]);
});

it('never restricts an emergency or medical vehicle', function (string $category) {
    $decision = $this->policy->decide($this->restricted, $category);

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['verdict'])->toBe('admit')
        ->and($decision['category_exempt'])->toBeTrue();
})->with(['emergency', 'medical', 'fire', 'utility_emergency']);

it('never restricts a resident\'s own entry', function () {
    // Restriction applies to guest passes only. A household in arrears can
    // always come home.
    $decision = $this->policy->decide($this->restricted, 'resident');

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['category_exempt'])->toBeTrue();
});

it('restricts guest passes for a restricted household', function (string $category) {
    $decision = $this->policy->decide($this->restricted, $category);

    expect($decision['admitted'])->toBeFalse()
        ->and($decision['verdict'])->toBe('restricted');
})->with(['guest', 'visitor', 'delivery', 'contractor']);

it('admits guests for a household that is not restricted', function () {
    $decision = $this->policy->decide($this->clear, 'guest');

    expect($decision['admitted'])->toBeTrue()
        ->and($decision['verdict'])->toBe('admit');
});

it('tells the guard nothing about money', function () {
    $decision = $this->policy->decide($this->restricted, 'guest');

    // Law 3 (12 Oct): the gate's one free-text sentence carries no em dash, and matches the Guard App's own copy.
    expect($decision['reason'])->toBe('Access restricted: contact management');

    // Invariant 2, asserted rather than trusted: no amount, no ageing bucket,
    // no payment history, and no wording that implies any of them.
    $serialised = strtolower(json_encode($decision));

    foreach (['balance', 'owed', 'arrear', 'amount', 'overdue', 'debt', 'payment', 'invoice', 'jmd', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }
});

it('admits an unrecognised category rather than denying by default', function () {
    // An unknown pass type must not become a denial. Failing closed here would
    // turn every future category into an outage at the gate.
    $decision = $this->policy->decide($this->restricted, 'something_new');

    expect($decision['admitted'])->toBeTrue();
});

it('makes a household eligible only at the configured threshold', function () {
    expect($this->policy->isEligible(89, $this->settings))->toBeFalse()
        ->and($this->policy->isEligible(90, $this->settings))->toBeTrue()
        ->and($this->policy->isEligible(200, $this->settings))->toBeTrue();
});

it('makes nobody eligible when an estate switches restriction off', function () {
    $off = new EstateSetting([
        'arrears_restriction_days' => 90,
        'arrears_notice_days' => 14,
        'arrears_restriction_enabled' => false,
    ]);

    expect($this->policy->isEligible(365, $off))->toBeFalse();
});

it('requires the notice period to elapse before restriction', function () {
    // Serving notice and restricting the same day is the failure this guards.
    expect($this->policy->noticePeriodHasElapsed(null, $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now(), $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now()->subDays(13), $this->settings))->toBeFalse()
        ->and($this->policy->noticePeriodHasElapsed(now()->subDays(14), $this->settings))->toBeTrue();
});

it('shields nothing for a household that was never saved', function () {
    // The in-memory households above carry no unit and no plan, and the policy
    // must not go looking for one. This asserts the guard that keeps every
    // assertion in this file free of a database.
    expect($this->restricted->exists)->toBeFalse()
        ->and($this->policy->decide($this->restricted, 'guest')['admitted'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A payment plan lifts the restriction — board 7
|--------------------------------------------------------------------------
|
| "While a plan is current and being met, the arrears restriction is LIFTED.
| Missing an instalment reinstates it."
|
| Which makes a payment plan a live thing a gate decision depends on, and it is
| asserted here — in the policy's own suite — rather than only in the
| collections one, because this is the file somebody reads to find out what the
| gate actually does.
|
| EACH CASE GETS ITS OWN HOUSEHOLD. These tests activate plans and default them;
| sharing one household between them would make the order they run in decide
| whether a guest gets through a gate.
|
*/

/**
 * Draft, agree and activate a plan on a freshly restricted household — its first
 * instalment two months gone, because only a date that has passed can be ruled missed.
 */
function planFor(Unit $unit): PaymentPlan
{
    $collections = app(Collections::class);

    $plan = $collections->draft($unit, 4, now()->subMonths(2)->toDateString());
    $collections->agree($plan, 'Test Householder');

    return $collections->activate($plan, 'Test Householder', new User(['name' => 'Treasurer']));
}

it('admits guests for a household that is meeting a payment plan', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 951');
    $household = $unit->household;
    $policy = new RestrictionPolicy;

    expect($policy->decide($household, 'guest')['admitted'])->toBeFalse();

    planFor($unit);

    // The flag is untouched — the arrears did not go away, they were given a
    // schedule — and the verdict is nonetheless admit.
    expect($household->fresh()->access_restricted)->toBeTrue()
        ->and($policy->decide($household, 'guest')['admitted'])->toBeTrue()
        ->and($policy->decide($household, 'guest')['verdict'])->toBe('admit');
});

it('restricts a household again once an instalment is missed', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 952');
    $household = $unit->household;
    $policy = new RestrictionPolicy;

    $plan = planFor($unit);

    expect($policy->decide($household, 'guest')['admitted'])->toBeTrue();

    app(Collections::class)->markMissed($plan->schedule()->firstOrFail());

    expect($policy->decide($household, 'guest')['admitted'])->toBeFalse()
        ->and($policy->decide($household, 'guest')['verdict'])->toBe('restricted');
});

it('admits an emergency vehicle whether a plan is being met or not', function (string $category) {
    // Named for the case, because `units.reference` is unique and this runs
    // once per category.
    $onPlan = CollectionsFixture::restrictedUnit('Lot ok-'.$category);
    $broken = CollectionsFixture::restrictedUnit('Lot no-'.$category);
    $policy = new RestrictionPolicy;

    planFor($onPlan);

    $defaulted = planFor($broken);
    app(Collections::class)->markMissed($defaulted->schedule()->firstOrFail());

    /*
     * The plan is irrelevant to an ambulance, in both directions, and that is
     * the guarantee: the exempt path returns BEFORE a plan is consulted, so no
     * future edit to collections can reach an emergency vehicle — not by
     * failing to find a plan, and not by finding a broken one.
     */
    expect($policy->decide($onPlan->household, $category)['admitted'])->toBeTrue()
        ->and($policy->decide($onPlan->household, $category)['category_exempt'])->toBeTrue()
        ->and($policy->decide($broken->household, $category)['admitted'])->toBeTrue()
        ->and($policy->decide($broken->household, $category)['category_exempt'])->toBeTrue();
})->with(['emergency', 'medical', 'fire', 'utility_emergency', 'resident']);

it('never lets a plan tell a guard about money', function () {
    $unit = CollectionsFixture::restrictedUnit('Lot 953');

    planFor($unit);

    $decision = (new RestrictionPolicy)->decide($unit->household, 'guest');

    // Invariant 2 again, over the new path. A household on a plan is admitted
    // exactly as a household with no arrears is, and the payload says nothing
    // about a plan, a schedule or an amount.
    $serialised = strtolower((string) json_encode($decision));

    foreach (['plan', 'instalment', 'balance', 'owed', 'arrear', 'amount', 'overdue', 'debt', 'jmd', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }

    expect($decision)->toBe([
        'admitted' => true,
        'verdict' => 'admit',
        'reason' => null,
        'category_exempt' => false,
    ]);
});
