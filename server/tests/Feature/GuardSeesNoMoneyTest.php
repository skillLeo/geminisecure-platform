<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Services\Restriction\RestrictionPolicy;

/*
|--------------------------------------------------------------------------
| Invariant 2 — a guard never sees an amount owed
|--------------------------------------------------------------------------
|
| ALLOWLIST, not denylist.
|
| This test used to enumerate forbidden words: balance, owed, arrear, amount,
| overdue, debt, ageing, jmd, j$. That catches the fields you thought of. It
| does not catch `outstanding`, `due`, `total`, `sum`, `value`, `cents`,
| `minor_units`, or whatever the next helpful addition is called — and the
| whole risk here is a field nobody anticipated being added in six months by
| someone who never read this file.
|
| So the direction is inverted. Every guard-reachable payload declares the
| exact set of keys it may contain, and ANY key outside that set fails. A new
| field cannot reach a guard without someone editing the allowlist below,
| which is a deliberate act with this comment attached to it.
|
| The assertions inspect the SERIALISED payload, not the code, because the
| guarantee is about what crosses the wire.
|
*/

/**
 * Assert a payload contains nothing beyond its permitted keys, at any depth.
 *
 * Recursive because nesting is exactly how an amount would arrive unnoticed —
 * a `household` object grows a `balance` and the top-level key list still
 * looks correct.
 *
 * @param  array<string, mixed>  $payload
 * @param  list<string>  $allowed
 */
function assertOnlyKeys(array $payload, array $allowed, string $endpoint): void
{
    $seen = [];

    $walk = function (array $node, string $path) use (&$walk, &$seen): void {
        foreach ($node as $key => $value) {
            if (is_int($key)) {
                // A list. The index is not a field name; recurse into members.
                if (is_array($value)) {
                    $walk($value, $path);
                }

                continue;
            }

            $seen[] = $path === '' ? $key : "{$path}.{$key}";

            if (is_array($value)) {
                $walk($value, $path === '' ? $key : "{$path}.{$key}");
            }
        }
    };

    $walk($payload, '');

    $unexpected = array_values(array_diff($seen, $allowed));

    expect($unexpected)->toBe(
        [],
        "{$endpoint} returned key(s) that are not on its allowlist: "
        .implode(', ', $unexpected)
        .'. If this field is genuinely safe for a guard to see, add it to the '
        .'allowlist in this test and say why. Do not widen the list to make a '
        .'failure go away.'
    );
}

/*
 * The scan verdict a guard's handset receives.
 *
 * access_restricted is the ONLY restriction-related key any guard endpoint may
 * return, and it is a boolean. The guard is told whether to admit, never why,
 * and never by how much.
 */
const GUARD_VERDICT_KEYS = [
    'verdict',            // admit | restricted | deny
    'tone',               // green | amber | red — drives the colour, carries no data
    'headline',           // "Access restricted"
    'detail',             // "Contact management."
    'household',          // the household's display name
    'unit',               // the unit reference, e.g. PP-1A
    'admitted',           // boolean
    'access_restricted',  // boolean. THE ONLY restriction key permitted.

    /*
     * The next two were added because this allowlist caught them. That is
     * the mechanism working, and each needed a decision rather than a shrug:
     *
     * reason  — null, or exactly the D-025 approved wording
     *           "Access restricted — contact management". It is the one free
     *           TEXT field a guard sees, which is where a figure could later
     *           be interpolated without any key-name check noticing. The
     *           value-level test below is what guards that.
     *
     * category_exempt — a boolean about the PASS CATEGORY (is this an
     *           ambulance?), not about the household's standing. It tells the
     *           guard why an arrival was waved through, which is information
     *           they already have from the pass in their hand.
     */
    'reason',
    'category_exempt',
];

it('returns only allowlisted keys for an admitted arrival', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 1', 'access_restricted' => false]);

    $decision = $policy->decide($household, 'guest');

    assertOnlyKeys($decision, GUARD_VERDICT_KEYS, 'RestrictionPolicy::decide (admit)');
});

it('returns only allowlisted keys for a restricted arrival', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 2', 'access_restricted' => true]);

    $decision = $policy->decide($household, 'guest');

    expect($decision['verdict'])->toBe('restricted');

    // The whole payload a guard would receive, verdict plus standing.
    $payload = [...$decision, ...$household->guardVisibleStanding()];

    assertOnlyKeys($payload, GUARD_VERDICT_KEYS, 'POST /api/v1/passes/verify (restricted)');
});

it('returns only allowlisted keys for an emergency arrival', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 3', 'access_restricted' => true]);

    foreach (RestrictionPolicy::UNRESTRICTABLE_CATEGORIES as $category) {
        assertOnlyKeys(
            $policy->decide($household, $category),
            GUARD_VERDICT_KEYS,
            "RestrictionPolicy::decide ({$category})"
        );
    }
});

it('exposes exactly one key about a household standing', function () {
    $household = new Household(['name' => 'Household 4', 'access_restricted' => true]);

    $standing = $household->guardVisibleStanding();

    expect(array_keys($standing))->toBe(['access_restricted'])
        ->and($standing['access_restricted'])->toBeTrue();

    assertOnlyKeys($standing, ['access_restricted'], 'Household::guardVisibleStanding');
});

/*
 * The denylist is kept as a SECOND line, not the only one.
 *
 * The allowlist above is the real guarantee. This catches the other shape of
 * the same mistake: a permitted key whose VALUE carries the figure — a
 * `detail` string reading "Owes J$48,000", which no key-name check can see.
 */
it('never lets a permitted field carry money in its value', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 5', 'access_restricted' => true]);

    $payload = [...$policy->decide($household, 'guest'), ...$household->guardVisibleStanding()];
    $serialised = strtolower((string) json_encode($payload));

    foreach ([
        'balance', 'owed', 'owes', 'arrear', 'overdue', 'debt', 'outstanding',
        'due', 'amount', 'payment', 'invoice', 'charge', 'ageing', 'aging',
        'jmd', 'usd', 'j$', 'minor', 'cents', 'total',
    ] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "a guard payload contained the word [{$forbidden}]: {$serialised}"
        );
    }

    // And no bare figure that could be an amount.
    expect($serialised)->not->toMatch('/\d[\d,]*\.\d{2}/');
});

it('keeps emergency vehicles admitted regardless of standing', function () {
    $policy = new RestrictionPolicy;
    $household = new Household(['name' => 'Household 6', 'access_restricted' => true]);

    // No estate setting, no arrears figure and no configuration can change
    // this. The exemption is checked before restriction is considered at all.
    foreach (RestrictionPolicy::UNRESTRICTABLE_CATEGORIES as $category) {
        $decision = $policy->decide($household, $category);

        expect($decision['admitted'])
            ->toBeTrue("category [{$category}] must never be restricted");
    }
});

it('does not expose a unit reference that was never loaded', function () {
    // A household with no unit relation loaded must not fatal on ->unit->reference.
    $household = new Household(['name' => 'Household 7', 'access_restricted' => false]);

    expect($household->unit?->reference)->toBeNull();

    $unit = new Unit(['reference' => 'PP-1A']);
    expect($unit->reference)->toBe('PP-1A');
});
