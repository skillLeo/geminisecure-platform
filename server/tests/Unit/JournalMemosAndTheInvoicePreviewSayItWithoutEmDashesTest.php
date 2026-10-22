<?php

declare(strict_types=1);

use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;

/*
|--------------------------------------------------------------------------
| No em dash in a journal's memo, or in the invoice preview's lines (Law 3; Hassam, 12 October 2026)
|--------------------------------------------------------------------------
|
| A memo is read on an account's entries, a journal and a household's statement, and the posting code wrote them with
| em dashes: "Payroll — May 2026 — net pay", "Payment received — receipt #4001", "Payment to Gate2 Contractor Ltd —
| #1031 · Barrier arm sensor", "Card refund — PI-…", "S01 remittance — September 2026". The Gemini console's invoice
| preview read "Base plan — Premium, 450 units" and "450 units × J$6,200/unit — includes …". The middot now, or a colon
| or a full stop where a sentence goes on. Copy only: what each memo says is unchanged, and a journal already written
| keeps its words (journals are immutable), so a statement still reads an older reversal's reason after " — ".
|
*/

$root = dirname(__DIR__, 2);

/*
 * The quoted strings with an em dash on one file's code lines (a comment's apostrophes read as quotes). The lone "—"
 * that stands for an empty value is let through: it waits on Law 5's decision (M53 Law 3).
 */
$offending = static fn (string $path): array => collect(explode("\n", (string) file_get_contents($root.'/'.$path)))
    ->reject(static fn (string $line): bool => (bool) preg_match('#^\s*(\*|//|/\*)#', $line))
    ->filter(static fn (string $line): bool => (bool) preg_match("/'(?!—')[^'\n]*—[^'\n]*'/u", $line))
    ->map(static fn (string $line): string => trim($line))
    ->values()
    ->all();

it('writes the payroll, remittance, supplier payment and card memos with the middot', function () use ($root, $offending): void {
    foreach (['app/Services/Estate/Payroll.php', 'app/Services/Estate/Payables.php', 'app/Services/Estate/CardGateway.php'] as $path) {
        expect($offending($path))->toBe([], $path.' carries an em dash in a quoted string');
    }

    $payroll = (string) file_get_contents($root.'/app/Services/Estate/Payroll.php');
    $payables = (string) file_get_contents($root.'/app/Services/Estate/Payables.php');
    $card = (string) file_get_contents($root.'/app/Services/Estate/CardGateway.php');

    expect(str_contains($payroll, "\$memo = 'Payroll · '.\$locked->period_label;"))->toBeTrue()
        ->and(str_contains($payroll, "\$memo.' · net pay'"))->toBeTrue()
        ->and(str_contains($payroll, "' remittance · '"))->toBeTrue()
        ->and(str_contains($payables, "'Payment to '.\$vendor->name.' · '"))->toBeTrue()
        ->and(str_contains($payables, "\$bill->vendor->name.' · '.\$bill->referenceLabel()"))->toBeTrue()
        ->and(str_contains($payables, "'account' => \$reconciliation->account->optionLabel(),"))->toBeTrue()
        ->and(str_contains($card, "'Card payout · '"))->toBeTrue()
        ->and(str_contains($card, "'Card chargeback · ' : 'Card refund · '"))->toBeTrue();
});

it('writes a payment\'s memo with the middot', function () use ($root): void {
    $dues = (string) file_get_contents($root.'/app/Services/Estate/Dues.php');

    expect(str_contains($dues, "'Payment received — receipt '"))->toBeFalse()
        ->and(str_contains($dues, "(no money) — receipt '"))->toBeFalse()
        ->and(substr_count($dues, "'Payment received · receipt '"))->toBe(2)
        ->and(str_contains($dues, "'DEMONSTRATION card payment (no money) · receipt '"))->toBeTrue();
});

it('writes a reversal\'s reason after a colon, and a statement still reads one written before', function () use ($root): void {
    $command = (string) file_get_contents($root.'/app/Console/Commands/LedgerReverseEntries.php');

    expect(str_contains($command, "' ('.\$entry['what'].'): '.\$reason"))->toBeTrue()
        ->and(str_contains($command, "') — '.\$reason"))->toBeFalse()
        ->and(Dues::statementDescription('Maintenance fee October 2026', 'Reversal of JRN-2026-10-0001 (Maintenance fee): Billed twice', Ledger::SOURCE_REVERSAL))
        ->toBe('Reversal: Maintenance fee October 2026 (Billed twice)')
        ->and(Dues::statementDescription('Maintenance fee October 2026', 'Reversal of JRN-2026-09-0001 (Maintenance fee) — Billed twice', Ledger::SOURCE_REVERSAL))
        ->toBe('Reversal: Maintenance fee October 2026 (Billed twice)');
});

it('draws the invoice preview\'s lines, and says what an override does, without an em dash', function () use ($root, $offending): void {
    $settings = (string) file_get_contents($root.'/app/Services/Gemini/PlatformSettings.php');

    expect($offending('app/Services/Gemini/PlatformSettings.php'))->toBe([], 'PlatformSettings.php carries an em dash in a quoted string')
        ->and(substr_count($settings, "'Base plan · '"))->toBe(2)
        ->and(substr_count($settings, "'/unit · includes '"))->toBe(2)
        ->and(str_contains($settings, 'at the full monthly amount. An override is billed in full'))->toBeTrue();
});
