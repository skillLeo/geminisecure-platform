<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every field on both consoles, the same — M12 §4.1
|--------------------------------------------------------------------------
|
| "Go through every input, select, date and time field, textarea, search box, modal and table control in both
| consoles and make them consistent. Include the focus treatment from M10 §6 — one definition, visible for keyboard
| users, no layout shift."
|
| The ring is defined once (app.css). A component may take the outline off a field ONLY where the field sits inside
| a box that wears the ring instead — otherwise the keyboard user sees nothing. The sweep found three fields with no
| ring at all (the deny note, the features reason field, and the boxes app.css did not name); this keeps a fourth
| from appearing.
|
*/

/** A file of the repository, as text. */
function consoleFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

/** Every selector in the consoles' components that resets `outline`, as "file :: selector". @return list<string> */
function outlineResets(): array
{
    $found = [];
    $root = dirname(__DIR__, 2).'/resources/js';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.vue')) {
            continue;
        }

        $css = (string) preg_replace('~/\*.*?\*/~s', '', consoleFile('resources/js/'.str_replace('\\', '/', substr((string) $file, strlen($root) + 1))));

        if (preg_match_all('~([^{}]+)\{[^{}]*\boutline\s*:\s*(?:0|none)\b[^{}]*\}~', $css, $rules)) {
            foreach ($rules[1] as $selector) {
                $found[] = basename((string) $file).' :: '.trim((string) preg_replace('~\s+~', ' ', $selector));
            }
        }
    }

    sort($found);

    return $found;
}

it('takes the outline off a field only where its box wears the ring', function () {
    $boxes = ['.m-input', '.top-search', '.l-input', '.m-textarea'];

    // The fields inside a box, and the boxes drawn inline that carry `field-box` (the dispatcher's message box, and the
    // control room's reply box in Live chat, 8 Oct 2026).
    $allowed = ['Alert.vue :: .panel input', 'Chat.vue :: .chat-box input', 'Show.vue :: .invite-row-outcome'];

    foreach (outlineResets() as $reset) {
        $insideABox = collect($boxes)->contains(fn (string $box): bool => str_contains($reset, $box.' '));

        expect($insideABox || in_array($reset, $allowed, true))->toBeTrue("{$reset} takes the focus ring off a field no box draws it for");
    }
});

it('names every box that draws the ring for the field inside it', function () {
    $css = consoleFile('resources/css/app.css');

    foreach (['.m-input', '.top-search', '.l-input', '.m-textarea', '.field-box'] as $box) {
        expect($css)->toContain("body[class*='wf-'] {$box}:has(:is(input, select, textarea):focus-visible)");
    }

    // The dispatcher's message box is one of them, and so is Live chat's reply box.
    expect(consoleFile('resources/js/Pages/Gemini/Dispatch/Alert.vue'))->toContain('class="field-box"');
    expect(consoleFile('resources/js/Pages/Gemini/Dispatch/Chat.vue'))->toContain('class="field-box chat-box"');
});

it('keeps the ring an outline — drawn outside the box, so nothing moves when it appears', function () {
    $css = consoleFile('resources/css/app.css');

    expect($css)->toContain("body[class*='wf-'] :focus-visible {\n    outline: 2px solid var(--navy-900);\n    outline-offset: 2px;");
});

it('sets what no page set — type, placeholder, disabled, date and time buttons — at zero specificity', function () {
    $css = consoleFile('resources/css/app.css');

    expect($css)->toContain(":where(body[class*='wf-']) :where(input, textarea, select, button) {\n    font-family: inherit;")
        ->and($css)->toContain(":where(body[class*='wf-']) :where(input, textarea)::placeholder")
        ->and($css)->toContain(":where(body[class*='wf-']) :where(input, select, textarea, button):disabled")
        ->and($css)->toContain('::-webkit-calendar-picker-indicator');
});
