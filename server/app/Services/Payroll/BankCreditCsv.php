<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Estate\PayrollRun;
use App\Services\Exports\SpreadsheetCell;
use Illuminate\Support\Collection;

/**
 * The bank file: an instruction to move money into people's accounts.
 *
 * NEUTRAL, AND SAYING SO. Every Jamaican bank takes a bulk-credit upload and no
 * two of their layouts agree — NCB, Scotiabank and JN each want their own column
 * order and their own header. This is the columns every one of them needs, in
 * the order a person reading it would expect, and it is what an estate hands
 * their bank or re-saves into the bank's own template.
 *
 * WHEN A BANK'S OWN SPEC ARRIVES, it becomes a second class beside this one and
 * nothing that calls the adapter changes. That is the whole point of the
 * interface — see `PayrollFileFormat`, and D-023 for the pattern.
 *
 * AN EMPLOYEE WITH NO ACCOUNT IS IN THE FILE, with their account blank and a
 * note saying so. Dropping them would hand the bank a file that pays fewer
 * people than the run approved, and the person who notices is the one who was
 * not paid.
 */
class BankCreditCsv implements PayrollFileFormat
{
    public function key(): string
    {
        return 'bank';
    }

    public function label(): string
    {
        return 'Bank credit file (CSV)';
    }

    public function description(): string
    {
        return 'An instruction to move money: each employee\'s bank, account and NET pay. It carries no deductions and no rates: those are the accountant\'s file, not the bank\'s.';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function filename(PayrollRun $run): string
    {
        return $run->reference.'-bank-credit.csv';
    }

    public function build(PayrollRun $run, Collection $lines): string
    {
        $out = fopen('php://temp', 'r+b');

        if ($out === false) {
            return '';
        }

        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['Employee', 'Bank', 'Account number', 'Amount JMD', 'Reference', 'Note'], escape: '');

        foreach ($lines as $line) {
            $employee = $line->employee;
            $hasAccount = $employee->bank_account_number !== null && $employee->bank_name !== null;

            /*
             * NO CELL IS A FORMULA (B074). This file is built here rather than by
             * `Exporter::csv()`, and an office re-saves it into its bank's template in
             * Excel — so an employee's name that starts with `=` is neutralised here
             * exactly as it is in every other export.
             */
            fputcsv($out, SpreadsheetCell::row([
                $employee->full_name,
                $employee->bank_name ?? '',
                $employee->bank_account_number ?? '',

                /*
                 * NET, and it is the only figure in this file. A bank credits
                 * what the person receives; the deductions went to TAJ on the
                 * S01 and putting them in a payment instruction would be an
                 * instruction to pay them twice.
                 */
                number_format($line->net_minor / 100, 2, '.', ''),
                $run->reference,
                $hasAccount ? '' : 'No bank account on file: pay by another arrangement',
            ]), escape: '');
        }

        rewind($out);
        $contents = (string) stream_get_contents($out);
        fclose($out);

        return $contents;
    }
}
