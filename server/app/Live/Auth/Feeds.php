<?php

declare(strict_types=1);

namespace App\Live\Auth;

use App\Models\User;
use App\Services\Estate\Dues;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * EVERY CONSOLE FEED IS BOUND TO THE PAGE THAT SHOWS ITS DATA (M11 Part C, DESIGN §3.3).
 *
 * A feed channel carries what one page shows, so it is authorised by that page's own
 * gate and by nothing written here: each feed names ONE route, pinned by name, and its
 * rule is every `can:` that route gathers — read from the router at the moment of the
 * grant, the way the router itself would apply them — plus, where the page narrows
 * further, the very predicate the page's controller calls. So a page whose gate changes
 * takes its channel with it, and there is no second copy of the permission matrix to
 * drift from the first.
 *
 * A RECORD CHANNEL is the feed's rule AND its detail route's `can:`. Today the two agree
 * everywhere (`LiveFeedsTest` says so); asking both means a detail page that is ever
 * narrowed narrows its channel too, and neither can be the wider door.
 *
 * WHERE EACH IS ASKED. An estate feed's gate is asked with that estate open
 * (`EstateScope`), because that is where the estate page asks it; a Gemini feed's is
 * asked centrally, as the Gemini pages are. The scope rules — assigned sites, the
 * platform-wide fan-in, the demonstration estate — are the rules' own, in front of this.
 *
 * FAIL CLOSED. A pinned route that is missing, or that carries a `can:` with model
 * arguments a channel cannot supply, yields no abilities and every grant on it is
 * refused. A route with no `can:` at all is refused too, unless it is listed in
 * `UNGATED` with the reason it may be.
 */
final class Feeds
{
    /**
     * Estate feed => the page route it mirrors (§3.3, revision 2 pins included).
     *
     * @var array<string, string>
     */
    public const ESTATE = [
        'security' => 'estate.security',
        'facilities' => 'estate.facilities.maintenance',
        'dues' => 'estate.dues.arrears',
        'ledger' => 'estate.dues.schedule',
        'payments' => 'estate.dues.receipts',
        'accounting' => 'estate.accounting.reconciliation',
        'governance' => 'estate.governance.notices',
        'residents' => 'estate.residents.index',
        'settings' => 'estate.settings.users',
        'billing' => 'estate.settings.billing',
        'console' => 'estate.home',
        'payroll' => 'estate.payroll.runs',
        'structure' => 'estate.structure',
    ];

    /**
     * Where the page narrows past its route, the page's own predicate, called as the
     * page calls it: `[class, static method]`, given the viewer.
     *
     * `dues`: the Arrears register's rows need the estate's position as well as the
     * ledger (`DuesController::arrears`), and the Admin Assistant — ledger E, reports
     * none — is shown an empty register. So its channel is refused them.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    public const ESTATE_EXTRAS = [
        'dues' => [Dues::class, 'maySeeEstatePosition'],
    ];

    /**
     * `{feed}.{type}` => the detail route of an estate record channel.
     *
     * @var array<string, string>
     */
    public const ESTATE_RECORDS = [
        'facilities.ticket' => 'estate.facilities.ticket',
        'ledger.unit' => 'estate.dues.unit',
        'residents.unit' => 'estate.residents.show',
        'governance.meeting' => 'estate.governance.meeting',
        'governance.ballot' => 'estate.governance.election',
        'governance.nomination' => 'estate.governance.nomination',
    ];

    /**
     * Gemini feed => the page route it mirrors.
     *
     * `workforce` is the Guard workforce directory; the operations screens (roster,
     * gate activity, incidents, standing orders) sit behind the same permission, and
     * `LiveFeedsTest` holds the two groups' `can:` sets equal so one route decides both.
     *
     * @var array<string, string>
     */
    public const GEMINI = [
        'dispatch' => 'gemini.dispatch',
        'workforce' => 'gemini.guard_workforce',
        'clients' => 'gemini.clients',
        'billing' => 'gemini.billing_subscriptions',
        'audit' => 'gemini.access_audit_log',
        'settings' => 'gemini.platform_settings',
        'payroll' => 'gemini.payroll_accounting',
        'dashboard' => 'gemini.dashboard',
    ];

    /**
     * No Gemini page narrows past its route in a way a channel can ask. The audit log's
     * scope narrowing (`AuditLogController`) is the scope rule the Gemini rule already
     * applies: a site-scoped reader reaches `gemini.{t}.audit` only for its own sites,
     * and the platform-wide fan-in only with `All` scope.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    public const GEMINI_EXTRAS = [];

    /**
     * `{feed}.{type}` => the detail route of a Gemini record channel.
     *
     * @var array<string, string>
     */
    public const GEMINI_RECORDS = [
        'dispatch.alert' => 'gemini.dispatch.alert',
        'workforce.incident' => 'gemini.guard_workforce.incident',
        'workforce.orders' => 'gemini.guard_workforce.standing_order',
        'workforce.guard' => 'gemini.guard_workforce.show',
    ];

    /**
     * Routes a feed may bind although they carry no `can:`, each with its reason.
     *
     * @var array<string, string>
     */
    public const UNGATED = [
        'estate.home' => 'The estate dashboard is every estate user\'s; estate.{t}.console carries only TileLive refs for the tiles it shows ungated (DESIGN §3.3).',
    ];

    /**
     * The routes an estate channel's rule reads: the feed's, then the record's detail route.
     *
     * @return list<string>
     */
    public static function estateRoutes(string $feed, ?string $type = null): array
    {
        $routes = [self::ESTATE[$feed] ?? ''];

        if ($type !== null) {
            $routes[] = self::ESTATE_RECORDS[$feed.'.'.$type] ?? '';
        }

        return $routes;
    }

    /**
     * The routes a Gemini channel's rule reads.
     *
     * @return list<string>
     */
    public static function geminiRoutes(string $feed, ?string $type = null): array
    {
        $routes = [self::GEMINI[$feed] ?? ''];

        if ($type !== null) {
            $routes[] = self::GEMINI_RECORDS[$feed.'.'.$type] ?? '';
        }

        return $routes;
    }

    /**
     * Every ability the route's `can:` middleware asks, in order — or null when the
     * route is missing, is ungated without being listed, or asks something no channel
     * can answer.
     *
     * @return list<string>|null
     */
    public static function abilities(string $routeName): ?array
    {
        $route = $routeName === '' ? null : Router::getRoutes()->getByName($routeName);

        if (! $route instanceof Route) {
            return null;
        }

        $excluded = array_map('strval', $route->excludedMiddleware());
        $abilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || in_array($middleware, $excluded, true)) {
                continue;
            }

            $argument = match (true) {
                str_starts_with($middleware, 'can:') => substr($middleware, 4),
                str_starts_with($middleware, Authorize::class.':') => substr($middleware, strlen(Authorize::class) + 1),
                default => null,
            };

            if ($argument === null) {
                continue;
            }

            // `can:update,post` asks about a bound model a channel never has.
            if ($argument === '' || str_contains($argument, ',')) {
                return null;
            }

            $abilities[] = $argument;
        }

        if ($abilities === [] && ! array_key_exists($routeName, self::UNGATED)) {
            return null;
        }

        return $abilities;
    }

    /**
     * Whether this user passes every route's gate and the extra predicate.
     *
     * For an estate channel, call it inside `EstateScope::run()` for that estate.
     *
     * @param  list<string>  $routes
     * @param  array{0: class-string, 1: string}|null  $extra
     */
    public static function allows(User $user, array $routes, ?array $extra = null): bool
    {
        foreach ($routes as $routeName) {
            $abilities = self::abilities($routeName);

            if ($abilities === null) {
                return false;
            }

            foreach ($abilities as $ability) {
                if (! $user->can($ability)) {
                    return false;
                }
            }
        }

        if ($extra !== null) {
            $predicate = [$extra[0], $extra[1]];

            if (! is_callable($predicate) || call_user_func($predicate, $user) !== true) {
                return false;
            }
        }

        return true;
    }
}
