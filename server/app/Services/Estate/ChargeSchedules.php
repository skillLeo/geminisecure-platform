<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\ChargeRun;
use App\Models\Estate\ChargeSchedule;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Math\Exception\MathException;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The charge schedule — board 5's "Charge schedule" tab (12 §2, item 17):
 * "recurring run with preview and reversal".
 *
 * A SCHEDULE IS THE STANDING DECISION, A RUN IS ONE MONTH OF IT. The schedule
 * says what every unit in its scope is billed and on which day; a run posts one
 * period of it as ONE entry — a debit line per unit, one credit to income —
 * through the same `Dues::chargeRun()` the estate's month has always been
 * billed with, so a scheduled month and a hand-raised one are the same thing in
 * the ledger.
 *
 * PREVIEW, THEN POST, AND THE TWO MUST AGREE. The screen shows the next
 * period's unit count and total before anything posts; posting sends those two
 * figures back, and a register that has changed in between is refused rather
 * than billed on a list nobody saw.
 *
 * ONE LIVE RUN PER PERIOD, NO SKIPPED MONTH. The next period is the earliest
 * month, from the first the schedule posted, with no run standing — or this
 * month, for a new schedule — so a month cannot be billed twice or silently
 * passed over, and a run is posted no further ahead than next month.
 *
 * REVERSAL, NEVER DELETION, AND LATEST FIRST. A wrong run is reversed whole:
 * the ledger posts the mirror entry, the run's charges are marked reversed so
 * they leave the ageing, and the run records who, when and why. Only the latest
 * month standing can be reversed, so a reversed month is never left between two
 * that stand — and it is the next period, so it can then be posted again.
 *
 * THE RATE CHANGES FROM A MONTH, NEVER BACKWARDS (M10 §3). The Community Super
 * Admin edits the estate's dues rate "with the same rule that issued invoices are
 * not retroactively rewritten". A change names the first month it bills, which
 * may be no earlier than `nextPeriod()`; every month before that has been billed
 * or has passed, and its charges carry the rate they were raised at. What a month
 * bills is answered by ONE resolver, `rateForPeriod()`, which both `preview()`
 * and `post()` ask — so the figures the office is shown and the figures that post
 * cannot come from two different places.
 */
final class ChargeSchedules
{
    /** See `2026_09_24_151000_a_dues_rate_changes_from_a_month_not_a_date`. */
    private const RATE_CHANGES = 'charge_schedule_rate_changes';

    /**
     * How many months, from the next one billed, the screen offers a rate change from.
     *
     * A year: far enough for an AGM that votes in March to set the rise for January, and
     * short enough that the list is one the office reads rather than scrolls.
     */
    public const RATE_MONTHS_AHEAD = 12;

    public function __construct(
        private readonly Dues $dues,
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What the posted months order by (M32 F5).
     *
     * @return array<string, string|\Closure>
     */
    public static function runSorts(): array
    {
        return [
            'period' => 'charge_runs.period',
            'charge' => static function ($query, string $dir): void {
                $query->orderBy(ChargeSchedule::query()->select('description')->whereColumn('charge_schedules.id', 'charge_runs.charge_schedule_id'), $dir);
            },
            'due' => 'charge_runs.due_on',
            'units' => 'charge_runs.unit_count',
            'total' => 'charge_runs.total_minor',
            'posted' => 'charge_runs.posted_at',
        ];
    }

    /** The posted months as the address asks for them (M32 F5): the newest month first. */
    public static function runsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::runSorts(), 'period', 'desc');
    }

    /**
     * The schedules, and every month posted from them a page at a time (M32 F5) — the list used to stop at the latest
     * twenty-four, and the twenty-fifth month was nowhere on the screen.
     *
     * @return array<string, mixed>
     */
    public function board(?TableQuery $table = null): array
    {
        $table ??= self::runsTable();

        /*
         * A WRITE ON A READ, SAID OUT LOUD. This board is served by a GET that
         * needs only `dues_ledger.view`, and the first thing it does is make any
         * rate change whose month has arrived the schedule's rate in force. That
         * is deliberate, and it is the choice `PlatformSettings::applyDuePriceChanges()`
         * makes for a tier price on the platform's own GET: a change that silently
         * never applied would leave this card printing a rate the estate no longer
         * bills, and reading the card is the moment somebody would notice.
         *
         * NOTHING BILLED DEPENDS ON IT. `preview()` and `post()` ask
         * `rateForPeriod()`, which reads the changes themselves, so a month is
         * billed at its own rate whether or not this sweep has run. What the sweep
         * moves is `charge_schedules.amount_minor`, the figure the card prints —
         * and it moves it only to what an approver already decided, once, guarded
         * by `applied_at`.
         */
        $this->applyDueRateChanges();

        $schedules = ChargeSchedule::query()->orderByDesc('is_active')->orderBy('description')->get();

        $posted = ChargeRun::query()->with('schedule');
        $table->order($posted, 'charge_runs.id');
        $page = $table->paginate($posted);
        $runs = $page->getCollection();

        // The latest month standing on each schedule — the only one Reverse is live on (bug hunt B021).
        $latestStanding = ChargeRun::query()
            ->whereNull('reversed_at')
            ->groupBy('charge_schedule_id')
            ->selectRaw('charge_schedule_id, MAX(period) AS latest')
            ->pluck('latest', 'charge_schedule_id');

        return [
            'schedules' => $schedules->map(function (ChargeSchedule $schedule): array {
                $next = $this->nextPeriod($schedule);
                $preview = null;
                $blocked = null;

                try {
                    $preview = $this->preview($schedule, $next);
                } catch (DomainException $refused) {
                    $blocked = $refused->getMessage();
                }

                $changes = $this->rateChanges($schedule);
                $units = $this->unitCount($schedule);

                return [
                    'id' => $schedule->id,
                    'description' => $schedule->description,
                    'amount' => MoneyFormatter::fromMinor($schedule->amount_minor, $schedule->currency),
                    'scope' => $schedule->scopeLabel(),
                    'due_day' => $schedule->due_day,
                    'account' => $schedule->account_code,
                    'is_active' => $schedule->is_active,
                    'next_period' => $next->format('Y-m'),
                    'next_label' => $next->format('F Y'),
                    'preview' => $preview,
                    'blocked' => $schedule->is_active ? $blocked : 'This schedule is stopped. Nothing further is billed from it.',

                    // How many units the schedule bills today — what the rate panel multiplies.
                    'units' => $units,

                    // The rate the next unposted month bills, in the form the rate panel edits.
                    'rate_input' => number_format($this->resolveRate($schedule, $changes, $next->format('Y-m')) / 100, 2, '.', ''),

                    'pending_rates' => $this->pendingRates($schedule, $changes, $units),
                    'rate_options' => $schedule->is_active ? $this->rateOptions($schedule, $changes, $next, $units) : [],
                ];
            })->all(),
            'runs' => $runs->map(function (ChargeRun $run) use ($latestStanding): array {
                $latest = $latestStanding[$run->charge_schedule_id] ?? null;

                // Why Reverse is not live on a month standing under a later one; null where it is.
                $blocked = ! $run->isReversed() && $latest !== null && (string) $latest > $run->period
                    ? $this->laterMonthStands($run->period, (string) $latest)
                    : null;

                return [
                    'id' => $run->id,
                    'schedule' => $run->schedule->description,
                    'period' => Carbon::createFromFormat('Y-m-d', $run->period.'-01')->format('F Y'),
                    'due_on' => $run->due_on->format('M j, Y'),
                    'units' => $run->unit_count,
                    'total' => MoneyFormatter::fromMinor($run->total_minor, $run->currency),
                    'journal_ref' => $run->journal_ref,
                    // Instants, printed as the day they were at the estate (bug hunt B082).
                    'posted' => SiteTime::local($run->posted_at)->format('M j, Y').($run->posted_by_name === null ? '' : ' by '.$run->posted_by_name),
                    'reversed' => $run->isReversed(),
                    'reversible' => ! $run->isReversed() && $blocked === null,
                    'reverse_blocked' => $blocked,
                    'reversal' => $run->isReversed()
                        ? 'Reversed '.$this->siteDay($run->reversed_at).' by '.($run->reversed_by_name ?? 'an unrecorded officer').': '.$run->reversal_reason
                        : null,
                ];
            })->values()->all(),
            'table' => $table->meta($page),
            'incomeAccounts' => Account::query()
                ->where('type', Account::INCOME)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(static fn (Account $account): array => ['code' => $account->code, 'label' => $account->optionLabel()])
                ->all(),
            'phases' => Unit::query()->whereNotNull('block')->distinct()->orderBy('block')->pluck('block')->all(),
        ];
    }

    /**
     * Define a schedule. Nothing is billed until a run is posted.
     *
     * @param  array<string, mixed>  $fields
     */
    public function create(array $fields, User $by): ChargeSchedule
    {
        $description = trim((string) ($fields['description'] ?? ''));

        // The same refusal `amendRate()` makes: a third decimal, or a figure past
        // what the ledger's integer holds, is a sentence and not an exception
        // (bug hunt B081). The route refuses both first; this is for every caller.
        try {
            $amount = Money::of((string) ($fields['amount'] ?? '0'), 'JMD');
            $minor = $amount->getMinorAmount()->toInt();
        } catch (MathException) {
            throw new DomainException('Enter the charge amount in dollars and cents, as in 6200.00.');
        }

        $scope = (string) ($fields['scope'] ?? ChargeSchedule::ESTATE);
        $phase = $scope === ChargeSchedule::PHASE ? trim((string) ($fields['phase'] ?? '')) : null;
        $dueDay = (int) ($fields['due_day'] ?? 1);
        $accountCode = (string) ($fields['account_code'] ?? '4000');

        if ($description === '') {
            throw new DomainException('Name the charge, as in "Maintenance fee". It is printed on every charge the schedule raises.');
        }

        if ($dueDay < 1 || $dueDay > 28) {
            throw new DomainException('Choose a due day from the 1st to the 28th, so every month has it.');
        }

        $account = Account::query()->where('code', $accountCode)->first();

        if ($account === null || $account->type !== Account::INCOME || ! $account->is_active) {
            throw new DomainException('A recurring charge credits an income account that is in use.');
        }

        // The same checks a whole-phase charge makes: a positive amount, a real
        // phase, and a register with somebody in it to bill — counted, not listed
        // (M11 A2), because a check needs the count and not two thousand rows.
        $this->dues->bulkCount($scope, $phase, $amount);

        $schedule = ChargeSchedule::query()->create([
            'description' => mb_substr($description, 0, 120),
            'amount_minor' => $minor,
            'currency' => 'JMD',
            'account_code' => $accountCode,
            'scope' => $scope,
            'phase' => $phase,
            'due_day' => $dueDay,
            'is_active' => true,
            'created_by' => $by->getKey(),
            'created_by_name' => $by->name,
        ]);

        $this->audit->record(
            action: 'estate.charge_schedule_created',
            entityType: 'ChargeSchedule',
            entityId: (string) $schedule->id,
            after: $schedule->only(['description', 'amount_minor', 'account_code', 'scope', 'phase', 'due_day']),
        );

        return $schedule;
    }

    /** Stop a schedule. Its runs stand; nothing further is billed from it. */
    public function stop(ChargeSchedule $schedule, User $by): void
    {
        $schedule->forceFill(['is_active' => false])->save();

        $this->audit->record(
            action: 'estate.charge_schedule_stopped',
            entityType: 'ChargeSchedule',
            entityId: (string) $schedule->id,
            after: ['stopped_by' => $by->name],
        );
    }

    /**
     * Change what a schedule bills a unit, from a month not yet billed (M10 §3).
     *
     * NEVER RETROACTIVE, BY MONTH. The earliest month a change may name is
     * `nextPeriod()` — the month after the latest run still standing, or this
     * month when nothing has posted. Every month before it has been billed or has
     * passed, and a posted month's charges carry the rate they were raised at: a
     * change that reached back would make the ledger disagree with the statement
     * a household already holds. NOTHING HERE WRITES to `charges`, `charge_runs`
     * or the journal, and nothing needs to — a posted month never reads its rate
     * back through the schedule.
     *
     * THE REASON IS REQUIRED, ten characters at least, which is the rule a
     * reversal already keeps: every household billed from the schedule sees the
     * new amount on its statement.
     *
     * A SECOND CHANGE FOR THE SAME MONTH CORRECTS THE FIRST while that month is
     * unbilled, rather than standing beside it; the unique key refuses two, and
     * the audit log keeps both saves.
     *
     * A MONTH ALREADY UNDER WAY APPLIES AT ONCE. A change for this month (possible
     * only while this month is unposted) becomes the schedule's rate in force now;
     * one for a later month waits for `applyDueRateChanges()`.
     *
     * @return array{period: Carbon, from_minor: int, to_minor: int, units: int, total_minor: int, applied: bool, summary: string, effect: string, untouched: string}
     */
    public function amendRate(ChargeSchedule $schedule, string $amount, string $effectivePeriod, string $reason, User $by): array
    {
        if (! $schedule->is_active) {
            throw new DomainException('This schedule is stopped. Nothing further is billed from it, so it has no rate left to change.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new DomainException('Say why the rate is changing. Every household billed from this schedule will see the new amount on its statement, and an unexplained change is the entry an auditor stops at.');
        }

        if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $effectivePeriod) !== 1) {
            throw new DomainException('Choose the month the new rate is first billed for.');
        }

        try {
            $minor = Money::of($amount, $schedule->currency)->getMinorAmount()->toInt();
        } catch (MathException) {
            throw new DomainException('Enter the dues rate in dollars and cents, as in 7000.00.');
        }

        if ($minor <= 0) {
            throw new DomainException('A dues rate is a positive amount a unit. To bill nothing further, stop the schedule instead.');
        }

        $period = Carbon::createFromFormat('Y-m-d', $effectivePeriod.'-01')->startOfDay();
        $next = $this->nextPeriod($schedule);

        /*
         * A MONTH STANDING IS REFUSED WHEREVER IT FALLS. `nextPeriod()` is the
         * earliest month with no run standing (bug hunt B021), so on a schedule
         * reversed from the middle before months were reversed latest first, a
         * month after it can already be billed — and its charges keep their rate.
         */
        $billed = ChargeRun::query()
            ->where('charge_schedule_id', $schedule->id)
            ->where('period', $period->format('Y-m'))
            ->whereNull('reversed_at')
            ->exists();

        if ($billed || $period->lessThan($next)) {
            throw new DomainException($billed
                ? sprintf(
                    '%s has already been billed from this schedule. A rate change applies from %s at the earliest: the charges already raised carry the rate they were raised at, and re-rating them would make the ledger disagree with the statement a household already holds.',
                    $period->format('F Y'),
                    $next->format('F Y'),
                )
                : sprintf(
                    '%s has passed. A rate change applies from %s at the earliest, the next month this schedule bills.',
                    $period->format('F Y'),
                    $next->format('F Y'),
                ));
        }

        $key = $period->format('Y-m');
        $changes = $this->rateChanges($schedule);
        $inForce = $this->resolveRate($schedule, $changes, $key);

        if ($minor === $inForce) {
            throw new DomainException('That is already the rate for '.$period->format('F Y').'. Saving it again would record a change nobody made.');
        }

        // What the month before bills: the rate this change moves away from. Read
        // BEFORE the write, so a correction of this month's own change still
        // records what the rate was before any change for this month.
        $from = $this->resolveRate($schedule, $changes, $period->copy()->subMonthNoOverflow()->format('Y-m'));
        $existing = $changes->firstWhere('effective_period', $key);
        // "Under way" is the estate's month: on the evening of the 30th UTC's is already the next (bug hunt B082).
        $applyNow = $key <= Dues::siteDate()->format('Y-m');
        $units = $this->unitCount($schedule);

        DB::connection('tenant')->transaction(function () use ($schedule, $key, $from, $minor, $reason, $by, $applyNow, $existing): void {
            $row = [
                'from_minor' => $from,
                'to_minor' => $minor,
                'currency' => $schedule->currency,
                'reason' => mb_substr($reason, 0, 300),
                'changed_by' => $by->getKey(),
                'changed_by_name' => $by->name,
                'applied_at' => $applyNow ? Carbon::now() : null,
                'updated_at' => Carbon::now(),
            ];

            if ($existing === null) {
                DB::connection('tenant')->table(self::RATE_CHANGES)->insert([
                    'charge_schedule_id' => $schedule->id,
                    'effective_period' => $key,
                    ...$row,
                    'created_at' => Carbon::now(),
                ]);
            } else {
                DB::connection('tenant')->table(self::RATE_CHANGES)->where('id', $existing->id)->update($row);
            }

            if ($applyNow) {
                $schedule->forceFill([
                    'amount_minor' => $this->rateForPeriod($schedule, Dues::siteDate()),
                ])->save();
            }
        });

        $this->audit->record(
            action: 'estate.charge_schedule_rate_changed',
            entityType: 'ChargeSchedule',
            entityId: (string) $schedule->id,
            before: ['amount_minor' => $inForce, 'effective_period' => $key],
            after: [
                'description' => $schedule->description,
                'amount_minor' => $minor,
                'effective_period' => $key,
                'reason' => $reason,
                'units' => $units,
                'total_minor' => $units * $minor,
                'applied' => $applyNow,
                'corrects' => $existing === null ? null : (int) $existing->to_minor,
            ],
        );

        $parts = $this->effectParts($schedule, $this->rateChanges($schedule), $period, $this->nextPeriod($schedule), $units);

        return [
            'period' => $period,
            'from_minor' => $from,
            'to_minor' => $minor,
            'units' => $units,
            'total_minor' => $units * $minor,
            'applied' => $applyNow,
            'summary' => sprintf(
                '%s: %s a unit from the %s charge run%s.',
                $schedule->description,
                MoneyFormatter::fromMinor($minor, $schedule->currency),
                $period->format('F Y'),
                $units === 0
                    ? ''
                    : sprintf(' · %d %s, %s a month', $units, $units === 1 ? 'unit' : 'units', MoneyFormatter::fromMinor($units * $minor, $schedule->currency)),
            ),
            'effect' => implode(' ', $parts),

            // The last sentence on its own — the month by name that this change left alone.
            'untouched' => $parts[count($parts) - 1],
        ];
    }

    /**
     * What a rate change from this month would reach, in the words the screen
     * prints BEFORE anything is saved.
     *
     * M10 §3 is explicit that both pricing screens "show clearly what a change
     * will affect and from when", and the client gave the shape: "Applies from
     * the November 2026 charge run. 212 households on this schedule. October's
     * charges, already posted, are not changed." Built HERE rather than in the
     * page, so the sentence the office reads is the one the tests read, from the
     * same resolver the post uses.
     */
    public function rateChangeEffect(ChargeSchedule $schedule, Carbon $period): string
    {
        return implode(' ', $this->effectParts(
            $schedule,
            $this->rateChanges($schedule),
            $period->copy()->startOfMonth(),
            $this->nextPeriod($schedule),
            $this->unitCount($schedule),
        ));
    }

    /**
     * Make every rate change whose month has arrived the schedule's rate in force.
     *
     * CALLED ON READ, from `board()` — see the note there for why a GET writes.
     * IDEMPOTENT: `applied_at` is the guard, and a change is applied once. In
     * month order, so where two have come due for one schedule the later month's
     * rate is the one left standing.
     *
     * NO ROW LOCK. Two readers sweeping the same change at once write the same
     * two values, so there is nothing a lock would protect — and the rule this
     * project learned the hard way is that a lock is never reached for by habit.
     */
    public function applyDueRateChanges(): int
    {
        $due = DB::connection('tenant')
            ->table(self::RATE_CHANGES)
            ->whereNull('applied_at')
            ->where('effective_period', '<=', Dues::siteDate()->format('Y-m'))
            ->orderBy('effective_period')
            ->orderBy('id')
            ->get();

        foreach ($due as $change) {
            DB::connection('tenant')->transaction(function () use ($change): void {
                DB::connection('tenant')->table('charge_schedules')->where('id', $change->charge_schedule_id)->update([
                    'amount_minor' => (int) $change->to_minor,
                    'updated_at' => Carbon::now(),
                ]);

                DB::connection('tenant')->table(self::RATE_CHANGES)->where('id', $change->id)->update([
                    'applied_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            });
        }

        return $due->count();
    }

    /**
     * What one month bills a unit on this schedule — THE ONE RESOLVER.
     *
     * `preview()` and `post()` both ask it, so a month is shown and billed at the
     * same rate. See `resolveRate()` for how it reads the changes.
     */
    public function rateForPeriod(ChargeSchedule $schedule, Carbon|string $period): int
    {
        $key = $period instanceof Carbon ? $period->format('Y-m') : $period;

        return $this->resolveRate($schedule, $this->rateChanges($schedule), $key);
    }

    /**
     * The period the next run bills: the earliest month, from the first this
     * schedule ever posted, that has no run standing — or this month, for a
     * schedule nothing has been posted from (bug hunt B021, B078).
     *
     * IT USED TO BE THE MONTH AFTER THE LATEST RUN STANDING, which skips any month
     * reversed from underneath a later one. September and October posted,
     * September reversed at the wrong rate: the next period was November, so
     * September could never be billed from the schedule again — while the flash
     * had just said it could be posted again. With every run reversed it fell back
     * to this month, passing over a reversed August the same way. A reversed month
     * is now always the next one offered, and "never skipped" holds however the
     * runs were reversed; `reverse()` also takes months latest first, so a gap in
     * the middle is not made in the first place.
     */
    public function nextPeriod(ChargeSchedule $schedule): Carbon
    {
        $runs = ChargeRun::query()
            ->where('charge_schedule_id', $schedule->id)
            ->get(['period', 'reversed_at']);

        // The estate's month (bug hunt B082): on the evening of September 30 in
        // Kingston, UTC's is October, and a schedule defined then could never bill September.
        if ($runs->isEmpty()) {
            return Dues::siteDate()->startOfMonth();
        }

        $standing = $runs->whereNull('reversed_at')->pluck('period')->flip();
        $month = Carbon::createFromFormat('Y-m-d', $runs->min('period').'-01')->startOfDay();

        while ($standing->has($month->format('Y-m'))) {
            $month->addMonthNoOverflow();
        }

        return $month;
    }

    /**
     * What a run for this period would post. Posts nothing.
     *
     * @return array{period: string, label: string, due_on: string, due_label: string, count: int, total_minor: int, total: string, rate_minor: int, rate: string}
     */
    public function preview(ChargeSchedule $schedule, Carbon $period): array
    {
        // "This month or next" at the estate, not two months ahead of it on the evening of the 30th (bug hunt B082).
        if ($period->greaterThan(Dues::siteDate()->startOfMonth()->addMonthNoOverflow())) {
            throw new DomainException($period->format('F Y').' is more than a month ahead. A run is posted for this month or next, never further out.');
        }

        // The month's OWN rate, not the card's: a change set for this month bills
        // from this month even before the calendar has reached it.
        $rate = $this->rateForPeriod($schedule, $period);
        $amount = Money::ofMinor($rate, $schedule->currency);

        // The count and the total, not the list (M11 A2): the card prints two
        // figures, and the list was every unit on the estate hydrated to count them.
        $list = $this->dues->bulkCount($schedule->scope, $schedule->phase, $amount);
        $due = $period->copy()->day($schedule->due_day);

        return [
            'period' => $period->format('Y-m'),
            'label' => $period->format('F Y'),
            'due_on' => $due->toDateString(),
            'due_label' => $due->format('M j, Y'),
            'count' => $list['count'],
            'total_minor' => $list['total_minor'],
            'total' => MoneyFormatter::fromMinor($list['total_minor'], $schedule->currency),
            'rate_minor' => $rate,
            'rate' => MoneyFormatter::fromMinor($rate, $schedule->currency),
        ];
    }

    /**
     * Post one period — all of it or none — against the figures the preview showed.
     *
     * DECIDED UNDER THE SCHEDULE'S LOCK (bug hunt B020). Two officers posting the
     * same month from two screens used to both read `nextPeriod()` before either
     * had written its run, and both billed the month — every household charged
     * twice. The schedule row is now held `FOR UPDATE` before anything is asked
     * of it, and every check below reads the schedule and its runs as they stand
     * under that lock: the second post waits for the first to commit, then finds
     * the month billed and is refused. `reverse()` takes the same lock, so a
     * month is never posted and reversed at the same instant either.
     */
    public function post(ChargeSchedule $schedule, string $period, int $expectedCount, int $expectedTotalMinor, User $by): ChargeRun
    {
        return DB::connection('tenant')->transaction(function () use ($schedule, $period, $expectedCount, $expectedTotalMinor, $by): ChargeRun {
            $schedule = $this->lockSchedule($schedule->id);

            if (! $schedule->is_active) {
                throw new DomainException('This schedule is stopped. Nothing further is billed from it.');
            }

            $next = $this->nextPeriod($schedule);

            if ($period !== $next->format('Y-m')) {
                throw new DomainException('The next period for this schedule is '.$next->format('F Y').'. A month is billed once, in order, and never skipped.');
            }

            $preview = $this->preview($schedule, $next);

            /*
             * A RATE CHANGED BETWEEN PREVIEW AND POST IS REFUSED HERE TOO. The total
             * sent back is the preview's count times the rate the office saw; a
             * change saved for this month in between moves the total, and the post is
             * refused rather than billed at a figure nobody was shown.
             */
            if ($preview['count'] !== $expectedCount || $preview['total_minor'] !== $expectedTotalMinor) {
                throw new DomainException(sprintf(
                    'The estate register has changed since this run was previewed: it now bills %d units for %s. Nothing has been posted; check the new figures and post again.',
                    $preview['count'],
                    $preview['total'],
                ));
            }

            // The same resolver the preview asked, so the charges and the preview agree.
            $amount = Money::ofMinor($preview['rate_minor'], $schedule->currency);
            // What a household reads on its ledger: the middot, never an em dash (Law 3).
            $description = $schedule->description.' · '.$next->format('F Y');

            $units = Unit::query()
                ->when($schedule->scope === ChargeSchedule::PHASE, static fn ($query) => $query->where('block', $schedule->phase))
                ->orderBy('reference')
                ->get();

            $reference = $this->dues->chargeRun(
                rows: $units->map(static fn (Unit $unit): array => [
                    'unit' => $unit,
                    'amount' => $amount,
                    'description' => $description,
                    'type' => 'dues',
                    'period' => $next->format('Y-m'),
                ])->all(),
                dueOn: $preview['due_on'],
                memo: $description,
                account: $schedule->account_code,
                by: $by,
            );

            $run = ChargeRun::query()->create([
                'charge_schedule_id' => $schedule->id,
                'period' => $next->format('Y-m'),
                'due_on' => $preview['due_on'],
                'unit_count' => $preview['count'],
                'total_minor' => $preview['total_minor'],
                'currency' => $schedule->currency,
                'journal_ref' => (string) $reference,
                'posted_by' => $by->getKey(),
                'posted_by_name' => $by->name,
                'posted_at' => Carbon::now(),
            ]);

            $this->audit->record(
                action: 'estate.charge_run_posted',
                entityType: 'ChargeRun',
                entityId: (string) $run->id,
                after: ['schedule' => $schedule->description, 'period' => $run->period, 'units' => $run->unit_count, 'total_minor' => $run->total_minor, 'journal' => $run->journal_ref],
            );

            return $run;
        });
    }

    /**
     * Reverse a whole run: the mirror entry, the charges out of the ageing, and
     * the reason on the record.
     *
     * ONCE, HOWEVER MANY PRESSES (bug hunt B020). Reversing a 450-unit month
     * writes 451 lines, and "Confirm reversal" used to stay live while it did: a
     * second press — or a second officer — checked `reversed_at` on the run it was
     * handed, found it empty because the first had not committed, and posted a
     * second mirror entry, crediting every household twice and leaving the run
     * naming only the second. The decision is now made under the schedule's lock
     * and the run's, on the run as it stands: the second request waits for the
     * first to commit, then finds the run reversed and is refused.
     */
    public function reverse(ChargeRun $run, string $reason, User $by): ChargeRun
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new DomainException('Say why the run is being reversed, in at least ten characters. Every household it billed will see the charge and its reversal on their statement.');
        }

        $reversed = DB::connection('tenant')->transaction(function () use ($run, $reason, $by): ChargeRun {
            $this->lockSchedule($run->charge_schedule_id);

            $run = ChargeRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if ($run->isReversed()) {
                throw new DomainException('This run was reversed on '.$this->siteDay($run->reversed_at).'. Reversing it twice would bill the month back.');
            }

            /*
             * LATEST FIRST (bug hunt B021, B078). Taking a month out from under a
             * later one left it between two that stand; `nextPeriod()` now finds
             * such a month, but the schedule reads as it was billed — one month
             * after another — only if the reversal starts at the end.
             */
            $latest = $this->latestStanding($run->charge_schedule_id);

            if ($latest !== null && $latest > $run->period) {
                throw new DomainException($this->laterMonthStands($run->period, $latest));
            }

            $journal = Journal::query()->where('reference', $run->journal_ref)->first();

            if ($journal === null) {
                throw new DomainException('The entry this run posted cannot be found, so it cannot be reversed from here.');
            }

            $mirror = $this->ledger->reverse($journal, 'Reversal of '.$journal->memo.': '.$reason, $by);

            DB::connection('tenant')->table('charges')
                ->where('journal_ref', $run->journal_ref)
                ->update(['status' => 'reversed', 'updated_at' => Carbon::now()]);

            $run->forceFill([
                'reversal_journal_ref' => $mirror->reference,
                'reversed_at' => Carbon::now(),
                'reversed_by' => $by->getKey(),
                'reversed_by_name' => $by->name,
                'reversal_reason' => mb_substr($reason, 0, 300),
            ])->save();

            $this->audit->record(
                action: 'estate.charge_run_reversed',
                entityType: 'ChargeRun',
                entityId: (string) $run->id,
                before: ['journal' => $run->journal_ref],
                after: ['reversal_journal' => $mirror->reference, 'reason' => $reason],
            );

            return $run;
        });

        // The caller's copy, brought up to date: the screen's flash names the entry from it.
        $run->setRawAttributes($reversed->getAttributes(), true);

        return $run;
    }

    /**
     * The schedule's row, held `FOR UPDATE` until the caller's transaction ends —
     * the one lock every change to a schedule's runs takes first (bug hunt B020).
     *
     * FIRST, and before any plain read in the transaction: under REPEATABLE READ
     * a transaction's snapshot is taken at its first consistent read, so a check
     * made after this lock sees whatever the request it waited for committed.
     */
    private function lockSchedule(int $id): ChargeSchedule
    {
        return ChargeSchedule::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** "Sep 30, 2026" — the day an instant was at the estate (bug hunt B082), or a dash where there is none. */
    private function siteDay(?Carbon $instant): string
    {
        return $instant === null ? '—' : SiteTime::local($instant)->format('M j, Y');
    }

    /** The latest month still standing on a schedule, as "2026-10", or null when none is. */
    private function latestStanding(int $scheduleId): ?string
    {
        $latest = ChargeRun::query()
            ->where('charge_schedule_id', $scheduleId)
            ->whereNull('reversed_at')
            ->max('period');

        return $latest === null ? null : (string) $latest;
    }

    /** Why a month cannot be reversed while a later one stands — the same sentence on the button and from the service. */
    private function laterMonthStands(string $period, string $latest): string
    {
        return sprintf(
            'Months are reversed latest first, and %s is posted after %s on this schedule. Reverse %s first, so the months this schedule has billed stay one after another with none missing between them.',
            Carbon::createFromFormat('Y-m-d', $latest.'-01')->format('F Y'),
            Carbon::createFromFormat('Y-m-d', $period.'-01')->format('F Y'),
            Carbon::createFromFormat('Y-m-d', $latest.'-01')->format('F Y'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* the rate, month by month */
    /* ------------------------------------------------------------------ */

    /**
     * Every change ever saved for this schedule, earliest month first.
     *
     * @return Collection<int, stdClass>
     */
    private function rateChanges(ChargeSchedule $schedule): Collection
    {
        return DB::connection('tenant')
            ->table(self::RATE_CHANGES)
            ->where('charge_schedule_id', $schedule->id)
            ->orderBy('effective_period')
            ->get();
    }

    /**
     * The rate one month bills, from the changes already read.
     *
     * THE LATEST CHANGE AT OR BEFORE THE MONTH WINS. With none, the month is
     * billed at the rate the schedule was DEFINED at — which is the earliest
     * change's `from_minor` when any change exists, and `amount_minor` only when
     * none does. Not `amount_minor` unconditionally: once a change has come due,
     * `applyDueRateChanges()` has moved that column to the new rate, and a month
     * from before the change (a reversed month posted again) must not be billed
     * at a rate decided after it.
     *
     * @param  Collection<int, stdClass>  $changes  earliest month first
     */
    private function resolveRate(ChargeSchedule $schedule, Collection $changes, string $period): int
    {
        $latest = $changes->filter(static fn (stdClass $change): bool => $change->effective_period <= $period)->last();

        if ($latest !== null) {
            return (int) $latest->to_minor;
        }

        $earliest = $changes->first();

        return $earliest === null ? $schedule->amount_minor : (int) $earliest->from_minor;
    }

    /** How many units the schedule bills today — its phase, or the whole register. */
    private function unitCount(ChargeSchedule $schedule): int
    {
        return Unit::query()
            ->when($schedule->scope === ChargeSchedule::PHASE, static fn ($query) => $query->where('block', $schedule->phase))
            ->count();
    }

    /**
     * What the month before a change bills — the rate the change moves AWAY from.
     *
     * Worked out from the changes as they stand, not read off `from_minor`. That
     * column is what the month before billed WHEN THE CHANGE WAS SAVED, and it
     * goes stale the moment an earlier month is given a change of its own: a rise
     * set for December and then a first step set for October would otherwise go
     * on reading "J$6,200 → J$7,500" for December when November bills J$7,000.
     * `from_minor` stays as the record of what the approver saw; the screen says
     * what is true now.
     *
     * @param  Collection<int, stdClass>  $changes
     */
    private function rateBefore(ChargeSchedule $schedule, Collection $changes, stdClass $change): int
    {
        $previous = Carbon::createFromFormat('Y-m-d', $change->effective_period.'-01')->startOfDay()->subMonthNoOverflow();

        return $this->resolveRate($schedule, $changes, $previous->format('Y-m'));
    }

    /**
     * Whether a saved change still moves the rate at all.
     *
     * @param  Collection<int, stdClass>  $changes
     */
    private function moves(ChargeSchedule $schedule, Collection $changes, stdClass $change): bool
    {
        return (int) $change->to_minor !== $this->rateBefore($schedule, $changes, $change);
    }

    /**
     * Changes saved and not yet in force, for the line under the card.
     *
     * A change that moves the rate nowhere — a month corrected back to what the
     * month before bills — is left off: it is on the audit log, and printing
     * "J$6,200.00 → J$6,200.00" would announce a change that changes nothing.
     *
     * @param  Collection<int, stdClass>  $changes
     * @return list<array<string, mixed>>
     */
    private function pendingRates(ChargeSchedule $schedule, Collection $changes, int $units): array
    {
        return $changes
            ->filter(fn (stdClass $change): bool => $change->applied_at === null && $this->moves($schedule, $changes, $change))
            ->map(fn (stdClass $change): array => [
                'period' => $change->effective_period,
                'label' => Carbon::createFromFormat('Y-m-d', $change->effective_period.'-01')->format('F Y'),
                'from' => MoneyFormatter::fromMinor($this->rateBefore($schedule, $changes, $change), $schedule->currency),
                'to' => MoneyFormatter::fromMinor((int) $change->to_minor, $schedule->currency),
                'bills' => $units === 0 ? null : MoneyFormatter::fromMinor($units * (int) $change->to_minor, $schedule->currency),
                'reason' => $change->reason,
                'by' => $change->changed_by_name,
            ])
            ->values()
            ->all();
    }

    /**
     * The months a change may be set from, each with what it would reach.
     *
     * @param  Collection<int, stdClass>  $changes
     * @return list<array{period: string, label: string, current_minor: int, current: string, effect: string}>
     */
    private function rateOptions(ChargeSchedule $schedule, Collection $changes, Carbon $next, int $units): array
    {
        $options = [];
        $posted = $this->postedLine($schedule);

        // A month already billed is never offered, even after the next one billed (see `amendRate()`).
        $standing = ChargeRun::query()
            ->where('charge_schedule_id', $schedule->id)
            ->whereNull('reversed_at')
            ->pluck('period')
            ->flip();

        for ($i = 0; $i < self::RATE_MONTHS_AHEAD; $i++) {
            $month = $next->copy()->addMonthsNoOverflow($i);

            if ($standing->has($month->format('Y-m'))) {
                continue;
            }

            $current = $this->resolveRate($schedule, $changes, $month->format('Y-m'));

            $options[] = [
                'period' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
                'current_minor' => $current,
                'current' => MoneyFormatter::fromMinor($current, $schedule->currency),
                'effect' => implode(' ', $this->effectParts($schedule, $changes, $month, $next, $units, $posted)),
            ];
        }

        return $options;
    }

    /**
     * The sentences of "what this change reaches", in the order the client wrote
     * them: from when, how many, and what is left alone.
     *
     * @param  Collection<int, stdClass>  $changes
     * @return list<string>
     */
    private function effectParts(ChargeSchedule $schedule, Collection $changes, Carbon $period, Carbon $next, int $units, ?string $postedLine = null): array
    {
        $parts = ['Applies from the '.$period->format('F Y').' charge run.'];

        $parts[] = $units === 0
            ? 'No unit is on this schedule\'s register today, so the new rate bills nobody until one is added.'
            : sprintf('%d %s on this schedule.', $units, $units === 1 ? 'unit' : 'units');

        // The months between the next one billed and the change keep what they bill now.
        $gap = [];

        for ($month = $next->copy(); $month->lessThan($period); $month->addMonthNoOverflow()) {
            $gap[] = $month->copy();
        }

        if ($gap !== []) {
            $rates = array_values(array_unique(array_map(
                fn (Carbon $month): int => $this->resolveRate($schedule, $changes, $month->format('Y-m')),
                $gap,
            )));

            $span = match (count($gap)) {
                1 => $gap[0]->format('F Y').' is',
                2 => $gap[0]->format('F Y').' and '.$gap[1]->format('F Y').' are',
                default => $gap[0]->format('F Y').' to '.$gap[count($gap) - 1]->format('F Y').' are',
            };

            $parts[] = count($rates) === 1
                ? $span.' still billed at '.MoneyFormatter::fromMinor($rates[0], $schedule->currency).' a unit.'
                : $span.' still billed at the rates already set for them.';
        }

        // A change already set for a later month still takes over on its own month.
        $later = $changes->first(fn (stdClass $change): bool => $change->effective_period > $period->format('Y-m')
            && $this->moves($schedule, $changes, $change));

        if ($later !== null) {
            $parts[] = sprintf(
                'The change already set for %s (%s a unit) still takes over from then.',
                Carbon::createFromFormat('Y-m-d', $later->effective_period.'-01')->format('F Y'),
                MoneyFormatter::fromMinor((int) $later->to_minor, $schedule->currency),
            );
        }

        $parts[] = $postedLine ?? $this->postedLine($schedule);

        return $parts;
    }

    /**
     * What is left alone: the latest month standing, by name.
     *
     * The client's own words — "October's charges, already posted, are not
     * changed" — because that is the month an office member worries about when
     * the rate moves, and naming it is what makes the promise checkable.
     */
    private function postedLine(ChargeSchedule $schedule): string
    {
        $standing = ChargeRun::query()
            ->where('charge_schedule_id', $schedule->id)
            ->whereNull('reversed_at')
            ->max('period');

        return match (true) {
            $standing !== null => Carbon::createFromFormat('Y-m-d', $standing.'-01')->format('F Y').'\'s charges, already posted, are not changed.',
            ChargeRun::query()->where('charge_schedule_id', $schedule->id)->exists() => 'Every month posted from this schedule has been reversed, so no charge standing is changed.',
            default => 'Nothing has been posted from this schedule yet, so no charge already raised is changed.',
        };
    }
}
