<?php

declare(strict_types=1);

use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\PermissionControlledMySQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager;

return [
    'tenant_model' => Tenant::class,

    /*
     * No generator: the tenant id IS the subdomain and must be supplied
     * explicitly at provisioning. A generated UUID would make the database
     * name gs_estate_<uuid>, which nobody can read in phpMyAdmin and which
     * would need a second column kept in sync with the domain.
     */
    'id_generator' => null,

    'domain_model' => Domain::class,

    /**
     * Domains hosting the central Gemini Console.
     *
     * The estate domain must be listed here even though estates are tenants.
     * InitializeTenancyBySubdomain strips a known central domain off the host
     * to find the subdomain; with `geminisecure.test` absent it cannot tell
     * that `oceanview` is a subdomain at all and throws NotASubdomainException,
     * surfacing as a 500 rather than the intended 404.
     */
    'central_domains' => array_values(array_unique([
        '127.0.0.1',
        'localhost',

        /*
         * The estate domain belongs here even though estates are tenants:
         * InitializeTenancyBySubdomain strips a known central domain off the
         * host to find the subdomain, and without it `phoenixpark` is not
         * recognised as a subdomain at all.
         *
         * array_unique because in local development the estate domain may BE
         * localhost, and a duplicate entry makes the resolver's diagnostics
         * confusing to read.
         */
        env('ESTATE_DOMAIN', 'geminisecure.test'),
    ])),

    /**
     * Tenancy bootstrappers are executed when tenancy is initialized.
     * Their responsibility is making Laravel features tenant-aware.
     *
     * To configure their behavior, see the config keys below.
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        QueueTenancyBootstrapper::class,
        // Stancl\Tenancy\Bootstrappers\RedisTenancyBootstrapper::class, // Note: phpredis is needed
    ],

    /**
     * Database tenancy config. Used by DatabaseTenancyBootstrapper.
     */
    'database' => [
        /**
         * gs_platform. Holds tenants, users, roles, permissions, subscriptions,
         * audit_log, statutory rates, and all of Gemini Security's own
         * operations. gs_app holds grants on THIS database only.
         */
        'central_connection' => env('DB_CONNECTION', 'mysql'),

        /**
         * Template for the dynamically created tenant connection — and, more
         * importantly, the connection the database manager issues DDL on.
         *
         * It must be `mysql_owner`, not `mysql`: provisioning an estate runs
         * CREATE DATABASE, CREATE USER and GRANT, none of which gs_app holds
         * and none of which it should ever hold.
         *
         * This does NOT leak owner credentials into tenant requests. stancl
         * overlays the per-estate username and password from the tenant record
         * over this template (DatabaseConfig::tenantConfig), so a tenant
         * connection authenticates as that estate's own restricted user.
         */
        'template_tenant_connection' => 'mysql_owner',

        /**
         * Estate databases are named gs_estate_<subdomain>.
         */
        'prefix' => env('TENANCY_DATABASE_PREFIX', 'gs_estate_'),
        'suffix' => '',

        /**
         * TenantDatabaseManagers handle creation & deletion of tenant databases.
         *
         * PermissionControlledMySQLDatabaseManager is deliberate, not the
         * default MySQLDatabaseManager. It provisions a DEDICATED MySQL user
         * per estate database, rather than granting gs_app access to each new
         * estate as it is created.
         *
         * That distinction is what makes the Phase 1 gate passable. If gs_app
         * accumulated a grant on every estate, then a query issued from the
         * Phoenix Park context against gs_estate_oceanview would SUCCEED --
         * the connection is authorised, only the application context differs.
         * With a per-estate user, that same query fails on a GRANT error,
         * which is exactly what override section 7 step 4 requires: a leak
         * between two communities must be a connection error, not a query
         * that quietly returns the wrong rows.
         *
         * Consequence: gs_app must never appear in SHOW GRANTS against any
         * gs_estate_* database, and never hold a wildcard gs_estate_%.* grant.
         */
        'managers' => [
            'sqlite' => SQLiteDatabaseManager::class,
            'mysql' => PermissionControlledMySQLDatabaseManager::class,
            'mariadb' => PermissionControlledMySQLDatabaseManager::class,
            'pgsql' => PostgreSQLDatabaseManager::class,
        ],
    ],

    /**
     * Cache tenancy config. Used by CacheTenancyBootstrapper.
     *
     * This works for all Cache facade calls, cache() helper
     * calls and direct calls to injected cache stores.
     *
     * Each key in cache will have a tag applied on it. This tag is used to
     * scope the cache both when writing to it and when reading from it.
     *
     * You can clear cache selectively by specifying the tag.
     */
    'cache' => [
        'tag_base' => 'tenant', // This tag_base, followed by the tenant_id, will form a tag that will be applied on each cache call.
    ],

    /**
     * Filesystem tenancy config. Used by FilesystemTenancyBootstrapper.
     * https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper.
     */
    'filesystem' => [
        /**
         * Each disk listed in the 'disks' array will be suffixed by the suffix_base, followed by the tenant_id.
         */
        'suffix_base' => 'tenant',
        'disks' => [
            'local',
            'public',
            // 's3',
        ],

        /**
         * Use this for local disks.
         *
         * See https://tenancyforlaravel.com/docs/v3/tenancy-bootstrappers/#filesystem-tenancy-boostrapper
         */
        'root_override' => [
            // Disks whose roots should be overridden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],

        /**
         * Should storage_path() be suffixed.
         *
         * Note: Disabling this will likely break local disk tenancy. Only disable this if you're using an external file storage service like S3.
         *
         * For the vast majority of applications, this feature should be enabled. But in some
         * edge cases, it can cause issues (like using Passport with Vapor - see #196), so
         * you may want to disable this if you are experiencing these edge case issues.
         */
        'suffix_storage_path' => true,

        /**
         * OFF, deliberately. This is a change from the package default.
         *
         * When this is on, an initialized tenant rebinds the asset() helper so
         * every asset() URL is rewritten to /tenancy/assets/..., which serves
         * files from that tenant's own storage disk.
         *
         * Laravel's Vite class builds its script and stylesheet URLs through
         * asset(). So with this on, the Estate Console asked for
         *
         *     /tenancy/assets/build/assets/app-<hash>.js
         *
         * from the estate's storage disk, where the compiled bundle does not
         * exist and never will. Both stylesheets and the entire Vue app 404'd,
         * and the console rendered as a blank page — on the subdomain in
         * production exactly as much as on the path locally. The Gemini Console
         * was unaffected only because tenancy is never initialized there, which
         * made it look like an estate-routing fault rather than an asset one.
         *
         * The compiled bundle is the product, not tenant data: byte-identical
         * for every estate, already public, and containing nothing an estate
         * owns. Serving it per-tenant buys no isolation.
         *
         * Tenant-owned files — resident photos, uploaded documents — must call
         * tenant_asset() explicitly, which still resolves per-estate and is
         * unaffected by this setting. That is the safer default anyway: a
         * tenant URL now has to be asked for by name rather than being applied
         * to every asset() call in the framework and in every package.
         */
        'asset_helper_tenancy' => false,
    ],

    /**
     * Redis tenancy config. Used by RedisTenancyBootstrapper.
     *
     * Note: You need phpredis to use Redis tenancy.
     *
     * Note: You don't need to use this if you're using Redis only for cache.
     * Redis tenancy is only relevant if you're making direct Redis calls,
     * either using the Redis facade or by injecting it as a dependency.
     */
    'redis' => [
        'prefix_base' => 'tenant', // Each key in Redis will be prepended by this prefix_base, followed by the tenant id.
        'prefixed_connections' => [ // Redis connections whose keys are prefixed, to separate one tenant's keys from another.
            // 'default',
        ],
    ],

    /**
     * Features are classes that provide additional functionality
     * not needed for tenancy to be bootstrapped. They are run
     * regardless of whether tenancy has been initialized.
     *
     * See the documentation page for each class to
     * understand which ones you want to enable.
     */
    'features' => [
        // Stancl\Tenancy\Features\UserImpersonation::class,
        // Stancl\Tenancy\Features\TelescopeTags::class,
        // Stancl\Tenancy\Features\UniversalRoutes::class,
        // Stancl\Tenancy\Features\TenantConfig::class, // https://tenancyforlaravel.com/docs/v3/features/tenant-config
        // Stancl\Tenancy\Features\CrossDomainRedirect::class, // https://tenancyforlaravel.com/docs/v3/features/cross-domain-redirect
        // Stancl\Tenancy\Features\ViteBundler::class,
    ],

    /**
     * Should tenancy routes be registered.
     *
     * Tenancy routes include tenant asset routes. By default, this route is
     * enabled. But it may be useful to disable them if you use external
     * storage (e.g. S3 / Dropbox) or have a custom asset controller.
     */
    'routes' => true,

    /**
     * Parameters used by the tenants:migrate command.
     */
    'migration_parameters' => [
        '--force' => true, // This needs to be true to run migrations in production.
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    /**
     * Parameters used by the tenants:seed command.
     */
    'seeder_parameters' => [
        '--class' => 'DatabaseSeeder', // root seeder class
        // '--force' => true, // This needs to be true to seed tenant databases in production
    ],
];
