<?php

declare(strict_types=1);

use App\Models\Estate\PayrollRun;
use App\Services\Estate\Payroll;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A pay run that cannot be approved yet says so in one line, and what would make it possible (DR-032; M73 §1, step 9)
|--------------------------------------------------------------------------
|
| Hassam's rule for the Sunday walk: Approve stays blocked, and its refusal reads as a deliberate stopping point, never
| as an error. An uncalculated run answered in two sentences; it says what comes first now. The page's own empty state
| says it without an em dash (Law 3).
|
*/

uses(TestCase::class);

it('refuses to approve an uncalculated run in one line that says what comes first', function (): void {
    $why = app(Payroll::class)->approvalRefusal((new PayrollRun)->forceFill(['status' => PayrollRun::DRAFT]), null);

    expect($why)->toBe('Calculate the run first; there are no payslips to approve yet.')
        ->and(substr_count((string) $why, '. '))->toBe(0);
});

it('says on the run page, without an em dash, what an uncalculated run needs', function (): void {
    $vue = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Payroll/Run.vue');

    expect($vue)->toContain('body="No payslips have been worked out yet. Resolve the run\'s exceptions, then calculate it."')
        ->and(preg_match('/title="This run has not been calculated"\s+body="[^"]*—/u', $vue))->toBe(0);
});
