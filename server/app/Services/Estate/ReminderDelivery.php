<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\DunningNotice;
use App\Models\Estate\Resident;
use App\Notifications\ArrearsReminder;
use App\Support\Seeded\SeededRecipients;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * ARREARS REMINDERS, TAKEN OFF THE QUEUE (M84 §3.5). SENDING SHIPS OFF.
 *
 * The morning ladder (`Collections::runAutomated()`) has written a reminder onto the log every day, `queued`, and
 * nothing ever took one off: no channel was ever handed anything. This is the pipeline that does, through four states
 * on the notice itself: queued, sending, delivered, failed, the failure's reason on the row where the office reads it.
 *
 * THREE RULES, THE NIGHT'S MOST IMPORTANT.
 *
 *   1. `reminders.delivery_enabled` SHIPS FALSE. Off, a run changes nothing and sends nothing; `preview()` shows
 *      exactly what would go out and to whom.
 *   2. TURNED ON, THE OLD QUEUE IS NEVER FLUSHED. A run refuses without `reminders.deliver_from`, the moment delivery
 *      started, and marks every reminder queued before it EXPIRED, never sent. A reminder still queued a day after the
 *      ladder wrote it is expired too: the amount in it was true that morning and may not be now.
 *   3. IT IS NOT TURNED ON UNTIL EST-B-01 IS FIXED AND HASSAM SAYS SO. The amounts were wrong (a charge not due until
 *      February 2027 counted as overdue). That rule is a person's, not code's: this class cannot tell.
 *
 * BY EMAIL. A step's other channels are recorded as not sent, with why, rather than claimed: push and text reminders
 * are not built, and a letter is the office's to post. A step delivered by email and not by push says both.
 */
final class ReminderDelivery
{
    /** A reminder still queued this long after the ladder wrote it is never sent. */
    public const FRESH_FOR_HOURS = 24;

    /** How many reminders one run takes off the queue. */
    public const BATCH = 500;

    /** The preview lists this many; its count says how many there are in all. */
    public const PREVIEW_ROWS = 100;

    public const EXPIRED_DETAIL = 'Queued before delivery started; never sent.';

    public static function enabled(): bool
    {
        return (bool) config('reminders.delivery_enabled', false);
    }

    /**
     * What a run would send now, to whom and by which channel, and how many reminders it would expire. Reads only.
     *
     * @return array{enabled: bool, rows: list<array<string, mixed>>, total: int, would_expire: int}
     */
    public function preview(): array
    {
        $cutoff = $this->cutoff($this->deliverFrom());

        $queued = DunningNotice::query()->where('delivery_state', DunningNotice::QUEUED);
        $fresh = (clone $queued)->where('sent_at', '>=', $cutoff);

        $rows = (clone $fresh)->with('unit.household')->orderBy('id')->limit(self::PREVIEW_ROWS)->get()
            ->map(fn (DunningNotice $notice): array => $this->previewRow($notice))
            ->values()->all();

        return [
            'enabled' => self::enabled(),
            'rows' => $rows,
            'total' => $fresh->count(),
            'would_expire' => (clone $queued)->where('sent_at', '<', $cutoff)->count(),
        ];
    }

    /**
     * One delivery run, in the estate tenancy has open. Off: nothing changes and nothing is sent. On: the stale queue is
     * expired first, then each fresh reminder is claimed, handed to its channels, and left delivered or failed.
     *
     * @return array{enabled: bool, refused: string|null, expired: int, delivered: int, failed: int}
     */
    public function run(): array
    {
        $report = ['enabled' => self::enabled(), 'refused' => null, 'expired' => 0, 'delivered' => 0, 'failed' => 0];

        if (! $report['enabled']) {
            return $report;
        }

        $from = $this->deliverFrom();
        if ($from === null) {
            $report['refused'] = 'Delivery is on, but REMINDERS_DELIVER_FROM does not name the moment it started, so nothing was sent and nothing changed. Every reminder queued before that moment is to be expired, never sent.';

            return $report;
        }

        $cutoff = $this->cutoff($from);

        // Rule 2, before anything is sent: whatever has stood on the queue is expired, in one statement.
        $report['expired'] = DunningNotice::query()
            ->where('delivery_state', DunningNotice::QUEUED)
            ->where('sent_at', '<', $cutoff)
            ->update(['delivery_state' => DunningNotice::EXPIRED, 'delivery_detail' => self::EXPIRED_DETAIL]);

        $ids = DunningNotice::query()
            ->where('delivery_state', DunningNotice::QUEUED)
            ->where('sent_at', '>=', $cutoff)
            ->orderBy('id')->limit(self::BATCH)->pluck('id');

        foreach ($ids as $id) {
            // Claimed in one guarded update: a second run, or a retry of this one, never sends a reminder twice.
            if (DunningNotice::query()->whereKey($id)->where('delivery_state', DunningNotice::QUEUED)->update(['delivery_state' => DunningNotice::SENDING]) !== 1) {
                continue;
            }

            $notice = DunningNotice::query()->with('unit.household')->findOrFail($id);
            [$state, $detail] = $this->deliver($notice);
            $notice->forceFill(['delivery_state' => $state, 'delivery_detail' => $detail])->save();

            $report[$state === DunningNotice::DELIVERED ? 'delivered' : 'failed']++;
        }

        return $report;
    }

    /**
     * Hands one claimed reminder to each of its step's channels, and says what became of it.
     *
     * @return array{0: string, 1: string|null}
     */
    private function deliver(DunningNotice $notice): array
    {
        $landed = [];
        $missed = [];

        foreach ($this->legs($notice) as $leg) {
            if ($leg['channel'] !== 'email') {
                $missed[] = $leg['note'];

                continue;
            }

            if ($leg['to'] === null) {
                $missed[] = $leg['note'];

                continue;
            }

            try {
                [, $dropped] = SeededRecipients::whileWatching(fn () => Notification::route('mail', $leg['to'])->notifyNow(new ArrearsReminder($notice->subject, $notice->body)));
            } catch (Throwable $failure) {
                report($failure);
                $missed[] = 'Email failed: the mail service did not take it';

                continue;
            }

            if ($dropped !== []) {
                $missed[] = 'Email not sent: the address belongs to sample data';

                continue;
            }

            $landed[] = 'email';
        }

        // The detail column holds 190 characters; the longest these make is about 120.
        if ($landed === []) {
            return [DunningNotice::FAILED, mb_substr(ucfirst(implode('; ', $missed)) ?: 'Not sent', 0, 190)];
        }

        return [DunningNotice::DELIVERED, $missed === [] ? null : mb_substr('Delivered by email; '.lcfirst(implode('; ', $missed)), 0, 190)];
    }

    /**
     * Each channel the step names, with where it would go or why it will not.
     *
     * @return list<array{channel: string, label: string, to: string|null, note: string|null}>
     */
    private function legs(DunningNotice $notice): array
    {
        $resident = $this->recipient($notice);
        $email = $resident === null ? null : (trim((string) $resident->email) ?: null);

        $legs = [];
        foreach (explode('+', $notice->channel) as $channel) {
            $legs[] = match ($channel) {
                'email' => [
                    'channel' => 'email', 'label' => 'Email', 'to' => $email,
                    'note' => match (true) {
                        $resident === null => 'No resident to address on the household',
                        $email === null => 'No email address on file',
                        default => null,
                    },
                ],
                'push' => ['channel' => 'push', 'label' => 'Push', 'to' => null, 'note' => 'push not sent: reminders do not go by push yet'],
                'sms' => ['channel' => 'sms', 'label' => 'SMS', 'to' => null, 'note' => 'SMS not sent: reminders do not go by text yet'],
                'letter' => ['channel' => 'letter', 'label' => 'Letter', 'to' => null, 'note' => 'letter not sent from here: the office posts it'],
                default => ['channel' => $channel, 'label' => ucfirst($channel), 'to' => null, 'note' => $channel.' not sent'],
            };
        }

        return $legs;
    }

    /** Who a reminder is addressed to: the household's primary resident, as its merge fields already name them. */
    private function recipient(DunningNotice $notice): ?Resident
    {
        return $notice->unit->household?->residents()->where('is_primary', true)->first();
    }

    /** @return array<string, mixed> */
    private function previewRow(DunningNotice $notice): array
    {
        $resident = $this->recipient($notice);

        return [
            'id' => $notice->id,
            'unit' => (string) $notice->unit->reference,
            'to' => $resident === null ? 'No resident on file' : (string) $resident->full_name,
            'step' => $notice->template_label,
            'channel' => $notice->channelLabel(),
            'queued' => SiteTime::fmt(SiteTime::local($notice->sent_at), 'M j, g:i A'),
            'legs' => array_map(static fn (array $leg): array => [
                'channel' => $leg['label'],
                'to' => $leg['to'],
                'note' => $leg['note'] === null ? null : ucfirst($leg['note']),
            ], $this->legs($notice)),
            'subject' => $notice->subject,
            'body' => $notice->body,
        ];
    }

    /** The instant before which a queued reminder is never sent: a day ago, or when delivery started if that is later. */
    private function cutoff(?Carbon $from): Carbon
    {
        $stale = Carbon::now()->subHours(self::FRESH_FOR_HOURS);

        return $from !== null && $from->greaterThan($stale) ? $from : $stale;
    }

    /** The moment delivery started, or null when it is not set or is not a date and time. */
    private function deliverFrom(): ?Carbon
    {
        $from = config('reminders.deliver_from');
        if (! is_string($from) || trim($from) === '') {
            return null;
        }

        try {
            return Carbon::parse($from);
        } catch (Throwable) {
            return null;
        }
    }
}
