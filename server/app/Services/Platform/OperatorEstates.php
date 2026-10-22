<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Enums\AccessScope;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * THE ESTATES A HUMAN OPERATOR IS LOOKING AT — the one place that says which
 * estates belong in the company's own operational and financial picture, and
 * the one place that says the demonstration estate does not (M5 §3).
 *
 * WHY THIS EXISTS AT ALL.
 *
 * `tenants.demo` marks the estate an App Store or Play reviewer signs in to.
 * Tenant isolation already holds for it — a reviewer reaches that estate's
 * database and no other's — but isolation is about what the REVIEWER can see.
 * It says nothing about what the COMPANY sees, and by default the company saw
 * everything: the demonstration estate was counted as an active client, carried
 * into every revenue report and churn figure, chased for a payment method, and
 * — the one with a real cost — its alerts landed in the live control-room
 * dispatch queue. A reviewer pressing the panic button in the Guard App raised
 * a duress alert that a real dispatcher would have responded to.
 *
 * WHY IT IS ONE CLASS AND NOT A `where('demo', false)` IN EVERY FILE.
 *
 * There were nine such reads when this was written and there will be more. A
 * rule spread across nine files is a rule that the tenth file will miss, and
 * the tenth file is the one that puts a fake panic in front of a dispatcher at
 * 3 AM. So every operator-facing read goes through `narrow()`, which applies
 * BOTH halves of the question at once:
 *
 *   1. may this viewer see this estate at all      (role scope + assignments)
 *   2. is this estate part of the running business (not the demonstration one)
 *
 * They are deliberately inseparable. Before this class the first half was
 * copied into every reader by hand and the second half did not exist; anybody
 * adding a new console screen copied the half that was there and never learned
 * about the half that was not. Now there is one call to copy and it carries
 * both.
 *
 * THE EXCLUSION IS SQL, AND SO IT CANNOT GO STALE.
 *
 * `exclude()` writes a subquery against `tenants` rather than reading a cached
 * list of ids into PHP. `demo:seed` sets the flag through the query builder, a
 * future settings screen would set it through the model, and a cache would be
 * right until one of those happened between two requests in the same process.
 * A subquery is evaluated by the database at the moment the read runs, so the
 * answer is whatever the column says right then. `tenants.demo` is indexed and
 * the table holds one row per client, so this costs nothing worth measuring.
 *
 * WHAT IS **NOT** IN SCOPE HERE — and the distinction matters:
 *
 *   INFRASTRUCTURE still walks every estate. `grants:append-only`,
 *   `grants:estates`, `tenants:migrate`, the idempotency prune and the
 *   provisioning seeders must reach the demonstration estate or it stops
 *   working — and a broken demonstration estate is a store rejection. Those
 *   keep calling `Tenant::estates()`, which is unchanged and still means
 *   "every estate this platform runs".
 *
 *   THE ESTATE'S OWN RESIDENTS still get everything. `push:reminders` telling
 *   the demonstration estate's residents that voting closes tomorrow is the
 *   product working, and a reviewer seeing that push is the whole point. What
 *   is excluded is what reaches somebody at GEMINI, not what reaches somebody
 *   inside the estate.
 *
 *   ONE ESTATE NAMED ON PURPOSE is still reachable. Somebody at the company has
 *   to be able to check the demonstration estate is seeded and notice it is
 *   broken before a reviewer does, so `/clients/demoestate` opens normally and
 *   a directory search that names it finds it. What it is out of is the
 *   DEFAULTS: the totals, the money, the queues, the lists nobody asked for.
 *
 * @see \App\Services\Reviewer\ReviewerMode for what the flag is for
 */
final class OperatorEstates
{
    /**
     * What a demonstration estate is called wherever one is deliberately shown.
     *
     * A constant rather than a string in each template: the label is a promise
     * that the figures beside it are invented, and a screen that spelled it
     * differently would be making a weaker promise without meaning to.
     */
    public const LABEL = 'Demonstration';

    /**
     * Narrow an operator-facing read to the estates this viewer is looking at.
     *
     * THE ONLY METHOD A CONSOLE READ SHOULD NEED. Both halves of the rule, in
     * the query rather than in the render — a filter applied after the rows are
     * fetched leaves every excluded row one URL, one sort or one page away.
     *
     * `$column` is whatever holds the estate id in the query being narrowed:
     * `tenant_id` on almost every central table, `tenants.id` or `id` when the
     * query is over the estate list itself.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function narrow(EloquentBuilder|QueryBuilder $query, User $viewer, string $column = 'tenant_id'): void
    {
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn($column, $viewer->accessibleEstateIds());
        }

        self::exclude($query, $column);
    }

    /**
     * `narrow()` for a read of OFFICERS, where an officer with no estate stays.
     *
     * An officer taken on and not yet posted has a null `tenant_id`, and belongs
     * to no estate — so no site scope can be the reason to hide them. The
     * workforce screens keep them for a site-scoped reader (`GuardWorkforce`,
     * which found out the hard way: a Head of Security added an officer and never
     * saw them again), and any other screen counting or listing officers for that
     * reader has to agree with those screens or the two disagree about the same
     * people. A bare `whereIn` drops them, because `NULL IN (…)` is not true.
     *
     * Grouped, so the OR cannot escape and widen the exclusion that follows.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function narrowOfficers(EloquentBuilder|QueryBuilder $query, User $viewer, string $column = 'guards.tenant_id'): void
    {
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $estates = $viewer->accessibleEstateIds();

            $query->where(static function ($group) use ($column, $estates): void {
                $group->whereNull($column)->orWhereIn($column, $estates);
            });
        }

        self::exclude($query, $column);
    }

    /**
     * Take the demonstration estate out of a read that has no viewer.
     *
     * Platform totals and rate cards answer for the company rather than for a
     * person, so there is no scope to apply — but there is still a
     * demonstration estate to leave out of the arithmetic. (The revenue reports
     * used to be read this way too; they have a reader now, and go through
     * `narrow()` — see `RevenueReports`.)
     *
     * A NOT IN subquery rather than a fetched list of ids: see the class note.
     * It also means the rule survives a query the caller goes on to clone,
     * paginate or count, which `ClientDirectory` does.
     *
     * NULL IS KEPT, AND THAT IS NOT A DETAIL. `guards.tenant_id` is nullable —
     * an officer taken on but not yet posted is a real state the workforce
     * screens spend rows on — and in SQL `NULL NOT IN (…)` is NULL, which is
     * not true, so a bare NOT IN would silently delete every unassigned guard
     * from the platform's own headcount. A row with no estate cannot belong to
     * the demonstration estate, so it is kept explicitly.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function exclude(EloquentBuilder|QueryBuilder $query, string $column = 'tenant_id'): void
    {
        $query->where(static function ($group) use ($column): void {
            $group
                ->whereNull($column)
                ->orWhereNotIn($column, static function (QueryBuilder $demonstrations): void {
                    $demonstrations->select('id')->from('tenants')->where('demo', true);
                });
        });
    }

    /**
     * The estates an operator is looking at, as models — `Tenant::estates()`
     * with the demonstration one left out.
     *
     * The shape callback behaves exactly as `Tenant::estates()`'s does, down to
     * the default ordering, so a caller swapping one for the other changes
     * which estates come back and nothing else.
     *
     * @param  (callable(EloquentBuilder<Tenant>): void)|null  $shape
     * @return Collection<int, Tenant>
     */
    public static function estates(?callable $shape = null): Collection
    {
        return Tenant::estates(static function (EloquentBuilder $query) use ($shape): void {
            self::exclude($query, 'tenants.id');

            if ($shape !== null) {
                $shape($query);
            } else {
                $query->orderBy('id');
            }
        });
    }

    /**
     * The estate ids an operator is looking at, ordered.
     *
     * This is what the dispatch screens subscribe their live alert channels to.
     * Alerts are broadcast on `estate.{id}.alerts` and on no global channel, so
     * an estate absent from this list is an estate whose panic alerts never
     * cross the wire to a console at all — the exclusion holds on the socket as
     * well as on the queue, without a second rule saying so.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        /** @var list<string> $ids */
        $ids = Tenant::query()->where('demo', false)->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * The ids of every demonstration estate on this platform.
     *
     * For LABELLING only — never for filtering. Filtering is `exclude()`, which
     * is SQL and cannot be stale; this reads a list into PHP, so a caller holds
     * it for one screen and asks again on the next.
     *
     * Plural on purpose. There is one today, and `ReviewerMode::estate()`
     * already picks the first if there are more; nothing here needs to assume
     * the count.
     *
     * @return list<string>
     */
    public static function demonstrationIds(): array
    {
        /** @var list<string> $ids */
        $ids = DB::connection('mysql')->table('tenants')->where('demo', true)->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * Is this one estate the demonstration estate?
     *
     * For a screen that has already been handed a single estate — a client
     * record, one alert's response screen — and needs to say so on the page.
     * A list labels itself from `demonstrationIds()` instead, so that a queue
     * of fifty rows asks the database once rather than fifty times.
     */
    public static function isDemonstration(?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }

        return DB::connection('mysql')->table('tenants')->where('id', $tenantId)->where('demo', true)->exists();
    }
}
