<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Estate\PayrollRun;
use App\Models\Estate\PayrollRunLine;
use Illuminate\Support\Collection;

/**
 * How a pay run leaves this system — the adapter the ruling asked for (12 §1).
 *
 * "Payroll export XLSX + bank CSV behind a `PaymentGateway`-style adapter." The
 * shape is deliberate and it is the shape D-023 already established for card
 * capture: an interface with a neutral implementation today, so the day an
 * estate's bank sends its own bulk-credit spec, a class is added here and
 * nothing that calls it changes.
 *
 * THE TWO FORMATS ARE DIFFERENT DOCUMENTS, which is what the old inert reason
 * said: "a payslip file for a bank and a summary for an accountant are
 * different documents". One is an instruction to move money and carries account
 * numbers; the other is a record of what was paid and carries deductions. They
 * are not two views of one file and a single "export" button would have had to
 * guess which somebody meant.
 */
interface PayrollFileFormat
{
    /** The key the screen passes back — stable, and what the route validates against. */
    public function key(): string;

    /** What the reader chooses, in words that say what the file is for. */
    public function label(): string;

    /** The sentence under the label: what is in it, and what it is not. */
    public function description(): string;

    public function contentType(): string;

    public function filename(PayrollRun $run): string;

    /**
     * Build the file.
     *
     * @param  Collection<int, PayrollRunLine>  $lines
     */
    public function build(PayrollRun $run, Collection $lines): string;
}
