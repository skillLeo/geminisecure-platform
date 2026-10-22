<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Jobs\Tenancy\MigrateEstateAsOwner;
use App\Jobs\Tenancy\ReapplyGrantsAfterMigration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Jobs;
use Stancl\Tenancy\Listeners;
use Stancl\Tenancy\Middleware;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;

class TenancyServiceProvider extends ServiceProvider
{
    // By default, no namespace is used to support the callable array syntax.
    public static string $controllerNamespace = '';

    /** @return array<class-string, array<int, mixed>> */
    public function events(): array
    {
        return [
            // Tenant events
            Events\CreatingTenant::class => [],
            Events\TenantCreated::class => [
                JobPipeline::make([
                    Jobs\CreateDatabase::class,

                    // AS THE SCHEMA OWNER, not the package's `MigrateDatabase`, which migrates
                    // as the estate's own user — a user with no UPDATE until the grants below,
                    // so every backfill migration stopped a new estate part-way (M7).
                    MigrateEstateAsOwner::class,

                    // Invariant 4, layer 1. Must run AFTER the migrations:
                    // it grants UPDATE/DELETE back per table, and a table must
                    // exist before it can be named in a GRANT.
                    ApplyAppendOnlyGrants::class,

                ])->send(function (Events\TenantCreated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],
            Events\SavingTenant::class => [],
            Events\TenantSaved::class => [],
            Events\UpdatingTenant::class => [],
            Events\TenantUpdated::class => [],
            Events\DeletingTenant::class => [],
            Events\TenantDeleted::class => [
                JobPipeline::make([
                    Jobs\DeleteDatabase::class,
                ])->send(function (Events\TenantDeleted $event) {
                    return $event->tenant;
                })->shouldBeQueued(false), // `false` by default, but you probably want to make this `true` for production.
            ],

            // Domain events
            Events\CreatingDomain::class => [],
            Events\DomainCreated::class => [],
            Events\SavingDomain::class => [],
            Events\DomainSaved::class => [],
            Events\UpdatingDomain::class => [],
            Events\DomainUpdated::class => [],
            Events\DeletingDomain::class => [],
            Events\DomainDeleted::class => [],

            // Database events
            Events\DatabaseCreated::class => [],

            /*
             * RE-GRANT AFTER EVERY MIGRATION, NOT ONLY AT PROVISIONING.
             *
             * UPDATE and DELETE are withheld at database level and granted back
             * per table (D-017), so a migration that adds a table leaves that
             * table with no grant at all for the estate's own user. The symptom
             * is subtle and late: reads work, inserts work, and only an update
             * fails — possibly weeks later, and in this project twice in one
             * day, once on the payroll tables and once on the notices.
             *
             * `grants:estates` existed for exactly this and its docblock said it
             * "must run after every tenants:migrate". Nothing enforced that, and
             * an instruction in a comment is not a guarantee. Now the migration
             * itself re-grants, which is idempotent and costs one query per
             * table on a path that already rewrote the schema.
             */
            Events\DatabaseMigrated::class => [
                JobPipeline::make([
                    ReapplyGrantsAfterMigration::class,
                ])->send(function (Events\DatabaseMigrated $event) {
                    return $event->tenant;
                })->shouldBeQueued(false),
            ],

            Events\DatabaseSeeded::class => [],
            Events\DatabaseRolledBack::class => [],
            Events\DatabaseDeleted::class => [],

            // Tenancy events
            Events\InitializingTenancy::class => [],
            Events\TenancyInitialized::class => [
                Listeners\BootstrapTenancy::class,
            ],

            Events\EndingTenancy::class => [],
            Events\TenancyEnded::class => [
                Listeners\RevertToCentralContext::class,
            ],

            Events\BootstrappingTenancy::class => [],
            Events\TenancyBootstrapped::class => [],
            Events\RevertingToCentralContext::class => [],
            Events\RevertedToCentralContext::class => [],

            // Resource syncing
            Events\SyncedResourceSaved::class => [
                Listeners\UpdateSyncedResource::class,
            ],

            // Fired only when a synced resource is changed in a different DB than the origin DB (to avoid infinite loops)
            Events\SyncedResourceChangedInForeignDatabase::class => [],
        ];
    }

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->restrictEstateUserGrants();

        $this->bootEvents();
        $this->mapRoutes();

        $this->makeTenancyMiddlewareHighestPriority();
    }

    /**
     * Withhold UPDATE and DELETE from the database-level grant given to each
     * estate's MySQL user, so that append-only tables can actually be enforced.
     *
     * MySQL cannot revoke a table-level privilege from a database-level grant
     * (ERROR 1147), so the only way to leave the estate user unable to update
     * `journals` is to never grant it at database scope. ApplyAppendOnlyGrants
     * grants both back, per table, on every table that is not append-only.
     *
     * Removing this line silently re-grants UPDATE and DELETE on journals to
     * every estate, leaving only the trigger between a posted ledger and an
     * edit. See DECISIONS.md D-017.
     */
    protected function restrictEstateUserGrants(): void
    {
        PermissionControlledMySQLDatabaseManager::$grants = array_values(array_diff(
            PermissionControlledMySQLDatabaseManager::$grants,
            ApplyAppendOnlyGrants::MUTATING_PRIVILEGES,
        ));
    }

    protected function bootEvents(): void
    {
        foreach ($this->events() as $event => $listeners) {
            foreach ($listeners as $listener) {
                if ($listener instanceof JobPipeline) {
                    $listener = $listener->toListener();
                }

                Event::listen($event, $listener);
            }
        }
    }

    protected function mapRoutes(): void
    {
        $this->app->booted(function () {
            if (file_exists(base_path('routes/tenant.php'))) {
                Route::namespace(static::$controllerNamespace)
                    ->group(base_path('routes/tenant.php'));
            }
        });
    }

    protected function makeTenancyMiddlewareHighestPriority(): void
    {
        $tenancyMiddleware = [
            // Even higher priority than the initialization middleware
            Middleware\PreventAccessFromCentralDomains::class,

            Middleware\InitializeTenancyByDomain::class,
            Middleware\InitializeTenancyBySubdomain::class,
            Middleware\InitializeTenancyByDomainOrSubdomain::class,
            Middleware\InitializeTenancyByPath::class,
            Middleware\InitializeTenancyByRequestData::class,
        ];

        foreach (array_reverse($tenancyMiddleware) as $middleware) {
            $this->app[Kernel::class]->prependToMiddlewarePriority($middleware);
        }
    }
}
