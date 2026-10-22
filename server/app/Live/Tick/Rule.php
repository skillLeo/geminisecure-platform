<?php

declare(strict_types=1);

namespace App\Live\Tick;

use App\Live\Stream;
use Carbon\CarbonImmutable;

/**
 * One kind of change that happens because TIME passed, not because anybody wrote (DESIGN.md §2.13):
 * a ticket crossing its target, a meeting starting, a ballot lapsing, a shift's rostered edge.
 *
 * Each rule is its own file here, `app/Live/Tick/<Name>Rule.php`, found by RuleRegistry, so a
 * wiring unit adds a rule and edits no shared list. `live:tick` runs every minute and hands each
 * rule the window `(from, to]` its stream has not been ticked through yet, so a minute the
 * scheduler missed is caught up rather than lost; a rule records its ref events for whatever fell
 * inside the window, with LiveBus, in a transaction of its own. It may be handed a window again
 * after a failure (the stream's `ticked_at` only moves when every rule of it succeeded), so what it
 * records must be a "re-read now" hint that is harmless twice.
 */
interface Rule
{
    /** `central` for the central stream, `estate` to run once per estate, inside it. */
    public function stream(): string;

    /** Record the events for what fell due in `(from, to]`, both UTC, by the database's clock. */
    public function run(Stream $stream, CarbonImmutable $from, CarbonImmutable $to): void;
}
