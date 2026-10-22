<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Api\Handoff;

/*
|--------------------------------------------------------------------------
| MOBILE_HANDOFF.md is the catalogue, written down — 13 C4
|--------------------------------------------------------------------------
|
| "One table per endpoint (method, path, ability, request, response, every error
| code and shape, offline behaviour)." The file is generated; this fails when it
| is stale, and when an entry lacks anything its table needs.
|
*/

it('matches what the catalogue renders — regenerate with php artisan api:handoff', function () {
    expect(file_get_contents(base_path('MOBILE_HANDOFF.md')))->toBe(Handoff::render());
});

it('gives every endpoint its method, path, ability, request, response, errors and offline behaviour', function () {
    $document = Handoff::render();

    foreach (Catalogue::endpoints() as $name => $entry) {
        foreach (['summary', 'offline'] as $field) {
            expect(trim((string) $entry[$field]))->not->toBe('', "[{$name}] has no {$field}");
        }

        expect($document)->toContain('### `'.$name.'`')
            ->and($document)->toContain('`'.$entry['method'].' /api/v1/'.$entry['uri'].'`');

        foreach (array_keys($entry['response']) as $key) {
            expect($document)->toContain('| `'.$key.'` |');
        }

        foreach (array_keys($entry['errors']) as $error) {
            expect($document)->toContain('`'.explode(' ', $error, 2)[1].'`');
        }

        // A path parameter is always a possible 404, and a write always a possible replay conflict.
        if (($entry['where'] ?? []) !== [] && ! isset($entry['errors']['404 not_found'])) {
            $section = substr($document, (int) strpos($document, '### `'.$name.'`'));
            $section = substr($section, 0, (int) (strpos($section, "\n### `", 5) ?: strlen($section)));

            expect(str_contains($section, '`not_found`'))->toBeTrue("[{$name}] takes a path parameter and documents no 404");
        }
    }
});
