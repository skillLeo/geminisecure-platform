<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| What the notices controller says, without an em dash (M53 Law 3; the Hub, 12 Oct 2026)
|--------------------------------------------------------------------------
|
| A notice that went out without its file was reported in two flashes, "Notice posted — without its file." and "...
| The notice itself went out — do not post it again.": the last flashes of a problem in the estate controllers that
| still carried one. Every string this controller hands a person is read here, its comments left out.
|
*/

it('says every word it hands a person without an em dash, the notice that went out without its file included', function () {
    $php = (string) file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/Estate/NoticesController.php');

    // Comments explain to the next developer and are not read by anybody using the console.
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $php);

    preg_match_all("/'(?:[^'\\\\]|\\\\.)*'/", $code, $strings);

    $dashed = array_values(array_filter($strings[0], static fn (string $literal): bool => str_contains($literal, '—')));

    expect($strings[0])->not->toBeEmpty()
        ->and($dashed)->toBe([])
        ->and(str_contains($code, "'Notice posted, without its file.'"))->toBeTrue('the success flash is not in its words');
});
