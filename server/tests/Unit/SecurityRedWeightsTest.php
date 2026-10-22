<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Red in three weights, on the Security pages (Hassam, 12 Oct)
|--------------------------------------------------------------------------
|
| Solid saturated red is an open panic or duress alert's alone. A red outline or muted red is Failed, Rejected and a
| destructive act. Amber is everything else: errors, warnings, refusals, urgent notices, overdue, blocked. These read
| the pages' own rules, as UrgentIsRedTest reads the console sheet.
|
*/

/** A file of the repository, read as text (a unit test boots no application). */
function securityPageSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$path);
}

/** The declarations of the first rule written exactly as `$selector {`. */
function securityRule(string $source, string $selector): string
{
    $at = strpos($source, $selector.' {');
    expect($at === false)->toBeFalse();

    $open = strpos($source, '{', (int) $at);
    $close = strpos($source, '}', (int) $open);

    return substr($source, (int) $open, (int) $close - (int) $open);
}

it('draws a panic alert\'s warnings and errors amber, and only a failed delivery in muted red', function (): void {
    $alert = securityPageSource('Gemini/Dispatch/Alert.vue');

    foreach (['.told-flag', '.told-outcome.off', '.drill-note', '.rs-count.over', '.rs-field-error', '.chat-error'] as $selector) {
        $rule = securityRule($alert, $selector);

        // A colour, not a word: `.chat-error` keeps its "Amber, not red" note inside the rule.
        expect(str_contains($rule, 'var(--amber-700)'))->toBeTrue()
            ->and(str_contains($rule, 'var(--red'))->toBeFalse();
    }

    foreach (['.told-flag.failed', '.told-outcome.off.failed'] as $selector) {
        expect(str_contains(securityRule($alert, $selector), 'var(--red-700)'))->toBeTrue();
    }

    // Only a destination nobody reached, and a leg that failed, take the Failed weight.
    expect(str_contains($alert, 'class="told-flag failed">Not reached</span>'))->toBeTrue()
        ->and(substr_count($alert, "failed: leg.outcome === 'failed'"))->toBe(2);
});

it('says a drill in words, with no command name and no em dash', function (): void {
    $alert = securityPageSource('Gemini/Dispatch/Alert.vue');

    expect(str_contains($alert, 'Drill: a practice alert, raised to test who a panic reaches.'))->toBeTrue()
        ->and(str_contains($alert, 'panic:walk to test'))->toBeFalse();
});

it('keeps the panic red off a warning card, a position being placed, and an urgent item that is not an alert', function (): void {
    // RED_MAP.md, routed to Security (12 Oct). The console sheet: a warning card's tile and a refusal's tile are amber.
    $css = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css'));
    $at = strpos($css, "html body[class*='wf-'] .kpi-card.warn .kpi-icon,\nhtml body[class*='wf-'] .feed-icon.deny {");
    expect($at === false)->toBeFalse()
        ->and(str_contains(securityRule($css, "html body[class*='wf-'] .feed-icon.deny"), 'var(--amber-100)'))->toBeTrue();

    // The shared map has an amber tone for a position being placed, and Sites & geofences uses it, never the panic red.
    $map = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Components/SiteMap.vue');
    $sites = securityPageSource('Gemini/Operations/Sites.vue');
    expect(str_contains($map, "placing: '--amber-600'"))->toBeTrue()
        ->and(str_contains($sites, "tone: 'danger'"))->toBeFalse()
        ->and(substr_count($sites, "tone: 'placing'"))->toBe(3);

    // The bell: an urgent item is amber, and only an open alert's dot is red.
    $bell = securityPageSource('Gemini/Notifications/Index.vue');
    expect(str_contains(securityRule($bell, '.nt-dot.urgent'), 'var(--amber-700)'))->toBeTrue()
        ->and(str_contains(securityRule($bell, '.nt-dot.urgent.alert'), 'var(--red-700)'))->toBeTrue()
        ->and(str_contains($bell, "alert: item.icon === 'alert'"))->toBeTrue();
});

it('tints a refusal at the gate amber, not pale red', function (): void {
    $rule = securityRule(securityPageSource('Gemini/Operations/GateActivity.vue'), 'html body .feed-icon.deny');

    expect(str_contains($rule, 'var(--amber-100)'))->toBeTrue()
        ->and(str_contains($rule, 'var(--red'))->toBeFalse();
});
