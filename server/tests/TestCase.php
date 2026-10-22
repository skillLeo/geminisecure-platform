<?php

namespace Tests;

use App\Live\Testing\EmitterCoverage;
use App\Support\Seeded\Retirements;
use App\Support\Seeded\Seeding;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /*
     * The forgotten-emitter guard (M11 Part C, DESIGN.md §2.15) watches every test: a transaction
     * that commits a write to a table an event class tracks, with no LiveBus::record() in it, fails
     * the test that made it. It watches nothing until App\Live\Registry has tracked tables.
     */
    protected function setUp(): void
    {
        parent::setUp();

        EmitterCoverage::watch($this->app);
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $forgotten = EmitterCoverage::violations();

        if ($forgotten !== []) {
            EmitterCoverage::forget();

            $this->fail("A write to a live table committed with no LiveBus::record() (EmitterCoverage):\n  ".implode("\n  ", $forgotten));
        }
    }

    /*
     * Sample data (M33 §5): a test that retired an estate, or a seeding command that threw before it could finish,
     * leaves nothing switched on for the next test. Forgotten only if something was retired, so a process that never
     * retired anything keeps the one read it made.
     */
    protected function tearDown(): void
    {
        Seeding::reset();
        Retirements::resetBlind();
        Retirements::forgetIfAny();

        parent::tearDown();
    }
}
