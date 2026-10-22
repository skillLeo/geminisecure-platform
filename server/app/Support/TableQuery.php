<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * ONE RULE FOR EVERY TABLE'S ADDRESS (M32 F5). "No list in either console sorts or pages": where one did, it read its
 * own query string its own way — `current` and `last` on one screen, `page` and `pages` on the next — and most did not
 * at all. A table's state is now one set of words in the URL, `?sort=lot&dir=asc&page=3&per=50&q=413`, read here and
 * nowhere else, so a link can be sent and a refresh keeps the place:
 *
 *   sort  one of the table's own columns, or nothing (its default order); anything else is dropped, never trusted
 *   dir   asc or desc, and only beside a sort
 *   page  from 1; past the last is an empty page that still knows the total
 *   per   25, 50, 100 or 200
 *   q     the table's search, which the screen owns
 *
 * THE SERVER ORDERS THE WHOLE SET, and pages it after. A browser sorting the twenty rows it was sent is a page that
 * reads as sorted and is not. Every order ends on the table's key, so no row sits on two pages or on none.
 */
final class TableQuery
{
    /** The page sizes every pager offers. */
    public const SIZES = [25, 50, 100, 200];

    /**
     * @param  array<string, string|Closure>  $sorts  the column a key orders by, or a closure ordering the query itself
     */
    private function __construct(
        public readonly ?string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $per,
        public readonly string $search,
        private readonly array $sorts,
        private readonly string $defaultSort,
        private readonly string $defaultDir,
        private readonly int $defaultPer = self::SIZES[0],
        public readonly string $prefix = '',
    ) {}

    /**
     * @param  array<string, string|Closure>  $sorts  key => column, or key => fn ($query, string $dir) for an order of its own
     * @param  string  $defaultSort  the table's own order when none is asked for: newest first for anything with a time,
     *                               name or number order for a register
     */
    public static function from(Request $request, array $sorts, string $defaultSort, string $defaultDir = 'asc', int $defaultPer = 25, string $prefix = ''): self
    {
        $q = $request->query($prefix.'q');
        $sort = $request->query($prefix.'sort');
        $dir = $request->query($prefix.'dir');

        return self::make(
            $sorts,
            $defaultSort,
            $defaultDir,
            sort: is_string($sort) ? $sort : null,
            dir: is_string($dir) ? $dir : null,
            page: (int) $request->query($prefix.'page', '1'),
            per: (int) $request->query($prefix.'per', (string) $defaultPer),
            search: is_string($q) ? $q : '',
            defaultPer: $defaultPer,
            prefix: $prefix,
        );
    }

    /**
     * The same, from values already read — a service called with its filters, or a test. Whatever is not one of the
     * table's own is dropped here exactly as an address's would be.
     *
     * @param  array<string, string|Closure>  $sorts
     */
    public static function make(
        array $sorts,
        string $defaultSort,
        string $defaultDir = 'asc',
        ?string $sort = null,
        ?string $dir = null,
        int $page = 1,
        int $per = 25,
        string $search = '',
        int $defaultPer = 25,
        string $prefix = '',
    ): self {
        $sort = $sort !== null && array_key_exists($sort, $sorts) ? $sort : null;
        $defaultPer = in_array($defaultPer, self::SIZES, true) ? $defaultPer : self::SIZES[0];

        return new self(
            sort: $sort,
            dir: $sort !== null && $dir === 'desc' ? 'desc' : 'asc',
            page: max(1, $page),
            per: in_array($per, self::SIZES, true) ? $per : $defaultPer,
            search: trim($search),
            sorts: $sorts,
            defaultSort: $defaultSort,
            defaultDir: $defaultDir === 'desc' ? 'desc' : 'asc',
            defaultPer: $defaultPer,
            prefix: $prefix,
        );
    }

    /** The column this table is ordered by now: the one asked for, or its default. */
    public function orderKey(): string
    {
        return $this->sort ?? $this->defaultSort;
    }

    public function orderDir(): string
    {
        return $this->sort === null ? $this->defaultDir : $this->dir;
    }

    /**
     * Order the query, the table's key last.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|QueryBuilder  $query
     */
    public function order(Builder|QueryBuilder $query, string $key = 'id'): void
    {
        $column = $this->sorts[$this->orderKey()] ?? null;

        if ($column instanceof Closure) {
            $column($query, $this->orderDir());
        } elseif (is_string($column)) {
            $query->orderBy($column, $this->orderDir());
        }

        $query->orderBy($key, $this->orderDir());
    }

    /**
     * The asked-for page of the ordered query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Paginator<int, TModel>
     */
    public function paginate(Builder $query): Paginator
    {
        return $query->paginate(perPage: $this->per, page: $this->page);
    }

    /**
     * The same, for a query of plain rows.
     *
     * @return Paginator<int, \stdClass>
     */
    public function paginateRows(QueryBuilder $query): Paginator
    {
        return $query->paginate(perPage: $this->per, page: $this->page);
    }

    /**
     * THE WHOLE SET, BUILT IN PHP, ORDERED HERE AND CUT TO THE PAGE. Some registers carry a figure the ledger sums per
     * row — what a supplier was paid this year, what a run cost — that no ORDER BY can reach, over a set small enough
     * to build whole. The server orders every row of it by the same rule, and then cuts the page, so it is never a page
     * sorted by itself. For such a table the sorts name the row's own field (`'paid' => 'paid_ytd_minor'`), or give a
     * closure reading the value from the row. Strings compare as a person reads them — case aside, Lot 2 before Lot 10
     * — and a missing value first ascending, as MySQL orders a NULL.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Paginator<int, array<string, mixed>>
     */
    public function pageOf(array $rows, string $key = 'id'): Paginator
    {
        $by = $this->sorts[$this->orderKey()] ?? null;
        $value = $by instanceof Closure ? $by : static fn (array $row): mixed => is_string($by) ? ($row[$by] ?? null) : null;
        $sign = $this->orderDir() === 'desc' ? -1 : 1;

        usort($rows, static fn (array $a, array $b): int => $sign * (self::compare($value($a), $value($b)) ?: self::compare($a[$key] ?? null, $b[$key] ?? null)));

        return new Paginator(
            array_slice($rows, ($this->page - 1) * $this->per, $this->per),
            count($rows),
            $this->per,
            $this->page,
        );
    }

    private static function compare(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) - ($b === null ? 0 : 1);
        }

        if (is_string($a) && is_string($b)) {
            return strnatcasecmp($a, $b);
        }

        return $a <=> $b;
    }

    /**
     * What the table component reads: how it is ordered, which columns may be, and where in the set this page is.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{sort: string|null, dir: string|null, default: array{sort: string, dir: string, per: int}, sortable: list<string>, page: int, last: int, per: int, sizes: list<int>, from: int|null, to: int|null, total: int, q: string, prefix: string}
     */
    public function meta(LengthAwarePaginator $page): array
    {
        return [
            // Where the table's words sit in the address when a page carries more than one table: `gate_sort`, `gate_page`.
            'prefix' => $this->prefix,
            'sort' => $this->sort,
            'dir' => $this->sort === null ? null : $this->dir,
            'default' => ['sort' => $this->defaultSort, 'dir' => $this->defaultDir, 'per' => $this->defaultPer],
            'sortable' => array_keys($this->sorts),
            'page' => $page->currentPage(),
            'last' => max(1, $page->lastPage()),
            'per' => $this->per,
            'sizes' => self::SIZES,
            'from' => $page->firstItem(),
            'to' => $page->lastItem(),
            'total' => $page->total(),
            'q' => $this->search,
        ];
    }
}
