<?php

declare(strict_types=1);

namespace App\Jobs\Tenancy;

/**
 * The same grants, re-applied after every tenant migration.
 *
 * WHY THIS RUNS AT ALL. UPDATE and DELETE are withheld at database level and
 * granted back per table (D-017), so a migration that adds a table leaves it
 * with no grant for the estate's own user. The symptom is subtle and late —
 * reads work, inserts work, and only an update fails, possibly weeks after the
 * migration that caused it. It happened twice in one day on this project, on
 * the payroll tables and then on the notices, and both times the fix was to
 * remember to run `grants:estates` by hand.
 *
 * `ApplyEstateGrants`' own docblock already said it "must run after every
 * tenants:migrate". An instruction in a comment is not a guarantee; this is.
 *
 * WHY IT IS A SUBCLASS RATHER THAN THE SAME JOB. The parent stops the world
 * when an estate has no dedicated MySQL user, and at provisioning that is
 * exactly right: an estate without one has no isolation boundary. After a
 * migration the same absence means something ordinary — the tenant was created
 * outside the provisioning path, by a test fixture, a restore or an import —
 * and there is no user to top up. Nothing is wrong; there is simply nothing to
 * do, and failing would break every such path to re-state a rule that
 * provisioning already enforces.
 */
class ReapplyGrantsAfterMigration extends ApplyAppendOnlyGrants
{
    protected function requiresDedicatedUser(): bool
    {
        return false;
    }
}
