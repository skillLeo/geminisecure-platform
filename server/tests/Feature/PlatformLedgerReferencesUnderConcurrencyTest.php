<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\SecondSession;

/*
|--------------------------------------------------------------------------
| Two postings in one month never fight over a reference (B085)
|--------------------------------------------------------------------------
|
| Gemini's journal references run per month — INV-2031-03-0001, -0002 — and
| the next one used to be read from `platform_journals` and retried on a
| collision. But both callers post INSIDE a transaction of their own (the
| invoice and its lines; the credit note), and under REPEATABLE READ that
| transaction's snapshot does not move: every retry read the same stale "last
| reference", chose the same one again, and the operator got a 500 on "Raise
| and post" whenever another operator had posted in the same month since the
| transaction began.
|
| The reference now comes from a counter row the posting takes a lock on, which
| reads the latest committed number whatever the caller's snapshot says.
|
| The rival's entry is COMMITTED — that is the race — so each run leaves one
| balanced entry in March 2031 behind, in a month nothing else posts in. The
| append-only books cannot be cleaned, and are not meant to be.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

/** The four-digit sequence number at the end of a reference. */
function sequenceOf(string $reference): int
{
    return (int) substr($reference, strrpos($reference, '-') + 1);
}

it('posts after a rival committed an entry in the same month since the caller\'s transaction began', function () {
    $on = Carbon::parse('2031-03-15');
    $ledger = app(PlatformLedger::class);

    DB::connection('mysql')->beginTransaction();

    try {
        // The caller has already read the books, as a raise has by the time it posts,
        // so its snapshot is older than the rival's entry below.
        DB::connection('mysql')->table('platform_journals')->where('reference', 'like', 'INV-2031-03-%')->count();

        $rival = SecondSession::run(fn (): string => app(PlatformLedger::class)->invoiceRaised(
            'ledgerracerival', 1, 'LRR-INV-203103', 100_00, 'JMD', $on, null,
        ));

        $ours = $ledger->invoiceRaised('ledgerraceours', 2, 'LRO-INV-203103', 200_00, 'JMD', $on, null);

        expect($rival)->toStartWith('INV-2031-03-')
            ->and($ours)->toStartWith('INV-2031-03-')
            ->and(sequenceOf($ours))->toBe(sequenceOf($rival) + 1)
            ->and($ledger->receivableFor('ledgerraceours'))->toBe(200_00);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});

it('raises an invoice while another operator posts in the same month, rather than answering 500', function () {
    $this->travelTo(Carbon::parse('2031-03-15 10:00:00', 'America/Jamaica'));

    DB::connection('mysql')->beginTransaction();

    try {
        DB::connection('mysql')->table('tenants')->insert([
            'id' => 'ledgerraceclient', 'name' => 'Ledger Race Test Estate', 'receipt_prefix' => 'LRC',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::query()->create([
            'key' => 'ledgerracetest', 'name' => 'Ledger Race', 'price_per_unit_minor' => 300_00,
            'currency' => 'JMD', 'min_units' => 1, 'is_active' => true, 'sort' => 94,
        ]);

        Subscription::query()->create([
            'tenant_id' => 'ledgerraceclient', 'plan_id' => $plan->id, 'unit_count' => 10,
            'contracted_guards' => 0, 'status' => 'active', 'started_on' => '2031-03-01',
        ]);

        DB::connection('mysql')->table('platform_journals')->where('reference', 'like', 'INV-2031-03-%')->count();

        // The other operator's client, raised and committed a moment ago.
        $rival = SecondSession::run(fn (): string => app(PlatformLedger::class)->invoiceRaised(
            'ledgerracerival', 3, 'LRR-INV-203103', 100_00, 'JMD', Carbon::parse('2031-03-15'), null,
        ));

        $invoice = app(InvoiceRun::class)->raise('ledgerraceclient', FacilitiesFixture::geminiViewer(Role::DIRECTOR));

        expect($invoice->journal_ref)->toStartWith('INV-2031-03-')
            ->and(sequenceOf((string) $invoice->journal_ref))->toBe(sequenceOf($rival) + 1)
            ->and(app(PlatformLedger::class)->receivableFor('ledgerraceclient'))->toBe(3_000_00);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});

it('numbers a month\'s entries one after another, and a rolled-back posting gives its number back', function () {
    $on = Carbon::parse('2031-04-15');
    $ledger = app(PlatformLedger::class);

    DB::connection('mysql')->beginTransaction();

    try {
        $first = $ledger->creditNoteIssued('ledgersequencetest', 1, 'LST-CN1', 100, 'JMD', $on, null);
        $second = $ledger->creditNoteIssued('ledgersequencetest', 2, 'LST-CN2', 100, 'JMD', $on, null);

        expect($first)->toStartWith('CN-2031-04-')
            ->and(sequenceOf($second))->toBe(sequenceOf($first) + 1);

        $before = sequenceOf($first);
    } finally {
        DB::connection('mysql')->rollBack();
    }

    // Rolled back: nobody else took those numbers, and the next posting gets the first of them again.
    DB::connection('mysql')->beginTransaction();

    try {
        expect(sequenceOf($ledger->creditNoteIssued('ledgersequencetest', 3, 'LST-CN3', 100, 'JMD', $on, null)))->toBe($before);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});
