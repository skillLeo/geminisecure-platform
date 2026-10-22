<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\Console;
use App\Models\AuditEntry;
use App\Models\Estate\Resident;
use App\Models\Guard;
use App\Models\Module;
use App\Models\ResidentAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SiteTime;
use App\Support\TableQuery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * The audit log: the only sanctioned way to write it, and the console's way to
 * read it back.
 *
 * Reading and writing live in one class deliberately. The write side decides
 * what an action is called; the read side is the only thing that has to turn
 * those strings back into words a reviewer understands. Split across two files
 * that vocabulary drifts, and the log starts describing an action the logger
 * cannot produce — the exact failure an audit log exists to rule out.
 *
 * The actor's name and role are denormalised onto the row rather than joined
 * at read time, because the log has to read correctly years later, after the
 * account has been renamed, re-roled or deleted. A join that returns null tells
 * a reviewer nothing about who did the thing.
 *
 * Nothing here updates or deletes. It cannot: audit_log is append-only at a
 * withheld grant, at a database trigger and in App\Models\AuditEntry.
 */
class AuditLogger
{
    /** How an entry with no signed-in actor is shown, and filtered by. */
    public const SYSTEM_ACTOR = 'System';

    /** How the board draws a platform-level entry that concerns no estate. */
    public const NO_ESTATE = '—';

    public const DEFAULT_SORT = 'timestamp';

    public const DEFAULT_DIRECTION = 'desc';

    /** Rows per page. The board draws five; a real log needs a real page. */
    public const PER_PAGE = 25;

    /**
     * The five columns the board draws, and what each one sorts by.
     *
     * The header text is the board's. The sort columns are here rather than in
     * the page component so the screen cannot offer a sort the query does not
     * implement: the component renders whatever this returns and nothing else.
     *
     * `client` is special-cased in the query — it sorts by the estate name the
     * column shows, which needs a join, not a column. `details` sorts by the
     * same fields the details sentence is built from, and in the same order,
     * so the visible order and the sort order are the same fact.
     *
     * `first` is the direction a column takes the first time it is clicked.
     * Time runs backwards by default in a log — the newest entry is the one
     * being looked for — while a name is looked up from the top.
     *
     * @var array<string, array{label: string, order: list<string>, first: string}>
     */
    private const COLUMNS = [
        'timestamp' => ['label' => 'Timestamp', 'order' => ['created_at'], 'first' => 'desc'],
        'admin' => ['label' => 'Admin', 'order' => ['actor_name'], 'first' => 'asc'],
        'action' => ['label' => 'Action', 'order' => ['action'], 'first' => 'asc'],
        'client' => ['label' => 'Client affected', 'order' => [], 'first' => 'asc'],
        'details' => ['label' => 'Details', 'order' => ['action', 'entity_type', 'entity_id'], 'first' => 'asc'],
    ];

    /**
     * Which part of the platform an action belongs to, keyed by the domain the
     * action names before the dot.
     *
     * Keyed by domain rather than by every individual action, so recording a
     * new `invoice.*` action does not also require an entry here before the
     * screen can label it. `tag` is the board's pill colour: the stylesheet
     * defines exactly three (billing, compliance, access) and no more may be
     * invented, so several categories share the neutral one.
     *
     * @var array<string, array{category: string, tag: string}>
     */
    private const DOMAINS = [
        'tenant' => ['category' => 'Clients', 'tag' => 'access'],
        'subscription' => ['category' => 'Billing', 'tag' => 'billing'],
        'invoice' => ['category' => 'Billing', 'tag' => 'billing'],
        'plan' => ['category' => 'Billing', 'tag' => 'billing'],
        'payroll' => ['category' => 'Billing', 'tag' => 'billing'],
        'payslip' => ['category' => 'Billing', 'tag' => 'billing'],
        'guard' => ['category' => 'Workforce', 'tag' => 'access'],
        'post' => ['category' => 'Workforce', 'tag' => 'access'],
        'shift' => ['category' => 'Workforce', 'tag' => 'access'],
        'user' => ['category' => 'Access', 'tag' => 'access'],
        'role' => ['category' => 'Access', 'tag' => 'access'],
        'permission' => ['category' => 'Access', 'tag' => 'access'],
        'session' => ['category' => 'Access', 'tag' => 'access'],
        'alert' => ['category' => 'Dispatch', 'tag' => 'compliance'],
        'duress' => ['category' => 'Dispatch', 'tag' => 'compliance'],
    ];

    /**
     * Actions that are compliance events whatever domain they name.
     *
     * A flagged licence is a `guard.*` action but it is not workforce news,
     * and the board colours it red. Checked before the domain table, so this
     * wins.
     *
     * @var list<string>
     */
    private const COMPLIANCE_ACTIONS = [
        '*licence*',
        '*compliance*',
        '*.flagged',
        '*.suspended',
        '*.expired',
        '*.breached',
    ];

    /**
     * WHAT AN ESTATE'S OWN AUDIT PAGE SHOWS, AND UNDER WHICH OF ITS MODULES (M84 §3.2).
     *
     * An estate's entries include Gemini's own work there: its guards, rosters, posts, standing orders, invoicing,
     * platform settings, dispatch, devices, and each person's own sign-in security. Those stay on the Gemini console's
     * log. On the estate's page an entry is shown only under an estate module, and only to a viewer who can see that
     * module (Law 4): a role given Settings without the money modules sees no amount in a before or after.
     *
     * First match wins, so a specific action sits above its domain. An action not listed is NOT shown: a new kind of
     * entry stays off the estate's page until it is placed here, rather than appearing by default.
     *
     * @var list<array{0: string, 1: string}> [the action's start, the estate module]
     */
    private const ESTATE_MODULE_OF = [
        ['estate.settings.payroll_routing_changed', 'payroll'],
        ['estate.settings.', 'settings'],
        ['estate.user_', 'settings'],
        ['estate.logo_changed', 'settings'],
        ['estate.resident_access_rules_changed', 'settings'],
        ['estate.test_rows_', 'settings'],
        ['estate.voting_eligibility_changed', 'governance'],
        ['estate.structure_', 'estate_structure'],
        ['estate.phase_', 'estate_structure'],
        ['estate.units_imported', 'estate_structure'],
        ['estate.charge_', 'dues_ledger'],
        ['estate.payment_plan_', 'dues_ledger'],
        ['estate.amenity_', 'facilities'],
        ['estate.employee_', 'payroll'],
        ['residents.', 'residents'],
        ['resident.', 'residents'],
        ['resident_account.', 'residents'],
        ['resident_app.', 'residents'],
        ['household.', 'residents'],
        ['unit_claim.', 'residents'],
        ['seeded.', 'residents'],
        ['election.', 'governance'],
        ['nomination.', 'governance'],
        ['ballot.', 'governance'],
        ['meeting.', 'governance'],
        ['payment.', 'payments'],
        ['payment_card.', 'payments'],
        ['autopay.', 'payments'],
        ['ledger.', 'accounting_posting'],
        ['account.em_dash_names_retitled', 'accounting_posting'],
        ['vendor.', 'vendor_costs'],
        ['amenity.', 'facilities'],
        ['booking.', 'facilities'],
        ['gate.', 'security'],
        ['alert.acknowledged', 'security'],
        ['alert.resolved', 'security'],
        ['operations.incident_', 'security'],
        ['role.', 'settings'],
        ['user.invit', 'settings'],
        ['user.bootstrapped', 'settings'],
        ['sample_data.', 'settings'],
    ];

    /* -----------------------------------------------------------------
     | Writing
     |------------------------------------------------------------------ */

    /**
     * `$actor` is for a caller that already knows who is acting and must not
     * depend on the session to say so — `RoleEditor`, whose every act has to name
     * its actor even when it runs from `gate:isolation`, where nobody is signed
     * in. Left out, it is whoever the session says, exactly as before.
     *
     * `$actorName` is for an entry that must NOT carry the actor's own name: a person deleting their own account (M18H)
     * is the one actor whose name the log is not to keep, because the log outlives what they asked to have removed. It
     * is written instead of the principal's name; the role is still the principal's.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
        ?User $actor = null,
        ?string $actorName = null,
    ): AuditEntry {
        $principal = $actor ?? Auth::user();

        return AuditEntry::create([
            // Falls back to the current tenant so an action taken inside an
            // estate is attributed to it without every caller remembering.
            'tenant_id' => $tenantId ?? tenant()?->getTenantKey(),
            /*
             * ONLY EVER A USER ID, because that is what the column is: a
             * foreign key to `users`. A Guard App handset is an actor too — its
             * token's tokenable is a `Guard` — and writing the guard's id here
             * pointed the key at a user that does not exist, so the first
             * audited thing a handset ever did failed on the constraint.
             *
             * Null with a name beside it is the honest shape. `actor_name` and
             * `actor_role` are already denormalised precisely so the log reads
             * correctly without a join, and a handset's entry reads "Marcus
             * Reid · Guard App handset" whether or not there is a user row to
             * point at.
             */
            'actor_id' => $principal instanceof User ? $principal->id : null,
            'actor_name' => $actorName ?? $this->actorName($principal),
            'actor_role' => $this->actorRole($principal),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => str(Request::userAgent() ?? '')->limit(250)->value(),
        ]);
    }

    /**
     * Who did it, whichever kind of principal they are.
     *
     * NOT EVERY ACTOR IS A CONSOLE USER, and assuming so was a real defect. A
     * Guard App handset authenticates with a Sanctum token whose tokenable is a
     * `Guard` — deliberately, so a handset credential can never reach a console
     * — and a `Guard` has a `full_name` rather than a `name` and no roles at
     * all. This method read `$actor->roles->first()` unconditionally and threw
     * the moment a guard's handset did anything audited.
     */
    private function actorName(?Authenticatable $actor): ?string
    {
        if ($actor instanceof User) {
            return $actor->name;
        }

        if ($actor instanceof Guard) {
            return $actor->full_name;
        }

        // A household's own act in the Resident App — a primary resident removing a member, changing a level.
        if ($actor instanceof ResidentAccount) {
            return $this->residentAppName($actor);
        }

        return null;
    }

    /**
     * A Resident App account, named as THE ESTATE'S REGISTER names the person it signs in as.
     *
     * NEVER BY THE ACCOUNT'S NAME ALONE: every access level may set that to anything through `me.update`
     * ("Estate Office"), and the actor column must not read as whoever a person chose to call themselves.
     * The register entry's name is the estate office's to change — `me.update` leaves it alone.
     *
     * An account whose register entry cannot be read — still pending, gone, or recorded outside its own
     * estate, where the register is not open — is named by its own name MARKED AS THE ACCOUNT'S, with its
     * id, so a chosen name can never pass for the register's. Reading the register never fails the act
     * being recorded (a panic is recorded this way too): a failed read is reported and falls back.
     */
    private function residentAppName(ResidentAccount $account): string
    {
        $registered = null;

        if ($account->resident_id !== null && tenancy()->initialized && (string) tenant()?->getTenantKey() === (string) $account->tenant_id) {
            try {
                $registered = Resident::withRemoved()->whereKey($account->resident_id)->value('full_name');
            } catch (\Throwable $unread) {
                report($unread);
            }
        }

        if (is_string($registered) && trim($registered) !== '') {
            return trim($registered);
        }

        $own = trim((string) $account->full_name);
        $which = 'Resident App account #'.$account->id;

        return $own === '' ? $which : $own.' ('.$which.')';
    }

    /**
     * What they were acting as.
     *
     * A guard is recorded as "Guard App handset" rather than left null, because
     * null in this column means "the system did it" and a person tapping a phone
     * is not the system. The distinction matters in the one place this log is
     * read: an entry nobody can attribute is the entry an auditor asks about.
     */
    private function actorRole(?Authenticatable $actor): ?string
    {
        if ($actor instanceof User) {
            return $actor->roles->first()?->label;
        }

        if ($actor instanceof Guard) {
            return 'Guard App handset';
        }

        if ($actor instanceof ResidentAccount) {
            return 'Resident App';
        }

        return null;
    }

    /* -----------------------------------------------------------------
     | Reading — Super Admin screen 41
     |------------------------------------------------------------------ */

    /**
     * The columns the screen may render and sort, in the board's order.
     *
     * @return list<array{key: string, label: string, first: string}>
     */
    public static function columns(): array
    {
        $columns = [];

        foreach (self::COLUMNS as $key => $column) {
            $columns[] = ['key' => $key, 'label' => $column['label'], 'first' => $column['first']];
        }

        return $columns;
    }

    /** A requested sort key, or the default if it is not one this screen sorts by. */
    public static function sortKey(?string $requested): string
    {
        return $requested !== null && array_key_exists($requested, self::COLUMNS)
            ? $requested
            : self::DEFAULT_SORT;
    }

    /** A requested direction, or the default. Only two values are ever accepted. */
    public static function direction(?string $requested): string
    {
        return $requested === 'asc' || $requested === 'desc'
            ? $requested
            : self::DEFAULT_DIRECTION;
    }

    /**
     * One page of the log, filtered, sorted and already in the shape the
     * screen renders.
     *
     * Every narrowing happens in the query, never after it. A scoped role that
     * sorts a column or asks for page nine must not see one row more than it
     * would on page one, and a limit applied to an already-fetched collection
     * gives exactly that.
     *
     * @param  array{q?: string|null, actor?: string|null, category?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  list<string>|null  $estateIds  Null sees every estate; a list sees those, plus platform-level entries.
     * @return array{rows: list<array<string, mixed>>, page: int, pages: int, total: int, previous_url: string|null, next_url: string|null}
     */
    public function search(
        array $filters,
        string $sortKey,
        string $direction,
        ?array $estateIds = null,
        int $perPage = self::PER_PAGE,
    ): array {
        $query = $this->filtered($filters, $estateIds);

        $this->applySort($query, $sortKey, $direction);

        $page = $query->paginate($perPage)->withQueryString();

        return [
            'rows' => $this->rowsOf($page->getCollection(), $estateIds),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'total' => $page->total(),
            'previous_url' => $page->previousPageUrl(),
            'next_url' => $page->nextPageUrl(),
        ];
    }

    /**
     * The screen's page of the log (M32 F5): the same filters as `search()`, in the order and at the page size the
     * shared table asks for. `search()` stays as the export's, which takes a file's worth at once.
     *
     * @param  array{q?: string|null, actor?: string|null, category?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  list<string>|null  $estateIds  Null sees every estate; a list sees those, plus platform-level entries.
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    public function page(array $filters, TableQuery $table, ?array $estateIds = null): array
    {
        $query = $this->filtered($filters, $estateIds);

        // A stable tie-break on a unique column, always (`TableQuery::order`): rows sharing a sort value would
        // otherwise drift between pages as the log grows, and a reader paging through an audit trail skip entries.
        $table->order($query, 'audit_log.id');
        $page = $table->paginate($query);

        return ['rows' => $this->rowsOf($page->getCollection(), $estateIds), 'table' => $table->meta($page)];
    }

    /**
     * ONE ESTATE'S LOG, AS ITS OWN PAGE SHOWS IT (M84 §3.2). Exactly this estate's entries: never another estate's and
     * never the platform-wide ones (`page()` with a list of estates includes those, for the Gemini console). Of those,
     * only the ones placed under an estate module the viewer can see (`ESTATE_MODULE_OF`). Filtered by person, module
     * and days; each row says who in which role, what was done, to what, before and after.
     *
     * @param  array{actor?: string|null, module?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, table: array<string, mixed>}
     */
    public function estatePage(string $estateId, User $viewer, array $filters, TableQuery $table): array
    {
        $query = $this->estateQuery($estateId, $viewer, $filters);
        $table->order($query, 'audit_log.id');
        $page = $table->paginate($query);

        return ['rows' => $this->estateRowsOf($page->getCollection(), $estateId), 'table' => $table->meta($page)];
    }

    /**
     * The same, a file's worth at once, newest first, for the estate page's export.
     *
     * @param  array{actor?: string|null, module?: string|null, from?: string|null, to?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function estateExport(string $estateId, User $viewer, array $filters, int $limit = 5000): array
    {
        $entries = $this->estateQuery($estateId, $viewer, $filters)
            ->orderByDesc('audit_log.created_at')->orderByDesc('audit_log.id')
            ->limit($limit)->get();

        return $this->estateRowsOf($entries, $estateId);
    }

    /**
     * The modules the page's filter offers: those this estate's log holds entries under, of the ones the viewer can
     * see, by the names the estate console gives them.
     *
     * @return list<array{key: string, label: string}>
     */
    public function estateModules(string $estateId, User $viewer): array
    {
        $visible = $this->estateModulesFor($viewer);
        $labels = $this->estateModuleLabels();

        /** @var list<array{key: string, label: string}> $modules */
        $modules = AuditEntry::query()->where('tenant_id', $estateId)->distinct()->pluck('action')
            ->map(static fn ($action): ?string => self::estateModuleOf((string) $action))
            ->filter(static fn (?string $module): bool => $module !== null && in_array($module, $visible, true))
            ->unique()
            ->map(static fn (string $module): array => ['key' => $module, 'label' => $labels[$module] ?? Str::headline($module)])
            ->sortBy('label')->values()->all();

        return $modules;
    }

    /**
     * The people the page's filter offers: those named on the entries this viewer can see, and nobody else.
     *
     * @return list<string>
     */
    public function estateActors(string $estateId, User $viewer): array
    {
        $query = AuditEntry::query()->where('audit_log.tenant_id', $estateId)->whereNotNull('actor_name');

        /** @var list<string> $names */
        $names = $this->underModules($query, $this->estateModulesFor($viewer))
            ->distinct()->orderBy('actor_name')->pluck('actor_name')->map(static fn ($n): string => (string) $n)->all();

        return $names;
    }

    /** The estate module an entry is shown under on an estate's page, or null when it stays on the Gemini console's log. */
    public static function estateModuleOf(string $action): ?string
    {
        foreach (self::ESTATE_MODULE_OF as [$start, $module]) {
            if (str_starts_with($action, $start)) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @param  array{actor?: string|null, module?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<AuditEntry>
     */
    private function estateQuery(string $estateId, User $viewer, array $filters): Builder
    {
        $query = $this->filtered(['actor' => $filters['actor'] ?? null, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null], [$estateId], exact: true);

        $modules = $this->estateModulesFor($viewer);
        $chosen = $filters['module'] ?? null;
        if ($chosen !== null) {
            // A module the viewer cannot see, typed into the address, narrows to nothing rather than widening.
            $modules = in_array($chosen, $modules, true) ? [$chosen] : [];
        }

        return $this->underModules($query, $modules);
    }

    /**
     * Narrows to the entries `ESTATE_MODULE_OF` places under one of these modules, in the query, so paging and the
     * total count only ever see what the page may show.
     *
     * @param  Builder<AuditEntry>  $query
     * @param  list<string>  $modules
     * @return Builder<AuditEntry>
     */
    private function underModules(Builder $query, array $modules): Builder
    {
        if ($modules === []) {
            return $query->whereRaw('1 = 0');
        }

        $case = 'CASE';
        $bindings = [];
        foreach (self::ESTATE_MODULE_OF as [$start, $module]) {
            $case .= ' WHEN LEFT(audit_log.action, ?) = ? THEN ?';
            array_push($bindings, strlen($start), $start, $module);
        }

        return $query->whereRaw('('.$case.' ELSE NULL END) IN ('.implode(', ', array_fill(0, count($modules), '?')).')', [...$bindings, ...$modules]);
    }

    /**
     * The estate modules entries are placed under that this viewer can open: Law 4 applied to the log's rows.
     *
     * @return list<string>
     */
    private function estateModulesFor(User $viewer): array
    {
        return array_values(array_filter(
            array_unique(array_column(self::ESTATE_MODULE_OF, 1)),
            static fn (string $module): bool => $viewer->can('estate.'.$module.'.view'),
        ));
    }

    /** @return array<string, string> each estate module's name in the console's navigation, by its key */
    private function estateModuleLabels(): array
    {
        /** @var array<string, string> $labels */
        $labels = Module::query()->where('console', Console::Estate->value)->pluck('label', 'key')->all();

        return $labels;
    }

    /**
     * @param  Collection<int, AuditEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function estateRowsOf($entries, string $estateId): array
    {
        $estateNames = $this->estateNames([$estateId]);
        $userNames = $this->userNames($entries);
        $labels = $this->estateModuleLabels();
        $lines = static fn (?array $values): array => collect($values ?? [])
            ->map(static fn ($value, $key): string => $key.': '.(is_scalar($value) || $value === null ? (string) ($value ?? 'none') : (string) json_encode($value)))
            ->values()->all();

        /** @var list<array<string, mixed>> $rows */
        $rows = $entries->map(function (AuditEntry $entry) use ($estateNames, $userNames, $lines, $labels): array {
            $shown = $this->present($entry, $estateNames, $userNames);
            $module = self::estateModuleOf((string) $entry->action);

            return [
                'id' => $entry->id,
                'when' => $shown['timestamp'],
                'date' => $shown['date'],
                'who' => $shown['actor'],
                'role' => (string) ($entry->actor_role ?? ''),
                'what' => $shown['details'],
                'module' => $module === null ? '' : ($labels[$module] ?? Str::headline($module)),
                'target' => trim(Str::headline((string) $entry->entity_type).' '.(string) $entry->entity_id),
                'before' => $lines($entry->before),
                'after' => $lines($entry->after),
            ];
        })->values()->all();

        return $rows;
    }

    /**
     * What the screen orders by (M32 F5): the five columns the board draws, each by what `COLUMNS` says it sorts on —
     * the client by the estate name the column shows. Newest first by default: time runs backwards in a log.
     *
     * @return array<string, \Closure>
     */
    public static function tableSorts(): array
    {
        $sorts = [];

        foreach (self::COLUMNS as $key => $column) {
            $sorts[$key] = $key === 'client'
                ? static fn ($query, string $dir) => $query->leftJoin('tenants', 'tenants.id', '=', 'audit_log.tenant_id')
                    ->select('audit_log.*')
                    ->orderBy('tenants.name', $dir)
                : static function ($query, string $dir) use ($column): void {
                    foreach ($column['order'] as $field) {
                        $query->orderBy('audit_log.'.$field, $dir);
                    }
                };
        }

        return $sorts;
    }

    public static function table(?HttpRequest $request = null): TableQuery
    {
        return TableQuery::from($request ?? new HttpRequest, self::tableSorts(), self::DEFAULT_SORT, self::DEFAULT_DIRECTION, self::PER_PAGE);
    }

    /**
     * The log as the filters narrow it, and the scope allows. Every narrowing happens in the query, never after it.
     *
     * @param  array{q?: string|null, actor?: string|null, category?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  list<string>|null  $estateIds
     * @param  bool  $exact  those estates' entries only, without the platform-wide ones (the estate console's own page)
     * @return Builder<AuditEntry>
     */
    private function filtered(array $filters, ?array $estateIds, bool $exact = false): Builder
    {
        $query = AuditEntry::query()->with('estate');

        if ($estateIds !== null && $exact) {
            $query->whereIn('audit_log.tenant_id', $estateIds);
        } elseif ($estateIds !== null) {
            // A site-scoped role sees its own estates, plus the platform-level
            // entries that belong to no single estate.
            $query->where(fn ($scoped) => $scoped->whereIn('audit_log.tenant_id', $estateIds)->orWhereNull('audit_log.tenant_id'));
        }

        $term = $filters['q'] ?? null;
        $category = $filters['category'] ?? null;

        /*
         * Both the search and the category filter have to reach the Action
         * column, whose pill is a category this class derives rather than a
         * column anyone can compare against. Deriving it needs the log's own
         * action strings, so they are fetched once, here, and only when one of
         * those two filters is actually in play.
         */
        $actions = $term !== null || $category !== null ? $this->distinctActions() : [];

        $this->applyTerm($query, $term, $actions);
        $this->applyActor($query, $filters['actor'] ?? null);
        $this->applyCategory($query, $category, $actions);

        $this->applyDays($query, $filters['from'] ?? null, $filters['to'] ?? null);

        return $query;
    }

    /**
     * @param  Collection<int, AuditEntry>  $entries
     * @param  list<string>|null  $estateIds
     * @return list<array<string, mixed>>
     */
    private function rowsOf($entries, ?array $estateIds): array
    {
        $estateNames = $this->estateNames($estateIds);
        $userNames = $this->userNames($entries);

        /** @var list<array<string, mixed>> $rows */
        $rows = $entries
            ->map(fn (AuditEntry $entry): array => $this->present($entry, $estateNames, $userNames))
            ->values()
            ->all();

        return $rows;
    }

    /**
     * The category and pill colour an action is shown under.
     *
     * @return array{category: string, tag: string}
     */
    public function categoryOf(string $action): array
    {
        if (Str::is(self::COMPLIANCE_ACTIONS, $action)) {
            return ['category' => 'Compliance', 'tag' => 'compliance'];
        }

        $domain = Str::before($action, '.');

        // An action from a domain nobody has classified still has to render,
        // and render usefully: the domain it names is a better label than
        // "Other", and it keeps working when a new domain starts logging.
        return self::DOMAINS[$domain] ?? ['category' => Str::headline($domain), 'tag' => 'access'];
    }

    /* -----------------------------------------------------------------
     | Reading — internals
     |------------------------------------------------------------------ */

    /**
     * Free-text search across everything the screen actually shows.
     *
     * "What the screen shows" is the whole point of the method. The estate
     * name lives on another table; the details sentence is the action string
     * with its dots and underscores opened out; the action tag is a category
     * derived in PHP. A reader who types a word they can see on the row and
     * gets nothing back does not conclude that the search is narrow — they
     * conclude the entry is gone, which is the one thing an audit log must
     * never suggest.
     *
     * @param  Builder<AuditEntry>  $query
     * @param  list<string>  $actions  Every distinct action in the log.
     */
    private function applyTerm($query, ?string $term, array $actions): void
    {
        if ($term === null) {
            return;
        }

        // The wildcards are the search's, not the reader's: a term containing
        // % or _ must match those characters, not stand in for any run of them.
        $like = '%'.addcslashes($term, '%_\\').'%';

        $categoryActions = array_values(array_filter(
            $actions,
            fn (string $action): bool => Str::contains($this->categoryOf($action)['category'], $term, true),
        ));

        $query->where(function ($matches) use ($like, $categoryActions) {
            $matches->where('actor_name', 'like', $like)
                ->orWhere('actor_role', 'like', $like)
                ->orWhere('action', 'like', $like)
                // The Details column reads "Guard licence flagged", so that is
                // what a reader searches for, not guard.licence_flagged.
                ->orWhereRaw("replace(replace(action, '.', ' '), '_', ' ') like ?", [$like])
                ->orWhere('entity_type', 'like', $like)
                ->orWhere('entity_id', 'like', $like)
                ->orWhereHas('estate', fn ($estate) => $estate->where('name', 'like', $like));

            if ($categoryActions !== []) {
                $matches->orWhereIn('action', $categoryActions);
            }
        });
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applyActor($query, ?string $actor): void
    {
        if ($actor === null) {
            return;
        }

        if ($actor === self::SYSTEM_ACTOR) {
            // Entries with no signed-in actor are drawn as "System", so
            // filtering by System has to return exactly the rows that are
            // drawn that way — the unattributed ones included.
            $query->where(fn ($system) => $system->whereNull('actor_name')->orWhere('actor_name', self::SYSTEM_ACTOR));

            return;
        }

        $query->where('actor_name', $actor);
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @param  list<string>  $actions  Every distinct action in the log.
     */
    private function applyCategory($query, ?string $category, array $actions): void
    {
        if ($category === null) {
            return;
        }

        /*
         * Resolved through categoryOf(), rather than from a second list of
         * which actions count as "Billing". The reader clicked a pill that
         * categoryOf() drew; putting the filter through the same function is
         * what makes it impossible for the two to disagree. A category nothing
         * belongs to narrows to nothing, which is the honest answer.
         */
        $query->whereIn('action', array_values(array_filter(
            $actions,
            fn (string $action): bool => $this->categoryOf($action)['category'] === $category,
        )));
    }

    /**
     * From and to, as the days the rows SHOW (B016).
     *
     * Storage is UTC (M4 §3.1), and this compared the UTC calendar date: an alert
     * acknowledged at 8:15 PM in Kingston on the 23rd is stored at 01:15 on the 24th, so
     * `?to=2026-09-23` left it out and `?from=2026-09-24` let it in, while the row itself
     * — printed on the estate's clock by `present()` — says the 23rd. A reviewer typing
     * the date of an evening incident missed it.
     *
     * One log holds rows from estates on different clocks, so there is no single pair of
     * instants to compare against. Each estate's rows are compared with the day's bounds
     * in that estate's zone, grouped by zone so the query holds one clause per clock, not
     * one per estate. A row that belongs to no estate — or to one whose row is gone — is
     * on the platform's clock, as `present()` prints it.
     *
     * A day is [its first instant, the next day's first instant), never "23:59:59", so no
     * fraction of the last second falls outside it.
     *
     * @param  Builder<AuditEntry>  $query
     */
    private function applyDays($query, ?string $from, ?string $to): void
    {
        if ($from === null && $to === null) {
            return;
        }

        $elsewhere = $this->estatesOffTheDefaultClock();

        $query->where(function ($onTheirDay) use ($elsewhere, $from, $to): void {
            foreach ($elsewhere as $zone => $tenantIds) {
                $onTheirDay->orWhere(function ($onThisClock) use ($zone, $tenantIds, $from, $to): void {
                    $onThisClock->whereIn('audit_log.tenant_id', $tenantIds);
                    $this->withinDays($onThisClock, $zone, $from, $to);
                });
            }

            $onTheirDay->orWhere(function ($onTheDefaultClock) use ($elsewhere, $from, $to): void {
                $offIt = array_merge(...array_values($elsewhere));

                if ($offIt !== []) {
                    $onTheDefaultClock->where(fn ($unlisted) => $unlisted->whereNull('audit_log.tenant_id')->orWhereNotIn('audit_log.tenant_id', $offIt));
                }

                $this->withinDays($onTheDefaultClock, SiteTime::platformZone(), $from, $to);
            });
        });
    }

    /**
     * Rows inside [the first instant of `from`, the first instant after `to`), on one clock.
     *
     * @param  Builder<AuditEntry>  $query
     */
    private function withinDays($query, string $zone, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->where('audit_log.created_at', '>=', Carbon::parse($from, $zone)->startOfDay()->utc());
        }

        if ($to !== null) {
            $query->where('audit_log.created_at', '<', Carbon::parse($to, $zone)->startOfDay()->addDay()->utc());
        }
    }

    /**
     * Every estate whose clock is not the platform's, by zone.
     *
     * Read through `SiteTime::valid()`, the same reading `present()` gives the zone, so a
     * blank or unknown zone in the column is the default in both places.
     *
     * @return array<string, list<string>> zone => tenant ids
     */
    private function estatesOffTheDefaultClock(): array
    {
        $byZone = [];

        foreach (Tenant::query()->toBase()->get(['id', 'timezone']) as $tenant) {
            $zone = SiteTime::valid($tenant->timezone ?? null);

            if ($zone !== SiteTime::platformZone()) {
                $byZone[$zone][] = (string) $tenant->id;
            }
        }

        return $byZone;
    }

    /**
     * The clock an entry is read on: its estate's, or the platform's for an entry that
     * belongs to no estate (or to an estate whose row is gone).
     */
    private function zoneOf(AuditEntry $entry): string
    {
        return $entry->tenant_id === null || $entry->estate === null
            ? SiteTime::platformZone()
            : SiteTime::valid($entry->estate->timezone);
    }

    /**
     * Every action string the log actually holds.
     *
     * @return list<string>
     */
    private function distinctActions(): array
    {
        $actions = AuditEntry::query()
            ->distinct()
            ->pluck('action')
            ->map(fn ($action): string => (string) $action)
            ->values()
            ->all();

        return $actions;
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applySort($query, string $sortKey, string $direction): void
    {
        if ($sortKey === 'client') {
            /*
             * Ordered by the estate name the column shows, not by the tenant id
             * behind it. A reader sorting a visible column expects the visible
             * order, and 'oceanview' does not sort where 'Ocean View' does.
             */
            $query->leftJoin('tenants', 'tenants.id', '=', 'audit_log.tenant_id')
                ->select('audit_log.*')
                ->orderBy('tenants.name', $direction);
        } else {
            foreach (self::COLUMNS[$sortKey]['order'] as $column) {
                $query->orderBy($column, $direction);
            }
        }

        // A stable tie-break on a unique column, always. Without one, rows
        // sharing a sort value can drift between pages as the log grows, and a
        // reader paging through an audit trail silently skips entries.
        $query->orderBy('audit_log.id', 'desc');
    }

    /**
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     * @return array<string, mixed>
     */
    private function present(AuditEntry $entry, array $estateNames, array $userNames): array
    {
        $category = $this->categoryOf($entry->action);
        $actor = $entry->actor_name ?? self::SYSTEM_ACTOR;

        /*
         * ON THE ESTATE'S CLOCK, NOT GREENWICH'S (B016, M4 §3.1). This printed the stored
         * UTC as it was, with nothing on the screen or in the export saying so: an alert
         * acknowledged at 8:15 PM in Kingston read "Sep 24, 2026 1:15 AM", while the
         * dispatch screen said "8:15 PM" for the same acknowledgement. `date` is the day
         * the row shows, which is the day `applyDays()` files it under — so clicking a
         * row's date finds that row.
         */
        $at = Carbon::instance($entry->created_at)->setTimezone($this->zoneOf($entry));

        return [
            'id' => $entry->id,
            // The board's format, to the minute. An audit trail is read as a
            // sequence, and a bare date cannot order two entries in one day.
            // With its zone (M11 §4.1): the log reaches across estates, each on
            // its own clock, and 8:15 PM in Kingston and in New York are an hour
            // apart — the label is what tells the reader which one this is. The estate's clock tag (M19A §4.2):
            // "Sep 23, 2026 8:15 PM JA", "… NY" — "EST" named Kingston and a New York winter alike.
            'timestamp' => SiteTime::fmt($at, 'M j, Y g:i A'),
            'date' => $at->toDateString(),
            'date_label' => $at->format('j F Y'),
            'actor' => $actor,
            'initials' => $this->initials($actor),
            'category' => $category['category'],
            'tag' => $category['tag'],
            'estate' => $entry->estate->name ?? self::NO_ESTATE,
            'details' => $this->details($entry, $estateNames, $userNames),
        ];
    }

    /**
     * The sentence in the Details column.
     *
     * Built from the action string rather than from a table of hand-written
     * sentences: `guard.licence_flagged` reads as "Guard licence flagged"
     * without anyone maintaining a translation, and an action added tomorrow
     * reads correctly the first time it is recorded.
     *
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     */
    private function details(AuditEntry $entry, array $estateNames, array $userNames): string
    {
        $phrase = Str::ucfirst(str_replace(['.', '_'], ' ', $entry->action));
        $subject = $this->subjectOf($entry, $estateNames, $userNames);

        return $subject === null ? $phrase : $phrase.' — '.$subject;
    }

    /**
     * What the entry was about, named rather than keyed where the platform
     * knows the name. An estate id and a user id say nothing to a reviewer.
     *
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     */
    private function subjectOf(AuditEntry $entry, array $estateNames, array $userNames): ?string
    {
        $id = $entry->entity_id;

        if ($id === null || $id === '') {
            return null;
        }

        return match ($entry->entity_type) {
            'Tenant' => $estateNames[$id] ?? $id,
            'User' => $userNames[$id] ?? $id,
            default => $id,
        };
    }

    /**
     * Estate names by id, fetched once for the page, and scoped the way the
     * rows are.
     *
     * A site-scoped role can see a platform-level entry that names an estate
     * it does not hold — a provisioning, say, which belongs to no tenant.
     * Turning that id into the client's name would tell it something the
     * client directory deliberately does not, so out of scope the id stays the
     * id. The Client affected column needs no such care: a row is only visible
     * at all if its estate is one this role holds.
     *
     * @param  list<string>|null  $estateIds
     * @return array<string, string>
     */
    private function estateNames(?array $estateIds): array
    {
        $names = [];

        foreach (Tenant::estates() as $estate) {
            $key = (string) $estate->getTenantKey();

            if ($estateIds === null || in_array($key, $estateIds, true)) {
                $names[$key] = $estate->name;
            }
        }

        return $names;
    }

    /**
     * The names behind the user ids on this page, in one query.
     *
     * @param  Collection<int, AuditEntry>  $entries
     * @return array<string, string>
     */
    private function userNames(Collection $entries): array
    {
        $ids = $entries
            ->filter(fn (AuditEntry $entry): bool => $entry->entity_type === 'User' && $entry->entity_id !== null)
            ->map(fn (AuditEntry $entry): string => (string) $entry->entity_id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();

        return $names;
    }

    /**
     * The two characters the board draws in the avatar: the initials of the
     * first two words, or the first two letters of a single-word name.
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return Str::upper(Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1));
        }

        return Str::upper(Str::substr($words[0] ?? '?', 0, 2));
    }
}
