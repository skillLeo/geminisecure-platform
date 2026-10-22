<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Estate\BillPayment;
use App\Models\Estate\Document;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Meeting;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\EstateBranding;
use App\Services\Estate\Governance;
use App\Support\SiteTime;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;

/**
 * What each kind of document actually says.
 *
 * ONE PLACE THE FACTS ARE GATHERED, and they are gathered at RENDER time from
 * the same services the screens read. A statement is a snapshot: the balance it
 * prints is the balance the ledger held the moment the worker ran, and the PDF
 * keeps it. Re-deriving it years later from live data would show a resident a
 * figure that was never on the paper they hold — which is the whole reason the
 * bytes are stored and hashed rather than the document being a view.
 *
 * AND ASKED AT REQUEST TIME TOO, of the same method (B027). `facts()` is what a
 * document would say if it were produced now; its hash is what a second press is
 * matched on, so an earlier document is handed back only while it still says it.
 *
 * THE LOGO IS EMBEDDED AS BYTES, never fetched by URL — see `EstateBranding`.
 */
class DocumentRenderer
{
    public function __construct(
        private readonly Dues $dues,
        private readonly Governance $governance,
        private readonly EstateBranding $branding,
    ) {}

    /**
     * What a document of this kind says about this subject, if it were produced now.
     *
     * EVERYTHING THE PAGE STATES AND NOTHING ABOUT THE ACT OF PRINTING IT. The lines, the balance,
     * the minutes, the tally and the letterhead are here; when it was issued, by whom and until
     * when it is kept are not, because two presses a minute apart by two officers are the same
     * statement. That split is what lets `Documents::request()` ask "would this say the same
     * thing?" (B027) by hashing this, and it is the same method the worker renders from, so the
     * question and the paper cannot drift apart.
     *
     * The logo is named by its path: every upload is stored under a new one (`EstateBranding`),
     * so a new mark is a new path, and the bytes are read only when a page is drawn.
     *
     * @param  array<string, mixed>  $payload
     * @return array{view: string, letterhead: array<string, mixed>, data: array<string, mixed>}
     */
    public function facts(string $kind, ?string $subjectId, string $title, array $payload = []): array
    {
        $setting = EstateSetting::query()->first();
        $subject = (int) $subjectId;

        [$view, $data] = match ($kind) {
            Document::STATEMENT => $this->statement($subject),
            Document::RECEIPT => $this->receipt($subject),
            Document::MINUTES, Document::AGENDA => $this->meetingPaper($kind, $subject),
            Document::ELECTION_CERTIFICATE => $this->certificate($subject, $payload),
            Document::REMITTANCE => $this->remittance($subject),
            default => throw new DomainException('No renderer for a '.$kind.'.'),
        };

        return [
            'view' => $view,
            'letterhead' => [
                'estateName' => (string) tenant()->name,
                'logoPath' => $setting?->logo_path,
                'enquiries' => $setting?->enquiries_email,
                'phone' => $setting?->enquiries_phone,
                'title' => $title,
            ],
            'data' => $data,
        ];
    }

    /**
     * The facts as one hash — what `documents.facts_sha256` holds.
     *
     * @param  array{view: string, letterhead: array<string, mixed>, data: array<string, mixed>}  $facts
     */
    public static function fingerprint(array $facts): string
    {
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The page itself: the facts, and the act of issuing them.
     *
     * @param  array{view: string, letterhead: array<string, mixed>, data: array<string, mixed>}  $facts
     */
    public function pdf(Document $document, array $facts): string
    {
        return Pdf::loadView($facts['view'], [
            'estateName' => $facts['letterhead']['estateName'],
            'logo' => $this->branding->dataUri(),
            'enquiries' => $facts['letterhead']['enquiries'],
            'phone' => $facts['letterhead']['phone'],
            // On the estate's clock (bug hunt B028): a statement printed at 3 PM in Kingston said 8:00 PM, and one at 8 PM carried tomorrow's date.
            // And naming it (M11 §4.3): the paper outlives the screen it was printed from, and says whose 3 PM it means.
            // By the clock's name where there is room (M19A §4.2): "September 30, 2026 at 8:30 PM Jamaica time".
            'issuedAt' => SiteTime::now()->format('F j, Y \a\t g:i A').' '.SiteTime::clockName(SiteTime::zone()),
            'timeZone' => SiteTime::zone(),
            'issuedBy' => $document->requested_by_name,
            'retainUntil' => $document->retain_until->format('F j, Y'),
            'title' => $document->title,
            ...$facts['data'],
        ])
            ->setPaper('a4')
            ->output();
    }

    /**
     * A unit's statement of account.
     *
     * EVERY LINE COMES FROM THE LEDGER, through the same `unitBoard()` the
     * screen reads. A statement built from a second query would be a document
     * that could disagree with the screen it was printed from, and the resident
     * holding it would be right and the office wrong.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function statement(int $unitId): array
    {
        $unit = Unit::query()->findOrFail($unitId);
        $board = $this->dues->unitBoard($unit);

        /*
         * THE WHOLE HISTORY, OLDEST FIRST. The screen shows the five most recent
         * movements newest-first, because that is what somebody glancing at an
         * account wants. A statement is read the other way — it is the account
         * from the beginning, and the running balance only makes sense read
         * downwards.
         */
        $lines = array_reverse($this->dues->statement($unit, PHP_INT_MAX));

        return ['documents.statement', [
            'unit' => $board['unit'],
            'balanceMinor' => $board['balance_minor'],
            'bucketLabel' => $board['bucket_label'],
            'strip' => $board['strip'],
            'statement' => $lines,
        ]];
    }

    /**
     * One payment's receipt — the document the resident keeps.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function receipt(int $paymentId): array
    {
        $payment = Payment::query()->with('unit')->findOrFail($paymentId);

        return ['documents.receipt', [
            'payment' => [
                'receipt_no' => $payment->receipt_no,
                'unit' => $payment->unit->reference,
                'amount_minor' => $payment->amount_minor,
                'method' => $payment->is_demonstration ? 'Card (demonstration)' : ucfirst($payment->method),
                'reference' => $payment->reference,
                // The calendar day the money arrived, read as the register reads it (bug hunt B023).
                'received_on' => $payment->receivedOn()->format('F j, Y'),
                'received_by' => $payment->received_by_name,
                // D-179: the paper says it too — a test card paid it and no money moved.
                'is_demonstration' => (bool) $payment->is_demonstration,
            ],
        ]];
    }

    /**
     * What the estate sends a supplier it has paid.
     *
     * A REMITTANCE ADVICE, NOT A RECEIPT. A receipt is issued by whoever
     * received the money, so the estate cannot issue one for a bill it paid —
     * see `Document::REMITTANCE`. This says what was paid, against which
     * invoice, on what date and by what method, which is what a supplier's
     * accounts department actually needs to clear their own ledger.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function remittance(int $billPaymentId): array
    {
        $payment = BillPayment::query()
            ->with(['bill.vendor'])
            ->findOrFail($billPaymentId);

        return ['documents.remittance', [
            'payment' => [
                'vendor' => $payment->bill->vendor->name ?? 'Supplier',
                'vendor_trn' => $payment->bill->vendor->trn ?? null,
                'invoice' => $payment->bill->referenceLabel(),
                'description' => $payment->bill->description,
                'invoice_minor' => $payment->bill->amount_minor,
                'paid_minor' => $payment->amount_minor,
                'method' => ucfirst($payment->method),
                'reference' => $payment->reference,
                'paid_on' => $payment->paid_on->format('F j, Y'),
                'paid_by' => $payment->paid_by_name,
            ],
        ]];
    }

    /**
     * A meeting's agenda or its minutes.
     *
     * MINUTES ARE ONLY ISSUED ONCE THEY EXIST. A blank minutes document would
     * be a piece of paper that looks like a record of a meeting and is not.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function meetingPaper(string $kind, int $meetingId): array
    {
        $meeting = Meeting::query()->with(['agenda', 'minutes'])->findOrFail($meetingId);

        if ($kind === Document::MINUTES && $meeting->minutes === null) {
            throw new DomainException('That meeting has no minutes recorded, so there is nothing to issue.');
        }

        return ['documents.meeting', [
            'kind' => $kind,
            'meeting' => [
                'title' => $meeting->title,
                'type' => $meeting->typeLabel(),
                'starts_at' => SiteTime::local($meeting->starts_at)->format('l, F j, Y \a\t g:i A').' '.SiteTime::clockName(SiteTime::zone()),

                /*
                 * The venue, and the link where there is no room. A hybrid
                 * meeting has both and a virtual one has only the link; a
                 * paper agenda saying "To be confirmed" for a meeting whose
                 * link is on record would send somebody looking for a hall.
                 */
                'location' => implode(' · ', array_filter([$meeting->venue, $meeting->virtual_link])),

                'agenda' => $meeting->agenda
                    ->sortBy('sort_order')
                    ->map(static fn ($item): array => [
                        'time' => $item->start_time,
                        'text' => $item->text,
                        'motion' => $item->motion_reference,
                    ])
                    ->values()
                    ->all(),

                'minutes' => $meeting->minutes?->body,
                'minutes_adopted' => $meeting->minutes?->adopted_at?->format('F j, Y'),
                'minutes_by' => $meeting->minutes?->recorded_by_name,
                'quorum' => $meeting->quorum_percent,
            ],
        ]];
    }

    /**
     * The election certificate — who was elected, on what count.
     *
     * THE FIGURES ARE THE TALLY'S, read back rather than recomputed. A
     * certificate is the document an estate files; a second arithmetic over the
     * same ballots would be a second answer to a question already decided.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function certificate(int $ballotId, array $payload): array
    {
        return ['documents.certificate', [
            'election' => $this->governance->certificateData($ballotId),
            'note' => $payload['note'] ?? null,
        ]];
    }
}
