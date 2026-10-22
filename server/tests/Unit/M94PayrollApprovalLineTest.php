<?php

declare(strict_types=1);

use App\Services\Estate\Payroll;

/*
|--------------------------------------------------------------------------
| M94 §1.1: a pay run says who approves it, and what would stop them, before anybody presses anything
|--------------------------------------------------------------------------
|
| Hassam, 11 October: "September cannot be approved by anybody." The two-person rule bars whoever performed a run's
| final calculation from approving it (D-233). The client's ruling (12 October 2026): only the Community Super Admin
| approves a pay run, there is no second approver, and the run page says, before anyone presses anything, who may
| approve and what would stop them, in Hassam's words, three cases:
|
|   can approve        "Only the Community Super Admin approves a pay run. Dahlia Reid can approve this one."
|   calculated it      "Dahlia Reid calculated this run." / "Whoever calculates a run cannot approve it." / "Ask Norman
|                      Case to calculate it instead, then Dahlia Reid can approve."
|   can never approve  "Only the Community Super Admin approves a pay run. That is Dahlia Reid."
|
| Never "she" or "he" about anyone (D-238): each person is named. The role is the approver role's own label and every
| name the estate's own: whoever can calculate is whoever holds the permission, named with their role.
|
| Phoenix Park's own people: Dahlia Reid the only Super Admin (1), Norman Case the Treasurer (2), and a President (4)
| who holds neither.
|
*/

/** @return array{0: array<int, array{name: string, role: string|null}>, 1: array<int, array{name: string, role: string|null}>} who approves, and who can calculate */
function m94PayrollPeople(): array
{
    $dahlia = ['name' => 'Dahlia Reid', 'role' => 'Community Super Admin'];

    return [[1 => $dahlia], [1 => $dahlia, 2 => ['name' => 'Norman Case', 'role' => 'Treasurer']]];
}

it('says who can approve the run when nobody is stopped', function () {
    [$approvers, $calculators] = m94PayrollPeople();

    // Calculated by the Treasurer: the Super Admin reads it, and so does anybody else who holds the approval.
    expect(Payroll::approvalLine(2, 'Norman Case', 1, $approvers, $calculators))
        ->toBe('Only the Community Super Admin approves a pay run. Dahlia Reid can approve this one.')
        // The run list, which names the month and has no reader.
        ->and(Payroll::approvalLine(2, 'Norman Case', null, $approvers, $calculators, 'September 2026'))
        ->toBe('Only the Community Super Admin approves a pay run. Dahlia Reid can approve September 2026.')
        // Two who hold it, one of whom calculated it: the other is named.
        ->and(Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers + [3 => ['name' => 'Marcus Hall', 'role' => 'Community Super Admin']], $calculators))
        ->toBe('Only the Community Super Admin approves a pay run. Marcus Hall can approve this one.')
        // An account with no role is named alone.
        ->and(Payroll::approvalLine(2, 'Norman Case', 1, [1 => ['name' => 'Dahlia Reid', 'role' => null]], $calculators))
        ->toBe('Only Dahlia Reid approves a pay run. Dahlia Reid can approve this one.');
});

it('says, in three short lines, that whoever calculated it cannot approve it and who can calculate it instead', function () {
    [$approvers, $calculators] = m94PayrollPeople();

    $lines = "Dahlia Reid calculated this run.\nWhoever calculates a run cannot approve it.\nAsk Norman Case, Treasurer, to calculate it instead, then Dahlia Reid can approve.";

    // To the Super Admin who calculated it.
    expect(Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers, $calculators))->toBe($lines)
        // To the run list.
        ->and(Payroll::approvalLine(1, 'Dahlia Reid', null, $approvers, $calculators, 'September 2026'))
        ->toBe("Dahlia Reid calculated September 2026.\nWhoever calculates a run cannot approve it.\nAsk Norman Case, Treasurer, to calculate it instead, then Dahlia Reid can approve.")
        // Several can calculate it: each named with their role.
        ->and(Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers, $calculators + [5 => ['name' => 'Ann Lee', 'role' => 'Property Manager']]))
        ->toBe("Dahlia Reid calculated this run.\nWhoever calculates a run cannot approve it.\nAsk Norman Case, Treasurer, or Ann Lee, Property Manager, to calculate it instead, then Dahlia Reid can approve.")
        // Nobody else can calculate it.
        ->and(Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers, $approvers))
        ->toBe("Dahlia Reid calculated this run.\nWhoever calculates a run cannot approve it.\nNobody else here can calculate a run, so Dahlia Reid cannot approve it.");
});

it('tells a role that can never approve who does, and what stops them when something does', function () {
    [$approvers, $calculators] = m94PayrollPeople();

    // The President, the Treasurer, everyone else: never a page with the table and nothing else.
    expect(Payroll::approvalLine(2, 'Norman Case', 4, $approvers, $calculators))
        ->toBe('Only the Community Super Admin approves a pay run. That is Dahlia Reid.')
        ->and(Payroll::approvalLine(2, 'Norman Case', 2, $approvers, $calculators))
        ->toBe('Only the Community Super Admin approves a pay run. That is Dahlia Reid.')
        // When the only approver calculated it, the three lines follow, so whoever can calculate it again reads it.
        ->and(Payroll::approvalLine(1, 'Dahlia Reid', 2, $approvers, $calculators))
        ->toBe("Only the Community Super Admin approves a pay run. That is Dahlia Reid.\nDahlia Reid calculated this run.\nWhoever calculates a run cannot approve it.\nAsk Norman Case, Treasurer, to calculate it instead, then Dahlia Reid can approve.")
        // Two who hold it.
        ->and(Payroll::approvalLine(2, 'Norman Case', 4, $approvers + [3 => ['name' => 'Marcus Hall', 'role' => 'Community Super Admin']], $calculators))
        ->toBe('Only the Community Super Admin approves a pay run. Those are Dahlia Reid and Marcus Hall.')
        // Nobody at the estate holds the permission at all (M93's UNREACHABLE).
        ->and(Payroll::approvalLine(2, 'Norman Case', 4, [], $calculators))
        ->toBe('Nobody at this estate can approve a pay run, so this run cannot be approved.');
});

it('warns the only approver, before they calculate, that someone else must calculate it', function () {
    [$approvers, $calculators] = m94PayrollPeople();

    expect(Payroll::calculationWarningLine(1, $approvers, $calculators))
        ->toBe('If you calculate this run, you cannot approve it, and nobody else here can. Ask Norman Case, Treasurer, to calculate it instead.')
        // The Treasurer calculating leaves the Super Admin free: nothing to warn.
        ->and(Payroll::calculationWarningLine(2, $approvers, $calculators))->toBeNull()
        // A second Super Admin: free, whatever else they did to the run (D-233), so nothing to warn.
        ->and(Payroll::calculationWarningLine(1, $approvers + [3 => ['name' => 'Marcus Hall', 'role' => 'Community Super Admin']], $calculators))->toBeNull()
        // Nobody else can calculate either.
        ->and(Payroll::calculationWarningLine(1, $approvers, $approvers))
        ->toBe('If you calculate this run, you cannot approve it, and nobody else here can. Someone else must calculate it, and nobody else here can calculate a run.');
});

it('writes none of its lines with an em dash, a pronoun, a committee or a second approver', function () {
    [$approvers, $calculators] = m94PayrollPeople();

    $lines = [
        Payroll::approvalLine(2, 'Norman Case', 1, $approvers, $calculators),
        Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers, $calculators),
        Payroll::approvalLine(1, 'Dahlia Reid', 2, $approvers, $calculators),
        Payroll::approvalLine(1, 'Dahlia Reid', null, $approvers, $calculators, 'September 2026'),
        Payroll::approvalLine(1, 'Dahlia Reid', 1, $approvers, $approvers),
        Payroll::approvalLine(2, 'Norman Case', 4, [], $calculators),
        Payroll::calculationWarningLine(1, $approvers, $calculators),
        Payroll::calculationWarningLine(1, $approvers, $approvers),
    ];

    foreach ($lines as $line) {
        $words = ' '.strtolower((string) $line).' ';

        expect($line)->toBeString()
            ->and(str_contains((string) $line, '—'))->toBeFalse()
            ->and(str_contains($words, 'second approver'))->toBeFalse()
            ->and(str_contains($words, 'committee'))->toBeFalse()
            ->and(preg_match('/\b(she|her|hers|he|him|his)\b/', $words))->toBe(0);
    }
});
