<?php

declare(strict_types=1);

use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Journal;
use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\ManualJournals;
use App\Services\Estate\Payables;
use App\Services\Estate\Posting;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M84 §3.4: manual journal entry (10 October 2026)
|--------------------------------------------------------------------------
|
| The rules, every one: a draft saves at any time, balanced or not; it posts only at a difference of exactly zero; posting
| takes an explicit confirm naming the total and the number of lines; once posted it is immutable (a correction is a
| reversal); only the Treasurer and the Community Super Admin may post, and nobody else sees the button; it posts
| through the engine that exists (`Ledger::post`); and it is reachable from bank reconciliation with the bank line
| prefilled. Behind FEATURE_MANUAL_JOURNALS, off by default: off, every address answers 404.
|
| The done test (M84 §3.4): a bank fee posts, the reconciliation difference moves by exactly that amount, debits equal
| credits, and the trial balance balances before and after.
|
| NOTHING POSTED HERE STAYS. Every case that posts calls the service inside a transaction on the estate database and
| rolls it back; requests are used only where nothing is posted (the switch, the page, who may post, saving a draft),
| and the drafts and statement rows they leave are taken back after each test.
|
*/

const M84_JOURNAL_REFERENCE = 'M84-TEST';

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    config(['features.manual_journals' => true]);
    $this->madeReconciliation = null;
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    DB::connection('tenant')->table('journal_drafts')->where('reference', M84_JOURNAL_REFERENCE)->delete();

    if ($this->madeReconciliation !== null) {
        DB::connection('tenant')->table('bank_statement_lines')->where('bank_reconciliation_id', $this->madeReconciliation)->delete();
        DB::connection('tenant')->table('bank_reconciliations')->where('id', $this->madeReconciliation)->delete();
    }
});

/** Every debit in the estate's ledger less every credit: zero, or the books do not balance. */
function m84TrialBalance(): int
{
    return (int) DB::connection('tenant')->selectOne('SELECT COALESCE(SUM(debit_minor), 0) - COALESCE(SUM(credit_minor), 0) AS d FROM journal_lines')->d;
}

/**
 * A draft saved through the service, as the page saves it: lines in minor units.
 *
 * @param  list<array{account: string, debit_minor?: int, credit_minor?: int}>  $lines
 */
function m84SaveDraft(array $lines, ?int $bankLine = null): int
{
    return app(ManualJournals::class)->saveDraft(null, [
        'date' => Ledger::today()->toDateString(),
        'reference' => M84_JOURNAL_REFERENCE,
        'memo' => 'M84 manual journal test',
        'lines' => array_map(static fn (array $l): array => ['account' => $l['account'], 'debit_minor' => $l['debit_minor'] ?? 0, 'credit_minor' => $l['credit_minor'] ?? 0, 'unit' => null], $lines),
        'bank_line' => $bankLine,
    ], FacilitiesFixture::viewer(Role::TREASURER))->id;
}

/**
 * The fixture's operating account holds what a case's fee takes from it: a bank cannot go below nothing (EST-B-02).
 * Called after the case's last fixture user is made, since making one re-points the estate connection.
 */
function m84Funded(int $minor): void
{
    app(Ledger::class)->post('M84 test: the bank holds the fee', [Posting::debit('1000', $minor), Posting::credit('3000', $minor)]);
}

/** The case's body inside a transaction on the estate database, rolled back whatever happens. */
function m84RolledBack(Closure $body): void
{
    $tenant = DB::connection('tenant');
    $tenant->beginTransaction();

    try {
        $body();
    } finally {
        $tenant->rollBack();
    }
}

it('is absent while its switch is off: every address answers 404', function () {
    config(['features.manual_journals' => false]);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/manual-journals'))->assertNotFound();
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/manual-journals/new'))->assertNotFound();
    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/accounting/manual-journals'), [])->assertNotFound();

    // No tab, and bank reconciliation offers no journal.
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->doesntContain('manual-journals')));
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/reconciliation'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manualJournals', false));
});

it('is reached from the chart\'s tabs and from bank reconciliation while switched on', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tabs', fn ($tabs): bool => collect($tabs)->pluck('key')->contains('manual-journals')));
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/reconciliation'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manualJournals', true));
    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/manual-journals'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Accounting/ManualJournals')->where('canCreate', true));
});

it('saves a draft from the page at any time, balanced or not, and posts nothing', function () {
    $journals = Journal::query()->count();
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)->post(FacilitiesFixture::url('/accounting/manual-journals'), [
        'date' => Ledger::today()->toDateString(), 'reference' => M84_JOURNAL_REFERENCE, 'memo' => 'M84 manual journal test',
        'lines' => [['account' => '5910', 'debit' => '1,500.00'], ['account' => '1000', 'credit' => '1000.00']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    FacilitiesFixture::boot();
    $draft = DB::connection('tenant')->table('journal_drafts')->where('reference', M84_JOURNAL_REFERENCE)->first();
    expect($draft)->not->toBeNull()
        ->and($draft->status)->toBe('draft')
        ->and(json_decode((string) $draft->lines, true))->toHaveCount(2)
        ->and(Journal::query()->count())->toBe($journals);

    $this->actingAs($treasurer)->get(FacilitiesFixture::url('/accounting/manual-journals/'.$draft->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Accounting/ManualJournal')
            ->where('draft.difference_minor', 50000)
            ->where('canPost', true));
});

it('refuses an account that is not in the chart, or a control account, under its line', function () {
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))->post(FacilitiesFixture::url('/accounting/manual-journals'), [
        'date' => Ledger::today()->toDateString(), 'reference' => M84_JOURNAL_REFERENCE, 'memo' => 'M84 manual journal test',
        'lines' => [['account' => '9999', 'debit' => '10.00'], ['account' => '1200', 'credit' => '10.00']],
    ])->assertSessionHasErrors(['lines.0.account', 'lines.1.account']);
});

it('will not post unless the difference is exactly zero', function () {
    m84RolledBack(function () {
        $id = m84SaveDraft([['account' => '5910', 'debit_minor' => 150000], ['account' => '1000', 'credit_minor' => 100000]]);
        $journals = Journal::query()->count();

        expect(fn () => app(ManualJournals::class)->post($id, 150000, 2, FacilitiesFixture::viewer(Role::TREASURER)))
            ->toThrow(DomainException::class, 'Debits and credits differ by $500.00. A journal posts only when they are equal.');
        expect(Journal::query()->count())->toBe($journals);
    });
});

it('posts only with a confirm naming the total and the lines, through the engine, and the books still balance', function () {
    m84RolledBack(function () {
        $before = m84TrialBalance();
        $id = m84SaveDraft([['account' => '5910', 'debit_minor' => 150000], ['account' => '1000', 'credit_minor' => 150000]]);
        $journals = app(ManualJournals::class);
        $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

        expect(fn () => $journals->post($id, 100000, 2, $treasurer))->toThrow(DomainException::class, 'The confirm named');
        expect(fn () => $journals->post($id, 150000, 3, $treasurer))->toThrow(DomainException::class, 'The confirm named');
        expect(Journal::query()->where('source', Ledger::SOURCE_MANUAL)->where('source_id', $id)->exists())->toBeFalse();

        m84Funded(150000);
        $journal = $journals->post($id, 150000, 2, $treasurer);
        $lines = DB::connection('tenant')->table('journal_lines')->where('entry_ref', $journal->reference)->get();

        expect($journal->source)->toBe(Ledger::SOURCE_MANUAL)
            ->and((int) $journal->source_id)->toBe($id)
            ->and($lines)->toHaveCount(2)
            ->and((int) $lines->sum('debit_minor'))->toBe(150000)
            ->and((int) $lines->sum('credit_minor'))->toBe(150000)
            ->and(DB::connection('tenant')->table('journal_drafts')->where('id', $id)->value('journal_reference'))->toBe($journal->reference)
            ->and(m84TrialBalance())->toBe($before)
            ->and($before)->toBe(0);
    });
});

it('lets only the Treasurer and the Community Super Admin post: nobody else sees the button or may press it', function () {
    $id = m84SaveDraft([['account' => '5910', 'debit_minor' => 150000], ['account' => '1000', 'credit_minor' => 150000]]);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $journals = Journal::query()->count();

    $this->actingAs($president)->get(FacilitiesFixture::url('/accounting/manual-journals/'.$id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canPost', false));
    $this->actingAs($president)->post(FacilitiesFixture::url('/accounting/manual-journals/'.$id.'/post'), ['confirm_total' => '1500.00', 'confirm_lines' => 2])
        ->assertForbidden();
    expect(fn () => app(ManualJournals::class)->post($id, 150000, 2, $president))->toThrow(DomainException::class);

    $this->actingAs(FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN))->get(FacilitiesFixture::url('/accounting/manual-journals/'.$id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canPost', true));

    expect(Journal::query()->count())->toBe($journals);
});

it('is immutable once posted: no edit, and a second post posts nothing more', function () {
    m84RolledBack(function () {
        $id = m84SaveDraft([['account' => '5910', 'debit_minor' => 150000], ['account' => '1000', 'credit_minor' => 150000]]);
        $journals = app(ManualJournals::class);
        $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

        m84Funded(150000);
        $journals->post($id, 150000, 2, $treasurer);

        expect(fn () => $journals->post($id, 150000, 2, $treasurer))->toThrow(DomainException::class, 'already posted');
        expect(fn () => $journals->saveDraft($id, ['date' => Ledger::today()->toDateString(), 'reference' => M84_JOURNAL_REFERENCE, 'memo' => 'changed',
            'lines' => [['account' => '5910', 'debit_minor' => 100, 'credit_minor' => 0, 'unit' => null], ['account' => '1000', 'debit_minor' => 0, 'credit_minor' => 100, 'unit' => null]],
            'bank_line' => null], $treasurer))->toThrow(DomainException::class, 'already posted');
        expect(Journal::query()->where('source', Ledger::SOURCE_MANUAL)->where('source_id', $id)->count())->toBe(1);
    });
});

it('posts a bank fee from reconciliation: the line is prefilled, and on posting it is matched, so the difference moves by exactly its amount', function () {
    $bank = (int) DB::connection('tenant')->table('accounts')->where('code', '1000')->value('id');
    $reconciliation = BankReconciliation::create([
        'account_id' => $bank, 'statement_date' => Ledger::today()->endOfMonth()->toDateString(),
        'opening_minor' => 0, 'closing_minor' => -1_500_00, 'currency' => 'JMD', 'status' => BankReconciliation::OPEN,
    ]);
    $this->madeReconciliation = $reconciliation->id;
    $line = BankStatementLine::create([
        'bank_reconciliation_id' => $reconciliation->id, 'value_date' => Ledger::today()->toDateString(),
        'description' => 'BANK SERVICE CHARGE', 'amount_minor' => -1_500_00, 'currency' => 'JMD',
    ]);

    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))->get(FacilitiesFixture::url('/accounting/manual-journals/new?bank_line='.$line->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('draft.memo', 'BANK SERVICE CHARGE')
            ->where('draft.bank_line_id', $line->id)
            ->where('draft.lines.0.account', '1000')
            ->where('draft.lines.0.credit_minor', 150000));

    m84RolledBack(function () use ($reconciliation, $line) {
        $payables = app(Payables::class);
        $differenceBefore = $payables->differenceOf($reconciliation);
        $balanceBefore = m84TrialBalance();

        $id = m84SaveDraft([['account' => '1000', 'credit_minor' => 150000], ['account' => '5910', 'debit_minor' => 150000]], $line->id);
        $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

        m84Funded(150000);
        $journal = app(ManualJournals::class)->post($id, 150000, 2, $treasurer);

        expect($line->refresh()->matched_entry_ref)->toBe($journal->reference)
            ->and(abs($payables->differenceOf($reconciliation->refresh()) - $differenceBefore))->toBe(150000)
            ->and(m84TrialBalance())->toBe($balanceBefore);
    });
});
