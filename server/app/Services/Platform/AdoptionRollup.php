<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\ClientAdoption;
use App\Models\Guard;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the adoption rows GEMINI owns.
 *
 * Gemini operates two things inside a client's estate: the Guard App its own
 * officers work from, and the visitor pass system those officers admit against.
 * Both leave their evidence in gs_platform — shifts, devices, gate decisions —
 * so both can be counted here without opening a single estate database.
 *
 * IT WRITES ONLY WHAT IT OWNS. Dues, facilities, governance and the estate's
 * own payroll are the estate's to count, and their rows arrive from the estate
 * console. This class never touches them, and a capability with no row is left
 * with no row rather than being written as zero — see the table's migration for
 * why those are different facts.
 *
 * COUNTS, NOT PERCENTAGES. "2 of 4 posts" survives a client adding a fifth
 * post; "50%" does not.
 */
class AdoptionRollup
{
    /** How recently a shift must have started for a post to count as worked. */
    private const SHIFT_WINDOW_DAYS = 7;

    /** The window the pass take-up is measured over. */
    private const ADMISSION_WINDOW_DAYS = 30;

    /**
     * A visitor admitted on something the platform issued or pre-approved,
     * rather than on a guard's own judgement at the gate. Matched on the basis
     * the guard recorded, because that is the field that says what they acted
     * on.
     *
     * @var list<string>
     */
    private const PASS_BASES = ['qr pass', 'pre-approved', 'tag read'];

    /** Recompute every Gemini-owned row for every estate. */
    public function refresh(): int
    {
        $written = 0;

        /*
         * Not the demonstration estate (M5 §3). Adoption answers "is this client
         * using what they bought", and the demonstration estate bought nothing:
         * its numbers are whatever `demo:seed` last wrote, so a row for it on
         * the Client Health report would be a measurement of a fixture.
         *
         * `refreshEstate()` is still public and still takes any estate, so
         * `report:estate-adoption --estate=demoestate` can be pointed at it on
         * purpose to check the seed took.
         */
        foreach (OperatorEstates::estates() as $estate) {
            $written += $this->refreshEstate($estate);
        }

        return $written;
    }

    /** Recompute the Gemini-owned rows for one estate. */
    public function refreshEstate(Tenant $estate): int
    {
        $rows = [
            $this->guardApp($estate->getTenantKey()),
            $this->visitorPasses($estate->getTenantKey()),
        ];

        foreach ($rows as $row) {
            ClientAdoption::updateOrCreate(
                ['tenant_id' => $estate->getTenantKey(), 'module_key' => $row['module_key']],
                [
                    'label' => $row['label'],
                    'adopted' => $row['adopted'],
                    'eligible' => $row['eligible'],
                    'detail' => $row['detail'],
                    'reported_by' => ClientAdoption::BY_GEMINI,
                    'measured_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    /**
     * How much of the estate's guarding is actually running on the Guard App.
     *
     * ELIGIBLE IS POSTS, NOT GUARDS. A client buys coverage of their gates; the
     * question is how many of those gates are being worked by a guard who is
     * licensed, carrying a bound handset and clocking on. A post standing empty
     * counts against the client's coverage, which is exactly what an account
     * manager needs to see before a renewal conversation.
     *
     * @return array<string, mixed>
     */
    private function guardApp(string $tenantId): array
    {
        $eligible = (int) DB::connection('mysql')
            ->table('posts')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $adopted = (int) DB::connection('mysql')
            ->table('shifts')
            ->join('guards', 'guards.id', '=', 'shifts.guard_id')
            ->where('shifts.tenant_id', $tenantId)
            ->whereNotNull('shifts.actual_start')
            ->where('shifts.actual_start', '>=', now()->subDays(self::SHIFT_WINDOW_DAYS))

            // A bound handset IS the control: the Guard App cannot be said to be
            // in use at a post nobody is signing on to it from.
            ->whereNotNull('guards.device_id')

            // And an unlicensed guard is not coverage, whatever the app says — licensed
            // through the expiry date on the officer's own site calendar (B008).
            ->where(function (QueryBuilder $licence): void {
                $licence->whereNull('guards.psra_expires_on')
                    ->orWhere(static fn (QueryBuilder $good) => Guard::applyLicenceDaysLeft($good, '>=', 0));
            })
            ->distinct()
            ->count('shifts.post_id');

        return [
            'module_key' => 'guard_app',
            'label' => 'Guard App coverage',
            'adopted' => $adopted,
            'eligible' => $eligible,
            'detail' => sprintf(
                '%d of %d active posts worked in the last %d days by a licensed guard on a bound handset.',
                $adopted,
                $eligible,
                self::SHIFT_WINDOW_DAYS,
            ),
        ];
    }

    /**
     * How many visitors arrive with a pass rather than an argument at the gate.
     *
     * The single clearest signal that residents have taken the platform up: a
     * pass only exists because a resident issued one. A gate running on the
     * guard's judgement is a gate the client bought a pass system for and is
     * not using.
     *
     * @return array<string, mixed>
     */
    private function visitorPasses(string $tenantId): array
    {
        $since = now()->subDays(self::ADMISSION_WINDOW_DAYS);

        $admissions = DB::connection('mysql')
            ->table('gate_events')
            ->where('tenant_id', $tenantId)
            ->whereIn('verdict', ['admit', 'override'])
            ->where('occurred_at', '>=', $since);

        $eligible = (int) (clone $admissions)->count();

        $adopted = (int) (clone $admissions)
            ->where(function ($basis): void {
                foreach (self::PASS_BASES as $pattern) {
                    $basis->orWhereRaw('LOWER(basis) LIKE ?', ['%'.$pattern.'%']);
                }
            })
            ->count();

        return [
            'module_key' => 'visitor_passes',
            'label' => 'Visitor passes',
            'adopted' => $adopted,
            'eligible' => $eligible,
            'detail' => sprintf(
                '%d of %d admissions in the last %d days were on a pass rather than a guard decision.',
                $adopted,
                $eligible,
                self::ADMISSION_WINDOW_DAYS,
            ),
        ];
    }
}
