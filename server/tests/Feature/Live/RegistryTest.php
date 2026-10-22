<?php

declare(strict_types=1);

use App\Live\Events\TileLive;
use App\Live\LiveEvent;
use App\Live\Registry;
use App\Live\Relay\Waker;
use App\Live\Testing\EmitterCoverage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Live\Fixtures\Clashing\ClashingExemptLive;
use Tests\Live\Fixtures\Clashing\ClashingHydratorLive;
use Tests\Live\Fixtures\Events\FixtureBookingLive;
use Tests\Live\Fixtures\Events\FixtureTicketLive;
use Tests\Live\Fixtures\FixtureHydrator;
use Tests\Live\Fixtures\Writers\FixtureWriter;
use Tests\Live\Support\LiveLog;
use Tests\Live\Support\SpyWaker;

/*
|--------------------------------------------------------------------------
| What each event class declares, found by discovery (DESIGN.md §2.1, §2.15, §12.2)
|--------------------------------------------------------------------------
|
| Eleven wiring units will each add event classes. If the tables they make live, the hydrators
| they add and the columns they declare silent were kept in one shared list, every one of them
| would edit that list and they could not be built side by side. So each class declares its own
| (`tracked()`, `hydrators()`, `exempt()`), and App\Live\Registry collects them by scanning
| app/Live/Events. EmitterCoverage reads the result: a committed write to a tracked table with no
| LiveBus::record() in the same transaction fails the test that made it.
|
| The fixtures stand where a wiring unit's classes and writers will: two event classes in
| tests/Live/Fixtures/Events, and a pretend application writer in tests/Live/Fixtures/Writers.
|
*/

beforeEach(function () {
    Registry::useDirectory(base_path('tests/Live/Fixtures/Events'), 'Tests\\Live\\Fixtures\\Events');
    EmitterCoverage::countWritesFrom(base_path('tests/Live/Fixtures/Writers'));

    LiveLog::reset();
    app()->instance(Waker::class, new SpyWaker);

    Schema::connection('mysql')->dropIfExists('live_fixture_tickets');
    Schema::connection('mysql')->create('live_fixture_tickets', function (Blueprint $table) {
        $table->id();
        $table->string('status', 16);
        $table->dateTime('viewed_at')->nullable();
    });

    Schema::connection('mysql')->dropIfExists('live_fixture_bookings');
    Schema::connection('mysql')->create('live_fixture_bookings', function (Blueprint $table) {
        $table->id();
        $table->string('status', 16);
        $table->string('note', 64)->nullable();
    });
});

afterEach(function () {
    EmitterCoverage::forget();
    Registry::reset();

    Schema::connection('mysql')->dropIfExists('live_fixture_tickets');
    Schema::connection('mysql')->dropIfExists('live_fixture_bookings');
    DB::purge('live_second');
});

it('collects tracked tables, hydrators and exemptions from two fixture event classes', function () {
    // NotAnEvent sits in the same directory and is not a LiveEvent: it contributes nothing.
    expect(Registry::events())->toBe([FixtureBookingLive::class, FixtureTicketLive::class])
        ->and(Registry::tracked())->toBe(['live_fixture_bookings.status', 'live_fixture_tickets'])
        ->and(Registry::hydrators())->toBe([
            'fixture.booking' => FixtureHydrator::class,
            'fixture.ticket' => FixtureHydrator::class,
        ])
        ->and(Registry::exempt())->toBe([
            'live_fixture_tickets.viewed_at' => 'a read receipt: no other screen shows it',
        ]);
});

it('reads the application\'s own events directory when nothing else is asked for', function () {
    Registry::reset();

    expect(Registry::events())->toContain(TileLive::class);

    foreach (Registry::events() as $class) {
        expect(is_subclass_of($class, LiveEvent::class))->toBeTrue()
            ->and(str_starts_with($class, 'App\\Live\\Events\\'))->toBeTrue();
    }

    // TileLive is emitted beside a record's own event, so it tracks no table itself.
    expect(Registry::collect([TileLive::class]))->toBe(['tracked' => [], 'hydrators' => [], 'exempt' => []]);
});

it('refuses two classes that claim one hydrator key for different hydrators', function () {
    expect(fn () => Registry::collect([FixtureTicketLive::class, ClashingHydratorLive::class]))
        ->toThrow(LogicException::class, 'fixture.ticket');
});

it('refuses a table that one class tracks and another declares silent', function () {
    expect(fn () => Registry::collect([FixtureTicketLive::class, ClashingExemptLive::class]))
        ->toThrow(LogicException::class, 'live_fixture_tickets');
});

it('makes EmitterCoverage fail a synthetic tracked write that has no record(), and pass once it has one', function () {
    FixtureWriter::openWithoutRecord();

    expect(EmitterCoverage::violations())->toHaveCount(1)
        ->and(EmitterCoverage::violations()[0])->toContain('live_fixture_tickets')
        ->and(EmitterCoverage::violations()[0])->toContain('FixtureWriter.php');

    // The suite's own post-condition (tests/TestCase.php) is what turns it into a failed test.
    expect(fn () => $this->assertPostConditions())->toThrow(AssertionFailedError::class, 'live_fixture_tickets');
    expect(EmitterCoverage::violations())->toBe([]);

    FixtureWriter::openWithRecord();

    expect(EmitterCoverage::violations())->toBe([]);
});

it('makes EmitterCoverage watch only what the Registry says is tracked', function () {
    Registry::reset();

    FixtureWriter::openWithoutRecord();

    expect(EmitterCoverage::violations())->toBe([]);
});

it('does not count what is not an application write: a muted scope, an exempt scope, an exempt column, a rolled-back write, or a test\'s own fixture row', function () {
    FixtureWriter::openMuted();
    FixtureWriter::openExempt();
    FixtureWriter::markViewed(FixtureWriter::openWithRecord());
    FixtureWriter::openThenRollBack();

    // Written by the test itself, as every fixture in this suite writes its rows.
    DB::connection('mysql')->transaction(fn () => DB::connection('mysql')->table('live_fixture_tickets')->insert(['status' => 'open']));

    expect(EmitterCoverage::violations())->toBe([]);
});

it('makes EmitterCoverage flag a tracked write made outside any transaction, which no record() can share', function () {
    FixtureWriter::openOutsideTransaction();

    expect(EmitterCoverage::violations())->toHaveCount(1)
        ->and(EmitterCoverage::violations()[0])->toContain('outside');

    // Seen and asserted; left alone, the suite's post-condition would fail this test for it.
    EmitterCoverage::forget();
});

it('holds a record() in a nested transaction to that transaction\'s fate', function () {
    FixtureWriter::recordInNestedTransaction(innerCommits: true);

    expect(EmitterCoverage::violations())->toBe([]);

    FixtureWriter::recordInNestedTransaction(innerCommits: false);

    expect(EmitterCoverage::violations())->toHaveCount(1);

    EmitterCoverage::forget();
});

it('does not let a record() on one connection answer for a write on another', function () {
    FixtureWriter::recordOnAnotherConnection(LiveLog::secondConnection());

    expect(EmitterCoverage::violations())->toHaveCount(1)
        ->and(EmitterCoverage::violations()[0])->toContain('live_second');

    EmitterCoverage::forget();
});

it('tracks one column of a table when that is what a class declares', function () {
    $booking = FixtureWriter::newBooking();
    FixtureWriter::setBookingNote($booking, 'gate code changed');

    expect(EmitterCoverage::violations())->toBe([]);

    FixtureWriter::setBookingStatus($booking, 'confirmed', record: false);

    expect(EmitterCoverage::violations())->toHaveCount(1)
        ->and(EmitterCoverage::violations()[0])->toContain('live_fixture_bookings.status');

    EmitterCoverage::forget();

    FixtureWriter::setBookingStatus($booking, 'cancelled', record: true);

    expect(EmitterCoverage::violations())->toBe([]);
});

it('counts Eloquent saves, quiet or forced, like any other write', function () {
    FixtureWriter::quietSave();
    FixtureWriter::forceFillSave(FixtureWriter::openWithRecord());

    expect(EmitterCoverage::violations())->toBe([])
        ->and(DB::connection('mysql')->table('live_events')->count())->toBe(3);
});
