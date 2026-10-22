<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The estate's security map: every word readable on a phone, a panic never a number, times in Law 5's form (12 Oct)
|--------------------------------------------------------------------------
|
| The screen came to the Residents lane from Security's first version (m98/gs6 c2aaab78, GUARD_TRACKING.md 5.4 and 5.5).
| Read as text: a unit test boots no application, and the JS test runner is banned on this PC.
|
|   1. A pin with words is never folded into a counted cluster. An open panic and a guard on duty carry their words
|      because a phone has no hover; above 25 pins the shared map counted them with the posts and checkpoints, and a
|      panic became a red number with no lot. The most urgent pin is drawn last, on top.
|   2. The map's own messages are sentences: two of them ran across an em dash (Law 3).
|   3. The estate's console writes a time with no zone (Law 5, DR-039): the map wrote "Raised 2:04 AM JA".
|   4. The open alert's count goes up every second out of a screen reader's hearing; the time it was raised is read.
|   5. Where the phone is now is said when it has moved since the panic was pressed.
|
*/

function estateMapSource(string $path): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$path));
}

/** From `$start` to the first line after it that closes a top-level block (`}` or `})`). */
function estateMapBlock(string $source, string $start): string
{
    $at = strpos($source, $start);
    expect($at)->not->toBeFalse('not found: '.$start);

    $ends = array_filter([strpos($source, "\n}\n", (int) $at), strpos($source, "\n})\n", (int) $at)], static fn (int|false $end): bool => $end !== false);
    expect($ends)->not->toBeEmpty();

    return substr($source, (int) $at, (int) min($ends) - (int) $at);
}

it('never folds a pin with words into a counted cluster, and draws the most urgent on top', function (): void {
    $map = estateMapSource('Components/SiteMap.vue');
    $clusters = estateMapBlock($map, 'function clusters() {');

    expect(str_contains($clusters, 'const worded = pins.filter((p) => p.label)'))->toBeTrue('the pins with words are set apart')
        ->and(str_contains($clusters, 'for (const p of pins.filter((pin) => !pin.label)) {'))->toBeTrue('only the pins without words are counted')
        ->and(str_contains($clusters, '...worded.map(alone),'))->toBeTrue('each pin with words is drawn on its own')
        ->and(str_contains($map, 'for (const c of clusters().sort((a, b) => (RANK[worst(a.pins)] ?? 0) - (RANK[worst(b.pins)] ?? 0))) {'))->toBeTrue('the most urgent last, on top');
});

it('says what the map cannot show in sentences, with no em dash', function (): void {
    $map = estateMapSource('Components/SiteMap.vue');
    $why = estateMapBlock($map, 'const why = computed(');
    $template = substr($map, (int) strpos($map, '<template>'), (int) strpos($map, '<style scoped>') - (int) strpos($map, '<template>'));

    expect(str_contains($why, '—'))->toBeFalse('an em dash in what the map says')
        ->and(str_contains($template, '—'))->toBeFalse('an em dash in what the map draws')
        ->and(str_contains($why, 'The map cannot load offline.'))->toBeTrue()
        ->and(str_contains($why, 'The map could not be drawn.'))->toBeTrue()
        // "Every place on it is listed below" only when something is.
        ->and(str_contains($map, "const listedBelow = computed(() => (inWords.value.length > 0 ? ' Every place on it is listed below.' : ''))"))->toBeTrue();
});

it('writes a time on the estate\'s map with no zone, says where the phone is now, and keeps the ticking count from a screen reader', function (): void {
    $page = estateMapSource('Pages/Estate/Security/Index.vue');
    $timeWords = estateMapBlock($page, 'const timeWords = (iso) => {');

    expect(str_contains($timeWords, 'timeZone: feed.value.zone'))->toBeTrue('the estate\'s own clock')
        ->and(str_contains($page, 'zone_tag'))->toBeFalse('the estate\'s console draws no zone (Law 5)')
        ->and(str_contains($page, '<span class="sx-alert-line">Raised {{ timeWords(alert.raised_at) }}<span aria-hidden="true"> · open {{ openFor(alert.raised_at) }}</span></span>'))->toBeTrue()
        // The live region wraps the card: on the link itself, role="alert" took away the link a reader opens it by.
        ->and(str_contains($page, 'class="sx-alert" role="alert"'))->toBeFalse('the card\'s link carries role="alert"')
        ->and(str_contains($page, "<div v-for=\"alert in openAlerts\" :key=\"alert.id\" role=\"alert\">\n        <Link :href=\"alertHref(alert)\" class=\"sx-alert\">"))->toBeTrue();
});

it('shows the estate where a guard is and whether it is fresh, never the battery or why a reading was taken', function (): void {
    // Hassam, 12 Oct: the consent says the estate's management sees "where you are". The battery and the reading's reason
    // are the employer's tools, kept to the Gemini dispatcher's screens: "show less, not say more".
    $page = estateMapSource('Pages/Estate/Security/Index.vue');

    expect(str_contains($page, 'battery_pct'))->toBeFalse('the estate is shown a guard\'s battery')
        ->and(str_contains($page, 'source_label'))->toBeFalse('the estate is shown why a reading was taken')
        ->and(str_contains($page, '<dt>Position</dt>'))->toBeTrue()
        ->and(str_contains($page, '<dt>Distance</dt>'))->toBeTrue();
});

it('shows the estate two states of an officer and never when a shift began, and keeps a waiting guard\'s line moving (ruling 1)', function (): void {
    // Hassam's ruling 1, 12 Oct: "On duty" or "Responding", as the feed sends them; no clock-in time anywhere; the post stays.
    $page = estateMapSource('Pages/Estate/Security/Index.vue');

    expect(str_contains($page, 'clocked_in_at'))->toBeFalse('the estate is shown when a shift began')
        ->and(str_contains($page, 'onShiftFor'))->toBeFalse()
        ->and(str_contains($page, '<span class="sx-guard-sub">{{ guard.post }} · {{ guard.state_label }}</span>'))->toBeTrue('the post and the state as sent')
        ->and(str_contains($page, "<dt>Doing</dt>\n                    <dd>{{ selected.state_label }}</dd>"))->toBeTrue('"Doing" is the state alone')
        // Away from the post is where the guard is, drawn with the position, never a third state.
        ->and(str_contains($page, '<template v-if="selected.off_post"> · away from the post</template></dd>'))->toBeTrue();

    // With no clock-in time the page cannot tick Waiting into No signal itself: it reads `map` again once a minute while
    // anybody waits, and stops when nobody does.
    expect(str_contains($page, "const anyWaiting = computed(() => guards.value.some((g) => g.fresh?.freshness === 'waiting'))"))->toBeTrue()
        ->and(str_contains($page, "setInterval(() => router.reload({ only: ['map'], preserveScroll: true, preserveState: true }), WAITING_REREAD_MS)"))->toBeTrue()
        ->and(str_contains($page, 'const WAITING_REREAD_MS = 60000'))->toBeTrue()
        ->and(substr_count($page, 'clearInterval(waiting)'))->toBe(2);
});

it('draws where a panic was pressed beside where the phone is now, and says the phone moved only when it did', function (): void {
    $page = estateMapSource('Pages/Estate/Security/Index.vue');

    // The feed's position_moved means a newer position was SENT: whether the phone went anywhere is measured, beyond a
    // reading's own wobble.
    expect(str_contains($page, 'const pressedApart = (a) => (movedMetres(a) ?? 0) > Math.max(30, a.accuracy_m ?? 0)'))->toBeTrue()
        ->and(str_contains($page, 'const showPressed = (a) => Boolean(a.position_moved) && raisedPlaced(a) && (pressedApart(a) || !nowPlaced(a))'))->toBeTrue();

    // Where it was pressed: a grey pin of its own, in words, joined to the red one by a line; off the boundary, listed.
    $pins = estateMapBlock($page, 'const mapPins = computed(');
    expect(str_contains($pins, '...openAlerts.value.filter(showPressed).map((a) => ({'))->toBeTrue()
        ->and(str_contains($pins, "tone: 'stale', href: alertHref(a),\n        label: 'Pressed here',"))->toBeTrue()
        ->and(str_contains(estateMapBlock($page, 'const mapLines = computed('), "label: 'From where it was pressed to where the phone is now'"))->toBeTrue()
        ->and(str_contains(estateMapBlock($page, 'const unconfirmed = computed('), 'why: a.raised_off_estate'))->toBeTrue();

    // In words under the alert: moved, and how far; still there; or where the phone is with nothing to compare.
    $where = estateMapBlock($page, 'const whereLine = (a) => {');
    expect(str_contains($where, 'The phone has moved ${distanceWords(movedMetres(a))} since it was pressed. The map shows both places, the phone as of ${timeWords(a.position_at)}.'))->toBeTrue()
        ->and(str_contains($where, 'The phone is still where it was pressed, as of ${timeWords(a.position_at)}.'))->toBeTrue()
        ->and(str_contains($page, '<span v-if="whereLine(alert)" class="sx-alert-line">{{ whereLine(alert) }}</span>'))->toBeTrue();
});
