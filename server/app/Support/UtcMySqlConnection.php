<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Seeded\SampleAwareBuilder;
use DateTimeInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Carbon;

/**
 * A MOMENT BOUND INTO A QUERY IS BOUND IN UTC (M4 §3.1).
 *
 * The framework binds a date-time by calling `->format('Y-m-d H:i:s')` on it, which prints
 * the digits of WHATEVER ZONE THE OBJECT IS CARRIED IN and drops the zone. Every stored time
 * is UTC, so a Carbon on the site's clock (what `SiteDateTime` hands a console screen) or one
 * parsed from a phone's `…-05:00` would be compared, or written, five hours out — silently.
 *
 * This moves any zoned date-time to UTC first. A value already on UTC — which is every
 * `now()` in the codebase — is untouched, so nothing that was right changes.
 *
 * A calendar DATE column is not a moment: bind it a `->toDateString()`, not a Carbon.
 *
 * AND EVERY QUERY IT BUILDS KNOWS ABOUT SAMPLE DATA (M33 §5): a seeder's rows are marked as they are written, and an
 * estate that has retired its sample data stops being shown it. See `SampleAwareBuilder`.
 */
final class UtcMySqlConnection extends MySqlConnection
{
    public function query()
    {
        return new SampleAwareBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface && $value->getOffset() !== 0) {
                $bindings[$key] = Carbon::instance($value)->utc();
            }
        }

        return parent::prepareBindings($bindings);
    }
}
