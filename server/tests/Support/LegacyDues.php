<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Services\Estate\Dues;
use App\Support\SiteTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE ARREARS ARITHMETIC AS IT WAS BEFORE M11, KEPT AS THE YARDSTICK.
 *
 * M11 A2 moved the FIFO ageing out of PHP and into one SQL statement, stopped
 * reading every unit's balance to answer for one, and paginated the arrears
 * register. None of that may change a single figure. So the pre-M11 code is
 * kept here VERBATIM — copied from `App\Services\Estate\Dues` at a342c56, the
 * bug hunt's fixes included (the estate's today, B023; a payment's calendar day,
 * B023; a reminder on the estate's clock, B028), with only `$this->` calls
 * turned into calls on this class — and
 * `EstateArrearsArithmeticIsUnchangedTest` asserts that the new code answers
 * exactly what this answers, at 2,000 lots and on every date it is asked about.
 *
 * NOTHING IN THE APPLICATION CALLS THIS. It is the old implementation, and the
 * reason to keep it is that "the same numbers" is only proven against something.
 *
 * ONE DELIBERATE CHANGE (EST-S-02, M85 A3, 11 October 2026): `ageing()` and
 * `ageingOf()` put each charge in its own bucket, as the application now does,
 * written the old way (every charge read and walked here in PHP), so the
 * yardstick is a second implementation of the new rule rather than a copy of it.
 * Balances, badges, phase bars and the register rows are still verbatim.
 */
final class LegacyDues
{
    /** @return array<int, int> */
    public static function unitBalances(?Carbon $asAt = null): array
    {
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', '1200')
            ->whereNotNull('journal_lines.unit_id')
            ->selectRaw('journal_lines.unit_id as unit_id, SUM(journal_lines.debit_minor - journal_lines.credit_minor) as balance')
            ->groupBy('journal_lines.unit_id');

        if ($asAt !== null) {
            $query->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
                ->where('journals.posted_on', '<=', $asAt->toDateString());
        }

        $balances = [];

        foreach ($query->get() as $row) {
            $balances[(int) $row->unit_id] = (int) $row->balance;
        }

        return $balances;
    }

    /** @return array<int, Carbon> */
    public static function oldestOpenChargeDates(Carbon $asAt): array
    {
        $charges = DB::connection('tenant')
            ->table('charges')
            ->where('due_on', '<=', $asAt->toDateString())
            ->where('status', '!=', 'reversed')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get(['unit_id', 'due_on', 'amount_minor']);

        $paid = DB::connection('tenant')
            ->table('payments')
            ->where('status', 'recorded')
            ->where('received_at', '<=', $asAt->copy()->endOfDay())
            ->selectRaw('unit_id, SUM(amount_minor) as paid')
            ->groupBy('unit_id')
            ->pluck('paid', 'unit_id');

        $remaining = [];
        $oldest = [];

        foreach ($charges as $charge) {
            $unitId = (int) $charge->unit_id;

            $remaining[$unitId] ??= (int) ($paid[$unitId] ?? 0);

            if ($remaining[$unitId] >= (int) $charge->amount_minor) {
                $remaining[$unitId] -= (int) $charge->amount_minor;

                continue;
            }

            $oldest[$unitId] ??= Carbon::parse((string) $charge->due_on);
            $remaining[$unitId] = 0;
        }

        return $oldest;
    }

    /** @return array<int, string> */
    public static function unitBuckets(?Carbon $asAt = null): array
    {
        $today = $asAt?->copy() ?? Dues::siteDate();
        $balances = self::unitBalances($asAt);

        $oldestOpen = self::oldestOpenChargeDates($today);

        $buckets = [];

        foreach ($balances as $unitId => $balance) {
            if ($balance <= 0) {
                continue;
            }

            $due = $oldestOpen[$unitId] ?? null;
            $daysOverdue = $due === null ? 0 : (int) $due->diffInDays($today, absolute: false);

            $buckets[$unitId] = match (true) {
                $daysOverdue >= 90 => 'd90',
                $daysOverdue >= 60 => 'd60',
                $daysOverdue >= 30 => 'd30',
                default => 'current',
            };
        }

        return $buckets;
    }

    /** @return array<string, int> */
    public static function ageing(?Carbon $asAt = null): array
    {
        $totals = array_fill_keys(array_keys(Dues::BUCKETS), 0);

        foreach (self::chargeAgeing($asAt) as $strip) {
            foreach ($strip as $bucket => $minor) {
                $totals[$bucket] += $minor;
            }
        }

        return $totals;
    }

    /** @return array<string, int> */
    public static function ageingOf(Unit $unit, ?Carbon $asAt = null): array
    {
        return self::chargeAgeing($asAt)[$unit->id] ?? array_fill_keys(array_keys(Dues::BUCKETS), 0);
    }

    /**
     * EACH CHARGE IN ITS OWN BUCKET (EST-S-02): every owing unit's balance laid over its charges due by the day, newest
     * first, each share in its own charge's bucket; what no charge explains in the oldest charge's bucket, or current.
     *
     * @return array<int, array<string, int>>
     */
    public static function chargeAgeing(?Carbon $asAt = null): array
    {
        $today = $asAt?->copy() ?? Dues::siteDate();

        $charges = DB::connection('tenant')
            ->table('charges')
            ->where('due_on', '<=', $today->toDateString())
            ->where('status', '!=', 'reversed')
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->get(['unit_id', 'due_on', 'amount_minor']);

        $byUnit = [];

        foreach ($charges as $charge) {
            $byUnit[(int) $charge->unit_id][] = $charge;
        }

        $ageing = [];

        foreach (self::unitBalances($asAt) as $unitId => $balance) {
            if ($balance <= 0) {
                continue;
            }

            $strip = array_fill_keys(array_keys(Dues::BUCKETS), 0);
            $left = $balance;
            $bucket = 'current';

            foreach ($byUnit[$unitId] ?? [] as $charge) {
                if ($left <= 0) {
                    break;
                }

                $daysOverdue = (int) Carbon::parse((string) $charge->due_on)->diffInDays($today, absolute: false);

                $bucket = match (true) {
                    $daysOverdue >= 90 => 'd90',
                    $daysOverdue >= 60 => 'd60',
                    $daysOverdue >= 30 => 'd30',
                    default => 'current',
                };

                $share = min((int) $charge->amount_minor, $left);
                $strip[$bucket] += $share;
                $left -= $share;
            }

            $strip[$bucket] += max(0, $left);
            $ageing[$unitId] = $strip;
        }

        return $ageing;
    }

    /** @return array<string, int> */
    public static function arrearsByPhase(?Carbon $asAt = null): array
    {
        $balances = array_filter(self::unitBalances($asAt), static fn (int $b): bool => $b > 0);
        $phaseByUnit = Unit::query()->whereIn('id', array_keys($balances))->pluck('block', 'id');

        $totals = [];

        foreach (Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values() as $phase) {
            $totals[(string) $phase] = 0;
        }

        foreach ($balances as $unitId => $balance) {
            $phase = (string) ($phaseByUnit[$unitId] ?? '');

            if ($phase === '') {
                continue;
            }

            $totals[$phase] = ($totals[$phase] ?? 0) + $balance;
        }

        return $totals;
    }

    /**
     * The register's rows exactly as the pre-M11 `arrearsBoard()` built them, for a
     * viewer who may read the estate's position.
     *
     * @return list<array<string, mixed>>
     */
    public static function arrearsRows(string $phase = '', bool $overdueOnly = false): array
    {
        $balances = array_filter(self::unitBalances(), static fn (int $b): bool => $b > 0);
        $buckets = self::unitBuckets();

        $lastPaid = Payment::query()
            ->where('status', 'recorded')
            ->selectRaw('unit_id, MAX(received_at) as last_received')
            ->groupBy('unit_id')
            ->pluck('last_received', 'unit_id');

        $lastReminded = DB::connection('tenant')
            ->table('dunning_notices')
            ->selectRaw('unit_id, MAX(sent_at) AS last_sent')
            ->groupBy('unit_id')
            ->pluck('last_sent', 'unit_id');

        $flags = UnitCollectionFlag::query()
            ->whereNull('lifted_at')
            ->whereIn('unit_id', array_keys($balances))
            ->get()
            ->keyBy('unit_id');

        $units = Unit::query()
            ->whereIn('id', array_keys($balances))
            ->with(['household.residents' => fn ($query) => $query->where('is_primary', true)])
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($units as $unit) {
            $bucket = $buckets[$unit->id] ?? 'current';

            if ($phase !== '' && $unit->block !== $phase) {
                continue;
            }

            if ($overdueOnly && $bucket !== 'd90') {
                continue;
            }

            $resident = $unit->household?->residents->first();

            $rows[] = [
                'id' => $unit->id,
                'resident' => $resident->full_name ?? 'No resident on record',
                'initials' => self::initials($resident->full_name ?? (string) $unit->reference),
                'household' => $unit->household->name ?? 'Vacant unit',
                'unit' => trim(($unit->block ?? '').' · '.$unit->reference, ' ·'),
                'balance_minor' => $balances[$unit->id],
                'bucket' => $bucket,
                'bucket_label' => Dues::BUCKET_LABELS[$bucket],
                'last_payment' => isset($lastPaid[$unit->id])
                    ? Payment::calendarDay(Carbon::parse((string) $lastPaid[$unit->id]))->format('M j, Y')
                    : null,
                'last_reminder' => isset($lastReminded[$unit->id])
                    ? self::reminderLabel(Carbon::parse((string) $lastReminded[$unit->id]))
                    : null,
                'flag' => isset($flags[$unit->id]) ? [
                    'kind' => $flags[$unit->id]->kind,
                    'label' => $flags[$unit->id]->shortLabel(),
                    'headline' => $flags[$unit->id]->headline(),
                ] : null,
            ];
        }

        return $rows;
    }

    private static function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }

    private static function reminderLabel(Carbon $sentAt): string
    {
        $local = SiteTime::local($sentAt);

        // The words follow the product (M19A: every time names its clock, "4:00 AM JA"); the arithmetic is what this
        // reference guards, and it is unchanged.
        return match (true) {
            $local->isToday() => 'Today, '.SiteTime::fmt($local, 'g:i A'),
            $local->isYesterday() => 'Yesterday',
            default => SiteTime::fmt($local, 'M j, g:i A'),
        };
    }
}
