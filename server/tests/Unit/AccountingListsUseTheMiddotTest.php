<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| No em dash in a list to pick from: the accounting half (M62 B4, DR-026; M66 §1.3)
|--------------------------------------------------------------------------
|
| An account is offered as its code and its name with the middot between them, "1200 · Accounts Receivable", never an
| em dash (Law 3). It was built four times over, "code — name", in four places; one helper says it now,
| `Account::optionLabel()`, and the accounting screens call it. The charges screens and the charge schedule build the
| same label and are the QA session's to switch to the helper, with a unit's flags, the users and the roles (M66 §0).
|
*/

uses(TestCase::class);

$root = dirname(__DIR__, 2);

it('writes an account in a list to pick from as its code, a middot and its name', function (): void {
    expect((new Account)->forceFill(['code' => '1200', 'name' => 'Accounts Receivable'])->optionLabel())->toBe('1200 · Accounts Receivable');
});

it('has the accounting screens call that helper, with no em dash left in a list they build', function () use ($root): void {
    foreach (['AccountingController.php', 'PayablesController.php'] as $controller) {
        $php = (string) file_get_contents($root.'/app/Http/Controllers/Estate/'.$controller);

        expect($php)->toContain('$account->optionLabel()')
            ->and($php)->not->toContain("->code.' — '");
    }
});

it('says the vendor page\'s two statuses with a middot', function () use ($root): void {
    $vue = (string) file_get_contents($root.'/resources/js/Pages/Estate/Accounting/Vendor.vue');

    expect($vue)->toContain('<option value="active">Active · bills may be recorded and paid</option>')
        ->and($vue)->toContain('<option value="inactive">Inactive · no new bills; every past one stays</option>');
});

it('leaves no em dash in an option written on any accounting or payroll page', function () use ($root): void {
    $pages = [...glob($root.'/resources/js/Pages/Estate/Accounting/*.vue'), ...glob($root.'/resources/js/Pages/Estate/Payroll/*.vue')];

    expect(count($pages))->toBeGreaterThan(5);

    foreach ($pages as $file) {
        expect(preg_match_all('/<option[^>]*>[^<]*—/u', (string) file_get_contents($file)))->toBe(0, basename($file).' has an em dash in an option');
    }
});
