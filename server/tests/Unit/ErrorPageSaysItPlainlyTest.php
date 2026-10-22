<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The error page says what happened in plain words
|--------------------------------------------------------------------------
|
| Reported by the Security session (12 October 2026): the server's 403 page carried an em dash (M53 Law 3) in a
| sentence about "the role access matrix" and a "module" that "navigation is generated from", words a resident or an
| officer who followed a saved link does not use. Every status the page can draw is read here.
|
*/

it('writes every error in plain words, with no em dash and nothing about how the console is built', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/ErrorPage.vue');

    preg_match('/const COPY = \{(.*?)\n\}/s', $page, $copy);

    expect($copy)->not->toBeEmpty('ErrorPage.vue has no COPY table');

    foreach (['—', 'role access matrix', 'module', 'generated'] as $forbidden) {
        expect(str_contains($copy[1], $forbidden))->toBeFalse("ErrorPage's copy says [{$forbidden}]");
    }
});
