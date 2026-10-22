<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Add resident waits, saying what is still to be typed, until the form can be sent (Law 1)
|--------------------------------------------------------------------------
|
| Found by Accounting's walk of the client's list (12 Oct, CLIENT_BUGS.md row 12): Add resident in the register's modal
| was live with nothing typed, and every press was refused by the server. Nothing enabled refuses when pressed: the
| save is greyed, with what is missing said under it, until there is a phase, a lot, a full name, a phone and an email
| or the tick that says there is none. The lot's own question comes first, the no-email invite rule last. The form is
| one composable, so the register's modal and the Add resident page both wait (useAddResident.js; the JS test that
| draws it is edited, not run: the ban).
|
*/

it('greys Add resident until everything the server asks of a person is typed, and says what is missing', function () {
    $js = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/composables/useAddResident.js');

    foreach (["form.phase === '' ? 'the phase' : null", "form.lot.trim() === '' ? 'the lot' : null", "form.full_name.trim() === '' ? 'their full name' : null",
        "form.phone.trim() === '' ? 'a phone number' : null", "!form.no_email && form.email.trim() === '' ? 'an email (or tick that they have none)' : null",
        'return `Enter ${list} first.`'] as $line) {
        expect(str_contains($js, $line))->toBeTrue('useAddResident.js does not check: '.$line);
    }

    // In the save's own reason, in order: the lot's question, then what is missing, then the no-email invite.
    $blocked = (string) substr($js, (int) strpos($js, 'const submitBlockedBy'));
    $occupied = strpos($blocked, 'occupied.value !== null');
    $missing = strpos($blocked, 'missingLine.value');
    $invite = strpos($blocked, "form.no_email && form.verification === 'invite'");

    expect($occupied)->not->toBeFalse()
        ->and($missing)->not->toBeFalse()
        ->and($invite)->not->toBeFalse()
        ->and($occupied < $missing && $missing < $invite)->toBeTrue('the reasons are asked out of order');
});
