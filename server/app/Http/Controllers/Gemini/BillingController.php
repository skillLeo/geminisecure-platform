<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Exports\Exporter;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceActions;
use App\Services\Gemini\InvoiceRun;
use App\Services\Gemini\PlatformSettings;
use App\Support\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing and subscriptions — Super Admin board screen 32.
 *
 * Gemini Security billing its client estates. Resident dues are a different
 * ledger in a different database and never appear here.
 *
 * Thin by design: every figure comes from BillingOverview, so the /api/v1
 * endpoints can serve the same numbers without either surface reimplementing
 * the money rules.
 */
class BillingController extends Controller
{
    /**
     * The invoice screen's two writes and the preview's one, each its own
     * permission since M11 B3 (D-150): they were all Billing's `update`, and
     * emailing a client, writing some of what it owes off and billing it are
     * three different powers.
     */
    private const RESEND = 'gemini.billing_subscriptions.resend_invoices';

    private const CREDIT = 'gemini.billing_subscriptions.issue_credit_notes';

    private const RAISE = 'gemini.billing_subscriptions.raise_invoices';

    /**
     * Why a reader without the act cannot use one of the invoice screen's
     * controls, naming the one permission it lacks.
     *
     * Resending an invoice sends a client an email; issuing a credit note posts
     * a financial record against their account. Both reach outside this console.
     */
    private const NO_RESEND = 'Resending an invoice emails the client, so it needs the Resend invoices permission on Billing & subscriptions. You are able to read this invoice.';

    private const NO_CREDIT = 'A credit note changes what the client owes, so it needs the Issue credit notes permission on Billing & subscriptions. You are able to read this invoice.';

    /** Why a reader without the act cannot raise the period the preview shows. */
    private const NO_RAISE = 'Raising an invoice bills the client and posts it to their account, so it needs the Raise invoices permission on Billing & subscriptions. You are able to read this preview.';

    /**
     * A tier price is not this screen's to change.
     *
     * Editing one re-prices every estate on that tier from an effective date.
     * It belongs to the package builder's approval path, not to the rate card
     * that displays it.
     */
    private const NO_PLAN_WRITE = 'Not editable here — changing a tier price re-prices every client on it, which needs an effective date and an approval path.';

    /**
     * D-023: manual recording is the day-one path.
     *
     * Card capture sits behind a PaymentGateway interface with a null
     * implementation, so there is nothing to capture into. Offering to add one
     * would be offering a capability the platform does not have.
     */
    // M98: drawn once above the table, in plain words; it named the gateway's implementation and carried an em dash.
    private const NO_PAYMENT_METHOD_WRITE = 'Adding or changing payment details here is not available yet: they are recorded by hand for now.';

    public function index(Request $request, BillingOverview $overview): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        // The search runs here, against the database, not in the browser
        // against a page of rows already sent: a term that matches an invoice
        // from two years ago has to be able to find it. Sorted and paged here
        // too, over the whole set (M32 F5).
        $table = BillingOverview::invoiceTable($request);
        ['rows' => $rows, 'table' => $meta] = $overview->invoicePage($table);

        return inertia('Gemini/Billing/Index', [
            'kpis' => $overview->kpis(),
            'rows' => $rows,
            'search' => $table->search,
            'table' => $meta,
        ]);
    }

    /**
     * One invoice — board screen super-admin-33.
     *
     * A posted record. There is no update or delete route behind this screen
     * and there must never be one: a correction to a raised invoice is a
     * credit note, which is a new posted record of its own.
     *
     * 404 rather than an empty screen for an id that does not exist. An
     * invoice reference is the kind of thing people paste from an email, and a
     * page that renders blank for a wrong one tells the reader their invoice
     * was deleted.
     */
    public function invoice(Request $request, BillingOverview $overview, InvoiceActions $actions, int $invoice): Response
    {
        $detail = $overview->invoice($invoice);

        abort_if($detail === null, 404);

        /*
         * WHO A RESEND WOULD MAIL, before anybody presses it (DR-074, the register's Part 7): the same selection the
         * resend makes, so the question names exactly who is told. Nobody: the resend's own refusal, said before the
         * press. Read only for a viewer who may resend.
         */
        $canResend = $request->user()->can(self::RESEND);
        $resendTo = $canResend ? $actions->recipientsFor((string) $detail['tenant_id']) : [];

        return inertia('Gemini/Billing/Invoice', [
            'invoice' => $detail,

            /*
             * The credit notes and the sends (12 §2, Wave 4). A raised invoice
             * is never edited, so what it is WORTH now is its total less its
             * credit notes — and both are shown, never one netted figure that
             * hides a correction. "Did they get it?" is the question a billing
             * conversation turns on, so the sends are here too.
             */
            ...$actions->creditsFor($invoice),
            'sends' => $actions->sendsFor($invoice),

            'canResend' => $canResend,
            'resendDisabledReason' => self::NO_RESEND,
            'resendTo' => $resendTo,
            'resendRefusal' => $canResend && $resendTo === [] ? InvoiceActions::nobodyToSendTo((string) $detail['client']) : null,
            'canCredit' => $request->user()->can(self::CREDIT),
            'creditDisabledReason' => self::NO_CREDIT,
        ]);
    }

    /**
     * The preview behind a "Not yet invoiced" row (12 §2, item 44).
     *
     * THE INVOICE RUN'S OWN DRAFT (B076). The lines, the total and the period
     * are the ones the raise would post — the tier and the add-on priced on the
     * period's first day, and the line items in force during the period — so
     * the confirmation's "Raise … at J$X?" is the figure that is posted. It used
     * to be board 44's reading with today's line items, which quoted a total
     * the raise did not post whenever a line item started or ended inside the
     * period.
     *
     * 404 for a client the run has no period for — no billing subscription, or
     * the demonstration estate.
     */
    public function preview(Request $request, string $tenant, PlatformSettings $settings, InvoiceRun $run): Response
    {
        try {
            ['draft' => $draft, 'refusal' => $refusal] = $run->preview($tenant);
        } catch (DomainException) {
            abort(404);
        }

        /*
         * Whether the run would raise this period today, and why not. The run's
         * own refusal, so the control and the POST cannot disagree.
         */
        $raiseBlocked = $refusal ?? ($draft['total_minor'] <= 0
            ? 'This period would total nothing or less. Check the client\'s line items before raising it.'
            : null);

        return inertia('Gemini/Billing/Preview', [
            'projection' => [
                'estate' => $draft['estate'],
                'period' => $draft['period_start']->format('F Y'),
                'due_on' => $draft['due_on']->format('M j, Y'),

                // What the confirmation quotes, sent back with the raise so it
                // posts exactly this or nothing (B076).
                'period_start' => $draft['period_start']->toDateString(),
                'total_minor' => $draft['total_minor'],
            ],
            'bill' => $settings->previewBill($draft),
            'raiseHref' => route('gemini.billing_subscriptions.invoice.raise', ['tenant' => $tenant], absolute: false),
            'canRaise' => $request->user()->can(self::RAISE),
            'raiseDisabledReason' => self::NO_RAISE,
            'raiseBlockedReason' => $raiseBlocked,
        ]);
    }

    /**
     * Raise the invoice the preview shows (13 B1). The run posts it and audits
     * it; this only turns a refusal into a sentence on the preview.
     *
     * WITH WHAT THE CONFIRMATION QUOTED (B076): the period and the total the
     * preview showed come back with the press, and the run posts exactly that
     * or refuses. A preview opened before a price changed, or before somebody
     * else raised the same period, used to post a figure or a month nobody had
     * confirmed. A raise that carries neither is refused before the run is asked.
     */
    public function raiseInvoice(Request $request, string $tenant, InvoiceRun $run): RedirectResponse
    {
        $confirmed = Validator::make($request->all(), [
            'period_start' => ['required', 'date_format:Y-m-d'],
            'total_minor' => ['required', 'integer'],
        ]);

        if ($confirmed->fails()) {
            return back()->withErrors([
                'invoice' => 'An invoice is raised from its preview, and posts only the period and total the preview quoted. '
                    .'This request carried neither, so nothing was posted. Open the preview and confirm it.',
            ]);
        }

        try {
            $invoice = $run->raise(
                $tenant,
                $request->user(),
                confirmedPeriodStart: (string) $request->input('period_start'),
                confirmedTotalMinor: (int) $request->input('total_minor'),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['invoice' => $refused->getMessage()]);
        }

        return redirect()
            ->route('gemini.billing_subscriptions.invoice', ['invoice' => $invoice->id])
            ->with('success', $invoice->reference.' raised for '.$invoice->period.' and posted to the client\'s account. It has not been sent — use Resend to send it.');
    }

    /**
     * Send the invoice to the estate accounts that may read it (12 §2, Wave 4;
     * B012 — see `InvoiceActions::resend()`).
     *
     * WHO IT GOES TO IS DERIVED. A free-text address on this screen would be
     * one nobody could check, and an invoice going to a typo is a conversation
     * about money that never happened.
     */
    public function resendInvoice(Request $request, int $invoice, InvoiceActions $actions): RedirectResponse
    {
        try {
            $addresses = $actions->resend($invoice, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['invoice' => $refused->getMessage()]);
        }

        return back()->with('success', 'Sent to '.implode(', ', $addresses).'. The send is on the record, so "did they get it?" has an answer.');
    }

    /**
     * Issue a credit note against an invoice. The invoice itself is untouched.
     *
     * Dollars and cents and nothing finer (B084): "100.005" is refused here, in
     * words, rather than stored as J$100.00. The service refuses it too.
     */
    public function creditNote(Request $request, int $invoice, InvoiceActions $actions): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        try {
            $note = $actions->creditNote($invoice, (string) $data['amount'], (string) $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $note['reference'].' issued for '.MoneyFormatter::fromMinor($note['amount_minor']).'. The invoice keeps its total — what it is worth now is that total less its credit notes.');
    }

    /**
     * The invoice as a PDF — board 35's "Download PDF".
     *
     * RENDERED ON DEMAND AND NOT STORED, which is the difference between this
     * and an estate's statement. An estate statement is a snapshot of a moving
     * balance and has to be kept as bytes; an invoice is immutable — its lines,
     * its total and its reference never change, and a credit note is a separate
     * record — so the PDF is reproducible from the row for as long as the row
     * exists. The row IS the retained record.
     *
     * It still writes the audit entry every export writes (12 §1).
     */
    public function invoicePdf(BillingOverview $overview, InvoiceActions $actions, int $invoice, Exporter $exporter): StreamedResponse
    {
        $detail = $overview->invoice($invoice);

        abort_if($detail === null, 404);

        $credits = $actions->creditsFor($invoice);

        $pdf = Pdf::loadView('documents.invoice', [
            'invoice' => $detail,
            'notes' => $credits['notes'],
            'creditedMinor' => $credits['credited_minor'],
        ])->setPaper('a4')->output();

        return $exporter->file(
            scope: 'Invoice '.$detail['reference'].' — '.$detail['client'],
            contents: $pdf,
            filename: $detail['reference'].'.pdf',
            contentType: 'application/pdf',
            rowCount: count($detail['lines']),
            tenants: [(string) $detail['client']],
        );
    }

    /**
     * The subscription tiers — board screen super-admin-34.
     *
     * A read. Editing a tier's price re-prices every estate on it, which is a
     * privileged, audited write with an effective date and belongs to the
     * package builder's own approval path rather than to the screen that
     * displays the rate card.
     */
    public function plans(BillingOverview $overview): Response
    {
        return inertia('Gemini/Billing/Plans', [
            ...$overview->plans(),
            'writeDisabledReason' => self::NO_PLAN_WRITE,
        ]);
    }

    /**
     * Every client's settlement instrument — board screen super-admin-35.
     *
     * D-023: manual recording is the day-one path and card capture sits behind
     * a PaymentGateway interface with a null implementation. So the row
     * controls are inert and say so — a screen that offered to add a card
     * would be offering a capability the platform does not have.
     */
    public function paymentMethods(Request $request, BillingOverview $overview): Response
    {
        // Every client, a page at a time, in the order its headings ask for (M32 F5).
        ['rows' => $rows, 'table' => $table] = $overview->paymentMethodsPage(BillingOverview::paymentMethodsTable($request));

        return inertia('Gemini/Billing/PaymentMethods', [
            'rows' => $rows,
            'table' => $table,
            'writeDisabledReason' => self::NO_PAYMENT_METHOD_WRITE,
        ]);
    }
}
