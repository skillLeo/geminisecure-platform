<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\ResidentAccount;
use App\Services\ResidentApp\AccountDeletion;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE DELETED ACCOUNTS TAB (M18H §6), read inside the estate that is open.
 *
 * Every Resident App account of this estate that is deleted and can still come back: who, which lot, when and by whom,
 * how many days are left before nothing of them is left, and what the lot owes — because a debt does not go with the
 * account, and the office restoring or removing somebody should see it. And the households the deletions left without
 * a primary resident: somebody has to be named, and until then the household is on this list.
 *
 * Money is shown only to a viewer who may see the ledger (the same `maySeeMoney` every Residents page is told).
 */
final class DeletedAccounts
{
    /** "Expiring soon": this many days or fewer before the account is anonymised, while the office can still restore it. */
    public const EXPIRING_DAYS = 7;

    public function __construct(private readonly AccountDeletion $deletion, private readonly Dues $dues) {}

    /** How many accounts are deleted and still restorable — the badge on the Residents page. */
    public function count(string $tenantId): int
    {
        return $this->deleted($tenantId)->count();
    }

    /**
     * What the list orders by (M32 F5). By default the oldest deletion first: the account nearest to being erased. What
     * the lot owes is a column only for a viewer who may see the ledger, and so a sort only for them.
     *
     * @return array<string, string|\Closure>
     */
    public static function sorts(bool $maySeeMoney = false): array
    {
        return [
            'name' => 'name',
            'lot' => 'unit',
            'deleted' => static fn (array $row): array => [(string) $row['deleted_at'], $row['id']],
            'days' => 'days_left',
            ...($maySeeMoney ? ['balance' => static fn (array $row): ?int => $row['balance']['minor'] ?? null] : []),
        ];
    }

    /** The list as the address asks for it (M32 F5). */
    public static function table(?Request $request = null, bool $maySeeMoney = false): TableQuery
    {
        return TableQuery::from($request ?? new Request, self::sorts($maySeeMoney), 'deleted', 'asc');
    }

    /**
     * Every deleted account the search and the filter admit — the whole list, or, given the table, the page it asks
     * for in the order it asks for (M32 F5).
     *
     * @return array{rows: list<array<string, mixed>>, total: int, expiring: int, search: string, only_expiring: bool, windows: array{self: int, office: int}, orphaned: list<array<string, mixed>>, table: array<string, mixed>|null}
     */
    public function board(string $tenantId, string $search = '', bool $onlyExpiring = false, bool $maySeeMoney = false, ?Carbon $at = null, ?TableQuery $table = null): array
    {
        $at ??= Carbon::now();
        $accounts = $this->deleted($tenantId)->orderBy('deleted_at')->get();

        $residents = Resident::withRemoved()->with('household.unit')
            ->whereIn('id', $accounts->pluck('resident_id')->filter()->all())
            ->get()->keyBy('id');
        $units = Unit::query()->whereIn('id', $accounts->pluck('unit_id')->filter()->all())->get()->keyBy('id');

        $rows = [];
        $alsoSearched = [];
        foreach ($accounts as $account) {
            $person = $account->resident_id === null ? null : $residents->get($account->resident_id);
            $unit = $person->household->unit ?? ($account->unit_id === null ? null : $units->get($account->unit_id));
            $standing = $this->deletion->standing($account, $at);

            // M32 F4: the register's search reads the person's phone and household too, so this list does.
            $alsoSearched[(int) $account->id] = [(string) preg_replace('/\D+/', '', (string) ($person->phone ?? '')), mb_strtolower((string) ($person->household->name ?? ''))];

            $rows[] = [
                'id' => (int) $account->id,
                'name' => (string) ($person->full_name ?? $account->full_name ?? 'No name given'),
                'email' => $account->channel === 'email' ? (string) $account->destination : null,
                'was_primary' => (bool) ($person->is_primary ?? false),
                'unit' => $unit?->reference,
                'unit_id' => $unit?->id,
                'unit_slug' => $unit === null ? null : Unit::slugFor((string) $unit->reference),
                'phase' => $unit?->block,
                'deleted_at' => $standing['deleted_at'],
                // Law 5 as decided (M62 A5, DR-039): "6 Sep 2026, 6:21 PM", and no zone on the estate's own console.
                'deleted_when' => $account->deleted_at === null ? '' : SiteTime::local($account->deleted_at, $tenantId)->format('j M Y, g:i A'),
                'deleted_by' => $account->deleted_by === AccountDeletion::BY_OFFICE ? (string) ($account->deleted_by_name ?: 'The estate office') : 'The resident, in the app',
                'reference' => (string) $account->deleted_reference,
                'days_left' => $standing['days_left'],
                'self_until' => Carbon::parse($standing['self_until'])->format('j M Y'),
                'can_self_restore' => $standing['can_self_restore'],
                'permanent_on' => Carbon::parse($standing['office_until'])->format('j M Y'),
                'can_restore' => $standing['can_office_restore'],
            ];
        }

        /*
         * EXPIRING IS A WARNING ABOUT AN ACCOUNT THAT CAN STILL COME BACK (the hub's finding, 12 Oct). One past the
         * office's window has 0 days left and was counted "expiring within 7 days", beside a greyed Restore that said
         * its time had run out.
         */
        $expiringSoon = static fn (array $r): bool => $r['can_restore'] && $r['days_left'] <= self::EXPIRING_DAYS;

        $total = count($rows);
        $expiring = count(array_filter($rows, $expiringSoon));

        $needle = mb_strtolower(trim($search));
        if ($needle !== '') {
            $digits = Residents::phoneDigits($search);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => str_contains(mb_strtolower(implode(' ', [$r['name'], (string) $r['email'], (string) $r['unit'], (string) $r['phase'], $r['reference']])), $needle)
                || str_contains($alsoSearched[$r['id']][1], $needle)
                || ($digits !== null && str_contains($alsoSearched[$r['id']][0], $digits))));
        }
        if ($onlyExpiring) {
            $rows = array_values(array_filter($rows, $expiringSoon));
        }

        // What each lot owes: the debt stays with the home, and the office acting here should see it.
        $owed = $maySeeMoney ? $this->dues->unitBalances(null, array_values(array_unique(array_filter(array_column($rows, 'unit_id'))))) : [];
        foreach ($rows as &$row) {
            $minor = $row['unit_id'] === null ? null : ($owed[$row['unit_id']] ?? 0);
            $row['balance'] = ! $maySeeMoney || $minor === null ? null : [
                'minor' => $minor,
                'text' => '$'.number_format(abs($minor) / 100, 2).($minor < 0 ? ' in credit' : ''),
                'owes' => $minor > 0,
            ];
        }
        unset($row);

        $page = $table?->pageOf($rows);

        return [
            'rows' => $page === null ? $rows : array_values($page->items()),
            'table' => $page === null ? null : $table->meta($page),
            'total' => $total,
            'expiring' => $expiring,
            'search' => $search,
            'only_expiring' => $onlyExpiring,
            'windows' => AccountDeletion::windowsFor($tenantId),
            'orphaned' => $this->householdsWithoutPrimary(),
        ];
    }

    /**
     * HOUSEHOLDS WITH NOBODY PRIMARY (M18H §4.1). A primary resident who deletes their account leaves the register, and
     * the household still has people on it: somebody must be named primary, and until then it is listed here.
     *
     * @return list<array{household_id: int, household: string, unit: ?string, unit_slug: ?string, members: int}>
     */
    public function householdsWithoutPrimary(): array
    {
        $current = Resident::query()->select('household_id', DB::raw('count(*) as members'), DB::raw('max(is_primary) as has_primary'))
            ->groupBy('household_id');

        $rows = DB::connection('tenant')->query()->fromSub($current, 'r')->where('r.has_primary', 0)->pluck('r.members', 'r.household_id');

        if ($rows->isEmpty()) {
            return [];
        }

        return Household::query()->with('unit')->whereIn('id', $rows->keys()->all())->orderBy('id')->get()
            ->map(static fn (Household $h): array => [
                'household_id' => (int) $h->id,
                'household' => (string) $h->name,
                'unit' => $h->unit?->reference,
                'unit_slug' => $h->unit === null ? null : Unit::slugFor((string) $h->unit->reference),
                'members' => (int) $rows[$h->id],
            ])->values()->all();
    }

    /** @return Builder<ResidentAccount> */
    private function deleted(string $tenantId): Builder
    {
        return ResidentAccount::query()
            ->where('tenant_id', $tenantId)
            ->where('status', ResidentAccount::DELETED)
            ->whereNotNull('deleted_at')
            ->whereNull('anonymised_at');
    }
}
