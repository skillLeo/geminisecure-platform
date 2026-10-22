<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a client bills at, in ONE place.
 *
 * WHAT WAS WRONG. `PlatformOverview::monthlyRecurringMinor()` and `ClientDirectory` both computed
 * monthly revenue as `unit_count * price_per_unit_minor` and nothing else, so the security
 * add-on — every guard on that client's contract, at the platform rate — was missing from every
 * MRR figure on the platform. The two screens agreed with each other and disagreed with the
 * invoice for the same month: Phoenix Park's September invoice totals J$756,500 while the
 * Director's dashboard read J$680,000, the difference being exactly 17 guards at J$4,500.
 *
 * The boards settle it. Board super-admin-05 draws Phoenix Park as "450 units · 4 guards
 * deployed · $171,000 MRR", and 450 x J$340 is J$153,000 — the remaining J$18,000 is 4 x J$4,500.
 * `BillingSeeder`'s own docblock says so in as many words: "the invoice board decomposes Phoenix
 * Park's August bill as 450 units at J$340 plus 4 guards at J$4,500, which reconciles to the
 * J$171,000 the dashboard shows. Four boards, one arithmetic." The code was doing three of them.
 *
 * ONE PLACE, BECAUSE THAT IS THE ACTUAL FIX. `PlatformOverview` already carries a comment about
 * how the dashboard and the client directory once formatted the same currency two different ways
 * — "Two formatters is how a system ends up disagreeing with itself about money." Two revenue
 * formulas are the same mistake one level up, so there is now one formula and one SQL expression,
 * and a screen that wants monthly revenue asks for it rather than writing it out again.
 */
final class PlatformPricing
{
    /** The add-on's key in `platform_rates`. Not a column on `plans`: a guard costs the same whatever tier the estate is on. */
    public const GUARD_RATE_KEY = 'security_provider_guard';

    /** Board 42's rate, used only when the catalogue has not been seeded yet. */
    private const GUARD_RATE_FALLBACK_MINOR = 4_500_00;

    /** See `guardRateMinor()`. A property rather than a function-static so it can be forgotten. */
    private static ?int $guardRateMinor = null;

    /**
     * The per-guard monthly charge in force TODAY, in minor units.
     *
     * Read once and remembered. A rate that changed halfway down a list would make the column not
     * add up to its own total.
     *
     * REMEMBERED FOR THE LIFE OF THE PROCESS, NOT THE REQUEST, which is why `forgetGuardRate()`
     * exists (M10 §3). Under PHP-FPM a process is near enough one request, but a queue worker, a
     * long-running server and the test suite all serve many from one process — and `mrrSql()`
     * bakes this integer into a SQL string, so a stale value is not a stale display but a revenue
     * figure that is arithmetically wrong. Every write to `platform_rates.amount_minor` forgets it.
     */
    public static function guardRateMinor(): int
    {
        if (self::$guardRateMinor !== null) {
            return self::$guardRateMinor;
        }

        $value = DB::connection('mysql')->table('platform_rates')
            ->where('key', self::GUARD_RATE_KEY)
            ->where('is_active', true)
            ->value('amount_minor');

        return self::$guardRateMinor = (int) ($value ?? self::GUARD_RATE_FALLBACK_MINOR);
    }

    /** Drop the remembered rate, so the next read sees what a rate change just wrote. */
    public static function forgetGuardRate(): void
    {
        self::$guardRateMinor = null;
    }

    /**
     * What a tier charged per unit ON A DATE — the price an invoice for a period is raised at.
     *
     * `plans.price_per_unit_minor` is the price in force TODAY, and until M10 §3 the invoice run
     * read it, which made a price change mean whatever the day an invoice happened to be raised
     * said it meant. Two ways that was wrong: a client billed in advance for October on 25
     * September paid September's price for October, and a catch-up invoice for a month already
     * past was raised at a price that month never had — a retroactive re-pricing done by accident.
     * The rule the client set is "the change applies from the next billing period", so the price
     * of a period is the one in force on its first day, read from the dated history.
     *
     * @param  int  $inForceTodayMinor  the plan's own column — the answer when nothing was ever changed
     */
    public static function planPriceMinorOn(int $planId, int $inForceTodayMinor, CarbonInterface $on): int
    {
        return self::inForceOn('plan_price_changes', 'plan_id', $planId, $inForceTodayMinor, $on);
    }

    /**
     * What a platform rate charged ON A DATE. The per-guard add-on's twin of `planPriceMinorOn()`.
     *
     * @param  int  $inForceTodayMinor  the rate's own column — the answer when nothing was ever changed
     */
    public static function rateMinorOn(int $rateId, int $inForceTodayMinor, CarbonInterface $on): int
    {
        return self::inForceOn('platform_rate_changes', 'platform_rate_id', $rateId, $inForceTodayMinor, $on);
    }

    /**
     * The amount in force on a date, from a table of dated changes.
     *
     * THE LATEST CHANGE DATED ON OR BEFORE THE DAY, pending or applied — "applied" is when the
     * column showing TODAY's price was brought up to date, which says nothing about what a period
     * costs. Two changes for the same day: the later one recorded is the later word, which is the
     * same order the sweep applies them in, so the two cannot disagree.
     *
     * BEFORE EVERY CHANGE, the first change's `from_minor`: what the price was when the history
     * began. With no history at all, the column itself — nothing was ever changed, so today's
     * price is every day's price.
     */
    private static function inForceOn(string $table, string $foreignKey, int $id, int $inForceTodayMinor, CarbonInterface $on): int
    {
        $changes = DB::connection('mysql')
            ->table($table)
            ->where($foreignKey, $id)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get(['from_minor', 'to_minor', 'effective_from']);

        return self::inForceFrom($changes, $inForceTodayMinor, $on);
    }

    /**
     * Every tier's dated price history, for many tiers in ONE read (M11 A2) — what
     * `planPriceMinorOn()` reads one tier at a time, for a caller pricing many clients at once.
     *
     * @param  list<int>  $planIds
     * @return array<int, Collection<int, stdClass>> each tier's changes, oldest first; an empty list for a tier never changed
     */
    public static function planPriceHistories(array $planIds): array
    {
        return self::histories('plan_price_changes', 'plan_id', $planIds);
    }

    /**
     * Every platform rate's dated history, in one read — `rateMinorOn()`'s twin of
     * `planPriceHistories()`.
     *
     * @param  list<int>  $rateIds
     * @return array<int, Collection<int, stdClass>>
     */
    public static function rateHistories(array $rateIds): array
    {
        return self::histories('platform_rate_changes', 'platform_rate_id', $rateIds);
    }

    /**
     * The amount in force on a date, from a history already read (`planPriceHistories()`,
     * `rateHistories()`). The rule `inForceOn()` states, and the code it runs: one arithmetic,
     * whether the history was read for one tier or for a hundred.
     *
     * @param  Collection<int, stdClass>  $changes  one tier's or one rate's changes, in `effective_from` then `id` order
     */
    public static function inForceFrom(Collection $changes, int $inForceTodayMinor, CarbonInterface $on): int
    {
        if ($changes->isEmpty()) {
            return $inForceTodayMinor;
        }

        $day = $on->toDateString();

        $latest = $changes->last(
            static fn (object $change): bool => Carbon::parse((string) $change->effective_from)->toDateString() <= $day
        );

        return $latest !== null ? (int) $latest->to_minor : (int) $changes->first()->from_minor;
    }

    /**
     * Each id's rows from a table of dated changes, oldest first, in one query.
     *
     * The order is the one `inForceOn()` reads a single id's rows in — `effective_from`, then
     * `id` — kept within each id by `groupBy()`, which never reorders a group's members. Every id
     * asked for is a key, with an empty list where it has no history.
     *
     * @param  list<int>  $ids
     * @return array<int, Collection<int, stdClass>>
     */
    private static function histories(string $table, string $foreignKey, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $histories = [];

        foreach ($ids as $id) {
            $histories[$id] = new Collection;
        }

        if ($ids === []) {
            return $histories;
        }

        $rows = DB::connection('mysql')
            ->table($table)
            ->whereIn($foreignKey, $ids)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get([$foreignKey, 'from_minor', 'to_minor', 'effective_from']);

        foreach ($rows->groupBy($foreignKey) as $id => $changes) {
            $histories[(int) $id] = $changes->values();
        }

        return $histories;
    }

    /**
     * What one client bills a month: the software on its units, plus the guards on its contract.
     *
     * The per-unit price is passed in rather than looked up, because every caller already holds
     * the plan row it came from and a second query here would be one more place for the two to
     * disagree.
     */
    public static function monthlyMinorAt(int $units, int $pricePerUnitMinor, int $guards): int
    {
        return ($units * $pricePerUnitMinor) + ($guards * self::guardRateMinor());
    }

    /**
     * The same arithmetic as SQL, for the queries that sum it across clients.
     *
     * `contracted_guards` is nullable — a client with no guarding contract bills for software
     * alone — so COALESCE rather than letting one NULL turn the whole sum into NULL.
     */
    public static function mrrSql(string $subscriptions = 'subscriptions', string $plans = 'plans'): string
    {
        $rate = self::guardRateMinor();

        return "({$subscriptions}.unit_count * {$plans}.price_per_unit_minor)"
            ." + (COALESCE({$subscriptions}.contracted_guards, 0) * {$rate})";
    }
}
