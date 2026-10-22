<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\JournalDraft;
use App\Services\Estate\Ledger;
use App\Services\Estate\ManualJournals;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Manual journal entry — M84 §3.4, behind FEATURE_MANUAL_JOURNALS. ABSENT (404) while the switch is off.
 *
 * Saving a draft is `create`, like recording a bill; posting is the Treasurer's or the Community Super Admin's alone
 * (`ManualJournals::mayPost`), and everybody else is answered 403 and shown no Post button. Every rule lives in
 * `ManualJournals`; this reads amounts as typed and hands them over.
 */
class ManualJournalController extends Controller
{
    /** The journals written by hand, newest first: drafts and posted. */
    public function index(Request $request): Response
    {
        self::switchedOn();

        return inertia('Estate/Accounting/ManualJournals', [
            'estate' => ['name' => (string) tenant()->name],
            'drafts' => JournalDraft::query()->latest('id')->limit(100)->get()->map(static fn (JournalDraft $d): array => [
                'id' => $d->id,
                'date' => $d->posted_on->format('j M Y'),
                'reference' => $d->reference,
                'memo' => $d->memo,
                'total_minor' => $d->totalMinor(),
                'difference_minor' => $d->differenceMinor(),
                'status' => $d->isPosted() ? 'Posted' : 'Draft',
                'journal_reference' => $d->journal_reference,
            ])->all(),
            'canCreate' => $request->user()->can('estate.accounting_posting.create'),
        ]);
    }

    /** A new draft: blank, or from a bank-only line on bank reconciliation (`?bank_line=`). */
    public function create(Request $request, ManualJournals $journals): Response
    {
        self::switchedOn();

        $line = $request->integer('bank_line') > 0 ? BankStatementLine::query()->find($request->integer('bank_line')) : null;

        $draft = $line === null
            ? [
                'date' => Ledger::today()->toDateString(),
                'reference' => null,
                'memo' => '',
                'lines' => [
                    ['account' => '', 'debit_minor' => 0, 'credit_minor' => 0, 'unit' => null],
                    ['account' => '', 'debit_minor' => 0, 'credit_minor' => 0, 'unit' => null],
                ],
                'bank_line_id' => null,
            ]
            : $journals->fromBankLine($line);

        return $this->editor($request, ['id' => null, 'status' => 'draft', 'journal_reference' => null, ...$draft]);
    }

    public function store(Request $request, ManualJournals $journals): RedirectResponse
    {
        self::switchedOn();

        $draft = $journals->saveDraft(null, $this->typed($request), $request->user());

        return redirect()->to($this->path('/accounting/manual-journals/'.$draft->id))->with('success', 'Draft saved.');
    }

    public function show(Request $request, JournalDraft $draft): Response
    {
        self::switchedOn();

        return $this->editor($request, [
            'id' => $draft->id,
            'status' => $draft->status,
            'journal_reference' => $draft->journal_reference,
            'date' => $draft->posted_on->toDateString(),
            'reference' => $draft->reference,
            'memo' => $draft->memo,
            'lines' => $draft->lines ?? [],
            'bank_line_id' => $draft->bank_statement_line_id,
        ]);
    }

    public function update(Request $request, JournalDraft $draft, ManualJournals $journals): RedirectResponse
    {
        self::switchedOn();

        try {
            $journals->saveDraft($draft->id, $this->typed($request), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['post' => $refused->getMessage()]);
        }

        return redirect()->to($this->path('/accounting/manual-journals/'.$draft->id))->with('success', 'Draft saved.');
    }

    /** Post, after the confirm that names the total and the number of lines. */
    public function post(Request $request, JournalDraft $draft, ManualJournals $journals): RedirectResponse
    {
        self::switchedOn();
        abort_unless(ManualJournals::mayPost($request->user()), 403, 'Posting a journal is for the Treasurer or the Community Super Admin.');

        $data = $request->validate([
            'confirm_total' => ['required', 'string'],
            'confirm_lines' => ['required', 'integer', 'min:0'],
        ]);

        $total = self::minor((string) $data['confirm_total']);

        try {
            $journal = $journals->post($draft->id, $total ?? -1, (int) $data['confirm_lines'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['post' => $refused->getMessage()]);
        }

        return redirect()->to($this->path('/accounting/manual-journals/'.$draft->id))->with('success', 'Posted as '.$journal->reference.'.');
    }

    /** @param  array<string, mixed>  $draft */
    private function editor(Request $request, array $draft): Response
    {
        $lines = $draft['lines'];
        $debits = array_sum(array_map(static fn (array $l): int => (int) $l['debit_minor'], $lines));
        $credits = array_sum(array_map(static fn (array $l): int => (int) $l['credit_minor'], $lines));

        return inertia('Estate/Accounting/ManualJournal', [
            'estate' => ['name' => (string) tenant()->name],
            'draft' => [
                ...$draft,
                'difference_minor' => $debits - $credits,
                'total_minor' => $debits,
                'line_count' => count(array_filter($lines, static fn (array $l): bool => (int) $l['debit_minor'] > 0 || (int) $l['credit_minor'] > 0)),
            ],
            // What a line may post to: open accounts that are not control accounts, by code.
            'accounts' => Account::query()->where('is_active', true)->where('is_control', false)->orderBy('code')->get(['code', 'name'])
                ->map(static fn (Account $a): array => ['code' => (string) $a->code, 'label' => $a->code.' · '.$a->name])->all(),
            'canEdit' => $draft['status'] !== JournalDraft::POSTED && $request->user()->can('estate.accounting_posting.create'),
            'canPost' => $draft['status'] !== JournalDraft::POSTED && ManualJournals::mayPost($request->user()),
            'editReason' => 'Writing a journal needs Accounting create access.',
        ]);
    }

    /**
     * The form as typed: amounts read as money ("1,500.00"), a refusal under the line whose amount is not money.
     *
     * @return array{date: string, reference: ?string, memo: string, lines: list<array{account: string, debit_minor: int, credit_minor: int, unit: ?string}>, bank_line: ?int}
     */
    private function typed(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:40'],
            'memo' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.account' => ['nullable', 'string', 'max:16'],
            'lines.*.debit' => ['nullable', 'string', 'max:20'],
            'lines.*.credit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit' => ['nullable', 'string', 'max:40'],
            'bank_line' => ['nullable', 'integer'],
        ]);

        $lines = [];
        $errors = [];

        foreach (array_values((array) $data['lines']) as $i => $line) {
            $debit = self::minor((string) ($line['debit'] ?? ''));
            $credit = self::minor((string) ($line['credit'] ?? ''));

            if ($debit === null) {
                $errors['lines.'.$i.'.debit'] = 'That does not look like an amount. Example: 1,500.00';
            }

            if ($credit === null) {
                $errors['lines.'.$i.'.credit'] = 'That does not look like an amount. Example: 1,500.00';
            }

            $lines[] = ['account' => (string) ($line['account'] ?? ''), 'debit_minor' => $debit ?? 0, 'credit_minor' => $credit ?? 0, 'unit' => $line['unit'] ?? null];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'date' => (string) $data['date'],
            'reference' => $data['reference'] ?? null,
            'memo' => (string) $data['memo'],
            'lines' => $lines,
            'bank_line' => isset($data['bank_line']) ? (int) $data['bank_line'] : null,
        ];
    }

    /** "1,500.00" as minor units; empty is nought; null when it is not money. */
    private static function minor(string $typed): ?int
    {
        $text = str_replace([',', '$', ' '], '', trim($typed));

        if ($text === '') {
            return 0;
        }

        return preg_match('/^\d{1,11}(\.\d{1,2})?$/', $text) === 1 ? (int) round(((float) $text) * 100) : null;
    }

    private static function switchedOn(): void
    {
        abort_unless((bool) config('features.manual_journals'), 404);
    }

    private function path(string $path): string
    {
        return app()->isLocal() ? '/estate/'.tenant()->getTenantKey().$path : $path;
    }
}
