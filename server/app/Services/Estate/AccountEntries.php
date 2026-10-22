<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Journal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AN ACCOUNT OPENS TO ITS ENTRIES, AND AN ENTRY TO ITS JOURNAL (M84 §3.1). READ ONLY: nothing here writes.
 *
 * The rows are the journal lines posted to one account, in the order the books were written: the journal's date, then
 * the line. The running balance sits on the account's normal side (`Account::signedMinor()`), the same sum the chart of
 * accounts prints, so the last row of a page that reaches today reads what the chart says.
 *
 * AS AT TODAY BY DEFAULT, like the chart (an entry dated later is in no figure until its day comes). A period that
 * starts later than the first entry carries the balance brought forward in, so a running balance is never a sum of
 * only the rows on the screen.
 */
final class AccountEntries
{
    public const PER_PAGE = 50;

    /** What made a journal, in words, by its `source`. */
    public const SOURCES = [
        Ledger::SOURCE_MANUAL => 'Manual entry',
        Ledger::SOURCE_CHARGE => 'Dues charge',
        Ledger::SOURCE_PAYMENT => 'Payment received',
        Ledger::SOURCE_BILL => 'Bill approved',
        Ledger::SOURCE_BILL_PAYMENT => 'Bill paid',
        Ledger::SOURCE_PAYROLL => 'Pay run',
        Ledger::SOURCE_PAYROLL_REMITTANCE => 'Statutory remittance',
        Ledger::SOURCE_DEPOSIT_HELD => 'Amenity deposit held',
        Ledger::SOURCE_DEPOSIT_REFUND => 'Amenity deposit returned',
        Ledger::SOURCE_DEPOSIT_FORFEIT => 'Amenity deposit kept',
        Ledger::SOURCE_OPENING => 'Opening balance',
        Ledger::SOURCE_REVERSAL => 'Reversal',
        Ledger::SOURCE_ADJUSTMENT => 'Adjustment',
        Ledger::SOURCE_CARD_SETTLEMENT => 'Card settlement',
        Ledger::SOURCE_CARD_REFUND => 'Card refund',
    ];

    /**
     * One page of an account's entries between two days, with the balance brought forward and carried out.
     *
     * @return array{opening_minor: int, carried_minor: int, closing_minor: int, rows: list<array{line_id: int, date: string, reference: string, memo: string, debit_minor: int, credit_minor: int, balance_minor: int, below_zero: bool}>, page: int, last_page: int, total: int}
     */
    public function page(Account $account, ?Carbon $from, Carbon $to, int $page = 1): array
    {
        $tenant = DB::connection('tenant');
        $page = max(1, $page);

        $inRange = static function () use ($tenant, $account, $from, $to) {
            return $tenant->table('journal_lines as l')
                ->join('journals as j', 'j.reference', '=', 'l.entry_ref')
                ->where('l.account_id', $account->id)
                ->where('j.posted_on', '<=', $to->toDateString())
                ->when($from !== null, static fn ($q) => $q->where('j.posted_on', '>=', $from?->toDateString()));
        };

        // Everything before the period: the balance brought forward.
        $before = $from === null ? null : $tenant->table('journal_lines as l')
            ->join('journals as j', 'j.reference', '=', 'l.entry_ref')
            ->where('l.account_id', $account->id)
            ->where('j.posted_on', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(l.debit_minor), 0) AS d, COALESCE(SUM(l.credit_minor), 0) AS c')
            ->first();
        $opening = $before === null ? 0 : $account->signedMinor((int) $before->d, (int) $before->c);

        $totals = $inRange()->selectRaw('COUNT(*) AS n, COALESCE(SUM(l.debit_minor), 0) AS d, COALESCE(SUM(l.credit_minor), 0) AS c')->first();
        $total = (int) ($totals->n ?? 0);
        $closing = $opening + $account->signedMinor((int) ($totals->d ?? 0), (int) ($totals->c ?? 0));
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PER_PAGE;

        // The rows on earlier pages, summed, so this page's running balance starts where theirs ended.
        $carried = $opening;
        if ($offset > 0) {
            $earlier = $tenant->query()
                ->fromSub($inRange()->select('l.debit_minor', 'l.credit_minor')->orderBy('j.posted_on')->orderBy('l.id')->limit($offset), 'e')
                ->selectRaw('COALESCE(SUM(e.debit_minor), 0) AS d, COALESCE(SUM(e.credit_minor), 0) AS c')
                ->first();
            $carried += $account->signedMinor((int) ($earlier->d ?? 0), (int) ($earlier->c ?? 0));
        }

        $rows = [];
        $balance = $carried;

        foreach ($inRange()
            ->orderBy('j.posted_on')->orderBy('l.id')
            ->offset($offset)->limit(self::PER_PAGE)
            ->get(['l.id', 'l.entry_ref', 'l.debit_minor', 'l.credit_minor', 'l.memo as line_memo', 'j.memo as journal_memo', 'j.posted_on']) as $line) {
            $was = $balance;
            $balance += $account->signedMinor((int) $line->debit_minor, (int) $line->credit_minor);

            $rows[] = [
                'line_id' => (int) $line->id,
                'date' => Carbon::parse((string) $line->posted_on)->format('j M Y'),
                'reference' => (string) $line->entry_ref,
                'memo' => (string) ($line->line_memo ?? $line->journal_memo ?? ''),
                'debit_minor' => (int) $line->debit_minor,
                'credit_minor' => (int) $line->credit_minor,
                'balance_minor' => $balance,
                // The entry that took the account below zero: the one a reader of a negative balance is looking for.
                'below_zero' => $balance < 0 && $was >= 0,
            ];
        }

        return [
            'opening_minor' => $opening,
            /*
             * THE BALANCE THIS PAGE STARTS FROM (M98: every figure ties to its rows). On page one it is the opening; on a
             * later page it is the opening and every earlier page, which is where this page's running balance begins.
             * The page drew the opening on every page, so page two read "Brought forward $100.00" over a first row of
             * "$550.00".
             */
            'carried_minor' => $carried,
            'closing_minor' => $closing,
            'rows' => $rows,
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $total,
        ];
    }

    /**
     * One journal: its header, its lines with their accounts, and what made it.
     *
     * @return array{journal: array{reference: string, memo: string, date: string, posted_by: ?string, source: string, source_key: string, reverses: ?string}, lines: list<array{account: string, memo: ?string, debit_minor: int, credit_minor: int}>}
     */
    public function journal(Journal $journal): array
    {
        $lines = DB::connection('tenant')->table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.entry_ref', $journal->reference)
            ->orderBy('l.line_no')
            ->get(['a.code', 'a.name', 'l.memo', 'l.debit_minor', 'l.credit_minor']);

        return [
            'journal' => [
                'reference' => $journal->reference,
                'memo' => (string) $journal->memo,
                'date' => $journal->posted_on->format('j M Y'),
                'posted_by' => $journal->posted_by_name,
                'source' => self::SOURCES[$journal->source] ?? 'Other',
                'source_key' => (string) $journal->source,
                'reverses' => $journal->reverses_journal_id === null
                    ? null
                    : Journal::query()->whereKey($journal->reverses_journal_id)->value('reference'),
            ],
            'lines' => $lines->map(static fn (object $line): array => [
                'account' => $line->code.' '.$line->name,
                'memo' => $line->memo,
                'debit_minor' => (int) $line->debit_minor,
                'credit_minor' => (int) $line->credit_minor,
            ])->values()->all(),
        ];
    }
}
