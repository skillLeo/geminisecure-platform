<?php

declare(strict_types=1);

namespace App\Live\Relay;

use RuntimeException;
use Throwable;

/**
 * A publish that did not reach Reverb, and why (DESIGN.md §1.5).
 *
 * `status` is Reverb's HTTP answer, or 0 when there was none (refused, reset, timed out). Zero and
 * 5xx mean Reverb is down: the breaker opens. 4xx means Reverb answered and refused THIS message,
 * which is retried alone and given up after five tries. The message never carries the payload.
 */
final class PublishFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Reverb answered, and said no to this message. */
    public function refused(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** Reverb did not answer, or answered that it is broken. */
    public function outage(): bool
    {
        return ! $this->refused();
    }
}
