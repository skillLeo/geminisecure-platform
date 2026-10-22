<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\PasswordResetController;

/*
|--------------------------------------------------------------------------
| The front door says what happened in sentences, without an em dash (M97 item 2)
|--------------------------------------------------------------------------
|
| Forgot password told a person "It works for thirty minutes and once.", the reset page refused a link with an em
| dash, and the reset email repeated the half sentence. Each now reads as the platform's other codes do: "It works
| once, for N minutes." The sentences verify contact shows (the expired code, the phone note) lost their em dashes too:
| ContactVerificationGateTest holds those.
|
*/

it('says what was sent and what was refused in plain sentences, without an em dash', function () {
    expect(PasswordResetController::SENT)
        ->toBe('If that address holds an account, a link to choose a new password has been sent to it. It works once, for thirty minutes.');

    foreach ([PasswordResetController::SENT, PasswordResetController::REFUSED] as $sentence) {
        expect(str_contains($sentence, '—'))->toBeFalse()
            ->and(str_contains($sentence, 'and once'))->toBeFalse();
    }
});

it('says the same in the reset email', function () {
    $mail = (string) file_get_contents(dirname(__DIR__, 2).'/app/Notifications/ResetPasswordLink.php');

    expect(str_contains($mail, 'The link works once, for thirty minutes.'))->toBeTrue()
        ->and(str_contains($mail, 'thirty minutes and once'))->toBeFalse();
});
