<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Support\SiteTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A document the estate issued, and has to keep for seven years (12 §1).
 *
 * @property int $id
 * @property string $kind
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $title
 * @property string $filename
 * @property string|null $content_type
 * @property string $status
 * @property string|null $path
 * @property int|null $bytes
 * @property string|null $sha256
 * @property string|null $facts_sha256 the hash of what the document states — see `DocumentRenderer::facts()`
 * @property string|null $failure_reason
 * @property int|null $requested_by_id
 * @property string $requested_by_name
 * @property Carbon|null $issued_at
 * @property Carbon $retain_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Document extends Model
{
    /**
     * SEVEN YEARS, AND NOT CONFIGURABLE (12 §1).
     *
     * A constant rather than a setting, because a retention period that can be
     * shortened after the fact is not a retention period. It is stamped onto
     * the row at issue so that changing this constant tomorrow cannot shorten
     * the life of a document issued today.
     */
    public const RETENTION_YEARS = 7;

    public const QUEUED = 'queued';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const STATEMENT = 'statement';

    public const RECEIPT = 'receipt';

    public const MINUTES = 'minutes';

    public const AGENDA = 'agenda';

    public const ELECTION_CERTIFICATE = 'election_certificate';

    /**
     * WHAT THE ESTATE SENDS A SUPPLIER IT HAS PAID, and it is not a receipt.
     *
     * The old inert control on board 27 read "View receipt" and its reason said
     * "a payment receipt is a document the supplier keeps". That is exactly
     * right, and it is why the estate cannot issue one: a receipt is issued by
     * whoever RECEIVED the money. What the payer issues is a remittance advice
     * — "we have paid you this much, against this invoice, on this date, by
     * this method" — and that is the document this kind is.
     */
    public const REMITTANCE = 'remittance';

    /**
     * THE TWO FILES A PAY RUN LEAVES IN (13 A2). Built in the request from an
     * approved run, not queued — the bytes already exist by the time anybody
     * asks for them — and kept on the same terms as every other financial
     * document, because a bank file is the instruction that moved the money.
     */
    public const PAYROLL_SUMMARY = 'payroll_summary';

    public const PAYROLL_BANK_FILE = 'payroll_bank_file';

    /**
     * A FILE THE OFFICE PUT WITH A NOTICE (M4 §3.5) — the contractor's letter
     * under "Gate 2 closed". Kept like everything else the estate hands out: the
     * bytes on the private disk, the hash on the row. Its subject is the notice,
     * and it is only ever as open as that notice is: the console behind
     * Governance, a resident's phone only for a notice published to them
     * (`Resident\CommunityController::noticeAttachment`).
     */
    public const NOTICE_ATTACHMENT = 'notice_attachment';

    /** @var array<string, string> */
    public const KIND_LABELS = [
        self::STATEMENT => 'Statement of account',
        self::RECEIPT => 'Payment receipt',
        self::MINUTES => 'Meeting minutes',
        self::AGENDA => 'Meeting agenda',
        self::ELECTION_CERTIFICATE => 'Election certificate',
        self::REMITTANCE => 'Remittance advice',
        self::PAYROLL_SUMMARY => 'Payroll summary',
        self::PAYROLL_BANK_FILE => 'Payroll bank file',
        self::NOTICE_ATTACHMENT => 'Notice attachment',
    ];

    /**
     * WHO MAY FETCH EACH KIND — the gate the kind was asked for behind.
     *
     * A document is only as open as the record it was made from. A statement is
     * a household's financial position, so a role locked out of the ledger must
     * not reach one by guessing an id on the download route; a bank file carries
     * every account number the estate pays into.
     *
     * @var array<string, string>
     */
    public const KIND_PERMISSIONS = [
        self::STATEMENT => 'estate.dues_ledger.view',
        self::RECEIPT => 'estate.payments.view',
        self::REMITTANCE => 'estate.accounting_posting.view',
        self::MINUTES => 'estate.governance.view',
        self::AGENDA => 'estate.governance.view',
        self::ELECTION_CERTIFICATE => 'estate.governance.view',
        self::PAYROLL_SUMMARY => 'estate.payroll.export',
        self::PAYROLL_BANK_FILE => 'estate.payroll.export',
        self::NOTICE_ATTACHMENT => 'estate.governance.view',
    ];

    protected $connection = 'tenant';

    protected $fillable = [
        'kind',
        'subject_type',
        'subject_id',
        'title',
        'filename',
        'content_type',
        'status',
        'path',
        'bytes',
        'sha256',
        'facts_sha256',
        'failure_reason',
        'requested_by_id',
        'requested_by_name',
        'issued_at',
        'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'issued_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && $this->path !== null;
    }

    public function kindLabel(): string
    {
        return self::KIND_LABELS[$this->kind] ?? $this->kind;
    }

    /** Every row written before content types were recorded is a PDF. */
    public function contentType(): string
    {
        return $this->content_type ?? 'application/pdf';
    }

    /**
     * The permission that opens this document. An unknown kind opens for nobody:
     * a document nobody classified is not one to hand out by default.
     */
    public function permission(): ?string
    {
        return self::KIND_PERMISSIONS[$this->kind] ?? null;
    }

    /**
     * What the screen says about a document while it waits, or when it is here.
     *
     * A QUEUED DOCUMENT IS NOT A FAILURE AND MUST NOT READ AS ONE. Somebody who
     * pressed "Print statement" ten seconds ago and sees nothing will press it
     * again; telling them it is being made, and that pressing again gets the
     * same one, is what stops four identical statements existing.
     *
     * "WHILE NOTHING ON IT HAS CHANGED" IS PART OF THAT PROMISE (B027). A press
     * after a payment is handed a new statement, and a line saying otherwise would
     * tell the office its proof of payment is the page still showing the debt.
     */
    public function statusLine(): string
    {
        return match ($this->status) {
            /*
             * `issued_at` is a UTC column, read on the estate's clock. In the console's one form, "Issued 9 Oct 2026,
             * 7:07 PM. Kept until 9 Oct 2033." (Hassam, 12 October): it read "Oct 9, 2026 7:07 PM JA", with a zone tag
             * an estate's own paper does not need, on a meeting's issued papers and every other document's line.
             */
            self::READY => ($this->issued_at === null ? 'Issued' : 'Issued '.SiteTime::local($this->issued_at)->format('j M Y, g:i A'))
                .'. Kept until '.$this->retain_until->format('j M Y').'.',
            self::FAILED => 'Could not be produced: '.($this->failure_reason ?? 'the renderer did not answer').'. Asking again is safe: nothing was issued.',
            default => 'Being produced. It is rendered by a worker rather than while you wait, so asking again while nothing on it has changed will hand you this same document rather than making a second one.',
        };
    }
}
