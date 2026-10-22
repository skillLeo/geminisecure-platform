<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 *   php artisan mobile:restore-seeded --dry-run
 *   php artisan mobile:restore-seeded
 *
 * Puts back the two demo records the mobile build's own checks cancelled by
 * mistake on the DEV database (M4 §4, defect 4):
 *
 *   VISITOR PASS #1   the test resident Andrea Fletcher's household. A write probe
 *                     sent `POST /visitor-passes/1/cancel` expecting "not yours";
 *                     it WAS hers, and the server correctly cancelled it.
 *   BOOKING #1        the seeded Gazebo booking of the same household
 *                     (`FacilitiesSeeder::BOOKINGS`, first row: confirmed, deposit
 *                     held). A live check of the Resident App cancelled it.
 *
 * WHAT "BACK" MEANS. Each record returns to what it was the moment before the
 * cancel: the status it had (`active`; `confirmed`), and the one timestamp the
 * cancel set (`cancelled_at`) back to NULL. Nothing else on either row was
 * touched by the cancel, so nothing else is touched here — a pass's token, code
 * and validity, a booking's terms, approval and deposit are read and reported,
 * never rewritten.
 *
 * THE SLOT. A cancelled booking stops holding the diary, so somebody else may
 * have taken the Gazebo for those hours since. The booking is put back ONLY if
 * the period is still free — no other pending or confirmed booking and no
 * blocked period over it — because two households told the same Sunday is
 * theirs is the one failure the diary exists to prevent.
 *
 * NO LEDGER ENTRY IS WRITTEN OR UNDONE. When booking #1 was cancelled a cancel
 * moved no money, so its deposit is still held and its one journal stands. If a
 * later release has already returned that deposit, the entry is history and
 * stays: the command says so and leaves the deposit as the ledger has it.
 *
 * HISTORY IS HISTORY. Neither cancel wrote an audit or gate-event row (a pass
 * cancel and a booking cancel are status changes on the household's own row),
 * and the idempotency record of the original request is left exactly as it is.
 *
 * IDEMPOTENT: a record already as its seeder made it is reported and left.
 * LOCAL ONLY, for the same reason `mobile:test-accounts` is.
 */
class MobileRestoreSeeded extends Command
{
    public const PASS_ID = 1;

    public const BOOKING_ID = 1;

    public const BOOKING_AMENITY = 'Gazebo';

    protected $signature = 'mobile:restore-seeded
        {--dry-run : report what would change, and change nothing}';

    protected $description = 'Restore the two seeded demo records the mobile build\'s checks cancelled by mistake (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refused: demo records are restored on a developer\'s machine only (APP_ENV is '.app()->environment().').');

            return self::FAILURE;
        }

        $estate = Tenant::query()->find(MobileTestAccounts::ESTATE);

        if (! $estate instanceof Tenant) {
            $this->error('The estate ['.MobileTestAccounts::ESTATE.'] does not exist. Provision and seed the platform first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        /** @var list<array{0: string, 1: string, 2: string}> $rows */
        $rows = $estate->run(function () use ($dryRun): array {
            $resident = Resident::query()->with('household')->where('email', MobileTestAccounts::RESIDENT_EMAIL)->first();
            $unitId = $resident?->household?->unit_id;

            if ($unitId === null) {
                return [['Both', 'refused', 'Andrea Fletcher is not on the register (run the seeders), so neither record can be checked as hers.']];
            }

            return [
                $this->restorePass((int) $unitId, $dryRun),
                $this->restoreBooking((int) $unitId, $dryRun),
            ];
        });

        $this->table(['Record', $dryRun ? 'Would be' : 'Result', 'Details'], $rows);

        $refused = collect($rows)->contains(static fn (array $row): bool => $row[1] === 'refused');

        return $refused ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function restorePass(int $unitId, bool $dryRun): array
    {
        $label = 'Visitor pass #'.self::PASS_ID;
        $pass = VisitorPass::query()->find(self::PASS_ID);

        if (! $pass instanceof VisitorPass) {
            return [$label, 'refused', 'No such pass on this estate.'];
        }

        if ((int) $pass->unit_id !== $unitId) {
            return [$label, 'refused', 'It belongs to another household, so it is not the record the probe cancelled. Left alone.'];
        }

        $what = sprintf('%s · %s · valid %s to %s', $pass->visitor_name, $pass->code, $pass->valid_from->toIso8601String(), $pass->valid_to->toIso8601String());

        if ($pass->status === VisitorPass::ACTIVE && $pass->cancelled_at === null) {
            return [$label, 'unchanged', 'Already active, as issued. '.$what];
        }

        if ($pass->status !== VisitorPass::CANCELLED) {
            return [$label, 'refused', 'It is '.$pass->status.', not cancelled — a used pass is a real admission and is not rewritten. '.$what];
        }

        $was = 'cancelled at '.($pass->cancelled_at?->toIso8601String() ?? 'an unrecorded time');

        if (! $dryRun) {
            $pass->forceFill(['status' => VisitorPass::ACTIVE, 'cancelled_at' => null])->save();
        }

        return [$label, 'restored', 'status cancelled -> active, cancelled_at -> null (was '.$was.'). '.$what];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function restoreBooking(int $unitId, bool $dryRun): array
    {
        $label = 'Booking #'.self::BOOKING_ID;
        $booking = AmenityBooking::query()->with('amenity')->find(self::BOOKING_ID);

        if (! $booking instanceof AmenityBooking) {
            return [$label, 'refused', 'No such booking on this estate.'];
        }

        if ((int) $booking->unit_id !== $unitId || $booking->amenity->name !== self::BOOKING_AMENITY) {
            return [$label, 'refused', 'It is not the seeded '.self::BOOKING_AMENITY.' booking of Andrea Fletcher\'s household. Left alone.'];
        }

        $what = sprintf(
            '%s · %s · %s %s–%s · deposit %s',
            $booking->reference,
            $booking->amenity->name,
            $booking->starts_at->format('Y-m-d'),
            $booking->starts_at->format('H:i'),
            $booking->ends_at->format('H:i'),
            $booking->deposit_state,
        );

        if ($booking->status === AmenityBooking::CONFIRMED && $booking->cancelled_at === null) {
            return [$label, 'unchanged', 'Already confirmed, as seeded. '.$what];
        }

        if ($booking->status !== AmenityBooking::CANCELLED) {
            return [$label, 'refused', 'It is '.$booking->status.', not cancelled, so there is no mistaken cancel to undo. '.$what];
        }

        // The side effect of the cancel: the period went back to the diary. It
        // comes back to this booking only if nobody has taken it since.
        $taken = AmenityBooking::query()
            ->where('amenity_id', $booking->amenity_id)
            ->whereKeyNot($booking->id)
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '<', $booking->ends_at)
            ->where('ends_at', '>', $booking->starts_at)
            ->first();

        if ($taken instanceof AmenityBooking) {
            return [$label, 'refused', 'The period has been booked since ('.$taken->reference.'). Restoring would tell two households the same hours are theirs. '.$what];
        }

        $blocked = AmenitySlot::query()
            ->where('amenity_id', $booking->amenity_id)
            ->where('starts_at', '<', $booking->ends_at)
            ->where('ends_at', '>', $booking->starts_at)
            ->exists();

        if ($blocked) {
            return [$label, 'refused', 'The estate has closed that period since. '.$what];
        }

        $was = 'cancelled at '.($booking->cancelled_at?->toIso8601String() ?? 'an unrecorded time');

        if (! $dryRun) {
            $booking->forceFill(['status' => AmenityBooking::CONFIRMED, 'cancelled_at' => null])->save();
        }

        $deposit = $booking->deposit_state === AmenityBooking::DEPOSIT_HELD
            ? 'The deposit is still held, as seeded: the cancel moved no money and no ledger entry is written or undone.'
            : 'The deposit is '.$booking->deposit_state.' in the ledger; that entry is history and is left as it is.';

        return [$label, 'restored', 'status cancelled -> confirmed, cancelled_at -> null (was '.$was.'); the period holds the diary again. '.$deposit.' '.$what];
    }
}
