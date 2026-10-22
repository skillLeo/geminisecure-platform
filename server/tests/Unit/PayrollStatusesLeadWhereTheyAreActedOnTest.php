<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A status in a payroll list leads to where it is acted on (Hassam, 12 Oct, batch 1 item 10)
|--------------------------------------------------------------------------
|
| "Awaiting approval", "Exceptions", "Action required" on a return due: each was a plain badge beside the thing that
| acts on it. Now the badge is the way there: a run's status opens what its row's action opens (the exceptions to
| settle, or the run to review and approve), on both consoles; a return due opens the File question on its own row, or,
| when its pay run is not approved yet, that run; a calendar entry opens the filings register, where returns are filed.
| Not made clickable, on purpose: a filed return and a paid run's status on the estate calendar (nothing left to do),
| an employee's Active or Inactive (nothing in the product changes it), and the Gemini filings register, whose rows are
| records with no action in the product by design (filing with TAJ is done outside it).
|
*/

function payrollStatusPage(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$page);
}

it('opens a run from its status on the estate run list and the Gemini pay runs list', function () {
    expect(preg_match('/<template #cell-status="\{ row \}">\s*(?:<!--.*?-->\s*)?<Link :href="rowHref\(row\)" class="status-badge status-link" :class="badgeClass\(row\)"/s', payrollStatusPage('Estate/Payroll/Runs.vue')))->toBe(1)
        ->and(preg_match('/<template #cell-status="\{ row: run \}">\s*(?:<!--.*?-->\s*)?<Link\s+:href="`\/payroll\/\$\{run\.id\}`"\s+class="status-badge status-link"/s', payrollStatusPage('Gemini/Payroll/Index.vue')))->toBe(1);
});

it('opens a return due at its File question, or at the pay run it waits on', function () {
    $page = payrollStatusPage('Estate/Payroll/Filings.vue');

    expect(preg_match('/<button\s+v-if="canFile && row\.status !== \'filed\' && !row\.file_blocked"\s+type="button"\s+class="status-badge status-link"[^>]*@click="file\(row\)"/s', $page))->toBe(1)
        ->and(preg_match('/<Link\s+v-else-if="row\.status !== \'filed\' && row\.file_blocked && row\.run_slug"\s+:href="`\$\{base\}\/payroll\/runs\/\$\{row\.run_slug\}`"\s+class="status-badge status-link"/s', $page))->toBe(1);
});

it('sends every return the filings board lists with the slug of the pay run it remits for', function () {
    $service = (string) file_get_contents(dirname(__DIR__, 2).'/app/Services/Estate/Payroll.php');

    expect(str_contains($service, "'run_slug' => \$filing->run?->slug,"))->toBeTrue();
});

it('opens the filings register from a calendar entry still to be filed', function () {
    expect(preg_match('/<Link\s+v-if="row\.state !== \'filed\'"\s+:href="`\$\{base\}\/payroll\/filings`"\s+class="status-badge status-link"/s', payrollStatusPage('Estate/Payroll/Calendar.vue')))->toBe(1);
});
