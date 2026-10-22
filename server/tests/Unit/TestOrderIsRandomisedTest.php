<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TextUI\Configuration\Registry;

/**
 * THE SUITE PROVES ITS OWN ORDER IS SHUFFLED (M4 section 4, defect 3; M6 section 3.1).
 *
 * The mobile project carries a gate, `tools/gates/shuffled-order.mjs`, that reads phpunit.xml and
 * every script that runs this suite. It catches the file-shaped ways the shuffle gets switched off.
 * It cannot catch the run-shaped ones, because it never watches a run: a flag typed at a prompt, a
 * flag added to somebody's editor or CI step, a second configuration passed with a lowercase c. All
 * of those leave every file on disk looking perfect.
 *
 * This test is the other half. It asks PHPUnit, from inside the run, what order THIS run is in. It
 * is cheap, it touches no database, and it fails the moment the suite stops shuffling for any
 * reason at all.
 *
 * WHY ANY OF THIS IS HERE. This suite used to run in the order the files happened to be found in,
 * and it was green. It was green because tests were leaning on each other: one left a row, a claimed
 * scratch lot or a changed setting behind, and the next passed because of it. Randomising the order
 * dropped out ten separate defects that had passed alone and failed together, and one file that
 * failed in two different groupings while passing in the whole suite. Nothing anybody could have run
 * by hand would have found that one. The deeper cost is a suite nobody trusts: the two worst defects
 * in this build, a phone passcode accepted as a guard's identity and mis-typed supervisor PINs
 * written to the log in plain text, were invisible to this suite and were found by reading code.
 * A suite that cannot be trusted stops catching real defects, and that is how those two lasted.
 *
 * IF THIS TEST IS THE ONLY FAILURE, read the message it printed. The one honest reason for it is
 * that you deliberately ran the suite in a fixed order to reproduce an order dependence, in which
 * case this single failure is expected and is the only one to ignore. Every other reason means the
 * shuffle is off, and a green suite means less than it did yesterday.
 */
final class TestOrderIsRandomisedTest extends TestCase
{
    public function test_this_run_is_in_a_random_order(): void
    {
        $order = Registry::get()->executionOrder();

        $this->assertSame(
            TestSuiteSorter::ORDER_RANDOMIZED,
            $order,
            'This suite is NOT running in a random order. Shuffling is a ruling (M6 section 3.1), not a preference: '
            ."it is what found the ten order-dependent failures, and each of those was a test passing because another test had run first.\n"
            ."  - If you passed a flag to fix the order to reproduce a failure, this one failure is expected; ignore it and nothing else.\n"
            ."  - Otherwise phpunit.xml has lost executionOrder=\"random\", or a wrapper script is overriding it. "
            .'Put it back, and read the header of tools/gates/shuffled-order.mjs in the mobile project before changing it again.',
        );
    }

    public function test_dependencies_are_still_resolved_so_the_shuffle_is_never_blamed_for_them(): void
    {
        $this->assertTrue(
            Registry::get()->resolveDependencies(),
            'resolveDependencies is off. It is the one thing that must NOT be shuffled: a test that declares a dependency '
            .'has to run after the test it depends on. With it off, such a test fails for a reason that has nothing to do '
            .'with the test, the shuffle gets the blame, and somebody switches the shuffle off to make the suite green.',
        );
    }

    public function test_the_seed_for_this_run_is_known_so_a_failure_can_be_reproduced(): void
    {
        $this->assertGreaterThan(
            0,
            Registry::get()->randomOrderSeed(),
            'This run has no random-order seed, so any order-dependent failure in it could never be reproduced. '
            .'An unreproducible failure is the thing that gets shuffling switched off.',
        );
    }

    public function test_the_seed_reporter_is_registered(): void
    {
        $registered = array_column(Registry::get()->extensionBootstrappers(), 'className');

        $this->assertContains(
            \Tests\Support\RandomOrderSeed::class,
            $registered,
            'Tests\Support\RandomOrderSeed is no longer registered as an extension in phpunit.xml. It prints the seed and '
            .'the exact command that repeats this order whenever a run goes red. Without it a shuffled failure is a mystery, '
            .'and a mystery is answered by switching the shuffle off rather than by fixing the test that was leaning.',
        );
    }
}
