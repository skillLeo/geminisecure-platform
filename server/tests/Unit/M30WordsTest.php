<?php

declare(strict_types=1);

use App\Support\NewScreenWords;

/*
|--------------------------------------------------------------------------
| M30 §9: the words on the new screens
|--------------------------------------------------------------------------
|
| The Schedule, its shift panel and Places say things in the words the client
| reads them in. Changed on the new screens only: the classic pages keep theirs
| (ClassicWorkforcePagesAreUnchangedTest holds them byte for byte).
|
| Read from the source, as UrgentIsRedTest reads the board's colours: a word
| here is the word on the screen, whatever data the page is drawn with.
|
*/

function m30Source(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Gemini/Operations/'.$file);
}

/** What can reach the screen: the source without its comments, which name the classic words to say what replaced them. */
function m30Shown(string $file): string
{
    return (string) preg_replace(['/<!--.*?-->/s', '#/\*.*?\*/#s', '#^\s*//.*$#m', '#\s//\s.*$#m'], '', m30Source($file));
}

it('says the new words on the new screens', function (string $file, array $words) {
    $source = m30Source($file);

    foreach ($words as $word) {
        expect($source)->toContain($word);
    }
})->with([
    'the Schedule' => ['Schedule.vue', ['Add shift', 'Needs a guard', 'asked for this shift', 'Copy last week into this week', 'on leave']],
    'the shift panel' => ['ShiftPanel.vue', ['Gate or patrol start', 'What he does there', 'Stands the gate', 'Starts a patrol here', 'Take the guard off this shift', 'Remove this shift', 'Leave it open, any guard can ask for it', 'Who stands it']],
    'Places' => ['Places.vue', ['Location set', 'No location yet', 'A guard here can clock in from anywhere, and the office is told.', 'How close a guard must be to clock in', 'How far out it reaches', 'Set its location', 'Move on the map', 'Print all tags']],
]);

it('no longer says the old ones there', function (string $file) {
    $source = m30Shown($file);

    foreach (['Post open shift', 'Why it is open', 'Not placed', 'Usual post', 'Release shift', 'Internal test estate'] as $old) {
        expect($source)->not->toContain($old);
    }
})->with(['Schedule.vue', 'ShiftPanel.vue', 'Places.vue']);

/*
 * M31 B7: the words the person testing the new screens looks for, and must find nowhere on them. Single words this
 * time ("placed", "duty"), so the source is read the way a person reads the screen, not as code: `block.duty` is an
 * identifier, "On duty" would be a word.
 */
const M31_OLD_WORDS = '/\b(post open shift|open shift|not placed|placed|geofences?|fences?|radius|radii|releas\w*|claim\w*|dut(?:y|ies)|usual post|why it is open)\b/i';

/**
 * What a person can read from a page's source: the template's text; the sentences inside its {{ }}, its bound
 * attributes and its script (a string with a space in it: keys such as 'on_duty' have none); and its title,
 * placeholder, aria-label and alt attributes. Never its comments, styles or identifiers.
 *
 * @return list<string>
 */
function m30Readable(string $source): array
{
    $source = (string) preg_replace(['/<!--.*?-->/s', '#<style\b.*?</style>#s'], '', $source);
    $said = [];
    $sentences = static function (string $code) use (&$said): void {
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//.*$#m'], '', $code);
        preg_match_all('/\'(?:\\\\.|[^\'\\\\])*\'|`(?:\\\\.|[^`\\\\])*`/s', $code, $m);
        foreach ($m[0] as $literal) {
            $text = (string) preg_replace('/\$\{[^}]*\}/', ' ', substr($literal, 1, -1));
            if (str_contains(trim($text), ' ')) {
                $said[] = $text;
            }
        }
    };

    preg_match_all('#<script\b[^>]*>(.*?)</script>#s', $source, $scripts);
    foreach ($scripts[1] as $script) {
        $sentences($script);
    }

    if (preg_match('#<template>(.*)</template>#s', $source, $template) === 1) {
        preg_match_all('/\{\{(.*?)\}\}/s', $template[1], $mustaches);
        foreach ($mustaches[1] as $code) {
            $sentences($code);
        }
        preg_match_all('/\s(?::|v-|@)[\w.:-]+="([^"]*)"/', $template[1], $bound);
        foreach ($bound[1] as $code) {
            $sentences($code);
        }
        preg_match_all('/\s(?:title|placeholder|aria-label|alt)="([^"]*)"/', $template[1], $human);
        array_push($said, ...$human[1]);
        $said[] = (string) preg_replace(['/\{\{.*?\}\}/s', '/<[^>]*>/s'], ' ', $template[1]);
    }

    return $said;
}

/**
 * The sentences a server file can send a screen: its string literals with a space in them, without comments and
 * without the patterns PlacesController matches the classic page's sentences by.
 *
 * @return list<string>
 */
function m30ServerSentences(string $file): array
{
    $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2).'/'.$file));
    $said = [];
    foreach ($tokens as $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        $text = $token[0] === T_CONSTANT_ENCAPSED_STRING ? substr($token[1], 1, -1) : $token[1];
        if (str_contains(trim($text), ' ') && preg_match('#^/\^.*/[a-z]*$#s', $text) !== 1) {
            $said[] = $text;
        }
    }

    return $said;
}

it('says none of the old words anywhere on the new screens (M31 B7)', function (string $file) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/'.$file);

    $found = array_values(array_filter(m30Readable($source), static fn (string $said): bool => preg_match(M31_OLD_WORDS, $said) === 1));

    expect($found)->toBe([], $file.' says: '.implode(' | ', array_map(static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', $s)), $found)));
})->with([
    'the Schedule' => 'Pages/Gemini/Operations/Schedule.vue',
    'the shift panel' => 'Pages/Gemini/Operations/ShiftPanel.vue',
    'Places' => 'Pages/Gemini/Operations/Places.vue',
    'the Places map' => 'Components/PlacesMap.vue',
]);

it('sends the new screens no sentence with an old word in it (M31 B7)', function (string $file) {
    $found = array_values(array_filter(m30ServerSentences($file), static fn (string $said): bool => preg_match(M31_OLD_WORDS, $said) === 1));

    expect($found)->toBe([], $file.' says: '.implode(' | ', $found));
})->with([
    'app/Services/Gemini/ShiftPlanner.php',
    'app/Services/Gemini/WeekCopy.php',
    'app/Services/Gemini/LeaveCover.php',
    'app/Services/Gemini/ShiftConflicts.php',
    'app/Services/Gemini/PlaceSearch.php',
    'app/Http/Controllers/Gemini/PlacesController.php',
    'app/Http/Controllers/Gemini/ShiftPanelController.php',
    'app/Http/Controllers/Gemini/ScheduleController.php',
    'app/Support/NewScreenWords.php',
]);

it('says the classic pages\' refusals that use those words in the new screens\' words instead (M31 B7)', function (string $classic) {
    $said = NewScreenWords::say($classic);

    expect($said)->not->toBe($classic)
        ->and(preg_match(M31_OLD_WORDS, $said))->toBe(0, $said)
        ->and($said)->not->toContain('—');
})->with([
    'Kemoy Allen is suspended from duty and cannot be posted.',
    'The estate is placed with its centre and its radius together, or taken off the map with neither.',
    'That is how this estate is placed already. Saving it again would record a change nobody made.',
    'Vehicle Patrol is a patrol route, and a patrol covers more ground than any circle. It is not placed: choose the gate it starts from, and its officer clocks in there.',
    'A checkpoint is placed with both its latitude and its longitude, or taken off the map with neither.',
    'A checkpoint is placed with both its latitude and its longitude, or made with neither and placed later.',
    'A checkpoint has a name a guard can find — "North fence", "Pool gate" — of at most 120 characters.',
    'A checkpoint has a name a guard can find at night — a landmark like "Basketball court", "Pool gate" — of at most 120 characters.',
    'Line 3: A checkpoint has a name a guard can find at night — a landmark like "Basketball court", "Pool gate" — of at most 120 characters.',
    'A checkpoint\'s radius is between 10 and 50 metres: how close a guard\'s phone must be to the tag for the scan to count.',
    'A checkpoint is in the list twice. Each one is placed once, where its tag hangs.',
    'A geofence radius is between 10 and 2,000 metres.',
]);

it('lets every other refusal through as it is', function () {
    $clean = 'Main Gate already has a shift covering part of that window (Oct 6, 6:00 AM to 6:00 PM JA). Two shifts on one post at one time is two officers told the same gate is theirs.';

    expect(NewScreenWords::say($clean))->toBe($clean)
        ->and(NewScreenWords::say('Kemoy Allen is suspended from duty and cannot be posted.'))->toBe('Kemoy Allen is suspended, so cannot be put on a shift.');
});

it('gives no named guard a pronoun on the new screens', function (string $file) {
    // Sentences about a guard use the name or "their"; the order's own label "What he does there" is the one he.
    $source = str_replace('What he does there', '', m30Shown($file));

    expect(preg_match('/\b(He|he|His|his|Him|him)\b/', $source))->toBe(0);
})->with(['Schedule.vue', 'ShiftPanel.vue', 'Places.vue']);
