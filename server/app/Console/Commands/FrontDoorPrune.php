<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Auth\FrontDoor;
use Illuminate\Console\Command;

/**
 *   php artisan front-door:prune
 *
 * The front door's handoff tokens (M11 §5.2) live 60 seconds and are redeemed once; a row a day old will never
 * be redeemed, used or not. They are working state — who was handed to which estate, and from where, is in the
 * append-only audit log — so this deletes them and the table stays the size of one day's sign-ins. A row holds
 * only a hash of a token that is already dead.
 */
class FrontDoorPrune extends Command
{
    protected $signature = 'front-door:prune';

    protected $description = 'Delete the front door\'s handoff tokens a day old or more (they expire after 60 seconds)';

    public function handle(FrontDoor $door): int
    {
        $this->info($door->prune().' handoff token(s) a day old or more deleted.');

        return self::SUCCESS;
    }
}
