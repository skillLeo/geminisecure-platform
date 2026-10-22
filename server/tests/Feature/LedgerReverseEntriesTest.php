<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Charge;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Entries that should never have been made, reversed — M34 §2
|--------------------------------------------------------------------------
|
| Phoenix Park Village 1, 4 October: a whole-phase dues charge posted while the system was being explored (400 charges
| of J$500 for March 2026), beside three test charges, ten test payments and a deploy gate's probe. A posted entry
| cannot be edited or deleted, so `ledger:reverse-entries` posts each one's mirror — dated the day the entry was, so
| every month reads as if it had never been made — marks each charge and payment reversed, and audits the lot. Dry run
| unless --confirm; all of it or none; and it refuses the sample, a run's shared entry and a gateway card payment.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** Bare lots of this file's own, numbered so no other file asks for them. @return list<Unit> */
function m34Lots(int $count = 3): array
{
    $base = 8_340_000 + random_int(0, 99_999) * 5;

    return array_map(static fn (int $i): Unit => FacilitiesFixture::scratchUnit('Lot '.($base + $i)), range(0, $count - 1));
}

/** A lot's receivable, read straight from its 1200 lines. */
function m34Owed(Unit $unit): int
{
    return (int) DB::connection('tenant')->table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', '1200')->where('journal_lines.unit_id', $unit->id)->sum(DB::raw('journal_lines.debit_minor - journal_lines.credit_minor'));
}

/**
 * Production's four kinds of test entry, on bare lots: a bulk post, a charge alone, two payments (one a test card)
 * and a hand-posted journal.
 *
 * @return array{lots: list<Unit>, bulk: list<Charge>, charge: Charge, bank: Payment, card: Payment, manual: Journal}
 */
function m34Books(): array
{
    $lots = m34Lots();
    $by = FacilitiesFixture::viewer(Role::TREASURER);
    FacilitiesFixture::boot();
    $dues = app(Dues::class);
    $description = 'Yearly charges '.Str::lower(Str::random(6));

    $dues->chargeMany(array_map(static fn (Unit $u): int => (int) $u->id, $lots), Money::of(500, 'JMD'), $description, '2026-03-22', 'dues', '4000', $by, Str::random(20));
    $bulk = Charge::query()->where('description', $description)->orderBy('id')->get()->all();

    $charge = $dues->charge($lots[0], Money::of(15_000, 'JMD'), 'Perimeter lighting test', '2026-10-23', 'special_assessment', '4100', null, $by);
    $bank = $dues->receive($lots[0], Money::of(25_000, 'JMD'), Payment::BANK, '2026-09-23', '1000', $by);
    $card = $dues->receive($lots[1], Money::of(6_200, 'JMD'), Payment::CARD, '2026-09-27', '1090', null, 'DEMO-M34', null, 'Demonstration card payment · Visa •••• 4242', true);
    $manual = m34Probe('2026-09-23');

    return ['lots' => $lots, 'bulk' => $bulk, 'charge' => $charge, 'bank' => $bank, 'card' => $card, 'manual' => $manual];
}

/**
 * The deploy gate's probe as production holds it: Dr 1000, Cr 1010, J$100, written straight into the tables by the
 * gate, which left 1010 at -$100. Through the ledger it is refused now (EST-B-02: a reserve cannot go below nothing);
 * the command still has to reverse the one production has.
 */
function m34Probe(string $on): Journal
{
    $reference = 'GATE-PROBE-'.Str::upper(Str::random(8));
    $account = static fn (string $code): int => (int) DB::connection('tenant')->table('accounts')->where('code', $code)->value('id');

    DB::connection('tenant')->table('journal_lines')->insert([
        ['entry_ref' => $reference, 'account_id' => $account('1000'), 'line_no' => 1, 'debit_minor' => 100_00, 'credit_minor' => 0, 'currency' => 'JMD', 'memo' => 'gate probe test', 'created_at' => now()],
        ['entry_ref' => $reference, 'account_id' => $account('1010'), 'line_no' => 2, 'debit_minor' => 0, 'credit_minor' => 100_00, 'currency' => 'JMD', 'memo' => 'gate probe test', 'created_at' => now()],
    ]);
    DB::connection('tenant')->table('journals')->insert([
        'reference' => $reference, 'memo' => 'gate probe test', 'source' => Ledger::SOURCE_MANUAL, 'amount_minor' => 100_00,
        'currency' => 'JMD', 'posted_on' => $on, 'created_at' => now(),
    ]);

    return Journal::query()->where('reference', $reference)->sole();
}

/** @param  array{bulk: list<Charge>, charge: Charge, bank: Payment, card: Payment, manual: Journal}  $books */
function m34Named(array $books, array $more = []): array
{
    return [
        '--estate' => FacilitiesFixture::ESTATE,
        '--bulk-of' => [$books['bulk'][0]->reference],
        '--charge' => [$books['charge']->reference],
        '--receipt' => [$books['bank']->receipt_no.','.$books['card']->receipt_no],
        '--journal' => [$books['manual']->reference],
        ...$more,
    ];
}

function m34Reversals(): int
{
    return Journal::query()->where('source', Ledger::SOURCE_REVERSAL)->count();
}

it('prints every entry and what the books would read, and writes nothing without --confirm', function () {
    $books = m34Books();
    $before = m34Reversals();
    $owed = array_map(m34Owed(...), $books['lots']);

    $this->artisan('ledger:reverse-entries', m34Named($books))
        ->expectsOutputToContain('What --confirm would reverse at')
        ->expectsOutputToContain('THE BULK POST THAT RAISED '.$books['bulk'][0]->reference.': 3 charges')
        ->expectsOutputToContain('CHARGES, ONE BY ONE: 1')
        ->expectsOutputToContain('PAYMENTS: 2')
        ->expectsOutputToContain('HAND-POSTED JOURNALS: 1')
        ->expectsOutputToContain('WHAT THE ACCOUNTS WOULD READ')
        ->expectsOutputToContain('THE PROFIT & LOSS, BY THE MONTH EACH ENTRY IS DATED')
        ->expectsOutputToContain('THE DUES PAGE\'S TOTAL OUTSTANDING')
        ->expectsOutputToContain('7 reversals would be posted. Nothing is written without --confirm.')
        ->assertSuccessful();

    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before)
        ->and(array_map(m34Owed(...), $books['lots']))->toBe($owed)
        ->and(Charge::query()->whereIn('id', array_map(static fn (Charge $c): int => $c->id, $books['bulk']))->where('status', 'reversed')->exists())->toBeFalse()
        ->and($books['bank']->fresh()->status)->toBe('recorded');
});

it('reverses every one with --confirm: mirrors dated the day each entry was, statuses reversed, the lots back to nothing, audited', function () {
    $books = m34Books();
    $before = m34Reversals();

    $this->artisan('ledger:reverse-entries', m34Named($books, ['--reason' => 'Entered while testing; not real', '--confirm' => true]))
        ->expectsOutputToContain('Reversing at')
        ->expectsOutputToContain('Done: 7 reversals posted')
        ->expectsOutputToContain('4 charges and 2 payments now read reversed')
        ->expectsOutputToContain('The ledger still balances')
        ->assertSuccessful();

    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before + 7);

    foreach ($books['lots'] as $lot) {
        expect(m34Owed($lot))->toBe(0, $lot->reference.' should owe nothing once every test entry is reversed');
    }

    $originals = [
        ...array_map(static fn (Charge $c): string => (string) $c->journal_ref, $books['bulk']),
        (string) $books['charge']->journal_ref, (string) $books['bank']->journal_ref, (string) $books['card']->journal_ref, $books['manual']->reference,
    ];

    foreach (Journal::query()->whereIn('reference', $originals)->get() as $original) {
        $mirror = Journal::query()->where('reverses_journal_id', $original->id)->sole();

        expect($mirror->posted_on->toDateString())->toBe($original->posted_on->toDateString())
            ->and($mirror->reference)->toStartWith('REV-'.$original->posted_on->format('Y-m').'-')
            ->and($mirror->memo)->toContain('Entered while testing; not real')
            ->and((int) $mirror->amount_minor)->toBe((int) $original->amount_minor);
    }

    expect(Charge::query()->whereIn('id', [...array_map(static fn (Charge $c): int => $c->id, $books['bulk']), $books['charge']->id])->pluck('status')->unique()->all())->toBe(['reversed'])
        ->and($books['bank']->fresh()->status)->toBe('reversed')
        ->and($books['card']->fresh()->status)->toBe('reversed');

    // The bulk post's income nets to nothing IN MARCH: each mirror is dated the day its entry was.
    $bulkRefs = array_map(static fn (Charge $c): string => (string) $c->journal_ref, $books['bulk']);
    $pairs = Journal::query()->whereIn('reference', $bulkRefs)
        ->orWhereIn('reverses_journal_id', Journal::query()->whereIn('reference', $bulkRefs)->pluck('id'))->pluck('reference');
    $march = (int) DB::connection('tenant')->table('journal_lines')
        ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
        ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.code', '4000')
        ->whereIn('journal_lines.entry_ref', $pairs)
        ->whereBetween('journals.posted_on', ['2026-03-01', '2026-03-31'])
        ->sum(DB::raw('journal_lines.credit_minor - journal_lines.debit_minor'));
    expect($pairs)->toHaveCount(6)->and($march)->toBe(0);

    $entry = AuditEntry::query()->where('action', 'ledger.entries_reversed')->where('entity_id', FacilitiesFixture::ESTATE)->latest('id')->firstOrFail();
    expect($entry->after['reason'])->toBe('Entered while testing; not real')
        ->and($entry->after['charges'])->toHaveCount(4)
        ->and($entry->after['payments'])->toBe([$books['bank']->receipt_no, $books['card']->receipt_no])
        ->and($entry->after['journals'])->toBe([$books['manual']->reference])
        ->and($entry->after['reversals'])->toHaveCount(7);

    // A second run finds them all reversed already, and does nothing.
    $this->artisan('ledger:reverse-entries', m34Named($books, ['--reason' => 'Entered while testing; not real', '--confirm' => true]))
        ->expectsOutputToContain('Nothing to reverse: every entry named is reversed already.')
        ->assertSuccessful();
    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before + 7);
});

it('leaves an entry already reversed as it is, and reverses the rest', function () {
    $books = m34Books();
    $journal = Journal::query()->where('reference', $books['card']->journal_ref)->sole();
    app(Ledger::class)->reverse($journal, 'Demonstration card payment reversed — receipt '.$books['card']->receipt_no);
    $books['card']->forceFill(['status' => 'reversed'])->save();
    $before = m34Reversals();

    $this->artisan('ledger:reverse-entries', m34Named($books, ['--reason' => 'Entered while testing; not real', '--confirm' => true]))
        ->expectsOutputToContain($books['card']->receipt_no.' is reversed already; it is left as it is.')
        ->expectsOutputToContain('Done: 6 reversals posted')
        ->assertSuccessful();

    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before + 6)
        ->and(Journal::query()->where('reverses_journal_id', $journal->id)->count())->toBe(1);
});

it('refuses the whole run, and changes nothing, when any one entry cannot be reversed here', function () {
    $books = m34Books();
    $dues = app(Dues::class);
    [$x, $y] = m34Lots(2);
    FacilitiesFixture::boot();

    // A schedule's month: one entry, two households' lines.
    $runRef = $dues->chargeRun([
        ['unit' => $x, 'amount' => Money::of(900, 'JMD'), 'description' => 'Run test'],
        ['unit' => $y, 'amount' => Money::of(900, 'JMD'), 'description' => 'Run test'],
    ], '2026-09-01', 'Run test');
    $inRun = Charge::query()->where('journal_ref', $runRef)->orderBy('id')->firstOrFail();

    // A card payment through a gateway, not a test card.
    $gateway = $dues->receive($x, Money::of(1_000, 'JMD'), Payment::CARD, '2026-09-27', '1000', null, 'pi_m34');

    // A charge the seed recorded in its range.
    $sampled = $dues->charge($y, Money::of(700, 'JMD'), 'Sampled test', '2026-09-01');
    DB::connection('mysql')->table('sample_data_ranges')->insert(['tag' => 'm34-test', 'tenant_id' => FacilitiesFixture::ESTATE, 'connection' => 'tenant', 'table_name' => 'charges', 'from_id' => $sampled->id - 1, 'to_id' => $sampled->id, 'append_only' => false, 'seeded_at' => now()]);

    $before = m34Reversals();
    $owed = m34Owed($books['lots'][0]);
    $refuse = fn (array $more, string $why) => $this->artisan('ledger:reverse-entries', m34Named($books, [...$more, '--reason' => 'Entered while testing; not real', '--confirm' => true]))
        ->expectsOutputToContain($why)
        ->expectsOutputToContain('Nothing was changed.')
        ->assertFailed();

    $refuse(['--charge' => [$books['charge']->reference, 'CHG-2099-01-9999']], '--charge=CHG-2099-01-9999: no charge carries that reference.');
    $refuse(['--charge' => [$inRun->reference]], $inRun->reference.' was posted in a run, in one entry ('.$runRef.') with 1 other. A run is reversed whole, from the Dues page.');
    $refuse(['--receipt' => [$gateway->receipt_no]], $gateway->receipt_no.' is a card payment taken through the gateway. It is refunded through the gateway, not reversed here.');
    $refuse(['--charge' => [$sampled->reference]], $sampled->reference.' is part of the demonstration\'s sample.');
    $refuse(['--journal' => [(string) $books['charge']->journal_ref]], $books['charge']->journal_ref.' was posted for a charge, not by hand.');

    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before)
        ->and($books['bank']->fresh()->status)->toBe('recorded')
        // J$500 + J$15,000 charged, J$25,000 paid: in credit by J$9,500, and still so.
        ->and($owed)->toBe(-9_500_00)
        ->and(m34Owed($books['lots'][0]))->toBe($owed);
});

it('needs an estate, something named, and a reason before it confirms', function () {
    $books = m34Books();
    $before = m34Reversals();

    $this->artisan('ledger:reverse-entries', ['--bulk-of' => [$books['bulk'][0]->reference]])->expectsOutputToContain('Say which estate')->assertExitCode(2);
    $this->artisan('ledger:reverse-entries', ['--estate' => FacilitiesFixture::ESTATE])->expectsOutputToContain('Name what to reverse')->assertExitCode(2);
    $this->artisan('ledger:reverse-entries', m34Named($books, ['--confirm' => true]))->expectsOutputToContain('Say why, in ten characters at least')->assertExitCode(2);
    $this->artisan('ledger:reverse-entries', m34Named($books, ['--reason' => 'oops', '--confirm' => true]))->expectsOutputToContain('Say why, in ten characters at least')->assertExitCode(2);

    FacilitiesFixture::boot();
    expect(m34Reversals())->toBe($before);
});
