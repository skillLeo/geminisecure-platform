<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Journal;
use App\Models\Estate\JournalDraft;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MANUAL JOURNAL ENTRY (M84 §3.4), behind FEATURE_MANUAL_JOURNALS. The rules, every one:
 *
 *   1. a draft saves at any time, balanced or not;
 *   2. it posts only when debits less credits is exactly zero;
 *   3. posting takes a confirm that names the total and the number of lines, and a confirm naming anything else posts
 *      nothing;
 *   4. once posted it is never edited (the draft is frozen, the journal append-only); a correction is a reversal;
 *   5. only the Treasurer and the Community Super Admin post (the estate console has no Accountant role: the Treasurer
 *      keeps the estate's books, the reading taken on 10 October); nobody else is shown the button;
 *   6. it posts through `Ledger::post` and nothing else;
 *   7. a draft can start from a bank-only line on bank reconciliation, with the bank's side filled in, and when it
 *      posts that line is matched to it (`Payables::match`, with every check that makes), so the reconciliation's
 *      difference moves by exactly the line's amount.
 *
 * A double press cannot post twice: posting holds the draft FOR UPDATE and asks again whether it has posted.
 * A control account (1200 Dues Receivable, 2000 Accounts Payable) is refused: each moves only through its own ledger,
 * so its total keeps tying to the households and suppliers behind it.
 */
final class ManualJournals
{
    /** The roles that may post (rule 5). */
    public const POSTERS = [Role::TREASURER, Role::COMMUNITY_SUPER_ADMIN];

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Payables $payables,
    ) {}

    public static function mayPost(User $user): bool
    {
        $tenant = (string) tenant()?->getTenantKey();

        foreach (self::POSTERS as $role) {
            $ids = Role::idsStandingFor($role, $tenant);

            if ($ids !== [] && $user->roles()->whereIn('roles.id', $ids)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save a draft, new or existing, balanced or not (rule 1). A line with neither amount is left out.
     *
     * @param  array{date: string, reference: ?string, memo: string, lines: list<array{account: string, debit_minor: int, credit_minor: int, unit: ?string}>, bank_line: ?int}  $data
     *
     * @throws ValidationException a line refused, under that line
     * @throws DomainException the draft has posted
     */
    public function saveDraft(?int $id, array $data, User $by): JournalDraft
    {
        $draft = $id === null ? new JournalDraft : JournalDraft::query()->findOrFail($id);

        if ($draft->exists && $draft->isPosted()) {
            throw new DomainException('This journal is already posted and cannot be changed. Post a reversal to correct it.');
        }

        $errors = [];
        $lines = [];

        foreach ($data['lines'] as $i => $line) {
            $debit = max(0, (int) $line['debit_minor']);
            $credit = max(0, (int) $line['credit_minor']);
            $code = trim((string) $line['account']);

            if ($code === '' && $debit === 0 && $credit === 0) {
                continue;
            }

            $account = Account::query()->where('code', $code)->first();

            if ($account === null || ! $account->is_active) {
                $errors['lines.'.$i.'.account'] = 'There is no open account '.($code === '' ? 'with that code' : $code).' in the chart.';

                continue;
            }

            if ($account->is_control) {
                $errors['lines.'.$i.'.account'] = $account->code.' '.$account->name.' moves only through its own ledger, never by a manual journal.';

                continue;
            }

            if ($debit > 0 && $credit > 0) {
                $errors['lines.'.$i.'.debit'] = 'A line is a debit or a credit, not both.';

                continue;
            }

            $unit = trim((string) ($line['unit'] ?? ''));

            if ($unit !== '' && ! Unit::query()->where('reference', $unit)->exists()) {
                $errors['lines.'.$i.'.unit'] = 'There is no lot '.$unit.' in this estate.';

                continue;
            }

            $lines[] = ['account' => (string) $account->code, 'debit_minor' => $debit, 'credit_minor' => $credit, 'unit' => $unit === '' ? null : $unit];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $draft->fill([
            'posted_on' => $data['date'],
            'reference' => ($data['reference'] ?? '') === '' ? null : $data['reference'],
            'memo' => trim($data['memo']),
            'lines' => $lines,
            'bank_statement_line_id' => $data['bank_line'] ?? null,
            'status' => JournalDraft::DRAFT,
        ]);

        if (! $draft->exists) {
            $draft->created_by = (int) $by->getKey();
            $draft->created_by_name = $by->name;
        }

        $draft->save();

        return $draft;
    }

    /**
     * Post a draft (rules 2 to 7). Returns the journal the engine wrote.
     *
     * @throws DomainException whatever stops it, in one line; nothing is posted
     */
    public function post(int $id, int $confirmTotalMinor, int $confirmLines, User $by): Journal
    {
        if (! self::mayPost($by)) {
            throw new DomainException('Posting a journal is for the Treasurer or the Community Super Admin.');
        }

        return DB::connection('tenant')->transaction(function () use ($id, $confirmTotalMinor, $confirmLines, $by): Journal {
            $draft = JournalDraft::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($draft->isPosted()) {
                throw new DomainException('This journal is already posted as '.$draft->journal_reference.'. Nothing more was posted.');
            }

            $lines = $draft->lines ?? [];

            if (count($lines) < 2) {
                throw new DomainException('A journal needs at least two lines to post.');
            }

            $difference = $draft->differenceMinor();

            if ($difference !== 0) {
                throw new DomainException('Debits and credits differ by '.self::money(abs($difference)).'. A journal posts only when they are equal.');
            }

            if ($confirmTotalMinor !== $draft->totalMinor() || $confirmLines !== count($lines)) {
                throw new DomainException(sprintf(
                    'The confirm named %s on %d lines; this journal is %s on %d lines. Nothing was posted.',
                    self::money($confirmTotalMinor),
                    $confirmLines,
                    self::money($draft->totalMinor()),
                    count($lines),
                ));
            }

            $bankLine = $draft->bank_statement_line_id === null
                ? null
                : BankStatementLine::query()->whereKey($draft->bank_statement_line_id)->lockForUpdate()->first();

            if ($bankLine !== null && ($bankLine->isMatched() || $bankLine->reconciliation->isCompleted())) {
                throw new DomainException('The bank line this journal started from is already matched, or its reconciliation is completed. Nothing was posted.');
            }

            $units = Unit::query()->whereIn('reference', array_values(array_filter(array_column($lines, 'unit'))))->pluck('id', 'reference');

            $postings = array_map(
                static fn (array $l): Posting => $l['debit_minor'] > 0
                    ? Posting::debit($l['account'], (int) $l['debit_minor'], unitId: $l['unit'] === null ? null : (int) $units[$l['unit']])
                    : Posting::credit($l['account'], (int) $l['credit_minor'], unitId: $l['unit'] === null ? null : (int) $units[$l['unit']]),
                $lines,
            );

            $journal = $this->ledger->post(
                memo: $draft->memo.($draft->reference === null ? '' : ' ('.$draft->reference.')'),
                postings: $postings,
                on: $draft->posted_on,
                source: Ledger::SOURCE_MANUAL,
                sourceId: $draft->id,
                by: $by,
            );

            $draft->forceFill([
                'status' => JournalDraft::POSTED,
                'journal_reference' => $journal->reference,
                'posted_by' => (int) $by->getKey(),
                'posted_by_name' => $by->name,
                'posted_at' => now(),
            ])->save();

            if ($bankLine !== null) {
                $this->payables->match($bankLine, $journal->reference);
            }

            return $journal;
        });
    }

    /**
     * A new draft's fields from a bank-only line (rule 7): its date, its words, and the bank's own side of the entry,
     * with a second line left for the other side.
     *
     * @return array{date: string, reference: ?string, memo: string, lines: list<array{account: string, debit_minor: int, credit_minor: int, unit: ?string}>, bank_line_id: int}
     */
    public function fromBankLine(BankStatementLine $line): array
    {
        $amount = abs((int) $line->amount_minor);

        return [
            'date' => $line->value_date->toDateString(),
            'reference' => $line->bank_reference,
            'memo' => (string) $line->description,
            'lines' => [
                [
                    'account' => (string) $line->reconciliation->account->code,
                    'debit_minor' => $line->amount_minor > 0 ? $amount : 0,
                    'credit_minor' => $line->amount_minor < 0 ? $amount : 0,
                    'unit' => null,
                ],
                ['account' => '', 'debit_minor' => 0, 'credit_minor' => 0, 'unit' => null],
            ],
            'bank_line_id' => (int) $line->id,
        ];
    }

    /** "$1,500.00": two decimals, always. */
    public static function money(int $minor): string
    {
        return '$'.number_format($minor / 100, 2);
    }
}
