<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use ReflectionProperty;

/**
 * Run some application code as ANOTHER operator would: on a second MySQL session.
 *
 * A race between two requests is a race between two database sessions, and a test
 * runs in one PHP process with one connection per name. The services under test
 * name their connection (`DB::connection('mysql')`), so a second connection under
 * a different name would not exercise them at all. This parks the test's own
 * `mysql` connection — its open transaction and all — while the work runs, so the
 * manager hands the SAME code a fresh session, which commits on its own. The
 * test's connection is put back afterwards exactly as it was.
 *
 * `innodb_lock_wait_timeout` is set short on the second session. Where the code
 * under test holds a row lock, the second session would otherwise wait fifty
 * seconds for a transaction that cannot finish until it returns — one PHP thread
 * holding both ends — and a one-second wait that ends in error 1205 is the
 * observable proof that it was made to wait.
 */
final class SecondSession
{
    public static function run(callable $work, int $lockWaitSeconds = 1): mixed
    {
        /** @var DatabaseManager $manager */
        $manager = app('db');

        $property = new ReflectionProperty(DatabaseManager::class, 'connections');

        /** @var array<string, Connection> $connections */
        $connections = $property->getValue($manager);
        $ours = $connections['mysql'] ?? null;

        unset($connections['mysql']);
        $property->setValue($manager, $connections);

        try {
            $manager->connection('mysql')->statement('SET SESSION innodb_lock_wait_timeout = '.max(1, $lockWaitSeconds));

            return $work();
        } finally {
            /** @var array<string, Connection> $connections */
            $connections = $property->getValue($manager);

            if (isset($connections['mysql']) && $connections['mysql'] !== $ours) {
                $connections['mysql']->disconnect();
            }

            unset($connections['mysql']);

            if ($ours !== null) {
                $connections['mysql'] = $ours;
            }

            $property->setValue($manager, $connections);
        }
    }
}
