<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Payment;
use App\Support\MoneyFormatter;
use App\Support\SiteTime;
use App\Support\TableQuery;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipt numbers, and the register they make — board 5's Receipts tab.
 *
 * THE RULING (12 §1): `{PREFIX}-R-{00001}`, sequential per estate, never reused,
 * gaps recorded and visible. One sequence per estate, allocated at posting,
 * never at draft.
 *
 * ALLOCATED UNDER A LOCK, INSIDE THE PAYMENT'S OWN TRANSACTION. `next()` locks
 * the sequence row, increments it and hands the number back; `Dues::receive()`
 * calls it inside the transaction that writes the payment and posts the entry.
 * Two payments keyed at once cannot be handed the same number, and a posting
 * that fails rolls the increment back with it — so the ordinary path leaves no
 * gap at all.
 *
 * A GAP IS STILL POSSIBLE AND IS NEVER HIDDEN. A number can be handed out and
 * its payment lost some other way — a restore from backup, a row removed by
 * hand at the database. The sequence remembers the high-water mark, so the
 * register draws every number up to it that no receipt carries, as a row that
 * says so. "Never reused" is what makes that honest: the gap stays a gap.
 *
 * THE PREFIX IS THE ESTATE RECORD'S `receipt_prefix` (13 A1) — `PPV`, set at
 * provisioning and fixed by the first receipt. It is found from the connection
 * rather than from tenancy: every estate database is `gs_estate_<subdomain>`
 * and the subdomain is the estate's key, so the lookup is the same in a
 * request, a queued job and a seeder.
 */
class Receipts
{
    /** Numbers are five digits wide, as the ruling writes them. */
    private const WIDTH = 5;

    /** @var array<string, string> the prefix per estate database, read once */
    private array $prefixes = [];

    /**
     * The estate's receipt prefix, from its record.
     *
     * AN ESTATE WITH NO PREFIX NUMBERS NOTHING. Every estate is given one at
     * provisioning and every earlier one by migration, so an empty value means
     * somebody cleared it by hand, and a receipt printed under a guessed
     * prefix would start a series nobody chose.
     *
     * A DATABASE NO ESTATE RECORD NAMES is a test fixture — tenancy resolves an
     * estate only through its record, so no route, job or command reaches one
     * in production. Only under test does it number from its own name.
     *
     * @throws DomainException
     */
    public function prefix(): string
    {
        $database = (string) DB::connection('tenant')->getDatabaseName();

        if (isset($this->prefixes[$database])) {
            return $this->prefixes[$database];
        }

        $key = (string) preg_replace('/^'.preg_quote((string) config('tenancy.database.prefix'), '/').'/', '', $database);

        $record = DB::connection((string) config('tenancy.database.central_connection'))
            ->table('tenants')
            ->where('id', $key)
            ->first(['receipt_prefix']);

        if ($record === null && app()->environment('testing')) {
            return $this->prefixes[$database] = strtoupper(substr($key, 0, 6));
        }

        if ($record === null || $record->receipt_prefix === null || $record->receipt_prefix === '') {
            throw new DomainException("Estate [{$key}] has no receipt prefix, so no receipt can be numbered. Set one with `php artisan estate:receipt-prefix`.");
        }

        return $this->prefixes[$database] = (string) $record->receipt_prefix;
    }

    /**
     * Allocate the next receipt number.
     *
     * MUST BE CALLED INSIDE A TRANSACTION, so that the lock holds until the
     * payment it numbers is committed and a failed posting takes the increment
     * back with it. `Dues::receive()` and `Dues::paymentRun()` both do.
     */
    public function next(): string
    {
        $prefix = $this->prefix();
        $connection = DB::connection('tenant');

        $row = $connection->table('receipt_sequences')->where('prefix', $prefix)->lockForUpdate()->first();

        if ($row === null) {
            $connection->table('receipt_sequences')->insert([
                'prefix' => $prefix,
                'first_no' => 1,
                'last_no' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = $connection->table('receipt_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
        }

        $next = (int) $row->last_no + 1;

        $connection->table('receipt_sequences')
            ->where('prefix', $prefix)
            ->update(['last_no' => $next, 'updated_at' => now()]);

        return $this->format($next);
    }

    /** "PPV-R-04471". */
    public function format(int $number): string
    {
        return sprintf('%s-R-%0'.self::WIDTH.'d', $this->prefix(), $number);
    }

    /** The integer inside a receipt number, or null for one not in the format. */
    public function numberOf(string $receiptNo): ?int
    {
        return preg_match('/-R-(\d+)$/', $receiptNo, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The register: every receipt, newest first, and every gap in the sequence.
     *
     * NOT ONE FIGURE HERE IS A HOUSEHOLD'S BALANCE. A receipt is an amount the
     * estate took, which is a fact about the receipt; what the household still
     * owes is board 5's and 6's, behind their own gate.
     *
     * BUT EVERY RECEIPT LISTED WITH ITS AMOUNT IS THE ESTATE'S TAKINGS (M10 §5).
     * Two hundred rows of "$6,200" add up to what the estate took, and the
     * month's tile says it outright. For a viewer who may not read the estate's
     * position — `Dues::maySeeEstatePosition()`, asked by the controller — the
     * register keeps what the office works from: the sequence, the gaps, the
     * dates, the unit, the method and the button for each receipt's own PDF.
     * The amount on every row is null and the month's tile is absent rather than
     * blank. ONE receipt's amount is still theirs to read — on the household's
     * own ledger, and on the printed receipt the office hands over.
     *
     * THE ENTRY IS A REFERENCE, NOT A LINK (bug hunt B030). Each row used to carry
     * its journal's id for a link to `records/journals/{id}` — an isolation probe
     * that answers the raw header as JSON, which Inertia opened as a modal of
     * JSON. No screen shows a journal's lines, so the row carries the reference
     * the office reads and quotes, and no id for a link nothing draws.
     *
     * @param  bool  $maySeePosition  whether the viewer may read the estate's takings — see `Dues::maySeeEstatePosition()`
     * @return array{prefix: string, first_no: int, last_no: int, rows: list<array<string, mixed>>, gaps: list<array<string, mixed>>, kpis: list<array<string, mixed>>, positionWithheld: bool, positionReason: string}
     */
    public function register(?Carbon $asAt = null, int $limit = 200, bool $maySeePosition = true): array
    {
        // The estate's today, so "Received in {month}" is the estate's month after 7 PM too (bug hunt B023).
        $today = ($asAt?->copy() ?? Dues::siteDate())->startOfDay();
        $prefix = $this->prefix();
        [$firstNo, $lastNo] = $this->bounds($prefix);

        $payments = Payment::query()
            ->with('unit')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $gaps = $this->gaps($prefix, $firstNo, $lastNo);

        return [
            'prefix' => $prefix,
            'first_no' => $firstNo,
            'last_no' => $lastNo,
            'rows' => $payments->map(fn (Payment $payment): array => $this->row($payment, $maySeePosition))->values()->all(),
            'gaps' => $gaps,
            'kpis' => $this->kpis($today, $firstNo, $lastNo, count($gaps), $maySeePosition),
            'positionWithheld' => ! $maySeePosition,
            // Said only to the reader it is about: the page draws it under `positionWithheld` and nowhere else.
            'positionReason' => $maySeePosition ? '' : Dues::POSITION_WITHHELD.' Each receipt\'s own amount is on the household\'s ledger and on the printed receipt.',
        ];
    }

    /** How many receipts the register SCREEN shows at once by default (M11 A2). */
    public const PER_PAGE = 50;

    /**
     * What the register orders by (M32 F5). `number` is the sequence — newest number first, its default, and the one
     * order in which a gap has a place to be drawn; ascending is its exact reverse. The amount only for a viewer who
     * may read the estate's takings: a list ordered by amounts it does not show would rank them all the same. A lot in
     * its own order: Lot 2 before Lot 10.
     *
     * @return array<string, string|\Closure>
     */
    public static function registerSorts(bool $maySeePosition = true): array
    {
        $flip = static fn (string $dir): string => $dir === 'asc' ? 'desc' : 'asc';
        $unit = static fn (string $column): string => '(SELECT '.$column.' FROM units WHERE units.id = payments.unit_id)';

        return [
            'number' => static fn ($query, string $dir) => $query
                ->orderByRaw('(REGEXP_LIKE(receipt_no, ?, \'c\') = 0) '.$flip($dir), [self::NUMBERED])
                ->orderByRaw('CASE WHEN REGEXP_LIKE(receipt_no, ?, \'c\') THEN CAST(SUBSTRING_INDEX(receipt_no, \'-R-\', -1) AS UNSIGNED) END '.$dir, [self::NUMBERED])
                ->orderBy('received_at', $dir),
            'received' => 'received_at',
            'entered' => 'entered_at',
            'unit' => static fn ($query, string $dir) => $query->orderByRaw('CHAR_LENGTH('.$unit('reference').') '.$dir)->orderByRaw($unit('reference').' '.$dir),
            'method' => 'method',
            ...($maySeePosition ? ['amount' => 'amount_minor'] : []),
            'status' => 'status',
        ];
    }

    /** The register as its address asks for it (M32 F5): the sequence, newest number first, fifty to a page. */
    public static function registerTable(?Request $request = null, bool $maySeePosition = true): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::registerSorts($maySeePosition), 'number', 'desc', self::PER_PAGE);
    }

    /**
     * The register a page at a time, in the order it is read — the sequence,
     * newest number first (M11 A2).
     *
     * THE SCREEN USED TO HOLD THE 200 MOST RECENTLY RECEIVED RECEIPTS AND NO
     * MORE. Phoenix Park has issued 11,726, so 11,526 of them could not be
     * reached from the register at all — the fault the residents register had
     * before it was paged. Every receipt is now on some page, and a search by
     * receipt number, lot, phase, bank reference or one of the household's
     * people (M32 F4) finds one without paging for it.
     *
     * PAGED IN SEQUENCE ORDER, because that is the order the screen draws: the
     * page used to re-sort whatever it was sent by number. So a page is a run of
     * the sequence, and EACH GAP BELONGS TO EXACTLY ONE PAGE — the one it falls
     * inside, or the one it follows: page one carries the gaps above its newest
     * receipt up to the high-water mark, each page the gaps between its oldest
     * receipt and the next page's newest, and the last page those down to the
     * floor. `gaps` is still every gap in the sequence, as the tile counts them.
     *
     * A search narrows the rows, and draws no gap in place: a gap's place is in
     * the sequence, and a searched list is not one. Nor does any other order
     * (M32 F5): a list ordered by amount or by lot is not a run of the sequence.
     *
     * @return array<string, mixed>
     */
    public function registerPage(?Carbon $asAt = null, ?TableQuery $table = null, bool $maySeePosition = true): array
    {
        $table ??= self::registerTable(null, $maySeePosition);

        // The estate's today, as `register()` reads it (bug hunt B023).
        $today = ($asAt?->copy() ?? Dues::siteDate())->startOfDay();
        $prefix = $this->prefix();
        [$firstNo, $lastNo] = $this->bounds($prefix);
        $search = $table->search;
        $page = $table->page;
        $inSequence = $table->orderKey() === 'number' && $table->orderDir() === 'desc';

        $query = $this->matching(Payment::query(), $search);
        $table->order($query, 'payments.id');

        // The unit only: the row carries its entry's reference, not a link to it (bug hunt B030).
        $paginator = $table->paginate($query->clone()->with('unit'));

        $gaps = $this->gaps($prefix, $firstNo, $lastNo);
        $pageGaps = [];

        if ($search === '' && $inSequence && $gaps !== []) {
            $rows = $paginator->getCollection();
            $numbers = $rows->map(fn (Payment $p): ?int => $this->numberOf($p->receipt_no))->filter();

            $upper = $page === 1 ? $lastNo : ($numbers->max() ?? PHP_INT_MIN);

            // The newest number on the next page, or the floor when there is none.
            $nextNo = $query->clone()->offset($page * $table->per)->limit(1)->value('receipt_no');
            $lower = $nextNo === null ? $firstNo - 1 : ($this->numberOf((string) $nextNo) ?? $firstNo - 1);

            $pageGaps = array_values(array_filter($gaps, static fn (array $gap): bool => $gap['number'] > $lower && $gap['number'] <= $upper));
        }

        return [
            'prefix' => $prefix,
            'first_no' => $firstNo,
            'last_no' => $lastNo,
            'rows' => $paginator->getCollection()->map(fn (Payment $payment): array => $this->row($payment, $maySeePosition))->values()->all(),
            'gaps' => $gaps,
            'page_gaps' => $pageGaps,
            'kpis' => $this->kpis($today, $firstNo, $lastNo, count($gaps), $maySeePosition),
            'search' => $search,
            // Whether the gaps are drawn in place: the sequence's own order, unsearched.
            'in_sequence' => $inSequence && $search === '',
            'table' => $table->meta($paginator),
            'positionWithheld' => ! $maySeePosition,
            // Said only to the reader it is about: the page draws it under `positionWithheld` and nowhere else.
            'positionReason' => $maySeePosition ? '' : Dues::POSITION_WITHHELD.' Each receipt\'s own amount is on the household\'s ledger and on the printed receipt.',
        ];
    }

    /**
     * Payments the search finds; the order is the table's (`registerSorts()`): by the number in the receipt, newest
     * first — the order `numberOf()` reads — with a receipt not in the format after every numbered one, by date, as the
     * screen has always drawn one, unless the headings ask for another.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    private function matching($query, string $search)
    {
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            // M32 F4: a receipt is found by the household's people too — name, email or phone — not by its numbers alone.
            $query->where(static function ($where) use ($like, $search): void {
                $where->where('receipt_no', 'like', $like)
                    ->orWhere('reference', 'like', $like)
                    ->orWhereHas('unit', static fn ($unit) => $unit->where('reference', 'like', $like)->orWhere('block', 'like', $like))
                    ->orWhereHas('unit.household.residents', static fn ($resident) => Residents::matchResident($resident, $search));
            });
        }

        return $query;
    }

    /** What `numberOf()` recognises, as a MySQL pattern: "-R-" and digits to the end, case-sensitive. */
    private const NUMBERED = '-R-[0-9]+$';

    /** @return array{0: int, 1: int} the sequence's floor and its high-water mark */
    private function bounds(string $prefix): array
    {
        $sequence = DB::connection('tenant')
            ->table('receipt_sequences')
            ->where('prefix', $prefix)
            ->first();

        return [(int) ($sequence->first_no ?? 1), (int) ($sequence->last_no ?? 0)];
    }

    /**
     * The gaps: every number from the sequence's floor to its high-water mark
     * that no receipt carries. Read from the whole table rather than the page,
     * because a gap on page three is still a gap. Below the floor nothing was
     * ever allocated, so nothing is missing.
     *
     * IN SQL (M11 A2). This used to fetch every receipt number the estate has
     * issued — 11,726 on Phoenix Park — into memory and test each number in the
     * sequence against them. It now asks MySQL for the runs: the distinct numbers
     * present within the sequence, and, by a window over them, the first, the
     * last, and every one followed by a jump. A gap is what lies between. Only
     * those boundary rows come back, and on a sequence with no gaps that is two.
     *
     * The same numbers as before: a receipt counts where it matches `numberOf()`
     * — "-R-" and digits to the end, case-sensitively — and a number of zero
     * counts as absent, as `->filter()` treated it.
     *
     * @return list<array{receipt_no: string, number: int, note: string}>
     */
    private function gaps(string $prefix, int $firstNo, int $lastNo): array
    {
        if ($lastNo < $firstNo) {
            return [];
        }

        $tenant = DB::connection('tenant');

        $present = $tenant->table('payments')
            ->where('receipt_no', 'like', $prefix.'-R-%')
            ->whereRaw('REGEXP_LIKE(receipt_no, ?, \'c\')', [self::NUMBERED])
            ->selectRaw('DISTINCT CAST(SUBSTRING_INDEX(receipt_no, \'-R-\', -1) AS UNSIGNED) AS n');

        $runs = $tenant->query()
            ->fromSub(
                $tenant->query()
                    ->fromSub($present, 'present')
                    ->where('n', '>', 0)
                    ->whereBetween('n', [$firstNo, $lastNo])
                    ->selectRaw('n, LEAD(n) OVER (ORDER BY n) AS next_n, ROW_NUMBER() OVER (ORDER BY n) AS rn'),
                'runs',
            )
            ->whereRaw('rn = 1 OR next_n IS NULL OR next_n > n + 1')
            ->orderBy('n')
            ->get(['n', 'next_n', 'rn']);

        $missing = [];

        if ($runs->isEmpty()) {
            $missing = range($firstNo, $lastNo);
        } else {
            $lowest = (int) $runs->first()->n;

            if ($lowest > $firstNo) {
                array_push($missing, ...range($firstNo, $lowest - 1));
            }

            foreach ($runs as $run) {
                if ($run->next_n !== null && (int) $run->next_n > (int) $run->n + 1) {
                    array_push($missing, ...range((int) $run->n + 1, (int) $run->next_n - 1));
                }
            }

            $highest = (int) $runs->last()->n;

            if ($highest < $lastNo) {
                array_push($missing, ...range($highest + 1, $lastNo));
            }
        }

        return array_map(fn (int $n): array => [
            'receipt_no' => $this->format($n),
            'number' => $n,
            'note' => 'No receipt carries this number. It will never be used again, and anything posted under it is not in this register.',
        ], $missing);
    }

    /** @return array<string, mixed> */
    private function row(Payment $payment, bool $maySeePosition): array
    {
        return [
            'id' => $payment->id,
            'receipt_no' => $payment->receipt_no,
            'unit' => $payment->unit->reference,
            'unit_id' => $payment->unit_id,
            'method' => $payment->method,
            'method_label' => $payment->is_demonstration ? 'Card (demonstration)' : ucfirst($payment->method),
            // A test card paid it (D-179): posted to 1090, no money moved — the register badges it.
            'is_demonstration' => (bool) $payment->is_demonstration,
            'reference' => $payment->reference,
            'amount' => $maySeePosition ? MoneyFormatter::fromMinor($payment->amount_minor) : null,
            'amount_minor' => $maySeePosition ? $payment->amount_minor : null,
            'received_on' => $payment->receivedOn()->format('M j, Y'),

            // The day it was keyed AT THE ESTATE: an instant, and UTC's date is tomorrow's every evening (bug hunt B023).
            'entered_on' => SiteTime::local($payment->entered_at)->format('M j, Y'),
            'entered_late' => $payment->enteredLate(),
            'received_by' => $payment->received_by_name,
            'status' => $payment->status,
            'journal_ref' => $payment->journal_ref,
        ];
    }

    /** @return list<array{key: string, value: string, label: string}> */
    private function kpis(Carbon $today, int $firstNo, int $lastNo, int $gapCount, bool $maySeePosition): array
    {
        /*
         * The month's takings — summed only for a viewer who may read them, so
         * a figure that is never read cannot be leaked by a later edit to the
         * array below.
         */
        $thisMonth = $maySeePosition
            ? Payment::query()
                ->where('status', 'recorded')
                ->whereBetween('received_at', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
                ->sum('amount_minor')
            : 0;

        return [
            ['key' => 'issued', 'value' => number_format(max(0, $lastNo - $firstNo + 1)), 'label' => 'Receipts issued'],

            // ABSENT, NOT BLANK, for a viewer who may not read the takings.
            ...($maySeePosition
                ? [['key' => 'month', 'value' => MoneyFormatter::fromMinor((int) $thisMonth), 'label' => 'Received in '.$today->format('F')]]
                : []),
            ['key' => 'gaps', 'value' => (string) $gapCount, 'label' => 'Gaps in the sequence'],
        ];
    }
}
