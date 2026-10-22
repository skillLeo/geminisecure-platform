<?php

declare(strict_types=1);

namespace App\Live\Relay;

use RuntimeException;

/** Another pass holds the stream's row, and the caller asked not to wait for it (Sequencer::run's NOWAIT). */
final class StreamBusy extends RuntimeException {}
