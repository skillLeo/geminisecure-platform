<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Full demonstration dataset, in dependency order.
 *
 * Order is not cosmetic:
 *   RbacMatrix  first - roles must exist before any user can be assigned one
 *   DemoData    next  - creates users and per-estate records
 *   GuardWorkforce    - guards need estates to be posted at
 *   Dispatch / orders / operations - all three hang off guards and posts
 *   Billing / Payroll - both need guards and estates
 *   AuditLog    last  - references the director created above
 *
 * Estates themselves are NOT seeded here. Provisioning creates a database and
 * a MySQL user per estate, which is a deliberate operation rather than
 * something a seeder should do silently:
 *
 *     php artisan estate:provision phoenixpark "Phoenix Park" --status=active
 *     php artisan estate:provision oceanview  "Ocean View"   --status=active
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RbacMatrixSeeder::class,
            DemoDataSeeder::class,
            GuardWorkforceSeeder::class,
            // Rosters, patrol tours and the requests inbox. After the
            // workforce: every row here hangs off a guard and a post.
            DispatchOperationsSeeder::class,
            // The standing orders library. After the workforce for the posts,
            // and after the roster because an acknowledgement is only written
            // for a guard who could actually have given it.
            StandingOrdersSeeder::class,
            // The day at the gates and the incident log. Last of the
            // operational four: the activity feed merges what the three above
            // wrote, so seeding it earlier would report a quieter day than
            // the platform ends up holding.
            SecurityOperationsSeeder::class,
            // The four numbers a panic at Phoenix Park Village 1 is texted to (Q-M09).
            // After the estates exist and before anything commercial: it touches one
            // small central table of its own and nothing else depends on it.
            SiteEmergencyNumbersSeeder::class,
            BillingSeeder::class,
            // The commercial catalogue: platform rates, package features and
            // per-client overrides. After Billing, because plan_features hangs
            // off the plans that seeder creates.
            PlatformCatalogueSeeder::class,
            PayrollSeeder::class,
            AuditLogSeeder::class,
        ]);
    }
}
