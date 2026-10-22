<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\Gemini\InvoiceActions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;
use Tests\Support\SecondSession;

/*
|--------------------------------------------------------------------------
| A credit note is the amount typed, and never more than the invoice (B084)
|--------------------------------------------------------------------------
|
| TWO DEFECTS IN ONE METHOD.
|
| The amount went through a float — `(int) round((float) $amount * 100)` — so
| "100.005" became J$100.00 with no refusal: 100.005 is 100.00499… as a double.
|
| And the "never more than the invoice, less what is already credited" check
| read the credits outside the transaction, with nothing locked, and the insert
| never checked again. Two notes against one invoice at nearly the same moment
| could both pass it: one read the credits as nought, the other committed a full
| credit, and the first then took the next reference and posted a second.
|
*/

it('refuses an amount finer than a cent rather than rounding it away', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    try {
        DB::connection('mysql')->table('tenants')->insert([
            'id' => 'creditroundtest', 'name' => 'Credit Round Test Estate', 'receipt_prefix' => 'CRD',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'tenant_id' => 'creditroundtest', 'reference' => 'CRD-INV-202609', 'period' => 'Sep 2026',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'subtotal_minor' => 171_000_00,
            'total_minor' => 171_000_00, 'currency' => 'JMD', 'due_on' => '2026-10-15', 'status' => 'issued',
        ]);

        $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

        // The service refuses it, whoever calls it.
        expect(fn () => app(InvoiceActions::class)->creditNote($invoice->id, '100.005', 'Sub-cent', $director))
            ->toThrow(DomainException::class, 'cent');

        // And the screen's form is refused before the service is asked.
        $this->actingAs($director)
            ->post('/billing/invoices/'.$invoice->id.'/credit-note', ['amount' => '100.005', 'reason' => 'Sub-cent'])
            ->assertSessionHasErrors('amount');

        expect(DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->exists())->toBeFalse();

        // What a person types in dollars and cents is what is stored, to the cent.
        app(InvoiceActions::class)->creditNote($invoice->id, '100.5', 'Half a dollar', $director);
        app(InvoiceActions::class)->creditNote($invoice->id, '0.29', 'A float would make this 28 cents', $director);

        expect(DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->orderBy('id')->pluck('amount_minor')->map(intval(...))->all())
            ->toBe([100_50, 29]);
    } finally {
        DB::connection('mysql')->rollBack();
    }
});

it('never lets two credit notes against one invoice together exceed it', function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    /*
     * COMMITTED, NOT ROLLED BACK, because the whole point is a second session that
     * can see the invoice. Cleaned up in the `finally` below. The note is posted
     * against an invoice with no journal, so nothing lands in the append-only books.
     */
    DB::connection('mysql')->table('tenants')->insert([
        'id' => 'creditracetest', 'name' => 'Credit Race Test Estate', 'receipt_prefix' => 'CRC',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $invoice = Invoice::query()->create([
        'tenant_id' => 'creditracetest', 'reference' => 'CRC-INV-202609', 'period' => 'Sep 2026',
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'subtotal_minor' => 171_000_00,
        'total_minor' => 171_000_00, 'currency' => 'JMD', 'due_on' => '2026-10-15', 'status' => 'issued',
    ]);

    // No `actingAs`: an audit entry names its actor, and this account is deleted afterwards.
    $director = User::factory()->create(['status' => 'active']);

    /*
     * THE OTHER DIRECTOR. The moment this request has first read the invoice's credit
     * notes, a second request — the same service, on a second database session —
     * issues a full credit against the same invoice. If this request holds nothing,
     * the rival gets through and commits; if it holds the invoice, the rival waits.
     */
    $rivalOutcome = null;
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$rivalOutcome, &$fired, $invoice, $director): void {
        if ($fired || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'credit_notes')) {
            return;
        }

        $fired = true;

        $rivalOutcome = SecondSession::run(function () use ($invoice, $director): string {
            try {
                app(InvoiceActions::class)->creditNote($invoice->id, '171000.00', 'The other director', $director);

                return 'committed';
            } catch (QueryException) {
                return 'waited';
            }
        });
    });

    try {
        $refused = null;

        try {
            app(InvoiceActions::class)->creditNote($invoice->id, '171000.00', 'Raised in error', $director);
        } catch (DomainException $e) {
            $refused = $e;
        }

        $credited = (int) DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->sum('amount_minor');

        expect($fired)->toBeTrue()
            ->and($credited)->toBeLessThanOrEqual(171_000_00)
            // The invoice was held while this request checked and wrote, so the rival waited.
            ->and($rivalOutcome)->toBe('waited')
            ->and($refused)->toBeNull()
            ->and($credited)->toBe(171_000_00);

        // The rival, pressing again once the first has committed, is refused in words.
        expect(fn () => app(InvoiceActions::class)->creditNote($invoice->id, '171000.00', 'Again', $director))
            ->toThrow(DomainException::class, 'more than the invoice charged');
    } finally {
        DB::connection('mysql')->table('credit_notes')->where('invoice_id', $invoice->id)->delete();
        DB::connection('mysql')->table('invoices')->where('id', $invoice->id)->delete();
        DB::connection('mysql')->table('tenants')->where('id', 'creditracetest')->delete();
        $director->delete();
    }
});
