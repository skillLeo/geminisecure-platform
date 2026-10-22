<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use App\Services\Audit\AuditLogger;
use App\Support\MoneyFormatter;
use App\Support\PlatformCalendar;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The two acts board 35 draws on an invoice — 12 §2, Wave 4.
 *
 * A RAISED INVOICE IS NEVER EDITED. `BillingController` says so and there is no
 * route that would: a correction is a credit note, which is a new posted record
 * of its own, and the invoice keeps its total, its lines and its reference.
 *
 * BOTH ACTS REACH OUTSIDE THIS CONSOLE, which is what the old reason said and
 * why they are audited: one sends a client an email, the other changes what they
 * owe. Neither is data entry.
 */
class InvoiceActions
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlatformLedger $ledger,
    ) {}

    /**
     * Who at an estate may read its Gemini invoice, and so who a resend goes to (B012).
     *
     * The estate reads the invoice under Settings → Billing, and that screen is
     * behind this permission (`routes/tenant.php`). A PERMISSION, NEVER A ROLE
     * NAME (D-139): on the seeded matrix it is the Community Super Admin, the
     * President and the Vice President, and a role an estate makes for itself
     * reaches the list by holding it.
     */
    public const RECIPIENT_PERMISSION = 'estate.settings.view';

    /**
     * Send the invoice to the estate accounts that may read it.
     *
     * WHO IT GOES TO IS DERIVED, not typed. A free-text address on this screen
     * would be one nobody could check, and an invoice going to a typo is a
     * conversation about money that never happened.
     *
     * AND ONLY TO WHO COULD OPEN IT IN THEIR OWN CONSOLE (B012). This used to
     * mail every active assignment at the estate — the Secretary, the Property
     * Manager, the Treasurer, the Admin Assistant, the estate's Head of Security,
     * and Gemini's own site-scoped Head of Security, who is assigned to the site
     * through the same table — and most of them are refused the figure it
     * carries. `InvoiceIssued` promises no figure a recipient cannot already see.
     * So a recipient is exactly who the estate's Settings → Billing screen
     * admits: an active estate-console account (Gemini staff are colleagues, not
     * the client), with an active assignment at this estate, holding
     * `RECIPIENT_PERMISSION`. When nobody qualifies the send is refused in words;
     * it never falls back to somebody who may not read the invoice.
     *
     * @return list<string> the addresses it went to
     */
    public function resend(int $invoiceId, User $by): array
    {
        $invoice = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.id', $invoiceId)
            ->select('invoices.*', 'tenants.name as estate')
            ->first();

        if ($invoice === null) {
            throw new DomainException('That invoice is not on this platform.');
        }

        if ($invoice->status === 'draft') {
            throw new DomainException('That invoice has not been issued. Sending a draft would put a figure in a client\'s hands that the platform has not committed to.');
        }

        $officers = $this->officers((string) $invoice->tenant_id);

        if ($officers->isEmpty()) {
            throw new DomainException(self::nobodyToSendTo((string) $invoice->estate));
        }

        Notification::send($officers, new InvoiceIssued(
            reference: (string) $invoice->reference,
            period: (string) $invoice->period,
            totalMinor: (int) $invoice->total_minor,
            currency: (string) $invoice->currency,
            dueOn: Carbon::parse((string) $invoice->due_on)->format('F j, Y'),
            estate: (string) $invoice->estate,
            isResend: true,
            // What is payable is the total less its credit notes (B080), and the mail says so.
            creditedMinor: $this->creditsFor($invoiceId)['credited_minor'],
        ));

        /** @var list<string> $addresses */
        $addresses = $officers->pluck('email')->all();

        DB::connection('mysql')->table('invoice_sends')->insert([
            'invoice_id' => $invoiceId,
            'recipients' => mb_substr(implode(', ', $addresses), 0, 500),
            'sent_by_id' => $by->getKey(),
            'sent_by_name' => (string) $by->name,
            'sent_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'billing.invoice_resent',
            entityType: 'Invoice',
            entityId: (string) $invoiceId,
            after: ['reference' => (string) $invoice->reference, 'recipients' => $addresses],
            tenantId: (string) $invoice->tenant_id,
        );

        return $addresses;
    }

    /**
     * Who a resend of an invoice to this estate would mail, as their addresses (DR-074, the register's Part 7).
     *
     * The invoice page names them before the press, and `resend()` mails exactly the same selection, so the
     * question and the send cannot disagree. Empty when nobody at the estate may read its billing.
     *
     * @return list<string>
     */
    public function recipientsFor(string $tenantId): array
    {
        /** @var list<string> $addresses */
        $addresses = $this->officers($tenantId)->pluck('email')->all();

        return $addresses;
    }

    /** Why a resend goes nowhere, in the same words before the press as after it. */
    public static function nobodyToSendTo(string $estate): string
    {
        return 'Nobody at '.$estate.' who may read its billing has an active account with an address, so there is nobody to send it to. '.
            'It goes only to the estate accounts that can open Settings → Billing, and never to somebody who may not read it.';
    }

    /**
     * The estate accounts that may read this estate's invoice (B012): active estate-console accounts with an address,
     * assigned here and active, holding RECIPIENT_PERMISSION. Gemini staff are colleagues, not the client.
     *
     * @return Collection<int, User>
     */
    private function officers(string $tenantId): Collection
    {
        return EstateAssignment::query()
            // Each officer's roles and direct permissions, which `can()` reads, loaded for all of
            // them at once (M11 A2) — they were two queries an officer.
            ->with(['user.roles', 'user.permissions'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get()
            ->map(static fn (EstateAssignment $assignment): User => $assignment->user)
            ->filter(static fn (User $user): bool => $user->console === Console::Estate
                && $user->status === 'active'
                && trim((string) $user->email) !== ''
                && $user->can(self::RECIPIENT_PERMISSION))
            ->unique('id')
            ->values();
    }

    /**
     * Issue a credit note against an invoice.
     *
     * NEVER MORE THAN THE INVOICE, LESS WHAT IS ALREADY CREDITED. A credit note
     * for more than was charged is money the platform is giving back that it
     * never took, and two notes that together exceed the invoice are the same
     * mistake made twice.
     *
     * THE INVOICE IS NOT TOUCHED. Its total is what was charged; what it is
     * worth now is that total less its credit notes, and every screen that
     * shows one shows both.
     *
     * THE AMOUNT IS EXACTLY WHAT WAS TYPED, TO THE CENT (B084). It is read as
     * money in the invoice's currency, and an amount finer than a cent is
     * refused rather than rounded: it used to go through a float, and
     * `(int) round((float) '100.005' * 100)` is 10000, because 100.005 is
     * 100.00499… as a double — a note stored as J$100.00 with no refusal.
     *
     * THE CHECK AND THE WRITE HAPPEN UNDER ONE LOCK ON THE INVOICE (B084). The
     * credits already issued used to be read outside the transaction with
     * nothing held, and the insert never looked again: a second note could read
     * them as nought, a first could commit a full credit, and the second then
     * posted another, crediting the invoice twice over and taking the client's
     * receivable below nothing. Now the invoice row is taken FOR UPDATE first —
     * `invoices` is a mutable table, so the lock is the application's to take —
     * and the existing notes are read, the rule applied and the next reference
     * chosen while it is held. A second note against the same invoice waits for
     * the first to commit and then sees it. The transaction reads nothing before
     * it holds the lock, so those reads come after whatever it waited for.
     *
     * @return array{reference: string, amount_minor: int}
     */
    public function creditNote(int $invoiceId, string $amount, string $reason, User $by): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why. A credit note with no stated reason is one nobody can review a year later, which is exactly what it exists to be.');
        }

        /*
         * ISSUED AND JOURNALLED ON GEMINI'S DATE (B082), not the UTC one: a note
         * issued at 8 p.m. in Kingston on 30 September is a September note, in
         * September's books, under a September reference.
         */
        $issuedOn = PlatformCalendar::today();

        [$invoice, $reference, $minor, $journal] = DB::connection('mysql')->transaction(function () use ($invoiceId, $amount, $reason, $by, $issuedOn): array {
            $invoice = DB::connection('mysql')->table('invoices')->where('id', $invoiceId)->lockForUpdate()->first();

            if ($invoice === null) {
                throw new DomainException('That invoice is not on this platform.');
            }

            if ($invoice->status === 'draft') {
                throw new DomainException('A draft invoice is corrected by raising it correctly. A credit note corrects one a client has already been given.');
            }

            $minor = $this->minorUnits($amount, (string) $invoice->currency);

            if ($minor <= 0) {
                throw new DomainException('A credit note is a positive amount. What it does to the account is what the words mean; a sign would let one be entered as a charge.');
            }

            $alreadyCredited = (int) DB::connection('mysql')
                ->table('credit_notes')
                ->where('invoice_id', $invoiceId)
                ->sum('amount_minor');

            if ($minor + $alreadyCredited > (int) $invoice->total_minor) {
                throw new DomainException(sprintf(
                    'That would credit more than the invoice charged. %s is charged, %s is already credited.',
                    MoneyFormatter::fromMinor((int) $invoice->total_minor, (string) $invoice->currency),
                    MoneyFormatter::fromMinor($alreadyCredited, (string) $invoice->currency),
                ));
            }

            $reference = $this->nextReference((string) $invoice->reference);

            $noteId = DB::connection('mysql')->table('credit_notes')->insertGetId([
                'tenant_id' => (string) $invoice->tenant_id,
                'invoice_id' => $invoiceId,
                'reference' => $reference,
                'amount_minor' => $minor,
                'currency' => (string) $invoice->currency,
                'reason' => $reason,
                'issued_by_id' => $by->getKey(),
                'issued_by_name' => (string) $by->name,
                'issued_on' => $issuedOn->toDateString(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            /*
             * POSTED ONLY AGAINST A POSTED INVOICE (13 B1). An invoice raised by
             * the run carries a journal, and its credit note reverses part of it
             * in Gemini's books: Dr 4000, Cr 1100 for this client. One raised
             * before the ledger existed was never in it, and crediting it there
             * would put a client's receivable below nothing.
             */
            if ($invoice->journal_ref === null) {
                return [$invoice, $reference, $minor, null];
            }

            $journal = $this->ledger->creditNoteIssued(
                tenantId: (string) $invoice->tenant_id,
                creditNoteId: $noteId,
                noteReference: $reference,
                amountMinor: $minor,
                currency: (string) $invoice->currency,
                on: $issuedOn,
                by: $by,
            );

            DB::connection('mysql')->table('credit_notes')->where('id', $noteId)->update(['journal_ref' => $journal]);

            return [$invoice, $reference, $minor, $journal];
        });

        $this->audit->record(
            action: 'billing.credit_note_issued',
            entityType: 'Invoice',
            entityId: (string) $invoiceId,
            after: [
                'credit_note' => $reference,
                'amount_minor' => $minor,
                'reason' => $reason,
                'invoice' => (string) $invoice->reference,
                'journal' => $journal,
            ],
            tenantId: (string) $invoice->tenant_id,
        );

        return ['reference' => $reference, 'amount_minor' => $minor];
    }

    /**
     * The credit notes against one invoice, and what it is worth now.
     *
     * @return array{notes: list<array<string, mixed>>, credited_minor: int}
     */
    public function creditsFor(int $invoiceId): array
    {
        $notes = DB::connection('mysql')
            ->table('credit_notes')
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get();

        return [
            'notes' => $notes->map(static fn (object $note): array => [
                'reference' => (string) $note->reference,
                'amount_minor' => (int) $note->amount_minor,
                'reason' => (string) $note->reason,
                'issued_on' => Carbon::parse((string) $note->issued_on)->format('M j, Y'),
                'issued_by' => (string) $note->issued_by_name,
            ])->all(),
            'credited_minor' => (int) $notes->sum('amount_minor'),
        ];
    }

    /**
     * The sends already made, so "did they get it?" has an answer.
     *
     * @return list<array<string, mixed>>
     */
    public function sendsFor(int $invoiceId): array
    {
        return DB::connection('mysql')
            ->table('invoice_sends')
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('sent_at')
            ->limit(10)
            ->get()
            ->map(static fn (object $send): array => [
                'recipients' => (string) $send->recipients,
                'by' => (string) $send->sent_by_name,
                // Stored as a UTC moment; read on Gemini's clock (M4 §3.1), so an
                // evening send is not printed as the small hours of tomorrow.
                'at' => SiteTime::fmt(PlatformCalendar::local(Carbon::parse((string) $send->sent_at)), 'M j, Y g:i A'),
            ])
            ->all();
    }

    /**
     * A typed amount as minor units of the invoice's currency, exactly (B084).
     *
     * Parsed as money, never through a float, and refused rather than rounded
     * when it is finer than the currency's smallest unit or is not a number at
     * all. The form refuses both first (`decimal:0,2`); this is the rule for
     * every caller, not only the form.
     */
    private function minorUnits(string $amount, string $currency): int
    {
        try {
            return Money::of(trim($amount), $currency)->getMinorAmount()->toInt();
        } catch (RoundingNecessaryException) {
            throw new DomainException(sprintf(
                'A credit note is an amount in dollars and cents, and %s has a fraction of a cent. It is refused rather than rounded, so the note is exactly what was typed.',
                trim($amount),
            ));
        } catch (MathException) {
            throw new DomainException('A credit note is an amount in dollars and cents, such as 1500.00.');
        }
    }

    /**
     * "INV-2026-08-004-CN1" — the invoice's own reference with a suffix.
     *
     * Derived from the invoice rather than from a sequence of its own: a credit
     * note is only ever read beside the invoice it corrects, and a reference
     * that says which one saves a lookup on every conversation about it.
     */
    private function nextReference(string $invoiceReference): string
    {
        $n = 1;

        // Past every reference the unique index holds, a retired sample credit note's too (M33 §5).
        while (Retirements::seeingEverything(static fn (): bool => DB::connection('mysql')->table('credit_notes')->where('reference', $invoiceReference.'-CN'.$n)->exists())) {
            $n++;
        }

        return $invoiceReference.'-CN'.$n;
    }
}
