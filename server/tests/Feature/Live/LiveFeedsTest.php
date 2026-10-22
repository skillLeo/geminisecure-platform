<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Live\Auth\ChannelRegistry;
use App\Live\Auth\EstateScope;
use App\Live\Auth\Feeds;
use App\Live\Auth\Principal;
use App\Live\ChannelName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FacilitiesFixture;
use Tests\Support\HeadOfSecurityScope;

/*
|--------------------------------------------------------------------------
| A FEED CHANNEL IS AS WIDE AS ITS PAGE, BECAUSE IT ASKS THE PAGE (DESIGN §3.3)
|--------------------------------------------------------------------------
|
| Each console feed names one page route, pinned below by name, and its rule is that
| route's own `can:` middleware — plus, where the page narrows further, the predicate
| its controller calls. This file fails when a feed names a route that does not exist,
| when a route has no `can:` and is not marked ungated, when a pinned name moves, when
| an extra predicate is not the page's own, and when the two Gemini workforce groups'
| gates drift apart. And it proves the binding is live: change a route's `can:` and the
| channel's answer changes with it.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    tenancy()->end();

    DB::connection('mysql')->beginTransaction();

    $this->t = FacilitiesFixture::ESTATE;
    $this->registry = app(ChannelRegistry::class);
});

afterEach(function () {
    tenancy()->end();

    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * The abilities every route of this name asks, read straight from the router.
 *
 * @return list<list<string>>
 */
function liveFeedsAbilitySets(string $routeName): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteObject $route): bool => $route->getName() === $routeName)
        ->map(fn (RouteObject $route): array => collect($route->gatherMiddleware())
            ->filter(fn ($m): bool => is_string($m) && str_starts_with($m, 'can:'))
            ->map(fn (string $m): string => substr($m, 4))
            ->values()
            ->all())
        ->unique(fn (array $set): string => implode('|', $set))
        ->values()
        ->all();
}

/** Replace one `can:` on every route of this name, as a change to the page's gate would. */
function liveFeedsRegate(string $routeName, string $from, string $to): void
{
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route->getName() !== $routeName) {
            continue;
        }

        $action = $route->getAction();
        $action['middleware'] = array_map(fn ($m) => $m === 'can:'.$from ? 'can:'.$to : $m, (array) ($action['middleware'] ?? []));
        $route->setAction($action);
        $route->computedMiddleware = null;
    }
}

it('binds every feed to its one pinned page route, which exists and carries a can: or is marked ungated', function () {
    // PINNED (DESIGN §3.3, revision 2), re-read at the unit's base. A name that moves fails here.
    $pinned = [
        'estate' => [
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
        ],
        'gemini' => [
            'dispatch' => 'gemini.dispatch',
            'workforce' => 'gemini.guard_workforce',
            'clients' => 'gemini.clients',
            'billing' => 'gemini.billing_subscriptions',
            'audit' => 'gemini.access_audit_log',
            'settings' => 'gemini.platform_settings',
            'payroll' => 'gemini.payroll_accounting',
            'dashboard' => 'gemini.dashboard',
        ],
    ];

    expect(Feeds::ESTATE)->toBe($pinned['estate'])
        ->and(Feeds::GEMINI)->toBe($pinned['gemini']);

    // Every feed the grammar has is bound, and nothing is bound that the grammar lacks.
    expect(collect(array_keys(Feeds::ESTATE))->sort()->values()->all())->toBe(collect(ChannelName::ESTATE_FEEDS)->sort()->values()->all())
        ->and(collect(array_keys(Feeds::GEMINI))->sort()->values()->all())->toBe(collect(ChannelName::GEMINI_FEEDS)->sort()->values()->all());

    foreach ([...array_values($pinned['estate']), ...array_values($pinned['gemini'])] as $routeName) {
        expect(Route::getRoutes()->getByName($routeName))->not->toBeNull("the pinned route [{$routeName}] is gone");

        $abilities = Feeds::abilities($routeName);
        $sets = liveFeedsAbilitySets($routeName);

        expect($abilities)->not->toBeNull("[{$routeName}] has no can: and is not marked ungated")
            ->and($sets)->toHaveCount(1, "every route named [{$routeName}] must ask the same can:")
            ->and($abilities)->toBe($sets[0]);

        if (array_key_exists($routeName, Feeds::UNGATED)) {
            expect($abilities)->toBe([], "[{$routeName}] is marked ungated but carries a can:");
        } else {
            expect($abilities)->not->toBe([], "[{$routeName}] carries no can:");
        }
    }

    expect(array_diff(array_keys(Feeds::UNGATED), array_values($pinned['estate']), array_values($pinned['gemini'])))->toBe([], 'an ungated mark on a route no feed binds');
});

it('binds every record type to its detail route, which asks exactly what its feed\'s page asks', function () {
    foreach (['estate' => [ChannelName::ESTATE_RECORD_TYPES, Feeds::ESTATE_RECORDS, Feeds::ESTATE], 'gemini' => [ChannelName::GEMINI_RECORD_TYPES, Feeds::GEMINI_RECORDS, Feeds::GEMINI]] as $console => [$types, $records, $feeds]) {
        $expected = [];

        foreach ($types as $feed => $list) {
            foreach ($list as $type) {
                $expected[] = $feed.'.'.$type;
            }
        }

        expect(collect(array_keys($records))->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all(), $console);

        foreach ($records as $key => $routeName) {
            $feed = explode('.', $key)[0];

            expect(Feeds::abilities($routeName))->not->toBeNull("[{$routeName}] for {$console} {$key}")
                ->and(Feeds::abilities($routeName))->toBe(Feeds::abilities($feeds[$feed]), "{$key}'s detail page and its feed's page ask different things");
        }
    }
});

it('holds the Gemini operations screens and the guard directory to one can: set', function () {
    // routes/gemini/guards.php:15 and routes/gemini/operations.php:30 — one route decides both groups.
    $directory = Feeds::abilities('gemini.guard_workforce');

    foreach (['gemini.guard_workforce.roster', 'gemini.guard_workforce.gate_activity', 'gemini.guard_workforce.incidents', 'gemini.guard_workforce.standing_orders', 'gemini.guard_workforce.incident', 'gemini.guard_workforce.standing_order'] as $screen) {
        expect(Feeds::abilities($screen))->toBe($directory, $screen);
    }

    expect($directory)->toBe(['gemini.guard_workforce.view']);
});

it('changes a channel\'s answer the moment its page route\'s can: changes', function () {
    $t = $this->t;
    $treasurer = Principal::web(FacilitiesFixture::viewer(Role::TREASURER));
    $manager = Principal::web(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER));

    expect($this->registry->decide($treasurer, "estate.{$t}.security"))->toBeFalse()
        ->and($this->registry->decide($manager, "estate.{$t}.security"))->toBeTrue();

    liveFeedsRegate('estate.security', 'estate.security.view', 'estate.dues_ledger.view');

    expect($this->registry->decide($treasurer, "estate.{$t}.security"))->toBeTrue('the page now asks for the ledger, which the Treasurer holds')
        ->and($this->registry->decide($manager, "estate.{$t}.security"))->toBeFalse('and the Property Manager does not');

    $accountant = Principal::web(FacilitiesFixture::geminiViewer(Role::ACCOUNTANT));
    $dispatcher = Principal::web(FacilitiesFixture::geminiViewer(Role::DISPATCHER));

    expect($this->registry->decide($accountant, "gemini.{$t}.dispatch"))->toBeFalse()
        ->and($this->registry->decide($dispatcher, "gemini.{$t}.dispatch"))->toBeTrue();

    liveFeedsRegate('gemini.dispatch', 'gemini.dispatch.view', 'gemini.billing_subscriptions.view');

    expect($this->registry->decide($accountant, "gemini.{$t}.dispatch"))->toBeTrue()
        ->and($this->registry->decide($dispatcher, "gemini.{$t}.dispatch"))->toBeFalse();
});

it('asks the page\'s own extra predicate, the one its controller calls, inside the estate', function () {
    foreach (Feeds::ESTATE_EXTRAS as $feed => [$class, $method]) {
        $route = Route::getRoutes()->getByName(Feeds::ESTATE[$feed]);
        [$controller, $action] = explode('@', (string) $route?->getActionName());

        $reflection = new ReflectionMethod($controller, $action);
        $source = implode('', array_slice(
            (array) file((string) $reflection->getFileName()),
            (int) $reflection->getStartLine() - 1,
            (int) $reflection->getEndLine() - (int) $reflection->getStartLine() + 1,
        ));

        expect(str_contains($source, class_basename($class).'::'.$method.'('))
            ->toBeTrue("{$controller}@{$action} does not call the predicate the {$feed} feed asks");
    }

    // And the feed refuses whom the page refuses. Since M53 (Law 4) the Admin Assistant holds no money module, so not even
    // the Arrears route, let alone its extra predicate; the Treasurer holds both.
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);
    $routes = Feeds::estateRoutes('dues');

    expect(EstateScope::run($this->t, fn (): bool => Feeds::allows($assistant, $routes)))->toBeFalse('the Admin Assistant holds no money route (M53 Law 4)')
        ->and(EstateScope::run($this->t, fn (): bool => Feeds::allows($assistant, $routes, Feeds::ESTATE_EXTRAS['dues'])))->toBeFalse()
        ->and($this->registry->decide(Principal::web($assistant), "estate.{$this->t}.dues"))->toBeFalse()
        ->and($this->registry->decide(Principal::web(FacilitiesFixture::viewer(Role::TREASURER)), "estate.{$this->t}.dues"))->toBeTrue();
});

it('admits a dashboard holder without All scope to gemini.dashboard, and keeps every other platform-wide feed for All scope', function () {
    $t = $this->t;
    $name = 'gemini.live_scoped_'.Str::lower(Str::random(6));

    Role::findOrCreate($name, 'web');
    $role = Role::named($name);
    $role->forceFill(['console' => Console::Gemini->value, 'label' => 'Live scoped', 'sort' => 97, 'scope_default' => 'assigned_sites'])->save();

    // Every permission any Gemini feed's page asks, so only the scope can refuse.
    $role->givePermissionTo(collect(Feeds::GEMINI)->flatMap(fn (string $route): array => Feeds::abilities($route) ?? [])->unique()->values()->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$name]);
    $scoped = Principal::web(HeadOfSecurityScope::assigned($user, $name, $t));

    expect($this->registry->decide($scoped, 'gemini.dashboard'))->toBeTrue();

    foreach (array_diff(ChannelName::GEMINI_FEEDS, ['dashboard']) as $feed) {
        expect($this->registry->decide($scoped, 'gemini.'.$feed))->toBeFalse("gemini.{$feed} is platform-wide and needs All scope");
    }

    foreach (array_diff(ChannelName::GEMINI_FEEDS, ChannelName::GEMINI_PLATFORM_ONLY) as $feed) {
        expect($this->registry->decide($scoped, "gemini.{$t}.{$feed}"))->toBeTrue("gemini.{$t}.{$feed} is its own site's");
    }

    $director = Principal::web(FacilitiesFixture::geminiViewer(Role::DIRECTOR));

    foreach (ChannelName::GEMINI_FEEDS as $feed) {
        expect($this->registry->decide($director, 'gemini.'.$feed))->toBeTrue('the Director reaches gemini.'.$feed);
    }
});

it('refuses every grant on a route that is missing, ungated without a reason, or asks about a model', function () {
    Route::get('live-feeds-probe/{post}', fn () => 'probe')->middleware('can:update,post')->name('live.feeds.model');
    Route::get('live-feeds-open', fn () => 'probe')->name('live.feeds.open');
    Route::getRoutes()->refreshNameLookups();

    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    expect(Feeds::abilities('no.such.route'))->toBeNull()
        ->and(Feeds::abilities('live.feeds.model'))->toBeNull()
        ->and(Feeds::abilities('live.feeds.open'))->toBeNull()
        ->and(Feeds::abilities(''))->toBeNull()
        ->and(Feeds::allows($director, ['no.such.route']))->toBeFalse()
        ->and(Feeds::allows($director, ['live.feeds.model']))->toBeFalse()
        ->and(Feeds::allows($director, ['live.feeds.open']))->toBeFalse()
        ->and(Feeds::allows($director, ['gemini.dispatch']))->toBeTrue();
});
