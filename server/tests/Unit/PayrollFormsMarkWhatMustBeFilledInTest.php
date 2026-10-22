<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Every Payroll and HR form says which fields must be filled in (Hassam, 12 Oct, batch 1 item 3)
|--------------------------------------------------------------------------
|
| "Why the mandatory fields are not mentioned across the website." On the payroll pages of both consoles: a required
| field carries <RequiredMark /> in its label and `required` on its input, a form with typed fields opens with the one
| legend above its first field, an example sits in a placeholder that starts "e.g.", and nothing opens prefilled with
| a value as if somebody had typed it. What is required is the server's rule, read from the controller: the name, job
| title, employment type, monthly gross and consent of a new employee (pension, start date and bank details are not),
| the reason a run is sent back, and the reconciliation a first live run's approver confirms.
|
*/

function payrollFormSource(string $page): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/'.$page);
}

/** The label for an input id, and whether it carries the mark. */
function payrollLabelMarked(string $source, string $id): bool
{
    return preg_match('/<label for="'.preg_quote($id, '/').'">[^<]*<RequiredMark \/><\/label>/', $source) === 1;
}

/** Whether the element with this id is a required input, select or textarea. */
function payrollInputRequired(string $source, string $id): bool
{
    return preg_match('/<(input|select|textarea)\b[^>]*\bid="'.preg_quote($id, '/').'"[^>]*\brequired\b/s', $source) === 1;
}

it('marks the new employee form: five required fields, the legend once, examples as e.g., nothing prefilled', function () {
    $page = payrollFormSource('Estate/Payroll/Employees.vue');

    foreach (['em-name', 'em-title', 'em-type', 'em-rate'] as $id) {
        expect(payrollLabelMarked($page, $id))->toBeTrue("{$id}'s label has no RequiredMark")
            ->and(payrollInputRequired($page, $id))->toBeTrue("{$id} is not required");
    }

    // Optional on the server, so not marked.
    foreach (['em-pension', 'em-since', 'em-bank', 'em-acct', 'em-nis'] as $id) {
        expect(payrollLabelMarked($page, $id))->toBeFalse("{$id} is optional and must not be marked");
    }

    // The consent box: the server refuses without it.
    expect(preg_match('/<input v-model="form\.consent" type="checkbox" required \/>/', $page))->toBe(1)
        ->and(preg_match('/<label class="emp-consent">.*?<RequiredMark \/>.*?<\/label>/s', $page))->toBe(1)
        ->and(substr_count($page, '<RequiredMark legend />'))->toBe(1);

    preg_match_all('/placeholder="([^"]*)"/', $page, $placeholders);

    foreach ($placeholders[1] as $placeholder) {
        expect(str_starts_with($placeholder, 'e.g. '))->toBeTrue("placeholder \"{$placeholder}\" is an example without e.g.");
    }

    // The pension opened as "0", a value nobody typed; empty is none on the server.
    expect(str_contains($page, "approved_pension: '',"))->toBeTrue();
});

it('marks the reason a run is sent back, and the reconciliation an approver confirms, on both consoles', function () {
    $run = payrollFormSource('Estate/Payroll/Run.vue');
    $show = payrollFormSource('Gemini/Payroll/Show.vue');

    expect(payrollLabelMarked($run, 'changes-reason'))->toBeTrue()
        ->and(payrollInputRequired($run, 'changes-reason'))->toBeTrue()
        ->and(preg_match('/<div class="changes-box">\s*<RequiredMark legend \/>/', $run))->toBe(1)
        ->and(preg_match('/placeholder="e\.g\. [^"]+"/', $run))->toBe(1);

    foreach (['Estate run' => $run, 'Gemini run' => $show] as $name => $source) {
        expect(preg_match('/<input v-model="reconciled" type="checkbox" required \/>\s*<span>\{\{ [^}]+ \}\}<RequiredMark \/><\/span>/', $source))
            ->toBe(1, "{$name}: the reconciliation tick is not marked required");
    }
});
