<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The household page never prints "null" or "undefined" in a sentence (batch 1 item 2's sweep, 12 Oct)
|--------------------------------------------------------------------------
|
| Hassam's amenity read-back said "cancel nullh ahead"; the sweep of this lane found three sentences on a household's
| page built from a value that can be missing: a past household with no move-out date ("Moved out null"), an invite
| refused with no reason recorded ("Not sent: undefined"), and a level change to a level the page does not know
| ("Change to undefined"). Each now says it in words. Read as text (a unit test boots no application).
|
*/

it('says a missing move-out date, refusal reason and level in words', function (): void {
    $vue = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Residents/Show.vue'));

    foreach (["'Living here' : `Moved out \${entry.moved_out}` }}", '`Not sent: ${invite.office_reason ?? invite.reason}`', '`Change to ${levelOf(levelChange.to)?.label}`', 'to ${levelOf(levelChange.to)?.label}?`', '`Keep ${levelOf(levelChange.from)?.label}`'] as $raw) {
        expect(str_contains($vue, $raw))->toBeFalse('still printed raw: '.$raw);
    }

    expect(str_contains($vue, 'entry.moved_out ? `Moved out ${entry.moved_out}` : \'Moved out\''))->toBeTrue()
        ->and(str_contains($vue, '`Not sent: ${invite.office_reason ?? invite.reason ?? \'no reason was recorded.\'}`'))->toBeTrue()
        ->and(str_contains($vue, "const levelLabel = (key) => levelOf(key)?.label ?? 'another level'"))->toBeTrue();
});
