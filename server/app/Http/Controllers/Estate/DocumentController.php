<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\BillPayment;
use App\Models\Estate\Document;
use App\Models\Estate\Meeting;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Services\Documents\Documents;
use App\Services\Estate\Governance;
use App\Services\Exports\Exporter;
use Closure;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking for a document, and fetching one — 12 §1.
 *
 * EVERY KIND IS GATED ON ITS OWN MODULE, not on a documents permission. A
 * statement is a household's financial position and needs Dues & ledger; a
 * receipt needs Payments; minutes and a certificate need Governance. A single
 * "documents" gate would be a way of reading any of them through one hole.
 *
 * NOTHING HERE RENDERS IN THE REQUEST. Every kind queues, hands back the row,
 * and the screen says what it is waiting for — see `Documents`.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly Documents $documents) {}

    /** A unit's statement of account — board 6's "Print statement". */
    public function statement(Request $request, Unit $unit): RedirectResponse
    {
        return $this->issue(fn (): Document => $this->documents->request(
            kind: Document::STATEMENT,
            title: 'Statement of account — '.$unit->reference,
            filename: 'statement-'.strtolower(str_replace(' ', '-', $unit->reference)).'.pdf',
            by: $request->user(),
            subjectType: 'unit',
            subjectId: (string) $unit->id,
        ));
    }

    /** One payment's receipt — board 27's "View receipt", and the register's. */
    public function receipt(Request $request, Payment $payment): RedirectResponse
    {
        return $this->issue(fn (): Document => $this->documents->request(
            kind: Document::RECEIPT,
            title: 'Receipt '.$payment->receipt_no,
            filename: 'receipt-'.strtolower($payment->receipt_no).'.pdf',
            by: $request->user(),
            subjectType: 'payment',
            subjectId: (string) $payment->id,
        ));
    }

    /**
     * The remittance advice for a bill the estate paid — board 27's row action.
     *
     * The control reads "View receipt" because the board draws it that way, and
     * what it issues is a REMITTANCE ADVICE: a receipt is issued by whoever
     * received the money, and the estate is the payer. See `Document::REMITTANCE`.
     */
    public function remittance(Request $request, BillPayment $payment): RedirectResponse
    {
        return $this->issue(fn (): Document => $this->documents->request(
            kind: Document::REMITTANCE,
            title: 'Remittance advice — '.($payment->bill->vendor->name ?? 'supplier'),
            filename: 'remittance-'.$payment->id.'.pdf',
            by: $request->user(),
            subjectType: 'bill_payment',
            subjectId: (string) $payment->id,
        ));
    }

    /** A meeting's agenda or its minutes — board 36's row action. */
    public function meetingPaper(Request $request, Meeting $meeting, string $kind): RedirectResponse
    {
        if (! in_array($kind, [Document::AGENDA, Document::MINUTES], true)) {
            return back()->withErrors(['document' => 'A meeting issues an agenda or its minutes.']);
        }

        if ($kind === Document::MINUTES && $meeting->minutes === null) {
            // A blank page dressed as the record of a meeting would mislead whoever reads it, so none is issued.
            return back()->withErrors(['document' => 'No minutes have been recorded for '.$meeting->title.' yet.']);
        }

        return $this->issue(fn (): Document => $this->documents->request(
            kind: $kind,
            // The console's separator, never an em dash (M53 Law 3); papers already issued keep their title.
            title: ucfirst($kind).' · '.$meeting->title,
            filename: $kind.'-'.$meeting->id.'.pdf',
            by: $request->user(),
            subjectType: 'meeting',
            subjectId: (string) $meeting->id,
        ));
    }

    /** The election certificate — board 11's "Export report". */
    public function certificate(Request $request, int $year, Governance $governance): RedirectResponse
    {
        $results = $governance->resultsBoard($year);

        if (($results['ballot']['id'] ?? null) === null) {
            return back()->withErrors(['document' => 'There is no ballot for '.$year.'.']);
        }

        try {
            // Asked here as well as in the renderer, so the refusal reaches the
            // reader now rather than as a failed document four seconds later.
            $governance->certificateData((int) $results['ballot']['id']);
        } catch (DomainException $refused) {
            return back()->withErrors(['document' => $refused->getMessage()]);
        }

        return $this->issue(fn (): Document => $this->documents->request(
            kind: Document::ELECTION_CERTIFICATE,
            title: 'Election certificate — '.$year,
            filename: 'election-certificate-'.$year.'.pdf',
            by: $request->user(),
            subjectType: 'ballot',
            subjectId: (string) $results['ballot']['id'],
        ));
    }

    /**
     * Fetch a document that is ready.
     *
     * STREAMED FROM THE PRIVATE DISK, never linked to. The file lives under
     * this estate's own prefix and this route is inside the estate's auth
     * group, so one estate's statements cannot be fetched from another's URL.
     *
     * GATED ON THE KIND'S OWN MODULE (13 A3). The route used to ask only that
     * the reader belonged to the estate, so a role locked out of the ledger
     * could fetch a household's statement by counting ids. Each kind now opens
     * behind the gate it was asked for behind — `Document::KIND_PERMISSIONS`.
     *
     * AND IT IS AN EXPORT, recorded before a byte is streamed.
     */
    public function download(Request $request, Document $document, Exporter $exporter): StreamedResponse
    {
        $permission = $document->permission();

        abort_if($permission === null || ! $request->user()->can($permission), 403);

        abort_unless($document->isReady(), 404);

        $path = (string) $document->path;

        abort_unless(Storage::disk('local')->exists($path), 404);

        $exporter->document($document, 'downloaded');

        return response()->stream(function () use ($path): void {
            echo Storage::disk('local')->get($path);
        }, 200, [
            'Content-Type' => $document->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$document->filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Ask, and say what came of it — the document, or why there is none.
     *
     * A REFUSAL IS SAID, NEVER A 500 (B097). `Documents::request()` refuses in
     * plain words when the document cannot be made — the queue would not take it,
     * or there is nothing yet to issue — and the reader is told that on the screen
     * they pressed on, under the same `document` key the other refusals here use.
     *
     * @param  Closure(): Document  $ask
     */
    private function issue(Closure $ask): RedirectResponse
    {
        try {
            $document = $ask();
        } catch (DomainException $refused) {
            return back()->withErrors(['document' => $refused->getMessage()]);
        }

        // The id travels in the flash, so the console's shell waits for the file and starts the download (M56 §5).
        return back()->with('success', $this->sentence($document))->with('document', [
            'id' => $document->id,
            'label' => $document->kindLabel(),
            'status_url' => $this->path('/documents/'.$document->id.'/status'),
            'download_url' => $this->path('/documents/'.$document->id),
        ]);
    }

    /** Where a screen lives, in whichever shape this environment serves: each estate's hostname, or local's path. */
    private function path(string $path): string
    {
        return app()->isLocal() ? '/estate/'.tenant()->getTenantKey().$path : $path;
    }

    /**
     * Where a document is: what the shell polls while a worker writes it (M56 §5). The document's own module gates it,
     * exactly as the download does, so a status is never a way to learn about a document one could not open.
     */
    public function status(Request $request, Document $document): JsonResponse
    {
        $permission = $document->permission();

        abort_if($permission === null || ! $request->user()->can($permission), 403);

        return response()->json([
            'id' => $document->id,
            'status' => $document->status,
            'ready' => $document->isReady(),
            'failed' => $document->status === Document::FAILED,
            'reason' => $document->status === Document::FAILED ? ($document->failure_reason ?: 'It could not be produced.') : null,
            'label' => $document->kindLabel(),
        ]);
    }

    /**
     * What to tell somebody who just pressed: one line (M53 Law 2, M56 §5), "Preparing the agenda. It will download in a
     * moment." Pressing again while nothing on it has changed hands back this same document (B027).
     */
    private function sentence(Document $document): string
    {
        $what = mb_strtolower($document->kindLabel());

        return $document->isReady()
            ? 'The '.$what.' is ready. It will download now.'
            : 'Preparing the '.$what.'. It will download in a moment.';
    }
}
