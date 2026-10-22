<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\MoneyFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invoice, sent to the estate accounts that may read it.
 *
 * Who those are is `InvoiceActions::resend()`'s to decide (B012): the estate
 * accounts that can open the invoice under Settings → Billing, and nobody else.
 *
 * NO FIGURES A RECIPIENT CANNOT ALREADY SEE. The mail names the reference, the
 * period, the total and the due date — the four things somebody needs to find
 * the invoice and pay it — and, once a credit note is against it, what was
 * credited and what is payable now, as the estate's billing screen shows them.
 * Nothing about the estate's own books. An email
 * is forwarded, printed and left on desks, and what it carries is what leaves
 * the building with it.
 *
 * NO LINK TO THE CONSOLE. The Gemini Console is the security company's, not the
 * client's; sending an estate officer a link they cannot open would be an email
 * that reads as a fault.
 */
class InvoiceIssued extends Notification
{
    use Queueable;

    /**
     * @param  int  $creditedMinor  the sum of the invoice's credit notes (B080). A credit
     *                              note never touches the invoice, so its total is not
     *                              what is due once one exists.
     */
    public function __construct(
        private readonly string $reference,
        private readonly string $period,
        private readonly int $totalMinor,
        private readonly string $currency,
        private readonly string $dueOn,
        private readonly string $estate,
        private readonly bool $isResend,
        private readonly int $creditedMinor = 0,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = MoneyFormatter::fromMinor($this->totalMinor, $this->currency);
        $payable = $this->totalMinor - $this->creditedMinor;

        $mail = (new MailMessage)
            ->subject(($this->isResend ? 'Copy of invoice ' : 'Invoice ').$this->reference.' — '.$this->estate)
            ->greeting('Invoice '.$this->reference)
            ->line($this->estate.' — '.$this->period.'.');

        /*
         * WHAT IS DUE IS THE TOTAL LESS ITS CREDIT NOTES (B080). An invoice
         * credited in part or in full used to be mailed as "Total due" on the
         * whole, the one figure a client pays from. So once a credit note exists
         * the mail gives all three — what was invoiced, what was credited, what is
         * payable now — the figures the estate's own billing screen shows.
         */
        if ($this->creditedMinor <= 0) {
            $mail->line('Total due: '.$total)
                ->line('Payable by '.$this->dueOn.'.');
        } else {
            $mail->line('Invoice total: '.$total)
                ->line('Credited: '.MoneyFormatter::fromMinor($this->creditedMinor, $this->currency))
                ->line($payable > 0
                    ? 'Now payable: '.MoneyFormatter::fromMinor($payable, $this->currency).', by '.$this->dueOn.'.'
                    : 'Nothing is payable on it: it has been credited in full.');
        }

        return $mail

            /*
             * Said plainly on a resend. Somebody who has already paid and is
             * sent the invoice again will otherwise ring to ask whether they
             * are being chased, and that call is the cost of not saying it.
             */
            ->lineIf($this->isResend, 'This is a copy of an invoice already issued. If it has been settled, no action is needed.')

            ->line('Gemini Security Limited will confirm receipt once payment is recorded.')
            ->salutation('Gemini Security Limited');
    }
}
