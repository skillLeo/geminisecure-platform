<?php

/*
|--------------------------------------------------------------------------
| THE DUES DIALOGS HASSAM USED ON LIVE, 12 OCTOBER 2026
|--------------------------------------------------------------------------
|
| Reverse a month: "QA test" (seven characters) was refused by the server, which needs ten, in a toast top right while
| the dialog stood open saying nothing. Post a month and Stop a schedule toasted their refusals the same way.
| A payment plan: once the agreement was recorded, "Record the agreement" was still drawn beside Activate.
| Every success toast: drawn with no background and a black edge on pages whose board lacks the greens.
*/

function duesDialogSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('says before the press that a reversal needs ten characters, and keeps a refusal inside the open dialog', function () {
    $page = duesDialogSource('resources/js/Pages/Estate/Dues/ChargeSchedule.vue');

    expect(str_contains($page, 'const REVERSE_REASON_MIN = 10'))->toBeTrue()
        ->and(str_contains($page, ':confirm-blocked-reason="reverseBlocked"'))->toBeTrue()
        ->and(str_contains($page, ':error="reverseRefusal"'))->toBeTrue()
        ->and(str_contains($page, ':error="postRefusal"'))->toBeTrue()
        ->and(str_contains($page, ':error="stopRefusal"'))->toBeTrue()
        // No dialog on this page hands its refusal to a toast any more.
        ->and(str_contains($page, 'refused('))->toBeFalse();
});

it('draws what the agreement recorded in place of its button once it is recorded', function () {
    $page = duesDialogSource('resources/js/Pages/Estate/Dues/PaymentPlan.vue');

    expect(str_contains($page, 'v-if="step === \'agree\' && canCreate"'))->toBeTrue()
        ->and(str_contains($page, '(step === \'agree\' || step === \'activate\') && canCreate'))->toBeFalse()
        ->and(str_contains($page, '<span v-if="step === \'activate\'" class="status-badge plan-state">Agreed by'))->toBeTrue();
});

it('gives a success toast its green ground and edge on every page, whatever the board defines', function () {
    $stack = duesDialogSource('resources/js/Components/ToastStack.vue');

    expect(str_contains($stack, 'var(--green-100)'))->toBeFalse()
        ->and(str_contains($stack, 'var(--green-700)'))->toBeFalse();
});
