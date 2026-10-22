<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SiteEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message from the public site's contact form, or an application from its Apply page, to the address the page
 * gives (M14, D-194; `landing.site.enquiries_to`).
 *
 * QUEUED, so the page answers at once whatever the relay is doing; ENCRYPTED on the queue, because it carries a
 * stranger's name, address and words. REPLY-TO is the sender, so answering it answers them. The row it was made
 * from is already written, so a relay that refuses it loses nothing (and the refusal is in `mail_failures`).
 */
class SiteEnquiryReceived extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    private const LABELS = [
        'community_name' => 'Community name',
        'community_type' => 'Community type',
        'address' => 'Address',
        'city' => 'City',
        'zip_code' => 'Zip code',
        'state' => 'State',
        'country' => 'Country',
    ];

    public function __construct(public readonly SiteEnquiry $enquiry) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $e = $this->enquiry;
        $application = $e->kind === SiteEnquiry::APPLICATION;

        $mail = (new MailMessage)
            ->subject($application
                ? 'Application for a community: '.($e->details['community_name'] ?? $e->name)
                : 'Website message from '.$e->name)
            ->replyTo($e->email, $e->name)
            ->greeting($application ? 'A new application from the website' : 'A new message from the website')
            ->line('From: '.$e->name.' <'.$e->email.'>'.($e->phone ? ' · '.$e->phone : ''));

        foreach (self::LABELS as $key => $label) {
            if (($e->details[$key] ?? null) !== null && $e->details[$key] !== '') {
                $mail->line($label.': '.$e->details[$key]);
            }
        }

        return $mail
            ->line(($application ? 'Description' : 'Message').':')
            ->line($e->message)
            ->line('Reply to this email to answer them. Sent from the form on geminisl.com (enquiry #'.$e->id.').')
            ->salutation('GeminiSecure');
    }
}
