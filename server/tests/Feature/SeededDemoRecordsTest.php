<?php

declare(strict_types=1);

use App\Console\Commands\MobileRestoreSeeded;
use App\Models\Estate\AmenityBooking;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The two demo records the mobile build's checks cancelled — M4 §4, defect 4
|--------------------------------------------------------------------------
|
| A write probe cancelled visitor pass #1, and a live check of the Resident App
| cancelled the seeded Gazebo booking #1. `php artisan mobile:restore-seeded`
| puts both back on a developer's machine (they are dev-database rows, not test
| fixtures, so the restore itself cannot be run here — it is pinned to the
| `phoenixpark` estate and to those two row ids).
|
| WHAT CAN BE HELD TO A TEST, AND IS, IS THE INTENDED STATE. Re-running
| `FacilitiesSeeder` deliberately does NOT un-cancel a booking — it repairs a
| deposit and leaves the status alone — so the seeder's first row is the only
| written-down record of what "back" means, and the command reads it off this
| file's constants. If either drifts the restore silently starts restoring
| something else. This runs the REAL seeder, through the fixture, and pins:
|
|   · the record the command names is the one the seeder makes (amenity, lot),
|   · the state it restores TO is the state the seeder gives it,
|   · and the command refuses to touch anything off a developer's machine.
|
| It counts nothing and writes nothing, so it is safe wherever it runs.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('leaves the seeded Gazebo booking confirmed, uncancelled and holding its deposit — the state the restore puts it back to', function () {
    // The command names the record by amenity; the seeder's first row is the Gazebo at Lot 47.
    expect(MobileRestoreSeeded::BOOKING_AMENITY)->toBe('Gazebo');

    $booking = FacilitiesFixture::booking(MobileRestoreSeeded::BOOKING_AMENITY, 'Lot 47');

    expect($booking->status)->toBe(AmenityBooking::CONFIRMED)
        ->and($booking->cancelled_at)->toBeNull()
        ->and($booking->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($booking->deposit_minor)->toBeGreaterThan(0)

        /*
         * AND THE DEPOSIT IS ON THE BOOKS, not just on the row. The restore writes no
         * journal and undoes none — "the cancel moved no money, so the deposit is still held
         * and its one entry stands" — which is only true while the entry is there.
         */
        ->and($booking->deposit_journal_ref)->not->toBeNull();

    /*
     * IT IS THE FIRST BOOKING THE SEEDER WRITES, which is why the command can name it by id
     * on a freshly seeded estate. Asserted as "the lowest id", not "id 1", because this
     * fixture's database is shared with every other file in the run.
     */
    expect($booking->id)->toBe((int) AmenityBooking::query()->min('id'));
});

it('restores nothing anywhere but a developer\'s machine', function () {
    /*
     * The one guard that matters on this command: it rewrites a status and a timestamp with
     * no audit entry behind them, which is defensible on a dev database being put back the
     * way a seeder left it and nowhere else.
     */
    app()->detectEnvironment(static fn (): string => 'production');

    try {
        $this->artisan('mobile:restore-seeded')
            ->expectsOutputToContain('Refused')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(static fn (): string => 'testing');
    }

    expect(app()->environment())->toBe('testing');
});
