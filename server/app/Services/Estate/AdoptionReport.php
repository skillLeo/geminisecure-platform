<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\ClientAdoption;
use App\Models\Estate\Amenity;
use App\Models\Estate\Ballot;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Notice;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\Unit;
use App\Support\SiteTime;
use Illuminate\Support\Facades\DB;

/**
 * What this estate reports about its own take-up — the other half of board 07.
 *
 * `AdoptionRollup` counts the two capabilities GEMINI operates and says in its
 * own docblock that it will never touch the rest: "Dues, facilities, governance
 * and the estate's own payroll are the estate's to count, and their rows arrive
 * from the estate console." This is the class that sends them.
 *
 * WHY IT HAS TO BE THIS WAY ROUND. A cross-client report that opened each
 * estate's database in turn to build itself would be a tenant-isolation breach
 * wearing a report's clothes — the hardest rule this platform has, and one a
 * plausible-looking dashboard is exactly how you break. So the fact's OWNER
 * counts it, inside its own tenancy, and pushes two integers up. `gs_platform`
 * ends up holding "212 of 450" and never learns whose 212.
 *
 * TWO INTEGERS AND A SENTENCE. No names, no money, no household, no unit. The
 * central table has no column that could carry one and this class assembles
 * none. That is what makes a cross-tenant report safe to draw for a Director who
 * may not open a single estate console.
 *
 * NOTHING TO MEASURE IS NOT NOUGHT PER CENT, and the eligible count is what
 * carries that. An estate with no ballot has not failed at governance; it has
 * not held an election. `ClientAdoption::isMeasurable()` reads `eligible > 0`,
 * so a capability with nothing behind it is reported honestly as nothing rather
 * than as a zero that would put a blameless client at the bottom of a health
 * list.
 *
 * COUNTS, NOT PERCENTAGES — the same rule the Gemini side follows. "212 of 450"
 * survives the estate adding a unit; "47%" does not.
 */
class AdoptionReport
{
    /** The window most of these are measured over. */
    private const WINDOW_DAYS = 30;

    /**
     * Count everything this estate owns and push it up.
     *
     * RUNS INSIDE THE ESTATE'S OWN TENANCY. Every read below is on the `tenant`
     * connection, and the single write is on the central one — which is the
     * whole shape of the thing: the estate reads itself and reports outward.
     *
     * @return int how many capabilities were reported
     */
    public function push(string $tenantId): int
    {
        $rows = [
            $this->duesLedger(),
            $this->facilities(),
            $this->governance(),
            $this->payroll(),
            $this->notices(),
        ];

        foreach ($rows as $row) {
            ClientAdoption::updateOrCreate(
                ['tenant_id' => $tenantId, 'module_key' => $row['module_key']],
                [
                    'label' => $row['label'],
                    'adopted' => $row['adopted'],
                    'eligible' => $row['eligible'],
                    'detail' => $row['detail'],
                    'reported_by' => ClientAdoption::BY_ESTATE,
                    'measured_at' => now(),
                ],
            );
        }

        return count($rows);
    }

    /**
     * How much of the estate is actually on the ledger.
     *
     * ELIGIBLE IS EVERY UNIT, INCLUDING THE EMPTY ONES, and the first version of
     * this counted only occupied ones on the reasoning that a lot nobody lives
     * in cannot be billed. The data said otherwise immediately: 450 of 433.
     * Maintenance dues are charged to the UNIT, not to whoever happens to live
     * in it — `charges.unit_id` is the sub-ledger key and the whole receivable
     * is keyed on it — so a vacant lot owes its share and the estate bills it.
     * Counting households as the denominator put the numerator and the
     * denominator on two different populations, which is the shape that produces
     * a percentage over a hundred.
     *
     * @return array<string, mixed>
     */
    private function duesLedger(): array
    {
        $eligible = (int) DB::connection('tenant')->table('units')->count();

        /*
         * Charged in the last two months rather than ever. An estate that billed
         * once in March and stopped is not using the ledger, and a lifetime
         * count would report it at 100% forever.
         */
        $adopted = (int) DB::connection('tenant')
            ->table('charges')
            ->where('due_on', '>=', SiteTime::date()->subMonths(2))
            ->where('status', '!=', 'reversed')
            ->distinct()
            ->count('unit_id');

        return [
            'module_key' => 'dues_ledger',
            'label' => 'Dues & ledger',
            'adopted' => $adopted,
            'eligible' => $eligible,
            'detail' => sprintf(
                '%d of %d units were billed in the last two months.',
                $adopted,
                $eligible,
            ),
        ];
    }

    /**
     * Whether the estate's own facilities are being run through the platform.
     *
     * TWO THINGS ARE COUNTED AS ONE CAPABILITY, and board 07 draws one bar:
     * amenities that get booked, and maintenance tickets that get closed. An
     * estate doing neither has a facilities module it is not using; an estate
     * doing either is using it. The denominator is what it HAS — bookable
     * amenities plus tickets raised — so an estate with no amenities and no
     * faults is not measured rather than scored zero.
     *
     * @return array<string, mixed>
     */
    private function facilities(): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $bookable = (int) Amenity::query()->where('is_bookable', true)->count();

        $booked = (int) DB::connection('tenant')
            ->table('amenity_bookings')
            ->where('created_at', '>=', $since)
            ->distinct()
            ->count('amenity_id');

        $tickets = (int) MaintenanceTicket::query()->where('reported_at', '>=', $since)->count();

        $handled = (int) MaintenanceTicket::query()
            ->where('reported_at', '>=', $since)
            ->whereNotNull('assigned_at')
            ->count();

        return [
            'module_key' => 'facilities',
            'label' => 'Facilities',
            'adopted' => $booked + $handled,
            'eligible' => $bookable + $tickets,
            'detail' => sprintf(
                '%d of %d bookable amenities used and %d of %d tickets assigned in the last %d days.',
                $booked,
                $bookable,
                $handled,
                $tickets,
                self::WINDOW_DAYS,
            ),
        ];
    }

    /**
     * Turnout at the most recent election, which is the governance signal.
     *
     * AN AGGREGATE AND NOTHING FINER. This counts RECEIPTS — how many households
     * cast a paper — and never touches `ballot_marks`. There is no query here
     * that could say how anybody voted, and the two tables share no column that
     * would let one be written: see the governance migration. A cross-client
     * report is the last place a ballot should become linkable to a voter, and
     * the safest way to guarantee it is to count the one table that holds no
     * choices.
     *
     * The denominator is the ballot's OWN snapshotted electorate rather than
     * today's household count, because a turnout figure recorded against an
     * election must not move when a unit is sold.
     *
     * @return array<string, mixed>
     */
    private function governance(): array
    {
        $ballot = Ballot::query()
            ->whereNotNull('opens_at')
            ->orderByDesc('opens_at')
            ->first();

        if ($ballot === null) {
            // No election has ever opened. Not a failure — nothing to measure.
            return [
                'module_key' => 'governance',
                'label' => 'Governance',
                'adopted' => 0,
                'eligible' => 0,
                'detail' => 'No election has opened yet, so there is no turnout to report.',
            ];
        }

        $cast = (int) DB::connection('tenant')
            ->table('ballot_receipts')
            ->where('ballot_id', $ballot->id)
            ->count();

        $eligible = (int) $ballot->eligible_households;

        return [
            'module_key' => 'governance',
            'label' => 'Governance',
            'adopted' => $cast,
            'eligible' => $eligible,
            'detail' => sprintf(
                '%d of %d eligible households voted in the %d election.',
                $cast,
                $eligible,
                $ballot->year,
            ),
        ];
    }

    /**
     * Whether the estate's own staff are actually being paid through this.
     *
     * ELIGIBLE IS THE MONTHS THAT HAVE HAPPENED, adopted the runs that were
     * paid. A payroll module with four employees on it and no completed run is
     * a module the client is paying for and not using, and counting employees
     * instead of runs would report it at 100%.
     *
     * @return array<string, mixed>
     */
    private function payroll(): array
    {
        $months = 3;
        $since = SiteTime::date()->startOfMonth()->subMonths($months - 1);

        $eligible = (int) PayrollRun::query()->where('period_start', '>=', $since)->count();

        $adopted = (int) PayrollRun::query()
            ->where('period_start', '>=', $since)
            ->where('status', PayrollRun::PAID)
            ->count();

        return [
            'module_key' => 'payroll',
            'label' => 'Payroll & accounting',
            'adopted' => $adopted,
            'eligible' => $eligible,
            'detail' => sprintf(
                '%d of %d pay runs in the last %d months reached payment.',
                $adopted,
                $eligible,
                $months,
            ),
        ];
    }

    /**
     * How much of the estate reads what it is told.
     *
     * THE MOST RECENT NOTICE ONLY, not a lifetime average. A notice posted this
     * morning that half the estate has read is the live signal; averaging it
     * with one from March would hide both. It is the same figure board 32 draws
     * as a bar, counted the same way — receipts over the roll — so the two
     * screens cannot disagree.
     *
     * @return array<string, mixed>
     */
    private function notices(): array
    {
        $notice = Notice::query()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->first();

        $roll = (int) DB::connection('tenant')->table('residents')->count();

        if ($notice === null || $roll === 0) {
            return [
                'module_key' => 'notices',
                'label' => 'Notices',
                'adopted' => 0,
                'eligible' => 0,
                'detail' => 'Nothing has been announced yet, so there is no readership to report.',
            ];
        }

        $read = (int) DB::connection('tenant')
            ->table('notice_reads')
            ->where('notice_id', $notice->id)
            ->count();

        return [
            'module_key' => 'notices',
            'label' => 'Notices',
            'adopted' => $read,
            'eligible' => $roll,
            'detail' => sprintf(
                '%d of %d residents have read the most recent notice.',
                $read,
                $roll,
            ),
        ];
    }

    /**
     * Every unit on the estate, for callers that need the denominator.
     *
     * Kept here rather than inlined so the one place that decides what "the
     * estate" means for adoption is this class.
     */
    public function unitCount(): int
    {
        return (int) Unit::lots()->count();
    }
}
