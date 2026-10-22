<?php

declare(strict_types=1);

namespace App\Services\Ring;

/**
 * THE QUEUE A RING GOES ON (M18 §1.4).
 *
 * `push.ring_queue` when it is set, else the panic queue (`push.urgent_queue`), else the default — so a server whose
 * workers were never told about the ring queue loses nothing: every ring still goes, only no faster than the mail.
 * A ring round, a ring push, a ring stop and the officer's own test ring are the jobs on it. Nothing slow is: no mail,
 * no text, no PDF.
 */
final class RingQueue
{
    public static function name(): ?string
    {
        foreach ([config('push.ring_queue'), config('push.urgent_queue')] as $queue) {
            if (is_string($queue) && $queue !== '') {
                return $queue;
            }
        }

        return null;
    }
}
