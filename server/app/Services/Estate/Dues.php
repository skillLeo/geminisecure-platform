<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Charge;
use App\Models\Estate\Journal;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Seeded\Retirements;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Brick\Money\Money;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Charges, payments and what a unit owes — boards 5, 6 and 35.
 *
 * EVERY WRITE HERE RAISES A JOURNAL ENTRY, and the entry is the money. A charge
 * debits Dues Receivable and credits the income account the treasurer picked; a
 * payment debits the bank and credits Dues Receivable. Neither is recorded any
 * other way, so a unit's balance and the receivables control account cannot
 * drift apart — they are the same rows read two ways.
 *
 * NOTHING IN THIS CLASS COMPUTES A BALANCE FROM `charges` AND `payments`. Every
 * figure a screen shows comes from the posted journal lines, and the billing
 * records supply only what bookkeeping does not: a due date, a receipt number,
 * a method. That is what makes the totals traceable — a dashboard figure derived
 * from the billing tables would agree with the ledger by coincidence, and the
 * coincidence is what fails at an audit.
 *
 * EACH CHARGE AGES IN ITS OWN BUCKET (EST-S-02, M85 A3; it was the whole balance
 * in the oldest open charge's bucket, so a nine-day-old October charge read 90+).
 * The ageing totals and a unit's strip lay the unit's balance over its charges,
 * newest first, each share in the bucket of its own charge (`chargeAgeing()`).
 * A unit's SINGLE badge still says how long it has had anything outstanding,
 * by its oldest open charge (`bucketsFrom()`): the board's one badge per unit,
 * and the question "how long has this unit been in arrears".
 */
class Dues
{
    /** The buckets board 5 draws, in order, with their lower bound in days. */
    public const BUCKETS = [
        'current' => 0,
        'd30' => 30,
        'd60' => 60,
        'd90' => 90,
    ];

    /** What each bucket is called on screen. */
    public const BUCKET_LABELS = [
        'current' => 'Current',
        'd30' => '30 days',
        'd60' => '60 days',
        'd90' => '90+ days',
    ];

    /**
     * What a screen says where the estate's own position would be (M10 §5).
     *
     * ONE SENTENCE FOR EVERY SCREEN THAT WITHHOLDS IT, so the dashboard, the
     * arrears register, the receipt register and the chart of accounts cannot
     * give four different accounts of one rule. It names no figure and no
     * amount: it ships to the browser beside the null it explains, and a reason
     * that described what it was hiding would be the leak describing itself.
     *
     * TRUE FOR EVERY ROLE IT REACHES. The Admin Assistant holds the ledger and
     * not Reports; the Property Manager, the Secretary and the Head of Security
     * hold Reports and not the ledger. "Does not hold both" is the one sentence
     * that is accurate about all four.
     */
    public const POSITION_WITHHELD = 'The estate\'s totals, such as what it is owed, what it has taken and what every household owes side by side, are for the committee. Seeing them needs Reports access as well as this page\'s, and your role does not have both.';

    /**
     * What the arrears register says in place of its rows (M10 §5).
     *
     * NOT `POSITION_WITHHELD` WITH A SUFFIX, because that sentence is about
     * figures and this screen withholds more than figures: which households
     * are behind at all. It names no household and no figure, for the same
     * reason — it ships beside the empty list it explains — and it says where
     * the office's real task goes instead, so the refusal is not a dead end.
     * Only a viewer who holds the ledger reaches this screen, so "does not hold
     * both" is true of everyone who reads it.
     */
    public const ARREARS_WITHHELD = 'Which households are behind with their dues is part of the estate\'s own picture, the same as what they owe, and reading it needs Reports access together with Dues & ledger. Your role does not hold both. To record a payment or a charge, open the household from Residents and go to its ledger.';

    /** What a charge against anything but an income account in use is told — the form and the service say the same. */
    public const NOT_AN_INCOME_ACCOUNT = 'A charge credits an income account that is in use. The receivable, the bank, a payable or an expense would record a debt the accounts do not carry, and a posted entry cannot be edited.';

    /**
     * How many rows one INSERT or UPDATE of a bulk charge carries (M11 A2) —
     * fifteen columns a charge, so 7,500 placeholders a statement, well inside
     * MySQL's 65,535.
     */
    private const ROWS_PER_INSERT = 500;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly Receipts $receipts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The estate's today, as a calendar date: midnight UTC carrying the site's date.
     *
     * THE MONEY SIDE'S "TODAY" IS THE ESTATE'S (bug hunt B023, B082). The server's
     * clock is UTC (M4 §3.1), and from 7 PM in Kingston its calendar is already on
     * tomorrow: an evening payment was dated tomorrow and, on the last evening of
     * a month, posted into the next; a charge aged a day early; a new schedule
     * started at next month. Every "today" in dues, collections and the charge
     * schedule is this one instead.
     *
     * AS MIDNIGHT UTC, because that is how every date column here is read — a
     * charge's `due_on`, a journal's `posted_on`, a payment's `received_at` — so
     * comparing and counting days against them is arithmetic between two dates,
     * not between a date and a moment five hours away from it.
     */
    public static function siteDate(): Carbon
    {
        return Carbon::parse(SiteTime::today()->toDateString());
    }

    /**
     * WHAT IS OWED IS WHAT IS OWED BY THIS DAY (EST-B-01, M83 §1.2 item 2). A charge posts on the day it falls due, so
     * one billed in advance sits in the ledger with a later date and is owed from that day, not before. The chart of
     * accounts and the Profit & Loss read the ledger as at the estate's today; every reading of a unit's receivable
     * here (its balance, the charges a payment is walked over, its statement) takes the same day unless one is named,
     * so Dues & ledger and Accounting's 1200 say the same figure. The one place the day is chosen.
     */
    private static function owedAsAt(?Carbon $asAt): Carbon
    {
        return $asAt?->copy() ?? self::siteDate();
    }

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * Who a whole-phase or whole-estate charge would reach — board 35 (12 §2).
     *
     * NOTHING IS POSTED HERE. A charge raised against the whole estate is
     * hundreds of journal entries at once, and the one thing a treasurer needs
     * before pressing it is the list: which units, how many, and what the total
     * comes to. The reason on the old inert control asked for exactly that.
     *
     * A UNIT WITH NO HOUSEHOLD IS STILL BILLED. Maintenance is owed by the
     * address, not by whoever is living at it — an empty lot that escaped the
     * annual assessment because nobody had moved in would be a hole in the
     * estate's income that nobody would find until the audit.
     *
     * @param  string  $scope  'phase' or 'estate'
     * @return array{scope: string, phase: string|null, units: list<array{id: int, reference: string, block: string|null}>, count: int, total_minor: int}
     */
    public function bulkPreview(string $scope, ?string $phase, Money $amount): array
    {
        $phase = $this->bulkScope($scope, $phase, $amount);

        $units = Unit::query()
            ->when($scope === 'phase', static fn ($query) => $query->where('block', $phase))
            ->orderBy('reference')
            ->get(['id', 'reference', 'block']);

        if ($units->isEmpty()) {
            throw new DomainException($this->noUnitsSentence($scope, $phase));
        }

        return [
            'scope' => $scope,
            'phase' => $phase,
            'units' => $units->map(static fn (Unit $unit): array => [
                'id' => $unit->id,
                'reference' => $unit->reference,
                'block' => $unit->block,
            ])->all(),
            'count' => $units->count(),
            'total_minor' => $amount->getMinorAmount()->toInt() * $units->count(),
        ];
    }

    /**
     * How many units a charge in this scope would reach, and what it would come
     * to — `bulkPreview()`'s two figures without its list (M11 A2).
     *
     * The charge schedule's card prints the count and the total for next month's
     * run, and asking `bulkPreview()` for them hydrated every unit on the estate —
     * all 2,000 at Phoenix Park — to count them. This counts them in SQL, over the
     * same query, after the same refusals in the same words.
     *
     * @return array{scope: string, phase: string|null, count: int, total_minor: int}
     */
    public function bulkCount(string $scope, ?string $phase, Money $amount): array
    {
        $phase = $this->bulkScope($scope, $phase, $amount);

        $count = (int) Unit::query()
            ->when($scope === 'phase', static fn ($query) => $query->where('block', $phase))
            ->count();

        if ($count === 0) {
            throw new DomainException($this->noUnitsSentence($scope, $phase));
        }

        return [
            'scope' => $scope,
            'phase' => $phase,
            'count' => $count,
            'total_minor' => $amount->getMinorAmount()->toInt() * $count,
        ];
    }

    /**
     * The refusals a bulk charge meets before any unit is read, and the phase it
     * names, trimmed. One copy, so the preview and the count cannot word a refusal
     * two ways.
     */
    private function bulkScope(string $scope, ?string $phase, Money $amount): ?string
    {
        if (! in_array($scope, ['phase', 'estate'], true)) {
            throw new DomainException('A charge is posted to one unit, one phase, or the whole estate.');
        }

        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A charge must be a positive amount. Crediting a unit is a credit note, which is a '.
                'different act with a different record, not a negative charge.'
            );
        }

        $phase = $phase === null || trim($phase) === '' ? null : trim($phase);

        if ($scope === 'phase' && $phase === null) {
            throw new DomainException('Name the phase. A phase charge with no phase would reach the whole estate.');
        }

        return $phase;
    }

    private function noUnitsSentence(string $scope, ?string $phase): string
    {
        return $scope === 'phase'
            ? 'No unit stands in '.$phase.'. Check the phase name against the estate register.'
            : 'This estate has no units on its register, so there is nothing to bill.';
    }

    /**
     * Post the same charge to every unit in a scope — ALL OF THEM, OR NONE.
     *
     * One transaction around the lot. A half-posted assessment bills some
     * households and not others, and the ones it missed find out when the
     * arrears run chases the ones it did not.
     *
     * EACH UNIT GETS ITS OWN CHARGE AND ITS OWN JOURNAL. Not one entry with
     * many lines: a charge is a unit's receivable, the sub-ledger ties to 1200
     * unit by unit, and a household disputing theirs has to be able to point at
     * one entry.
     *
     * ONCE PER PREVIEW, AND THE DATABASE SAYS SO (bug hunt B020). The preview is
     * held in the session under a token, and the post used to "spend" it by
     * forgetting it after billing everybody — which two posts carrying the same
     * session (two tabs, or a retry while the first was still billing) both got
     * past. Every charge a bulk post raises now carries that token, and no unit
     * may carry one token twice: a second post of the same preview is refused at
     * its first unit — after waiting, if the first has not committed yet — and
     * the whole list rolls back with it.
     *
     * @param  list<int>  $unitIds
     * @param  string|null  $previewToken  the preview this list was drawn in; a second post of it is refused
     * @return array{posted: int, total_minor: int}
     */
    public function chargeMany(
        array $unitIds,
        Money $amount,
        string $description,
        Carbon|string $dueOn,
        string $type,
        string $account,
        User $by,
        ?string $previewToken = null,
    ): array {
        $units = Unit::query()->whereIn('id', $unitIds)->orderBy('reference')->get();

        if ($units->count() !== count($unitIds)) {
            throw new DomainException('The estate register has changed since this list was drawn: a unit on it is no longer there. Nothing has been posted; take the preview again.');
        }

        if ($units->isEmpty()) {
            return ['posted' => 0, 'total_minor' => 0];
        }

        // `charge()`'s own refusals, in its order, before anything is written.
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A charge must be a positive amount. Crediting a unit is a credit note, which is a '.
                'different act with a different record, not a negative charge.'
            );
        }

        $accountId = $this->incomeAccountId($account);

        /*
         * EXACTLY WHAT `charge()` POSTS, UNIT BY UNIT — WRITTEN IN BATCHES (M11 A2).
         *
         * Posting the list through `charge()` one unit at a time was about eight
         * statements a unit: sixteen thousand for a whole-estate levy at Phoenix
         * Park, long enough to outlast a request. The records are the same records:
         *
         *   - each unit its own charge, with the reference the next `charge()` would
         *     have been handed (`CHG-2026-09-0041`, `-0042`, … in the list's order);
         *   - each charge its OWN journal entry — Dr 1200 against the unit, Cr the
         *     income account, the description as the memo — whose `source_id` is that
         *     charge, and whose reference the charge carries, through
         *     `Ledger::postEach()`, which makes `post()`'s checks entry by entry;
         *   - each charge carrying the preview's token, so the unique (bulk_token,
         *     unit_id) index refuses a second post of the same preview at its
         *     first chunk (bug hunt B020) — waiting, as a single INSERT waits, if
         *     the first post has not committed yet — and the whole list rolls back;
         *   - one transaction around all of it, so the estate is billed whole or
         *     not at all, exactly as before.
         *
         * `EstateBulkChargeIsUnchangedTest` posts one list both ways and compares
         * every column of every row they write.
         */
        try {
            return DB::connection('tenant')->transaction(fn (): array => $this->writeChargeList(
                $units->values()->all(), $amount, $description, $dueOn, $type, $account, $accountId, $by, $previewToken,
            ));
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('This list has already been posted: every unit on it carries the charge once. Nothing more was raised.');
        }
    }

    /**
     * `chargeMany()`'s writes, inside its transaction: the charges, their entries,
     * and each charge's `journal_ref`, in that order — `charge()`'s order.
     *
     * @param  list<Unit>  $units
     * @return array{posted: int, total_minor: int}
     */
    private function writeChargeList(
        array $units,
        Money $amount,
        string $description,
        Carbon|string $dueOn,
        string $type,
        string $account,
        int $accountId,
        User $by,
        ?string $previewToken,
    ): array {
        $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);
        $minor = $amount->getMinorAmount()->toInt();
        $currency = $amount->getCurrency()->getCurrencyCode();
        $sequence = $this->nextSequence('CHG', $due);
        $now = now();

        $rows = [];

        foreach ($units as $i => $unit) {
            $rows[] = [
                'unit_id' => $unit->id,
                'type' => $type,
                'period' => $due->format('Y-m'),
                'reference' => $this->stampReference('CHG', $due, $sequence + $i),
                'description' => $description,
                'amount_minor' => $minor,
                'currency' => $currency,
                'account_id' => $accountId,
                'due_on' => $due->toDateString(),
                'status' => 'outstanding',
                'posted_by' => $by->getKey(),
                'posted_by_name' => $by->name,
                'bulk_token' => $previewToken,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
            DB::connection('tenant')->table('charges')->insert($chunk);
        }

        // Each charge's id, by the reference that is unique to it.
        $ids = [];

        foreach (array_chunk(array_column($rows, 'reference'), self::ROWS_PER_INSERT) as $references) {
            $ids += DB::connection('tenant')->table('charges')->whereIn('reference', $references)->pluck('id', 'reference')->all();
        }

        $journals = $this->ledger->postEach(
            entries: array_map(static fn (array $row): array => [
                'memo' => $description,
                'postings' => [
                    Posting::debit('1200', $minor, $description, unitId: $row['unit_id']),
                    Posting::credit($account, $minor, $description),
                ],
                'source_id' => (int) $ids[$row['reference']],
            ], $rows),
            on: $due,
            source: Ledger::SOURCE_CHARGE,
            by: $by,
            prefix: 'CHG',
        );

        /*
         * And each charge carries its entry's reference, as `charge()` leaves it
         * — one UPDATE a chunk rather than one a charge. `charge()` writes the
         * charge first and sets `journal_ref` once its entry exists, because the
         * entry's `source_id` is the charge; the same order is kept here.
         */
        foreach (array_chunk(array_keys($rows), self::ROWS_PER_INSERT) as $chunk) {
            $cases = [];
            $bindings = [];
            $keys = [];

            foreach ($chunk as $k) {
                $id = (int) $ids[$rows[$k]['reference']];

                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $id, $journals[$k]);
                $keys[] = $id;
            }

            DB::connection('tenant')->update(
                'UPDATE `charges` SET `journal_ref` = CASE `id` '.implode(' ', $cases).' END, `updated_at` = ? '
                .'WHERE `id` IN ('.implode(', ', array_fill(0, count($keys), '?')).')',
                [...$bindings, $now, ...$keys],
            );
        }

        return [
            'posted' => count($units),
            'total_minor' => $minor * count($units),
        ];
    }

    /**
     * Bill a unit, and post the entry that makes it real.
     *
     * @param  string  $account  the income account code the charge credits — board 35 draws it as a field, and anything but an income account in use is refused
     * @param  string|null  $bulkToken  the whole-phase or whole-estate preview this charge was posted from — see `chargeMany()`
     */
    public function charge(
        Unit $unit,
        Money $amount,
        string $description,
        Carbon|string $dueOn,
        string $type = 'dues',
        string $account = '4000',
        ?string $period = null,
        ?User $by = null,
        ?string $bulkToken = null,
    ): Charge {
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A charge must be a positive amount. Crediting a unit is a credit note, which is a '.
                'different act with a different record, not a negative charge.'
            );
        }

        $accountId = $this->incomeAccountId($account);

        return DB::connection('tenant')->transaction(function () use (
            $unit, $amount, $description, $dueOn, $type, $account, $accountId, $period, $by, $bulkToken
        ): Charge {
            $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);
            $minor = $amount->getMinorAmount()->toInt();

            $charge = Charge::create([
                'unit_id' => $unit->id,
                'type' => $type,
                'period' => $period ?? $due->format('Y-m'),
                'reference' => $this->nextReference('CHG', $due),
                'description' => $description,
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'account_id' => $accountId,
                'due_on' => $due->toDateString(),
                'status' => 'outstanding',
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
                'bulk_token' => $bulkToken,
            ]);

            $entry = $this->ledger->post(
                memo: $description,
                postings: [
                    Posting::debit('1200', $minor, $description, unitId: $unit->id),
                    Posting::credit($account, $minor, $description),
                ],
                on: $due,
                source: Ledger::SOURCE_CHARGE,
                sourceId: $charge->id,
                by: $by,
                prefix: 'CHG',
            );

            $charge->forceFill(['journal_ref' => $entry->reference])->save();

            return $charge;
        });
    }

    /**
     * REVERSE ONE CHARGE FROM THE CONSOLE, WITH A REASON (M53 §2, the Sunday rehearsal's dues step; Hassam, 9 October).
     *
     * A month's run was reversible, whole, from the charge schedule; one charge only by the server command
     * `ledger:reverse-entries`. What may be reversed alone is that command's rule (`whyNotReversible()`): a charge that
     * owns its entry, posted on its own or as one of a bulk post (each of which has its own); never one inside a month's
     * run, never the demonstration's sample, never twice. Its mirror goes through `Ledger::reverse`, dated today, so the
     * charge and its reversal both stay on the statement; the charge reads `reversed`, which the arrears, the statements
     * and the resident's app already leave out; the reason is on the reversing entry and in the audit log. Once, however
     * many presses: the charge is held `FOR UPDATE` and asked again, as `Ledger::reverse` asks its entry.
     */
    public function reverseCharge(Charge $charge, string $reason, User $by): Journal
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new DomainException('Say why, in ten characters at least. The reason goes on the reversing entry and in the audit log.');
        }

        return DB::connection('tenant')->transaction(function () use ($charge, $reason, $by): Journal {
            $locked = Charge::query()->whereKey($charge->getKey())->lockForUpdate()->firstOrFail();
            $refusal = $this->whyNotReversible($locked);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            $journal = Journal::query()->where('reference', $locked->journal_ref)->firstOrFail();
            $mirror = $this->ledger->reverse($journal, 'Reversal of '.$locked->reference.' ('.$locked->description.'): '.$reason, $by);

            $before = (string) $locked->status;
            $locked->forceFill(['status' => 'reversed'])->save();

            $this->audit->record(
                action: 'estate.charge_reversed',
                entityType: 'Charge',
                entityId: (string) $locked->id,
                before: ['reference' => $locked->reference, 'journal' => $locked->journal_ref, 'amount_minor' => (int) $locked->amount_minor, 'status' => $before],
                after: ['status' => 'reversed', 'reversal_journal' => $mirror->reference, 'reason' => $reason],
            );

            return $mirror;
        });
    }

    /**
     * Why this charge cannot be reversed on its own, or null when it can: `ledger:reverse-entries`'s rule for one charge.
     */
    public function whyNotReversible(Charge $charge): ?string
    {
        if ($charge->status === 'reversed') {
            return $charge->reference.' is reversed already. Reversing it again would bill it back.';
        }

        if ($this->inTheSample($charge)) {
            return $charge->reference.' is part of the demonstration\'s sample, which is never reversed.';
        }

        $journal = $charge->journal_ref === null ? null : Journal::query()->where('reference', $charge->journal_ref)->first();

        if (! $journal instanceof Journal) {
            return 'The entry '.$charge->reference.' posted cannot be found, so it cannot be reversed from here.';
        }

        $sharing = Charge::query()->where('journal_ref', $charge->journal_ref)->count();

        if ($sharing > 1 || $journal->source !== Ledger::SOURCE_CHARGE || (int) $journal->source_id !== (int) $charge->id) {
            return $charge->reference.' was posted in a month\'s run, in one entry with other households. A run is reversed whole, from the charge schedule.';
        }

        if ($journal->isReversed()) {
            return 'The entry '.$charge->reference.' posted is reversed already.';
        }

        return null;
    }

    /** Part of the demonstration's sample: marked `seeded_at`, or inside a span the seed recorded for this estate. */
    private function inTheSample(Charge $charge): bool
    {
        return isset($this->sampleMarked([(int) $charge->id])[(int) $charge->id])
            || self::inSampleSpans($this->sampleChargeSpans(), (int) $charge->id);
    }

    /**
     * Which of these charges carry the seeder's mark, asked by name: a `*` select never returns the mark (M33 §5), so
     * a model's `seeded_at` always reads null. `ledger:reverse-entries` asks the same way.
     *
     * @param  list<int>  $ids
     * @return array<int, true>
     */
    private function sampleMarked(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_fill_keys(DB::connection('tenant')->table('charges')
            ->whereIn('id', $ids)
            ->whereNotNull('seeded_at')
            ->pluck('id')
            ->map(intval(...))
            ->all(), true);
    }

    /**
     * Inside a span the seed recorded: after its first id, up to and including its last, as `ledger:reverse-entries`
     * reads `sample_data_ranges`.
     *
     * @param  Collection<int, array{0: int, 1: int}>  $spans
     */
    private static function inSampleSpans(Collection $spans, int $id): bool
    {
        return $spans->contains(static fn (array $span): bool => $id > $span[0] && $id <= $span[1]);
    }

    /** @return Collection<int, array{0: int, 1: int}> */
    private function sampleChargeSpans(): Collection
    {
        return DB::connection('mysql')->table('sample_data_ranges')
            ->where('tenant_id', (string) tenant()?->getTenantKey())
            ->where('connection', 'tenant')
            ->where('table_name', 'charges')
            ->get(['from_id', 'to_id'])
            ->map(static fn (object $range): array => [(int) $range->from_id, (int) $range->to_id]);
    }

    /**
     * WHAT EACH CHARGE LINE ON A STATEMENT PAGE MAY HAVE DONE TO IT (the console's Reverse). A line whose charge may be
     * reversed alone carries `reverse` (the charge, to name it on the confirmation); a reversed one says `reversed`; a
     * line of a month's run or of the demonstration's sample carries neither, because a control it could not use would
     * only refuse. Two reads for the page, whatever its length (the unit ledger's query budget, at 2,000 lots): the
     * lines' charges with their sample mark asked by name, and the seed's recorded spans. Whether the charge owns its
     * entry is read off the line itself (the entry's source), and a reversed charge says so in its status, which every
     * reversal (a run's, `ledger:reverse-entries`, this one) sets.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withReversals(array $rows): array
    {
        $refs = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['reference'],
            array_filter($rows, static fn (array $row): bool => ($row['charge_minor'] ?? null) !== null && ($row['source'] ?? null) === Ledger::SOURCE_CHARGE),
        )));

        $charges = $refs === [] ? collect() : Charge::query()
            ->whereIn('journal_ref', $refs)
            ->select('charges.*')
            ->selectRaw('(`seeded_at` IS NOT NULL) AS `in_sample`')
            ->get()
            ->groupBy('journal_ref');
        $spans = $charges->isEmpty() ? collect() : $this->sampleChargeSpans();

        return array_map(static function (array $row) use ($charges, $spans): array {
            $owned = ($row['charge_minor'] ?? null) !== null && ($row['source'] ?? null) === Ledger::SOURCE_CHARGE;
            $group = $owned ? ($charges[$row['reference']] ?? null) : null;
            $sourceId = $row['source_id'] ?? null;

            unset($row['source'], $row['source_id']);
            $row['reverse'] = null;
            $row['reversed'] = false;

            if ($group === null || $group->count() !== 1) {
                return $row;
            }

            /** @var Charge $charge */
            $charge = $group->first();

            if ((int) $sourceId !== (int) $charge->id) {
                return $row;
            }

            if ($charge->status === 'reversed') {
                $row['reversed'] = true;

                return $row;
            }

            if (! (bool) $charge->getAttribute('in_sample') && ! self::inSampleSpans($spans, (int) $charge->id)) {
                $row['reverse'] = [
                    'charge' => (int) $charge->id,
                    'reference' => (string) $charge->reference,
                    'description' => (string) $charge->description,
                    'amount_minor' => (int) $charge->amount_minor,
                ];
            }

            return $row;
        }, $rows);
    }

    /**
     * Record money received against a unit, and post the entry.
     *
     * THE DAY-ONE COLLECTION PATH. No card gateway exists (Q-012, held back),
     * so every jamaican dollar an estate collects arrives as cash, a cheque or
     * a bank transfer and is keyed here, from board 6's "Record manual
     * payment". Dr 1000 Bank, Cr 1200 Dues Receivable against the unit.
     *
     * `receivedAt` and `enteredAt` are separate arguments and neither defaults
     * to the other. A guard takes cash at the gate on Friday and the treasurer
     * keys it on Monday; the resident's receipt is dated Friday, and an arrears
     * report that used the entry date would show them in default over a weekend
     * they had already paid for.
     *
     * THE RECEIPT NUMBER IS NOT A PARAMETER. It is allocated from the estate's
     * sequence inside this transaction, as ruled — "allocated at posting, never
     * at draft" — so nothing that calls this can choose one, reuse one, or hold
     * one against a payment that never posts.
     */
    public function receive(
        Unit $unit,
        Money $amount,
        string $method,
        Carbon|string $receivedAt,
        string $bankAccount = '1000',
        ?User $by = null,
        ?string $gatewayRef = null,
        ?Money $gatewayFee = null,
        ?string $reference = null,
        bool $demonstration = false,
    ): Payment {
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A payment must be a positive amount. Reversing one is a reversal, which leaves both '.
                'records standing — not a negative receipt.'
            );
        }

        if (! in_array($method, [Payment::CASH, Payment::CHEQUE, Payment::BANK, Payment::CARD], true)) {
            throw new DomainException('A payment arrives as cash, a cheque, a bank transfer or a card. "'.$method.'" is none of those.');
        }

        /*
         * THE DAY THE MONEY ARRIVED, AT THE ESTATE (bug hunt B023). The date the
         * office typed is taken as it was typed, a moment handed in by code is the
         * day it was at the estate at that moment, and "the future" is anything
         * after the estate's today — not UTC's, which at 8:30 PM in Kingston has
         * already turned, and used to let tomorrow's date through and post it into
         * tomorrow's period. See `Payment::calendarDay()`.
         */
        $received = Payment::calendarDay($receivedAt);

        if ($received->greaterThan(self::siteDate())) {
            throw new DomainException(
                'A payment cannot be received in the future. The date on a receipt is the day the money arrived, at the estate.'
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $unit, $amount, $method, $received, $bankAccount, $by, $gatewayRef, $gatewayFee, $reference, $demonstration
        ): Payment {
            $minor = $amount->getMinorAmount()->toInt();

            $payment = Payment::create([
                'unit_id' => $unit->id,

                // ALLOCATED HERE, AT POSTING, and never before: the ruling's
                // sequence hands the number out inside this transaction, so a
                // posting that fails takes the number back with it.
                'receipt_no' => $this->receipts->next(),
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'method' => $method,
                'reference' => $reference,
                'received_at' => $received,
                'entered_at' => now(),
                'received_by' => $by?->getKey(),
                'received_by_name' => $by?->name,
                'gateway_ref' => $gatewayRef,
                'gateway_fee_minor' => $gatewayFee?->getMinorAmount()->toInt(),
                'status' => 'recorded',
                'is_demonstration' => $demonstration,
            ]);

            // The console's middot, never an em dash (Law 3): a journal's memo is read on the account, the journal and the statement.
            $memo = $demonstration
                ? 'DEMONSTRATION card payment (no money) · receipt '.$payment->receipt_no
                : 'Payment received · receipt '.$payment->receipt_no;

            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit($bankAccount, $minor, $memo),
                    Posting::credit('1200', $minor, $memo, unitId: $unit->id),
                ],
                on: $received,
                source: Ledger::SOURCE_PAYMENT,
                sourceId: $payment->id,
                by: $by,
                prefix: 'RCT',
            );

            $payment->forceFill(['journal_ref' => $entry->reference])->save();

            return $payment;
        });
    }

    /**
     * A whole month's dues, posted as ONE entry.
     *
     * This is how an estate actually bills: a dues run on the first of the
     * month, one entry, one credit to income and a debit line per unit. Four
     * hundred and fifty separate entries would be four hundred and fifty
     * references for a single act, and a treasurer reviewing the month would
     * have to read all of them to see what was billed.
     *
     * The sub-ledger is unaffected — each unit still gets its own line, which
     * is what its statement and the control-account tie both read.
     *
     * @param  array<int, array{unit: Unit, amount: Money, description: string, type?: string, period?: string}>  $rows
     * @return string|null the reference of the one entry posted, or null when there was nothing to bill
     */
    public function chargeRun(
        array $rows,
        Carbon|string $dueOn,
        string $memo,
        string $account = '4000',
        ?User $by = null,
    ): ?string {
        if ($rows === []) {
            return null;
        }

        $accountId = $this->incomeAccountId($account);

        return DB::connection('tenant')->transaction(function () use ($rows, $dueOn, $memo, $account, $accountId, $by): string {
            $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);

            $postings = [];
            $total = 0;
            $charges = [];

            /*
             * The sequence is taken once and advanced in memory. Asking the
             * table for the next reference inside the loop would hand every row
             * in the batch the same one, because none of them is written until
             * the loop ends.
             */
            $sequence = $this->nextSequence('CHG', $due);

            foreach ($rows as $row) {
                $minor = $row['amount']->getMinorAmount()->toInt();
                $total += $minor;

                $charges[] = [
                    'unit_id' => $row['unit']->id,
                    'type' => $row['type'] ?? 'dues',
                    'period' => $row['period'] ?? $due->format('Y-m'),
                    'reference' => $this->stampReference('CHG', $due, $sequence++),
                    'description' => $row['description'],
                    'amount_minor' => $minor,
                    'currency' => $row['amount']->getCurrency()->getCurrencyCode(),
                    'account_id' => $accountId,
                    'due_on' => $due->toDateString(),
                    'status' => 'outstanding',
                    'posted_by' => $by?->getKey(),
                    'posted_by_name' => $by?->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $postings[] = Posting::debit('1200', $minor, $row['description'], unitId: $row['unit']->id);
            }

            $postings[] = Posting::credit($account, $total, $memo);

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $due,
                source: Ledger::SOURCE_CHARGE,
                by: $by,
                prefix: 'CHG',
            );

            foreach ($charges as $i => $charge) {
                $charges[$i]['journal_ref'] = $entry->reference;
            }

            DB::connection('tenant')->table('charges')->insert($charges);

            return $entry->reference;
        });
    }

    /**
     * A batch of receipts banked together, posted as ONE entry.
     *
     * A bank sweep is one deposit and one entry. Each payment keeps its own
     * receipt number and its own credit line, so the resident's statement still
     * shows their payment and nobody else's.
     *
     * @param  array<int, array{unit: Unit, amount: Money, method: string, received_at: Carbon|string}>  $rows
     */
    public function paymentRun(
        array $rows,
        Carbon|string $bankedOn,
        string $memo,
        string $bankAccount = '1000',
        ?User $by = null,
    ): void {
        if ($rows === []) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($rows, $bankedOn, $memo, $bankAccount, $by): void {
            $banked = $bankedOn instanceof Carbon ? $bankedOn->copy() : Carbon::parse($bankedOn);

            $postings = [];
            $total = 0;
            $payments = [];

            foreach ($rows as $row) {
                $minor = $row['amount']->getMinorAmount()->toInt();
                $total += $minor;

                // One number per receipt from the estate's sequence, under
                // its lock, inside this transaction — the same allocation a
                // single payment gets, so a banked batch and a keyed receipt
                // cannot collide.
                $receipt = $this->receipts->next();
                $lineMemo = 'Payment received · receipt '.$receipt;

                // The calendar day, read as `receive()` reads it (bug hunt B023).
                $received = Payment::calendarDay($row['received_at']);

                $payments[] = [
                    'unit_id' => $row['unit']->id,
                    'receipt_no' => $receipt,
                    'amount_minor' => $minor,
                    'currency' => $row['amount']->getCurrency()->getCurrencyCode(),
                    'method' => $row['method'],
                    'received_at' => $received,
                    'entered_at' => now(),
                    'received_by' => $by?->getKey(),
                    'received_by_name' => $by?->name,
                    'status' => 'recorded',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $postings[] = Posting::credit('1200', $minor, $lineMemo, unitId: $row['unit']->id);
            }

            $postings[] = Posting::debit($bankAccount, $total, $memo);

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $banked,
                source: Ledger::SOURCE_PAYMENT,
                by: $by,
                prefix: 'RCT',
            );

            foreach ($payments as $i => $payment) {
                $payments[$i]['journal_ref'] = $entry->reference;
            }

            DB::connection('tenant')->table('payments')->insert($payments);
        });
    }

    /* ------------------------------------------------------------------ */
    /* who may read the estate's own position (M10 §5) */
    /* ------------------------------------------------------------------ */

    /**
     * May this viewer read the ESTATE'S OWN financial position in this module?
     *
     * NOT THE SAME QUESTION AS `estate.dues_ledger.view`, and the difference is
     * the whole of M10 §5 on this console. The Admin Assistant holds Entry on
     * Dues & ledger, Payments and Accounting so they can key a receipt, post a
     * charge or record a bill against the lot or the supplier in front of them
     * — board 24 draws those cells deliberately. What data entry never needs is
     * the estate's total arrears, the ageing strip, the arrears-by-phase chart,
     * a month's takings, the balances on the chart of accounts, or every
     * household's balance listed side by side. Those are the committee's
     * picture of what the estate is owed and holds.
     *
     * TWO PERMISSIONS, AND THE CONJUNCTION IS THE RULE. The module's `view` says
     * this viewer may be in that module at all; `estate.reports.view` says they
     * may read the estate ABOUT ITSELF. No single cell separates the Admin
     * Assistant from the President, and this pair does, against the seeded
     * matrix, with no cell changed:
     *
     *   Community Super Admin  dues F · reports F  → yes
     *   President              dues V · reports F  → yes
     *   Vice President         dues V · reports F  → yes
     *   Secretary              dues - · reports V  → no
     *   Property Manager       dues - · reports V  → no  (D-010 — and the
     *                                                     dashboard tile was
     *                                                     shipping it anyway)
     *   Treasurer              dues A · reports F  → yes
     *   Admin Assistant        dues E · reports -  → NO  (M10 §5)
     *   Head of Security       dues - · reports V  → no  (M10 §2)
     *
     * Payments and Accounting carry byte-identical rows to Dues & ledger, so
     * the same eight answers hold for `payments` and `accounting_posting`.
     *
     * ASKED OF PERMISSIONS, NEVER OF A ROLE NAME. §4 makes roles editable: a
     * role a Community Super Admin builds tomorrow inherits this rule from the
     * cells they give it, and there is no list of role names here to forget
     * to extend.
     *
     * A STATIC PREDICATE TAKING THE VIEWER, called by the controllers, which
     * hand the answer down. The services keep `Residents::standingOf()`'s
     * discipline of being told rather than consulting the container, so there
     * is still one copy of the route matrix and it is the routes.
     *
     * @param  string  $module  the estate module whose figures are in question — `dues_ledger`, `payments` or `accounting_posting`
     */
    public static function maySeeEstatePosition(User $viewer, string $module = 'dues_ledger'): bool
    {
        return $viewer->can('estate.'.$module.'.view')
            && $viewer->can('estate.reports.view');
    }

    /* ------------------------------------------------------------------ */
    /* reading — every figure below comes from posted journal lines */
    /* ------------------------------------------------------------------ */

    /**
     * What one unit owes, from the ledger.
     *
     * The sum of every line posted to Dues Receivable for this unit, debits
     * less credits. Not a sum of charges less payments — that would be a second
     * arithmetic over a second set of rows, free to disagree with the accounts.
     */
    public function balanceOf(Unit $unit, ?Carbon $asAt = null): Money
    {
        /*
         * ONE UNIT'S LINES, NOT THE ESTATE'S (M11 A2). This used to total every
         * unit's receivable — 23,792 lines on Phoenix Park — and keep one answer.
         * The same sum, narrowed to this unit in SQL, is the same figure.
         */
        return Money::ofMinor($this->unitBalances($asAt, [$unit->id])[$unit->id] ?? 0, 'JMD');
    }

    /**
     * EVERYTHING CHARGED AND NOT YET PAID, whatever day each charge falls due (EST-B-01's correction, 11 October): what
     * a payment may clear, since a household may pay a booking's fee before its day, and the balance every walk over
     * the charges lays out. What is OWED is `balanceOf()`, as at today.
     */
    public function unpaidOf(Unit $unit): Money
    {
        return $this->balanceOf($unit, Carbon::create(9999, 12, 31));
    }

    /**
     * What is still owed on each of a unit's charges, keyed by charge id, in minor units.
     *
     * The walk the resident's invoice list and the ageing use: PAYMENTS CLEAR THE OLDEST CHARGE FIRST, so the balance
     * sits on the newest charges, and a reversed charge was never owed and takes none of it. A charge not in the
     * answer is owed nothing.
     *
     * @return array<int, int>
     */
    public function outstandingByCharge(Unit $unit): array
    {
        return self::walk($this->ledgerOf($unit));
    }

    /**
     * A unit's balance and its charges, newest first — what `walk` and `owedThrough` read, fetched once for a screen.
     *
     * @return array{balance: int, charges: list<array{id: int, due: string, amount: int}>}
     */
    public function ledgerOf(Unit $unit): array
    {
        /*
         * EVERY CHARGE, AND THE BALANCE OF EVERY POSTING. The walk says what is still UNPAID on each charge, not what
         * is owed today (EST-B-01's correction, 11 October): read as at today, a booking's fee due next month left the
         * walk and read paid. Walked whole, each charge up to today takes exactly what the today-only walk gives it (no
         * payment is dated ahead) and a later one reads unpaid. What is OWED stays as at today (owedAsAt).
         */
        return [
            'balance' => $this->unpaidOf($unit)->getMinorAmount()->toInt(),
            'charges' => Charge::query()->where('unit_id', $unit->id)->where('status', '!=', 'reversed')
                ->orderByDesc('due_on')->orderByDesc('id')->get(['id', 'due_on', 'amount_minor'])
                ->map(static fn (Charge $c): array => ['id' => (int) $c->id, 'due' => $c->due_on->toDateString(), 'amount' => (int) $c->amount_minor])
                ->values()->all(),
        ];
    }

    /**
     * The walk itself, over `ledgerOf`: the balance laid on the newest charges first.
     *
     * @param  array{balance: int, charges: list<array{id: int, due: string, amount: int}>}  $ledger
     * @return array<int, int>
     */
    public static function walk(array $ledger): array
    {
        $left = max(0, $ledger['balance']);
        $owed = [];

        foreach ($ledger['charges'] as $charge) {
            if ($left <= 0) {
                break;
            }

            $on = min($charge['amount'], $left);
            $owed[$charge['id']] = $on;
            $left -= $on;
        }

        return $owed;
    }

    /**
     * What must be paid for ONE charge to read paid (M12 §2.14): its own share of the balance and every older
     * charge's. Payments clear the oldest charge first, so paying a booking's J$3,000 fee alone, over J$12,400 of older
     * dues, would clear J$3,000 of the dues and leave the fee still owing. Nothing when the walk already calls it paid.
     *
     * A charge NOT YET RAISED (`$chargeId` null) is walked as it would be raised — `$newAmount`, due on `$newDue`, the
     * newest of its day — so the phone can say what a booking's fee will take before the fee exists.
     *
     * @param  array{balance: int, charges: list<array{id: int, due: string, amount: int}>}  $ledger
     */
    public static function owedThrough(array $ledger, ?int $chargeId, int $newAmount = 0, ?string $newDue = null): int
    {
        if ($chargeId === null) {
            $charges = $ledger['charges'];
            $at = count($charges);

            foreach ($charges as $i => $charge) {
                if ($charge['due'] <= (string) $newDue) {
                    $at = $i;
                    break;
                }
            }

            array_splice($charges, $at, 0, [['id' => 0, 'due' => (string) $newDue, 'amount' => $newAmount]]);
            $ledger = ['balance' => $ledger['balance'] + $newAmount, 'charges' => $charges];
            $chargeId = 0;
        }

        $owed = self::walk($ledger);

        if (($owed[$chargeId] ?? 0) <= 0) {
            return 0;
        }

        // The walk's own order — newest first — so this charge and everything after it are this charge and older.
        $sum = 0;
        $reached = false;

        foreach ($owed as $id => $on) {
            $reached = $reached || $id === $chargeId;
            $sum += $reached ? $on : 0;
        }

        return $sum;
    }

    /**
     * Every unit's balance, keyed by unit id, in one query.
     *
     * `$unitIds` narrows it to the units a screen is actually drawing (M11 A2):
     * a page of twenty-five households needs twenty-five balances, and the sum
     * for each is the same whether or not the other 1,975 are summed beside it.
     * Null is the whole estate; an empty list is nobody, and reads nothing.
     *
     * @param  list<int>|null  $unitIds
     * @return array<int, int> unit id => minor units owed
     */
    public function unitBalances(?Carbon $asAt = null, ?array $unitIds = null): array
    {
        if ($unitIds === []) {
            return [];
        }

        $balances = [];

        foreach ($this->balancesQuery($asAt, $unitIds)->get() as $row) {
            $balances[(int) $row->unit_id] = (int) $row->balance;
        }

        return $balances;
    }

    /**
     * Each unit's receivable, as a query — `unitBalances()` runs it and the arrears
     * register joins it, so the two can never sum a balance two different ways.
     *
     * @param  list<int>|null  $unitIds
     */
    private function balancesQuery(?Carbon $asAt, ?array $unitIds): Builder
    {
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', '1200')
            ->whereNotNull('journal_lines.unit_id')
            // EST-B-01: as at the estate's today unless a day is named (owedAsAt).
            ->where('journals.posted_on', '<=', self::owedAsAt($asAt)->toDateString())
            ->selectRaw('journal_lines.unit_id as unit_id, SUM(journal_lines.debit_minor - journal_lines.credit_minor) as balance')
            ->groupBy('journal_lines.unit_id');

        if ($unitIds !== null) {
            $query->whereIn('journal_lines.unit_id', $unitIds);
        }

        return $query;
    }

    /**
     * The running statement board 6 draws, newest first.
     *
     * Built from the JOURNAL, not from charges and payments. The running balance
     * accumulates oldest-first and is then reversed for display, because that is
     * the only order in which a balance means anything.
     *
     * @return list<array<string, mixed>>
     */
    public function statement(Unit $unit, int $limit = 5): array
    {
        return array_map(
            static fn (array $line): array => array_diff_key($line, ['source' => true, 'source_id' => true]),
            array_slice(array_reverse($this->statementLines($unit)), 0, $limit),
        );
    }

    /**
     * What a unit's statement orders by on board 6 (M32 F5). Date is the statement's own order — the day posted, then
     * the line — and every balance is still the one after its line in that order, whichever way the table is read.
     *
     * @return array<string, string|\Closure>
     */
    public static function statementSorts(): array
    {
        return [
            'date' => static fn (array $row): array => [$row['posted_on'], $row['id']],
            'description' => 'description',
            'charge' => 'charge_minor',
            'payment' => 'payment_minor',
            'balance' => 'balance_minor',
        ];
    }

    /** A unit's statement as the address asks for it (M32 F5): the newest line first. */
    public static function statementTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::statementSorts(), 'date', 'desc');
    }

    /**
     * Every line of a unit's statement, oldest first, each carrying the balance after it.
     *
     * @return list<array<string, mixed>>
     */
    private function statementLines(Unit $unit): array
    {
        $lines = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', '1200')
            ->where('journal_lines.unit_id', $unit->id)
            // EST-B-01: the statement as at the estate's today, so its last balance is the balance shown everywhere.
            ->where('journals.posted_on', '<=', self::owedAsAt(null)->toDateString())
            ->orderBy('journals.posted_on')
            ->orderBy('journal_lines.id')
            ->select([
                'journal_lines.id',
                'journals.posted_on',
                'journals.memo as entry_memo',
                'journals.reference',
                'journals.source',
                'journals.source_id',
                'journal_lines.memo as line_memo',
                'journal_lines.debit_minor',
                'journal_lines.credit_minor',
            ])
            ->get();

        $running = 0;
        $rows = [];

        foreach ($lines as $line) {
            $net = (int) $line->debit_minor - (int) $line->credit_minor;
            $running += $net;
            $reversal = (string) $line->source === Ledger::SOURCE_REVERSAL;

            $rows[] = [
                // The line, so a row is a row and not a position (M32 F5).
                'id' => (int) $line->id,
                'date' => Carbon::parse((string) $line->posted_on)->format('M j'),
                'posted_on' => Carbon::parse((string) $line->posted_on)->toDateString(),

                /*
                 * The LINE's memo first. A monthly dues run is one entry across
                 * four hundred and fifty units, and its header says so — but
                 * this resident's statement has to read "Payment received ·
                 * receipt #4471", which is their line and nobody else's.
                 */
                'description' => self::statementDescription($line->line_memo, $line->entry_memo, (string) $line->source),
                'reference' => (string) $line->reference,

                // EST-B-04: a reversal is a charge taken back (or a payment put back), never a payment: all of it in the charge column, signed.
                'charge_minor' => $reversal ? ($net ?: null) : ((int) $line->debit_minor ?: null),
                'payment_minor' => $reversal ? null : ((int) $line->credit_minor ?: null),
                'balance_minor' => $running,

                // What posted the entry, for the line's Reverse (`withReversals()`, which takes both off again).
                'source' => (string) $line->source,
                'source_id' => $line->source_id === null ? null : (int) $line->source_id,
            ];
        }

        return $rows;
    }

    /**
     * WHAT A STATEMENT LINE SAYS (EST-B-04, M81 §3.8, M85 A7), on the console's statement, its PDF and the Resident App's.
     *
     * The line's own memo first, as before. A REVERSAL SAYS SO: its lines carry the memo of the line they mirror, so a
     * reversed October fee read "Maintenance fee October 2026", a payment nobody made. It reads "Reversal: Maintenance fee
     * October 2026 (why)", the reason taken from the reversing entry's memo: what follows its last ": " or " — ", which is
     * where every reversal writes it ("Reversal of CHG-… (…): why"; " — why" only on a journal written before 12 October
     * 2026, which is kept as it was written).
     */
    public static function statementDescription(?string $lineMemo, ?string $entryMemo, ?string $source): string
    {
        if ($source !== Ledger::SOURCE_REVERSAL) {
            return (string) ($lineMemo ?? $entryMemo);
        }

        $what = trim((string) $lineMemo);
        $why = preg_match('/^.*(?:: | — )(.+)$/us', (string) $entryMemo, $m) === 1 ? trim($m[1]) : '';

        return 'Reversal'.($what !== '' ? ': '.$what : '').($why !== '' ? ' ('.$why.')' : '');
    }

    /**
     * The due date of the oldest charge THIS unit has not worked off.
     *
     * Exposed for the dunning merge fields, which have to tell a resident which
     * bill is overdue and by how many days. It delegates to the same FIFO walk
     * the ageing uses rather than repeating it, because two answers to "how
     * long has this unit been in arrears" is one answer too many — and the one
     * that would drift is the one printed on a demand letter.
     */
    public function oldestOpenChargeDate(Unit $unit, ?Carbon $asAt = null): ?Carbon
    {
        return $this->oldestOpenChargeDates($asAt?->copy() ?? self::siteDate(), [$unit->id])[$unit->id] ?? null;
    }

    /**
     * How long each unit has had anything outstanding, and therefore which
     * bucket its whole balance sits in.
     *
     * `$unitIds` narrows it to the units a screen is drawing, exactly as
     * `unitBalances()` does. A unit's bucket depends on its own charges and its
     * own payments and on nobody else's, so the answer for one unit is the same
     * whether or not the rest of the estate is aged beside it.
     *
     * @param  list<int>|null  $unitIds
     * @return array<int, string> unit id => bucket key
     */
    public function unitBuckets(?Carbon $asAt = null, ?array $unitIds = null): array
    {
        return $this->bucketsFrom($this->unitBalances($asAt, $unitIds), $asAt, $unitIds);
    }

    /**
     * Each unit's balance AND the bucket it sits in, from one read of the balances (M11 A2).
     *
     * A screen that prints both used to ask `unitBalances()` and then `unitBuckets()`,
     * which reads the same balances again to derive the buckets from them. The figures
     * are those two methods' exactly; only the reading is shared.
     *
     * @param  list<int>|null  $unitIds
     * @return array{balances: array<int, int>, buckets: array<int, string>}
     */
    public function standings(?Carbon $asAt = null, ?array $unitIds = null): array
    {
        $balances = $this->unitBalances($asAt, $unitIds);

        return ['balances' => $balances, 'buckets' => $this->bucketsFrom($balances, $asAt, $unitIds)];
    }

    /**
     * The four ageing totals board 5 puts across the top.
     *
     * The balances are read ONCE and the buckets are derived from that same read
     * (M11 A2). It used to read every balance twice — once here and once more
     * inside `unitBuckets()` — which is the same answer paid for twice.
     *
     * @return array<string, int> bucket key => minor units
     */
    public function ageing(?Carbon $asAt = null): array
    {
        $balances = $this->unitBalances($asAt);

        return $this->ageingFrom($balances, $this->bucketsFrom($balances, $asAt, null), $asAt);
    }

    /**
     * The estate's whole position — the four ageing totals and the phase bars —
     * from one read of the receivable (M11 A2).
     *
     * The dashboard draws both, and `ageing()` then `arrearsByPhase()` read every
     * balance three times between them. The figures are those two methods'
     * figures exactly; only the reading is shared.
     *
     * @return array{ageing: array<string, int>, by_phase: array<string, int>}
     */
    public function position(?Carbon $asAt = null): array
    {
        $balances = $this->unitBalances($asAt);

        return [
            'ageing' => $this->ageingFrom($balances, $this->bucketsFrom($balances, $asAt, null), $asAt),
            'by_phase' => $this->byPhaseFrom($balances),
        ];
    }

    /**
     * Which bucket each owing unit's balance sits in, given the balances already read.
     *
     * @param  array<int, int>  $balances  unit id => minor units owed
     * @param  list<int>|null  $unitIds  the same narrowing the balances were read with
     * @return array<int, string>
     */
    private function bucketsFrom(array $balances, ?Carbon $asAt, ?array $unitIds): array
    {
        // The estate's today, not UTC's, which ages every charge a day early after 7 PM (bug hunt B023).
        $today = $asAt?->copy() ?? self::siteDate();

        // Nobody owes anything: there is no open charge to date, so none is read.
        if (array_filter($balances, static fn (int $b): bool => $b > 0) === []) {
            return [];
        }

        /*
         * The oldest charge that is still open, per unit, under FIFO: payments
         * settle the oldest debt first. Derived rather than stored — an
         * allocation table is a third set of rows to keep in step, and the one
         * time it falls behind, a unit is chased for a bill it has paid.
         */
        $oldestOpen = $this->oldestOpenChargeDates($today, $unitIds);

        $buckets = [];

        foreach ($balances as $unitId => $balance) {
            if ($balance <= 0) {
                continue;
            }

            $buckets[$unitId] = self::bucketFor($oldestOpen[$unitId] ?? null, $today);
        }

        return $buckets;
    }

    /**
     * The bucket a balance sits in, from the due date of its oldest open charge.
     *
     * ONE PLACE FOR THE BOUNDARIES, so the register's rows, the ageing strip and
     * the SQL filter behind the "90+ days only" chip cannot disagree about which
     * side of ninety days a unit is on.
     */
    public static function bucketFor(?Carbon $oldestOpen, Carbon $today): string
    {
        $daysOverdue = $oldestOpen === null ? 0 : (int) $oldestOpen->diffInDays($today, absolute: false);

        return match (true) {
            $daysOverdue >= 90 => 'd90',
            $daysOverdue >= 60 => 'd60',
            $daysOverdue >= 30 => 'd30',
            default => 'current',
        };
    }

    /**
     * The four totals for the units in `$buckets` (the owing ones, or a phase's): each charge's share in its own
     * bucket (`chargeAgeing()`), so the totals still add up to those units' balances.
     *
     * @param  array<int, int>  $balances
     * @param  array<int, string>  $buckets
     * @return array<string, int>
     */
    private function ageingFrom(array $balances, array $buckets, ?Carbon $asAt = null): array
    {
        $totals = array_fill_keys(array_keys(self::BUCKETS), 0);

        foreach ($this->chargeAgeing(array_intersect_key($balances, $buckets), $asAt) as $strip) {
            foreach ($strip as $key => $minor) {
                $totals[$key] += $minor;
            }
        }

        return $totals;
    }

    /**
     * EACH CHARGE AGES IN ITS OWN BUCKET (EST-S-02, M85 A3). Per owing unit, its balance as at the day laid over the
     * charges due by then, newest first (payments clear the oldest first, so what is owed sits on the newest), each
     * share in the bucket of its own charge's days overdue: Lot 9 on 10 October reads June's remainder and July 90+,
     * August 60, September 30, October current. A balance no charge explains (an opening balance, a journal) sits where
     * the oldest open charge does, or Current with none, as the whole balance used to. One read of the charges for every
     * owing unit, never one per unit (M11 A2). Four buckets per unit, at zero where nothing falls, because a strip
     * missing figures reads as a broken screen.
     *
     * @param  array<int, int>  $balances  unit id => minor units owed
     * @return array<int, array<string, int>> unit id => bucket key => minor units
     */
    private function chargeAgeing(array $balances, ?Carbon $asAt): array
    {
        // The estate's today, not UTC's (bug hunt B023), and as at the day the balances were read (EST-B-01).
        $today = $asAt?->copy() ?? self::siteDate();
        $owing = array_filter($balances, static fn (int $b): bool => $b > 0);

        if ($owing === []) {
            return [];
        }

        $charges = DB::connection('tenant')->table('charges')
            ->whereIn('unit_id', array_keys($owing))
            ->where('due_on', '<=', $today->toDateString())
            // A reversed charge was never owed, as the oldest-open rule says.
            ->where('status', '!=', 'reversed')
            ->orderBy('unit_id')
            ->orderByDesc('due_on')
            ->orderByDesc('id')
            ->get(['unit_id', 'due_on', 'amount_minor'])
            ->groupBy('unit_id');

        $ageing = [];

        foreach ($owing as $unitId => $balance) {
            $strip = array_fill_keys(array_keys(self::BUCKETS), 0);
            $left = $balance;
            $oldest = 'current';

            foreach ($charges->get($unitId, collect()) as $charge) {
                if ($left <= 0) {
                    break;
                }

                $oldest = self::bucketFor(Carbon::parse((string) $charge->due_on), $today);
                $share = min((int) $charge->amount_minor, $left);
                $strip[$oldest] += $share;
                $left -= $share;
            }

            $strip[$oldest] += max(0, $left);
            $ageing[$unitId] = $strip;
        }

        return $ageing;
    }

    /**
     * One unit's balance split across the four buckets — board 6's strip, each charge in its own (EST-S-02).
     *
     * @return array<string, int>
     */
    public function ageingOf(Unit $unit, ?Carbon $asAt = null): array
    {
        $balances = $this->unitBalances($asAt, [$unit->id]);

        return $this->chargeAgeing($balances, $asAt)[$unit->id] ?? array_fill_keys(array_keys(self::BUCKETS), 0);
    }

    /**
     * The bucket one unit's balance sits in, or null when it owes nothing — exactly the entry
     * `unitBuckets()` holds for it, worked out from this unit's rows alone (M11 A2).
     */
    public function bucketOf(Unit $unit, ?Carbon $asAt = null): ?string
    {
        return $this->unitBuckets($asAt, [(int) $unit->id])[$unit->id] ?? null;
    }

    /**
     * The five phase bars board 2's dashboard draws.
     *
     * SAME READ AS `unitBalances()`, GROUPED DIFFERENTLY — not a second
     * arithmetic over the billing tables. A dashboard bar chart of "arrears by
     * phase" is a GROUP BY of the exact rows the ageing strip above already
     * sums; computing it any other way would be the "second way to total
     * arrears" this whole module exists to rule out. `ArrearsPlan` fits the
     * seeded ledger so this and board 5's ageing total agree to the cent.
     *
     * Every phase the estate has is a key, even one owing nothing, because a
     * bar chart silently missing a column reads as a phase that does not
     * exist rather than one that is settled.
     *
     * @return array<string, int> phase name => minor units owed
     */
    public function arrearsByPhase(?Carbon $asAt = null): array
    {
        return $this->byPhaseFrom($this->unitBalances($asAt));
    }

    /**
     * @param  array<int, int>  $all  every unit's balance, owing or not
     * @return array<string, int>
     */
    private function byPhaseFrom(array $all): array
    {
        $balances = array_filter($all, static fn (int $b): bool => $b > 0);
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

    /* ------------------------------------------------------------------ */
    /* what the screens read — every figure below is the ledger, read back */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 5 draws: the ageing strip, the filter chips and the
     * table of households in arrears.
     *
     * THE REGISTER IS THE ESTATE'S WHOLE ARREARS IN ONE PAYLOAD (M10 §5), and
     * for a viewer who may not read the estate's position
     * (`maySeeEstatePosition()`, asked by the controller and handed in) none of
     * it is sent — not the strip, not the figures, AND NOT THE ROWS. A list of
     * which households are behind is itself about what they pay, with every
     * figure struck out or without: "The Admin Assistant must not see anything
     * about what anyone pays" exempts only what the role needs, and recording a
     * payment needs one household, which the office reaches by lot from
     * Residents and opens on its own ledger.
     *
     * So that viewer gets an empty register and the reason, and nothing below
     * the early return runs: no balance, bucket, payment date, reminder or flag
     * is read for them, and so no later edit to the payload can leak one. Every
     * filter is ignored — a phase or a 90+ chip over an empty list would only
     * be a question the server declined to answer.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's own position — see `maySeeEstatePosition()`
     * @return array<string, mixed>
     */
    public function arrearsBoard(string $phase = '', bool $overdueOnly = false, bool $maySeePosition = true): array
    {
        if (! $maySeePosition) {
            return [
                'ageing' => [],
                'phases' => [],
                'filters' => ['phase' => '', 'overdue' => false],
                'rows' => [],
                'positionWithheld' => true,
                'positionReason' => self::ARREARS_WITHHELD,
            ];
        }

        /*
         * ONE READ OF THE RECEIVABLE, AND NOTHING READ FOR A UNIT THAT OWES
         * NOTHING (M11 A2). This used to read every balance four times and walk
         * every charge on the estate twice — 12,015 of them on Phoenix Park — in
         * PHP. The balances are now read once, the ageing and the buckets come
         * from that same read, and the payments, the reminders and the flags are
         * read for the owing units only, which are the only rows this builds.
         */
        $all = $this->unitBalances();
        $balances = array_filter($all, static fn (int $b): bool => $b > 0);
        $owing = array_keys($balances);
        $buckets = $this->bucketsFrom($all, null, null);

        // THE STRIP FOLLOWS THE PHASE CHIP (the client, 26 Sep), and never the 90+ one — see `arrearsPage()`.
        $ageing = $this->ageingFrom(
            $all,
            $phase === '' ? $buckets : array_intersect_key($buckets, array_flip(Unit::query()->where('block', $phase)->pluck('id')->all())),
            null,
        );
        $lastPaid = $this->lastPaymentDates($owing);
        $lastReminded = $this->lastReminderDates($owing);

        /*
         * THE FLAG IN FORCE, ON THE ROW (13 A4). A treasurer reading the arrears
         * list is deciding who to chase, and a household the committee has
         * protected looks exactly like one it has not unless the row says so.
         * The flag changes nothing else on the row — see `UnitCollectionFlag`.
         */
        $flags = $this->flagsInForce($owing);

        $units = Unit::query()
            ->whereIn('id', $owing)
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

            $rows[] = $this->arrearsRow($unit, $balances[$unit->id], $bucket, $lastPaid[$unit->id] ?? null, $lastReminded[$unit->id] ?? null, $flags[$unit->id] ?? null);
        }

        return [
            'ageing' => $this->ageingCards($ageing),
            'phases' => $this->phaseNames(),
            'filters' => ['phase' => $phase, 'overdue' => $overdueOnly],
            'rows' => $rows,

            // The same two keys the withheld branch sends, so the page reads
            // one shape. False here, and the reason is never drawn.
            'positionWithheld' => false,
            'positionReason' => '',
        ];
    }

    /** How many units the arrears register shows at once by default — the residents register's rhythm. */
    public const ARREARS_PER_PAGE = 25;

    /**
     * The columns the arrears register may be ordered by (M11 A2, M32 F5).
     *
     * EVERY ONE IS ORDERED IN SQL, over the whole register, before the page is
     * cut — never the twenty-five rows already fetched, which would let a
     * treasurer page through "sorted by balance" and never reach the largest
     * debt on the estate. Ageing orders by how long the unit has had anything
     * outstanding, which is what its bucket is made from: fewest days overdue
     * first when ascending — the most recent oldest-open date, and a unit with no
     * open date counts as today. A lot in its own order: Lot 2 before Lot 10.
     *
     * `register` is the register's own order — the order the estate holds its lots
     * in, the order this list has always had — and its default.
     *
     * @return array<string, string|\Closure>
     */
    public static function arrearsSorts(): array
    {
        $today = self::siteDate()->toDateString();

        return [
            'register' => 'units.id',
            'unit' => static fn ($query, string $dir) => $query->orderByRaw('CHAR_LENGTH(units.reference) '.$dir)->orderBy('units.reference', $dir),
            'balance' => 'b.balance',
            'ageing' => static fn ($query, string $dir) => $query->orderByRaw('COALESCE(o.oldest, ?) '.($dir === 'asc' ? 'desc' : 'asc'), [$today]),
            'last_payment' => 'lp.last_received',
        ];
    }

    /** The arrears register as its address asks for it (M32 F5). */
    public static function arrearsTable(?Request $request = null): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::arrearsSorts(), 'register', 'asc', self::ARREARS_PER_PAGE);
    }

    /**
     * Board 5, a page at a time — what the arrears SCREEN draws (M11 A2).
     *
     * THE SAME REGISTER AS `arrearsBoard()`, and the same rows: each is built by
     * `arrearsRow()` from the same balance, bucket, payment, reminder and flag.
     * What changes is that the register is no longer handed to the browser whole.
     * Phoenix Park has 163 units in arrears; a household in arrears in a bigger
     * estate is not a reason to ship the estate. The phase and "90+ days" filters,
     * the search, the order and the page are all applied in SQL, so no balance or
     * charge is read for a unit the page does not show, and nothing is loaded
     * into memory beyond the twenty-five rows being drawn.
     *
     * THE AGEING STRIP ACROSS THE TOP IS THE CHOSEN PHASE'S (the client, 26 Sep: "main top calculation must change
     * if I switch the phase"). With no phase chosen it is the whole estate's; with "Phase 2" it is Phase 2's total
     * and its four buckets — the same balances and buckets the rows below are made of, in the same statement. It does
     * NOT follow "90+ days only", the search or the page: those narrow which households are listed, and a strip
     * that zeroed its current, 30 and 60-day cards under a 90+ filter would say those debts had gone.
     *
     * SEARCH READS WHAT THE ROW PRINTS: the lot, the phase, the household and its
     * primary resident — the household being the unit's current one, the same
     * one the row names. Nothing that is not on the row, so a search cannot find
     * a household by a fact the screen does not show.
     *
     * The withheld branch is `arrearsBoard()`'s, unchanged: a viewer who may not
     * read the estate's position is sent no rows, no strip and no filters.
     *
     * @return array<string, mixed>
     */
    public function arrearsPage(
        string $phase = '',
        bool $overdueOnly = false,
        bool $maySeePosition = true,
        ?TableQuery $table = null,
    ): array {
        if (! $maySeePosition) {
            return [
                ...$this->arrearsBoard($phase, $overdueOnly, false),
                'table' => null,
                'total' => 0,
            ];
        }

        $table ??= self::arrearsTable();

        // The estate's today, as every other figure on the page ages by (bug hunt B023).
        $today = self::siteDate();
        $search = $table->search;

        /*
         * THE RECEIVABLE, READ ONCE FOR THE WHOLE PAGE (M11 A2). The register's rows
         * and the ageing strip above them are both made of every owing unit's balance
         * and its oldest open charge — a sum over the whole ledger and a FIFO walk of
         * every charge. They used to be read twice a load: once for the rows, and once
         * more for the strip through `ageing()`. Here each is a common table expression
         * of ONE statement, which MySQL materialises once and hands to both: the page
         * joins them, and the strip (`s`) sums them. They are the very queries
         * `ageing()` and `unitBalances()` run — `balancesQuery()`, `oldestOpenQuery()`
         * — so the figures cannot drift apart.
         */
        $register = [
            'b' => $this->balancesQuery(null, null)->havingRaw('SUM(journal_lines.debit_minor - journal_lines.credit_minor) > 0'),
            'o' => $this->oldestOpenQuery($today, null),
            'lp' => $this->lastPaymentQuery(null),
        ];

        $query = Unit::query()
            ->join('b', 'b.unit_id', '=', 'units.id')
            ->leftJoin('o', 'o.unit_id', '=', 'units.id')
            ->leftJoin('lp', 'lp.unit_id', '=', 'units.id')
            ->select('units.*', 'b.balance', 'o.oldest', 'lp.last_received');

        if ($phase !== '') {
            $query->where('units.block', $phase);
        }

        if ($overdueOnly) {
            // `bucketFor()`'s d90, in SQL: an open charge ninety or more days past due.
            $query->whereNotNull('o.oldest')->where('o.oldest', '<=', $today->copy()->subDays(90)->toDateString());
        }

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $plate = Residents::plateCondition($search);

            // M32 F4: the register's search — any of the household's people by name, email or phone, its lot, phase,
            // name and vehicles — where it used to read the lot, the phase, the household's name and its primary's name.
            $query->where(static function ($where) use ($like, $search, $plate): void {
                $where->where('units.reference', 'like', $like)
                    ->orWhere('units.block', 'like', $like)
                    ->orWhereHas('household', static fn ($household) => $household->where('name', 'like', $like))
                    ->orWhereHas('household.residents', static fn ($resident) => Residents::matchResident($resident, $search));

                if ($plate !== null) {
                    $where->orWhereExists(static fn ($v) => $v->select(DB::raw(1))->from('household_vehicles')
                        ->join('households as vh', 'vh.id', '=', 'household_vehicles.household_id')
                        ->whereColumn('vh.unit_id', 'units.id')
                        ->whereRaw($plate[0], $plate[1]));
                }
            });
        }

        // `id` last, always (`TableQuery::order`): every sort has ties, and a page boundary drawn
        // through a tie would show a unit twice and another never.
        $table->order($query, 'units.id');

        /*
         * THE PAGE AND THE COUNT IN ONE STATEMENT. `paginate()` would run the whole
         * join twice — once to count the register, once to cut the page — and the
         * join is every balance and a FIFO walk of every charge. `COUNT(*) OVER ()`
         * is evaluated over the filtered register before the LIMIT, so every row of
         * the page carries the register's size. Only a page past the end, which
         * has no row to carry it, asks for the count on its own.
         */
        $page = $table->page;
        $strip = $this->stripQuery($today, $phase);

        // And the strip rides on every row of the page, from the same two tables.
        $units = Unit::hydrate($this->selectWith($register, (clone $query)
            ->crossJoinSub($strip, 's')
            ->addSelect(array_map(static fn (string $key): string => 's.strip_'.$key, array_keys(self::BUCKETS)))
            ->selectRaw('COUNT(*) OVER () AS register_total')
            ->forPage($page, $table->per)
            ->toBase()));

        /*
         * A page with no row on it — a search nothing matches, or a page past the end —
         * has nothing to carry the strip or the count, and asks for them on their own.
         */
        $first = $units->first();

        $total = $first !== null
            ? (int) $first->getAttribute('register_total')
            : ($page === 1 ? 0 : (int) ($this->selectWith($register, DB::connection('tenant')->query()->fromSub($query->toBase(), 'register')->selectRaw('COUNT(*) AS aggregate'))[0]->aggregate ?? 0));

        $stripRow = $first !== null ? (object) $first->getAttributes() : ($this->selectWith($register, $strip)[0] ?? null);

        $ageing = [];

        foreach (array_keys(self::BUCKETS) as $key) {
            $ageing[$key] = (int) ($stripRow->{'strip_'.$key} ?? 0);
        }

        $paginator = new LengthAwarePaginator($units, $total, $table->per, $page);
        $units->load(['household.residents' => fn ($q) => $q->where('is_primary', true)]);

        $ids = $units->modelKeys();
        $lastReminded = $this->lastReminderDates($ids);
        $flags = $this->flagsInForce($ids);

        $rows = [];

        foreach ($units as $unit) {
            $oldest = $unit->getAttribute('oldest');
            $bucket = self::bucketFor($oldest === null ? null : Carbon::parse((string) $oldest), $today);

            $rows[] = $this->arrearsRow(
                $unit,
                (int) $unit->getAttribute('balance'),
                $bucket,
                $unit->getAttribute('last_received'),
                $lastReminded[$unit->id] ?? null,
                $flags[$unit->id] ?? null,
            );
        }

        return [
            'ageing' => $this->ageingCards($ageing),
            'phases' => $this->phaseNames(),
            'filters' => ['phase' => $phase, 'overdue' => $overdueOnly, 'search' => $search],
            'rows' => $rows,
            'total' => $paginator->total(),

            // The shared table's (M32 F5): its order, the columns it may be ordered by, and this page's place.
            'table' => $table->meta($paginator),
            'positionWithheld' => false,
            'positionReason' => '',
        ];
    }

    /**
     * The ageing strip — `ageing()`'s four totals — over the `b` (every owing unit's
     * balance) of `arrearsPage()`'s statement, each charge in its own bucket
     * (EST-S-02, M85 A3): `chargeAgeing()` in SQL, so the page's strip and the
     * board's cannot disagree. Each owing unit's charges due by today, newest
     * first; a charge's share is what is left of the balance after the newer ones,
     * up to its amount. What no charge explains sits in the oldest charge's bucket,
     * or current with none. Both dates are calendar days, so DATEDIFF is Carbon's
     * whole-day difference exactly.
     */
    private function stripQuery(Carbon $today, string $phase = ''): Builder
    {
        $tenant = DB::connection('tenant');
        $day = $today->toDateString();

        // `bucketFor()` in SQL, for a due date.
        $bucket = static fn (string $due): string => "CASE WHEN DATEDIFF(?, {$due}) >= 90 THEN 'd90' "
            ."WHEN DATEDIFF(?, {$due}) >= 60 THEN 'd60' "
            ."WHEN DATEDIFF(?, {$due}) >= 30 THEN 'd30' "
            ."ELSE 'current' END";

        // One phase's strip when a phase is chosen: its units' balances, aged exactly as the whole estate's are.
        $inPhase = static fn ($query, string $column) => $query->when(
            $phase !== '',
            static fn ($q) => $q->join('units', 'units.id', '=', $column)->where('units.block', $phase),
        );

        $charges = $inPhase($tenant->table('charges')->join('b', 'b.unit_id', '=', 'charges.unit_id'), 'charges.unit_id')
            ->where('charges.due_on', '<=', $day)
            // A reversed charge was never owed.
            ->where('charges.status', '!=', 'reversed')
            ->selectRaw('charges.due_on, charges.amount_minor, b.balance, SUM(charges.amount_minor) OVER '
                .'(PARTITION BY charges.unit_id ORDER BY charges.due_on DESC, charges.id DESC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS through_minor');

        $shares = $tenant->query()->fromSub($charges, 'c')->selectRaw(
            'LEAST(c.amount_minor, GREATEST(0, c.balance - (c.through_minor - c.amount_minor))) AS amount, '.$bucket('c.due_on').' AS bucket',
            [$day, $day, $day],
        );

        $charged = $tenant->table('charges')
            ->where('due_on', '<=', $day)
            ->where('status', '!=', 'reversed')
            ->groupBy('unit_id')
            ->selectRaw('unit_id, SUM(amount_minor) AS charged, MIN(due_on) AS oldest');

        $unexplained = $inPhase($tenant->query()->from('b')->leftJoinSub($charged, 't', 't.unit_id', '=', 'b.unit_id'), 'b.unit_id')
            ->selectRaw(
                "GREATEST(0, b.balance - COALESCE(t.charged, 0)) AS amount, CASE WHEN t.oldest IS NULL THEN 'current' ELSE ".$bucket('t.oldest').' END AS bucket',
                [$day, $day, $day],
            );

        return $tenant->query()->fromSub($shares->unionAll($unexplained), 'aged')->selectRaw(implode(', ', array_map(
            static fn (string $key): string => "COALESCE(SUM(CASE WHEN bucket = '{$key}' THEN amount END), 0) AS strip_{$key}",
            array_keys(self::BUCKETS),
        )));
    }

    /**
     * Run `$query` in one statement, WITH the named queries as its common tables.
     *
     * @param  array<string, Builder>  $tables
     * @return list<\stdClass>
     */
    private function selectWith(array $tables, Builder $query): array
    {
        $with = [];
        $bindings = [];

        foreach ($tables as $name => $table) {
            $with[] = '`'.$name.'` AS ('.$table->toSql().')';
            array_push($bindings, ...$table->getBindings());
        }

        return array_values(array_map(
            static fn (mixed $row): \stdClass => (object) $row,
            DB::connection('tenant')->select('WITH '.implode(', ', $with).' '.$query->toSql(), [...$bindings, ...$query->getBindings()]),
        ));
    }

    /**
     * One register row, however it was read.
     *
     * @param  string|null  $lastPaid  the unit's latest recorded receipt, as stored
     * @param  string|null  $lastReminded  the unit's latest dunning notice, as stored
     * @return array<string, mixed>
     */
    private function arrearsRow(Unit $unit, int $balance, string $bucket, ?string $lastPaid, ?string $lastReminded, ?UnitCollectionFlag $flag): array
    {
        $resident = $unit->household?->residents->first();

        return [
            'id' => $unit->id,
            'resident' => $resident->full_name ?? 'No resident on record',
            'initials' => $this->initials($resident->full_name ?? (string) $unit->reference),
            'household' => $unit->household->name ?? 'Vacant unit',
            'unit' => trim(($unit->block ?? '').' · '.$unit->reference, ' ·'),
            'balance_minor' => $balance,
            'bucket' => $bucket,
            'bucket_label' => self::BUCKET_LABELS[$bucket],

            /*
             * Null rather than a date that is not there. A unit that has
             * never paid is a different fact from one that paid long ago,
             * and the board draws an em dash for the first.
             */
            'last_payment' => $lastPaid !== null
                ? Payment::calendarDay(Carbon::parse($lastPaid))->format('M j, Y')
                : null,

            // Board 5's own format — "Today, 9:00 AM" — and null for a
            // household nobody has reminded.
            'last_reminder' => $lastReminded !== null
                ? $this->reminderLabel(Carbon::parse($lastReminded))
                : null,

            'flag' => $flag !== null ? [
                'kind' => $flag->kind,
                'label' => $flag->shortLabel(),
                'headline' => $flag->headline(),
            ] : null,
        ];
    }

    /**
     * @param  array<string, int>  $ageing
     * @return list<array{key: string, value_minor: int, label: string}>
     */
    private function ageingCards(array $ageing): array
    {
        return [
            ['key' => 'total', 'value_minor' => array_sum($ageing), 'label' => 'Total outstanding'],
            ...array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'value_minor' => $ageing[$key],
                    'label' => self::BUCKET_LABELS[$key],
                ],
                array_keys(self::BUCKETS),
            ),
        ];
    }

    /** @return list<string> every phase the register names, in order */
    private function phaseNames(): array
    {
        return Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values()->all();
    }

    /**
     * The last reminder each unit was sent, from the dunning log itself —
     * the record board 8 keeps verbatim. Read, never stored on the unit, so
     * the column cannot say "never" about a household that was chased.
     *
     * @param  list<int>  $unitIds
     * @return Collection<int, string>
     */
    private function lastReminderDates(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return DB::connection('tenant')
            ->table('dunning_notices')
            ->whereIn('unit_id', $unitIds)
            ->selectRaw('unit_id, MAX(sent_at) AS last_sent')
            ->groupBy('unit_id')
            ->pluck('last_sent', 'unit_id');
    }

    /**
     * @param  list<int>  $unitIds
     * @return Collection<int, UnitCollectionFlag>
     */
    private function flagsInForce(array $unitIds): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return UnitCollectionFlag::query()
            ->whereNull('lifted_at')
            ->whereIn('unit_id', $unitIds)
            ->get()
            ->keyBy('unit_id');
    }

    /**
     * Everything board 6 draws for one unit: its statement whole, a page at a time (M32 F5), where it used to be the
     * latest five lines and the sixth was only on the printed statement.
     *
     * @return array<string, mixed>
     */
    public function unitBoard(Unit $unit, ?TableQuery $table = null): array
    {
        $table ??= self::statementTable();
        $statement = $table->pageOf($this->statementLines($unit));

        /*
         * THIS UNIT, READ ONCE (M11 A2). The bucket, the strip and the balance
         * used to be three reads of the whole estate's receivable and two walks of
         * every charge on it, to draw one household's ledger. They are this unit's
         * balance and this unit's oldest open charge, and the same three figures.
         */
        $balances = $this->unitBalances(null, [$unit->id]);
        $balance = $balances[$unit->id] ?? 0;
        $bucket = $this->bucketsFrom($balances, null, [$unit->id])[$unit->id] ?? 'current';
        // Each charge in its own bucket (EST-S-02); the badge above is still the oldest open charge's.
        $strip = $this->chargeAgeing($balances, null)[$unit->id] ?? array_fill_keys(array_keys(self::BUCKETS), 0);

        return [
            'unit' => $this->unitHeader($unit),
            'balance_minor' => $balance,
            'bucket' => $bucket,
            'bucket_label' => self::BUCKET_LABELS[$bucket],
            'strip' => array_map(
                static fn (string $key): array => [
                    'key' => $key,
                    'value_minor' => $strip[$key],
                    'label' => strtoupper(self::BUCKET_LABELS[$key]),
                ],
                array_keys(self::BUCKETS),
            ),
            'statement' => $this->withReversals(array_values($statement->items())),
            'table' => $table->meta($statement),
        ];
    }

    /**
     * The unit's identity as every screen prints it.
     *
     * @return array<string, mixed>
     */
    public function unitHeader(Unit $unit): array
    {
        $resident = $unit->household?->residents()->where('is_primary', true)->first();

        return [
            'id' => $unit->id,
            'reference' => (string) $unit->reference,
            'phase' => (string) ($unit->block ?? ''),
            'household' => $unit->household->name ?? 'Vacant unit',
            'resident' => $resident->full_name ?? 'No resident on record',
            'title' => trim($unit->reference.' · '.($resident->full_name ?? 'Vacant'), ' ·'),
            'meta' => implode(' · ', array_filter([
                $unit->block,
                $unit->reference,
                $unit->household?->name,
            ])),
        ];
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }

    /**
     * When each unit last paid anything — or each of the named units (M11 A2).
     *
     * @param  list<int>|null  $unitIds
     * @return Collection<int, string>
     */
    public function lastPaymentDates(?array $unitIds = null): Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return $this->lastPaymentQuery($unitIds)->pluck('last_received', 'unit_id');
    }

    /** @param  list<int>|null  $unitIds */
    private function lastPaymentQuery(?array $unitIds): Builder
    {
        return DB::connection('tenant')
            ->table('payments')
            ->where('status', 'recorded')
            ->when($unitIds !== null, static fn (Builder $query) => $query->whereIn('unit_id', $unitIds))
            ->selectRaw('unit_id, MAX(received_at) as last_received')
            ->groupBy('unit_id');
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * "Today, 9:00 AM", "Yesterday", "Sep 20, 9:00 AM" — the dunning log's own
     * style, on the estate's clock (bug hunt B028): the column is read raw from
     * the log as UTC, and printed as stored it said the 09:00 run went at 2:00 PM.
     */
    private function reminderLabel(Carbon $sentAt): string
    {
        $local = SiteTime::local($sentAt);

        return match (true) {
            $local->isToday() => 'Today, '.SiteTime::fmt($local, 'g:i A'),
            $local->isYesterday() => 'Yesterday',
            default => SiteTime::fmt($local, 'M j, g:i A'),
        };
    }

    /**
     * The due date of the oldest charge each unit has not yet worked off.
     *
     * FIFO: charges oldest first, payments applied against them in turn. What
     * remains unpaid when the money runs out is the oldest open charge.
     *
     * IN SQL, NOT IN PHP (M11 A2). This used to fetch every charge on the estate
     * — 12,015 rows on Phoenix Park — and walk them in memory to answer "since
     * when has each unit owed". The walk is exactly this: the payments settle the
     * charges in (due date, id) order, so a charge is still open precisely when
     * the running total of the unit's charges up to and including it exceeds what
     * the unit has paid, and the oldest open charge is the first such charge —
     * the smallest due date among them, because the order is by due date first.
     * A window function computes the running total; one row comes back per unit
     * in arrears rather than one per charge. `EstateArrearsArithmeticIsUnchangedTest`
     * holds this to the old walk, kept in `Tests\Support\LegacyDues`, at 2,000
     * lots on every date it is asked about.
     *
     * `$unitIds` narrows it to the units a screen is drawing. FIFO is per unit,
     * so a unit's date is the same whether or not the rest of the estate is read.
     *
     * @param  list<int>|null  $unitIds
     * @return array<int, Carbon>
     */
    public function oldestOpenChargeDates(Carbon $asAt, ?array $unitIds = null): array
    {
        if ($unitIds === []) {
            return [];
        }

        $oldest = [];

        foreach ($this->oldestOpenQuery($asAt, $unitIds)->get() as $row) {
            $oldest[(int) $row->unit_id] = Carbon::parse((string) $row->oldest);
        }

        return $oldest;
    }

    /**
     * One row per unit with an open charge: `unit_id`, and `oldest`, the due date
     * of its oldest open charge — as a query, so the arrears register can join it.
     *
     * @param  list<int>|null  $unitIds
     */
    private function oldestOpenQuery(Carbon $asAt, ?array $unitIds): Builder
    {
        $tenant = DB::connection('tenant');

        $running = $tenant->table('charges')
            ->where('due_on', '<=', $asAt->toDateString())

            // A reversed charge was never owed: its mirror entry took it back
            // out of the receivable, so it cannot be the oldest thing open.
            ->where('status', '!=', 'reversed')
            ->when($unitIds !== null, static fn (Builder $query) => $query->whereIn('unit_id', $unitIds))
            ->selectRaw('unit_id, due_on, SUM(amount_minor) OVER (PARTITION BY unit_id ORDER BY due_on, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running');

        $paid = $tenant->table('payments')
            ->where('status', 'recorded')
            ->where('received_at', '<=', $asAt->copy()->endOfDay())
            ->when($unitIds !== null, static fn (Builder $query) => $query->whereIn('unit_id', $unitIds))
            ->selectRaw('unit_id, SUM(amount_minor) AS paid')
            ->groupBy('unit_id');

        return $tenant->query()
            ->fromSub($running, 'c')
            ->leftJoinSub($paid, 'p', 'p.unit_id', '=', 'c.unit_id')
            ->whereRaw('c.running > COALESCE(p.paid, 0)')
            ->groupBy('c.unit_id')
            ->selectRaw('c.unit_id AS unit_id, MIN(c.due_on) AS oldest');
    }

    /**
     * The id of the income account a charge credits — refusing anything else (bug hunt B024).
     *
     * A charge is Dr 1200 against the unit and Cr this account. Handed 1200 itself
     * the entry balances and the control account does not move, while the unit's
     * sub-ledger rises and an unlinked credit appears under the empty key: the
     * household is chased for a debt the control account does not carry, and the
     * tie gate:ledger checks is broken for good, because nothing posted can be
     * edited. Handed 1000 it credits the bank. The docblock always said "the
     * income account"; `ChargeSchedules::create()` already refused anything else
     * for a recurring charge, and every one-off charge now goes through the same
     * rule, whoever calls it.
     */
    private function incomeAccountId(string $code): int
    {
        $account = Account::query()->where('code', $code)->first();

        if ($account === null || $account->type !== Account::INCOME || ! $account->is_active) {
            throw new DomainException(self::NOT_AN_INCOME_ACCOUNT);
        }

        return $account->id;
    }

    /** "CHG-2026-09-0007" — sequential within a prefix and month. */
    private function nextReference(string $prefix, Carbon $on): string
    {
        return $this->stampReference($prefix, $on, $this->nextSequence($prefix, $on));
    }

    /**
     * The next number in this prefix and month, under a lock.
     *
     * Separated from the formatting so a batch can take the sequence once and
     * advance it in memory across hundreds of rows that are all written at the
     * end.
     */
    private function nextSequence(string $prefix, Carbon $on): int
    {
        $stem = sprintf('%s-%s-', $prefix, $on->format('Y-m'));

        // Past every reference the unique index holds, a retired sample charge's too (M33 §5).
        $last = Retirements::seeingEverything(static fn (): mixed => Charge::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference'));

        return $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;
    }

    private function stampReference(string $prefix, Carbon $on, int $sequence): string
    {
        return sprintf('%s-%s-%s', $prefix, $on->format('Y-m'), str_pad((string) $sequence, 4, '0', STR_PAD_LEFT));
    }
}
