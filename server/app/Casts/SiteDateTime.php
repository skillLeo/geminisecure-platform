<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\SiteTime;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A time the OFFICE types and reads — a shift's start, a meeting, a booking, a technician's
 * window, when a poll closes (M4 §3.1, `SiteTime`).
 *
 *   in the database   true UTC
 *   on the model      the same moment, ON THE SITE'S CLOCK — so every `->format('g:i A')` on a
 *                     console screen, a PDF or an export prints the digits the office typed
 *   set from          a string with no offset  → a wall-clock time at the site
 *                     a string with an offset, or any date-time object → that moment
 *
 * Comparisons between moments are unaffected by the zone a Carbon is carried in. Sending one
 * to a phone goes through `SiteTime::iso()`, which is UTC whatever zone it arrives in.
 *
 * `SiteDateTime::class.':tenant_id'` names the attribute that holds the estate's id, for a
 * CENTRAL model (a shift) read from a console that has no estate open. With no argument the
 * site is the estate that is open.
 *
 * @implements CastsAttributes<Carbon|null, DateTimeInterface|string|int|null>
 */
final class SiteDateTime implements CastsAttributes
{
    /** A fresh Carbon on every read, as the framework's own `datetime` cast gives — never a shared, mutable one. */
    public bool $withoutObjectCaching = true;

    public function __construct(private readonly ?string $siteAttribute = null) {}

    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stored = $value instanceof DateTimeInterface
            ? Carbon::instance($value)->utc()
            : Carbon::parse((string) $value, 'UTC');

        return $stored->setTimezone(SiteTime::zone($this->site($attributes)));
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = is_int($value) || (is_string($value) && ctype_digit($value))
            ? Carbon::createFromTimestampUTC((int) $value)
            : SiteTime::parse($value, $this->site($attributes));

        return $moment->format($model->getDateFormat());
    }

    /** @param  array<string, mixed>  $attributes */
    private function site(array $attributes): ?string
    {
        if ($this->siteAttribute === null) {
            return null;
        }

        $site = $attributes[$this->siteAttribute] ?? null;

        return $site === null ? null : (string) $site;
    }
}
