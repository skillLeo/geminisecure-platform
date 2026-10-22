<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Which parts of the estate dashboard draw in which screen state (B105)
|--------------------------------------------------------------------------
|
| A dashboard whose activity feed happens to be empty still has every figure
| and every quick action it had the day before. The empty state is the
| activity panel's; loading, denied and failed are the whole screen's.
|
| READ FROM THE FILE, the way `ClientMessagingTest` reads its screen: nothing
| in this suite renders Vue, and the fault under test is in the template's own
| `v-if` chain rather than in anything the server sends. So the template is
| read into a tree and the chain is evaluated for each state — which asks what
| the page would draw, not where a string happens to sit in the file.
|
*/

/**
 * The dashboard's template as a tree, comments removed.
 *
 * @return object{tag: string, attrs: array<string, string>, children: list<object>}
 */
function dashboardTree(): object
{
    $sfc = (string) file_get_contents(resource_path('js/Pages/Estate/Dashboard.vue'));

    expect(preg_match('/^<template>\R(.*)^<\/template>/ms', $sfc, $found))->toBe(1);

    $template = (string) preg_replace('/<!--.*?-->/s', '', $found[1]);

    $root = (object) ['tag' => '#root', 'attrs' => [], 'children' => []];
    $stack = [$root];

    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    preg_match_all('/<(\/?)([A-Za-z][\w.-]*)((?:\s+[^\s=\/>]+(?:\s*=\s*"[^"]*")?)*)\s*(\/?)>/s', $template, $tags, PREG_SET_ORDER);

    foreach ($tags as [, $closing, $tag, $attributes, $selfClosing]) {
        if ($closing === '/') {
            $open = array_pop($stack);

            expect($open->tag)->toBe($tag, "The template closes <{$tag}> where <{$open->tag}> is open.");

            continue;
        }

        preg_match_all('/([^\s=\/>]+)(?:\s*=\s*"([^"]*)")?/', $attributes, $pairs, PREG_SET_ORDER);

        $node = (object) [
            'tag' => $tag,
            'attrs' => array_column(array_map(static fn (array $pair): array => [$pair[1], $pair[2] ?? ''], $pairs), 1, 0),
            'children' => [],
        ];

        end($stack)->children[] = $node;

        // Case-sensitive: `<Link>` is Inertia's component, and `<link>` is void.
        if ($selfClosing !== '/' && ! in_array($tag, $void, true)) {
            $stack[] = $node;
        }
    }

    expect($stack)->toHaveCount(1);

    return $root;
}

/**
 * A condition on the page's screen state, three-valued: true, false, or null
 * for anything that is not `state.*` (a prop, which may be either).
 *
 * @param  array<string, bool>  $state
 */
function dashboardCondition(string $expression, array $state): ?bool
{
    $any = false;

    foreach (explode('||', $expression) as $alternative) {
        $all = true;

        foreach (explode('&&', $alternative) as $atom) {
            $atom = trim($atom);
            $negated = str_starts_with($atom, '!');

            if (preg_match('/^state\.(\w+)\.value$/', ltrim($atom, '! '), $match) !== 1) {
                return null;
            }

            $value = ($state[$match[1]] ?? false) !== $negated;

            $all = $all && $value;
        }

        $any = $any || $all;
    }

    return $any;
}

/**
 * Every element that CAN draw in this state — a `v-if` on a prop may go either
 * way, so both of its branches count.
 *
 * @param  array<string, bool>  $state
 * @return list<object>
 */
function dashboardDraws(object $node, array $state): array
{
    $drawn = [];
    $settled = false;

    foreach ($node->children as $child) {
        $attrs = $child->attrs;

        $condition = match (true) {
            array_key_exists('v-if', $attrs) => dashboardCondition($attrs['v-if'], $state),
            array_key_exists('v-else-if', $attrs) => $settled ? false : dashboardCondition($attrs['v-else-if'], $state),
            array_key_exists('v-else', $attrs) => ! $settled,
            default => true,
        };

        if (array_key_exists('v-if', $attrs)) {
            $settled = $condition === true;
        } elseif (array_key_exists('v-else-if', $attrs)) {
            $settled = $settled || $condition === true;
        } else {
            $settled = false;
        }

        if ($condition === false) {
            continue;
        }

        $drawn[] = $child;
        array_push($drawn, ...dashboardDraws($child, $state));
    }

    return $drawn;
}

/** @param  list<object>  $drawn */
function dashboardHas(array $drawn, string $attribute, string $value): bool
{
    foreach ($drawn as $node) {
        $actual = $node->attrs[$attribute] ?? null;

        if ($actual === null) {
            continue;
        }

        if ($attribute === 'class' ? in_array($value, preg_split('/\s+/', $actual) ?: [], true) : $actual === $value) {
            return true;
        }
    }

    return false;
}

it('keeps every figure and every quick action on the dashboard when its activity feed is empty (B105)', function () {
    $tree = dashboardTree();

    $state = static fn (string $current): array => [
        'isLoading' => $current === 'loading',
        'isDenied' => $current === 'denied',
        'isError' => $current === 'error',
        'isEmpty' => $current === 'empty',
        'isEmptyFiltered' => $current === 'empty-filtered',
        'isPopulated' => $current === 'populated',
    ];

    /*
     * A NEW ESTATE — no payment, incident, verification, closed ticket or
     * booking yet — or a role whose modules withhold every row the feed has.
     * The tiles, the arrears panel and the quick actions read nothing from the
     * feed, and "Add resident" and "New charge" are the first two things a new
     * estate has to do.
     */
    $empty = dashboardDraws($tree, $state('empty'));

    expect(dashboardHas($empty, 'class', 'kpi-row'))->toBeTrue('The KPI tiles vanish when the activity feed is empty.')
        ->and(dashboardHas($empty, 'class', 'kpi-card'))->toBeTrue()
        ->and(dashboardHas($empty, 'class', 'bar-chart'))->toBeTrue('The arrears panel vanishes when the activity feed is empty.')
        ->and(dashboardHas($empty, 'class', 'qa-row'))->toBeTrue('The quick actions vanish when the activity feed is empty.')
        ->and(dashboardHas($empty, ':href', 'quickActions.addResident.href'))->toBeTrue()
        ->and(dashboardHas($empty, ':href', 'quickActions.newCharge.href'))->toBeTrue()
        ->and(dashboardHas($empty, ':href', 'activity.seeAllHref'))->toBeTrue()
        ->and(dashboardHas($empty, 'variant', 'first-use'))->toBeTrue('An empty feed says so.');

    // The first-use sentence is the activity PANEL's: the one `.panel` whose
    // own subtree holds the feed's rows holds the sentence too.
    $subtree = static function (object $node) use (&$subtree): array {
        $all = [];

        foreach ($node->children as $child) {
            $all[] = $child;
            array_push($all, ...$subtree($child));
        }

        return $all;
    };

    $panels = array_values(array_filter(
        dashboardDraws($tree, $state('empty')),
        static fn (object $node): bool => dashboardHas([$node], 'class', 'panel')
            && dashboardHas($subtree($node), 'v-for', '(row, index) in activity.rows'),
    ));

    expect($panels)->toHaveCount(1)
        ->and(dashboardHas($subtree($panels[0]), 'variant', 'first-use'))->toBeTrue('The empty feed is explained somewhere other than the activity panel.');

    // With rows, the sentence is gone and everything else is there.
    $populated = dashboardDraws($tree, $state('populated'));

    expect(dashboardHas($populated, 'variant', 'first-use'))->toBeFalse()
        ->and(dashboardHas($populated, 'class', 'kpi-row'))->toBeTrue()
        ->and(dashboardHas($populated, 'class', 'qa-row'))->toBeTrue();

    // Loading, denied and failed still replace the whole screen — a control
    // proving the reading above can tell a hidden block from a drawn one.
    foreach (['loading', 'denied', 'error'] as $whole) {
        expect(dashboardHas(dashboardDraws($tree, $state($whole)), 'class', 'kpi-row'))->toBeFalse();
    }
});
