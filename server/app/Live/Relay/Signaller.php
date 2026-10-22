<?php

declare(strict_types=1);

namespace App\Live\Relay;

/**
 * Where LiveBus::signal() hands an ephemeral signal once the change it follows has committed
 * (DESIGN.md §1.3, §2.11 K13).
 *
 * A signal is never logged and has no seq: it is the turnout hint `{ballot_id}`, which must not
 * leave a record of when anybody voted. The publisher that sends it is the relay's; this is bound
 * when that exists. While it is unbound LiveBus::signal() validates and sends nothing, which is
 * what every live update does before the relay is running.
 */
interface Signaller
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $data
     */
    public function signal(array $channels, string $type, array $data): void;
}
