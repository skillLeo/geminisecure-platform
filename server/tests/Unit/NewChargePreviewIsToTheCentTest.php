<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| New charge's ledger preview is written to the cent
|--------------------------------------------------------------------------
|
| THE MONEY RULE (M97): every figure on a page ties to the ledger. The preview's Current balance is the unit's own,
| off its posted lines (`Dues::balanceOf`, which EstateArrearsTest traces to raw journal lines and
| EstateAdminAssistantSeesNoMoneyTest holds the page's prop to). The preview printed it, the charge and the new balance
| in whole dollars, so $12,400.50 and a $15,000.00 charge read $12,401 + $15,000 = $27,401. It is the cents now, the
| same figure the amount field carries.
|
*/

it('writes the ledger preview to the cent, and keeps no whole-dollar money format on the page', function () {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Dues/NewCharge.vue');

    expect(str_contains($page, 'const preview = (minor) => withCents.format(minor / 100)'))->toBeTrue('the preview is not written with cents')
        ->and(str_contains($page, 'maximumFractionDigits: 0'))->toBeFalse('a whole-dollar money format is still on the page');
});
