<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\AuditEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * HOW EACH ESTATE'S ON-DUTY OFFICERS ARE SAMPLED, AND HOW LONG A SILENCE IS TOLERATED (Hassam, 12 October 2026: guard
 * live tracking). The one reader of `site_tracking_settings`, for everybody: the officer's phone through
 * `GuardTracking::status()`, the no signal watch and the Gemini map through `forMany()`, and the estate's Settings page.
 * Nobody reads the table directly, so the defaults and the names are decided here once.
 *
 * A VALUE THE ESTATE NEVER CHOSE IS THE PLATFORM'S DEFAULT, read from config/tracking.php at the moment of asking, so a
 * default moved later reaches every estate that never chose one.
 *
 * WHAT AN ESTATE MAY CHOOSE KEEPS THE WORDS EVERY OFFICER AGREED TO TRUE (D-239: a setting may never be moved outside
 * what the people it affects were told). The location policy says "about once a minute" (2026-10-10), and from version
 * 2026-10-12 "every 45 to 90 seconds while you move, less often while you stand still or your battery is low": so moving
 * is between 45 and 90 seconds, and standing still and low battery are each slower than moving.
 *
 * "NO SIGNAL" NEVER COMES SOONER THAN THE SLOWEST READING PLUS TWO MINUTES (Hassam, 12 Oct, Payroll's rule): a phone
 * that sends every five minutes is not silent at minute five. Saving a shorter tolerance is refused, in a sentence that
 * shows the consequence; and every read lifts a stored tolerance to that floor, so a default moved later can never make
 * a healthy phone raise "No signal".
 */
final class SiteTrackingSettings
{
    /** The five values, in the order the Settings page draws them. */
    public const KEYS = ['moving_interval_s', 'stationary_interval_s', 'low_battery_pct', 'low_battery_interval_s', 'no_signal_after_s'];

    /** How much longer than the slowest reading a silence lasts before "No signal" (Hassam, 12 Oct). */
    public const NO_SIGNAL_MARGIN_S = 120;

    /** What an estate may choose, each [lowest, highest], the percentage in percent and the rest in seconds. */
    public const BOUNDS = [
        'moving_interval_s' => [45, 90],
        'stationary_interval_s' => [120, 900],
        'low_battery_pct' => [10, 30],
        'low_battery_interval_s' => [120, 900],
        'no_signal_after_s' => [120, 1800],
    ];

    /**
     * The platform's defaults, from config/tracking.php (Security's file).
     *
     * @return array<string, int>
     */
    public static function defaults(): array
    {
        return [
            'moving_interval_s' => (int) config('tracking.sampling.interval_s', 45),
            'stationary_interval_s' => (int) config('tracking.sampling.stationary_interval_s', 180),
            'low_battery_pct' => (int) config('tracking.sampling.low_battery_pct', 20),
            'low_battery_interval_s' => (int) config('tracking.sampling.low_battery_interval_s', 180),
            'no_signal_after_s' => (int) config('tracking.no_signal_after_seconds', 300),
        ];
    }

    /**
     * One estate's values, its own where it chose and the defaults where it did not. One query.
     *
     * @return array<string, int>
     */
    public static function for(string $tenantId): array
    {
        return self::forMany([$tenantId])[$tenantId];
    }

    /**
     * Many estates' values at once, keyed by tenant id: ONE query, however many (the Gemini map reads every estate, and
     * the no signal watch every estate with an officer on duty). An estate with no row has the defaults.
     *
     * @param  array<int, string>  $tenantIds
     * @return array<string, array<string, int>>
     */
    public static function forMany(array $tenantIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $tenantIds)));

        if ($ids === []) {
            return [];
        }

        $defaults = self::defaults();
        $chosen = self::chosenMany($ids);
        $out = [];

        foreach ($ids as $id) {
            foreach (self::KEYS as $key) {
                $out[$id][$key] = $chosen[$id][$key] ?? $defaults[$key];
            }

            // Never sooner than the slowest reading plus the margin, whatever is stored or defaulted.
            $out[$id]['no_signal_after_s'] = max($out[$id]['no_signal_after_s'], self::slowestReading($out[$id]) + self::NO_SIGNAL_MARGIN_S);
        }

        return $out;
    }

    /**
     * The longest a healthy phone goes between readings, with these values: the slowest of moving, standing still and
     * low battery.
     *
     * @param  array<string, int>  $values
     */
    public static function slowestReading(array $values): int
    {
        return max($values['moving_interval_s'], $values['stationary_interval_s'], $values['low_battery_interval_s']);
    }

    /**
     * What one estate chose itself, key by key, and null where it follows the platform's default: what its Settings page
     * draws as chosen.
     *
     * @return array<string, int|null>
     */
    public static function chosen(string $tenantId): array
    {
        return self::chosenMany([$tenantId])[$tenantId] ?? array_fill_keys(self::KEYS, null);
    }

    /**
     * Save what an estate chose: a number within its bounds, or null to follow the platform's default. Every key not in
     * `$input` is left as it was. Refused, in a sentence the page shows, before anything is written. A save that changes
     * nothing writes no audit entry.
     *
     * @param  array<string, int|null>  $input
     */
    public static function save(string $tenantId, array $input, User $by): void
    {
        $before = self::chosen($tenantId);
        $after = $before;

        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];

            if ($value !== null) {
                [$low, $high] = self::BOUNDS[$key];

                if ($value < $low || $value > $high) {
                    throw new DomainException(self::outOfBounds($key));
                }
            }

            $after[$key] = $value;
        }

        // "Less often while you stand still or your battery is low": each slower than moving, in what would apply.
        $defaults = self::defaults();
        $applies = static fn (string $key): int => $after[$key] ?? $defaults[$key];

        if ($applies('stationary_interval_s') <= $applies('moving_interval_s') || $applies('low_battery_interval_s') <= $applies('moving_interval_s')) {
            throw new DomainException(
                'Standing still and low battery must each send less often than moving: the words every officer agreed to '.
                'say so. Nothing was saved.'
            );
        }

        // "No signal" never sooner than the slowest reading plus the margin, said as its consequence (Hassam, 12 Oct).
        $slowest = max($applies('moving_interval_s'), $applies('stationary_interval_s'), $applies('low_battery_interval_s'));

        if ($applies('no_signal_after_s') < $slowest + self::NO_SIGNAL_MARGIN_S) {
            throw new DomainException(sprintf(
                'Readings every %s, so “No signal” cannot be raised before %s of silence. Choose %s or longer, or have the '.
                'phones send more often. Nothing was saved.',
                self::spanWords($slowest),
                self::spanWords($slowest + self::NO_SIGNAL_MARGIN_S),
                self::spanWords($slowest + self::NO_SIGNAL_MARGIN_S),
            ));
        }

        if ($after === $before) {
            return;
        }

        DB::connection('mysql')->transaction(static function () use ($tenantId, $after, $before, $by): void {
            $table = DB::connection('mysql')->table('site_tracking_settings');
            $row = [...$after, 'updated_by_id' => $by->getKey(), 'updated_at' => now()];

            if ($table->clone()->where('tenant_id', $tenantId)->exists()) {
                $table->clone()->where('tenant_id', $tenantId)->update($row);
            } else {
                $table->clone()->insert([...$row, 'tenant_id' => $tenantId, 'created_at' => now()]);
            }

            AuditEntry::create([
                'tenant_id' => $tenantId,
                'actor_id' => $by->getKey(),
                'actor_name' => $by->name,
                'actor_role' => $by->roles->pluck('name')->implode(', '),
                'action' => 'estate.settings.guard_tracking_updated',
                'entity_type' => 'site_tracking_settings',
                'entity_id' => $tenantId,
                'before' => $before,
                'after' => $after,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, int|null>>
     */
    private static function chosenMany(array $ids): array
    {
        $out = [];

        foreach (DB::connection('mysql')->table('site_tracking_settings')->whereIn('tenant_id', $ids)->get() as $row) {
            foreach (self::KEYS as $key) {
                $out[(string) $row->tenant_id][$key] = $row->{$key} === null ? null : (int) $row->{$key};
            }
        }

        return $out;
    }

    /** A span of seconds in words: "45 seconds", "1 minute", "7 minutes", "75 seconds". */
    public static function spanWords(int $seconds): string
    {
        if ($seconds % 60 !== 0) {
            return $seconds.' seconds';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes === 1 ? '1 minute' : $minutes.' minutes';
    }

    /** Why a value is refused, in words the page shows: what is allowed and why. */
    private static function outOfBounds(string $key): string
    {
        [$low, $high] = self::BOUNDS[$key];

        return match ($key) {
            // True under both wordings: "about once a minute" (2026-10-10) and "every 45 to 90 seconds" (2026-10-12).
            'moving_interval_s' => "Choose between {$low} and {$high} seconds while moving: that keeps to what the officers were told when they agreed to share their position. Nothing was saved.",
            'low_battery_pct' => "Choose a low battery line between {$low} and {$high} percent. Nothing was saved.",
            'no_signal_after_s' => 'Choose between '.intdiv($low, 60).' and '.intdiv($high, 60).' minutes before "No signal". Nothing was saved.',
            default => 'Choose between '.intdiv($low, 60).' and '.intdiv($high, 60).' minutes. Nothing was saved.',
        };
    }
}
