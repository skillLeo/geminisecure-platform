<?php

declare(strict_types=1);

namespace App\Support\Seeded;

use Illuminate\Database\Console\Seeds\SeedCommand;

/**
 * `db:seed`, MARKING EVERY ROW ITS SEEDERS WRITE (M33 §5.2, KI-25).
 *
 * Laravel's own command, unchanged but for the mark, put in its place in the container (`AppServiceProvider`), so
 * `php artisan db:seed`, `Artisan::call('db:seed')` and a test's `$this->seed()` all run through it. Kept out of
 * `app/Console/Commands`, where it would be registered a second time under the same name. A seeder run any other way
 * — by hand, outside `db:seed` — would write no mark; `SeededMarksTest` fails application code that does that.
 */
final class MarkedSeedCommand extends SeedCommand
{
    use SeedsMarkedRows;
}
