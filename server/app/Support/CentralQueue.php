<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Bus\Queueable;

/**
 * PUT THIS QUEUED THING ON THE CENTRAL CONNECTION, NOT ON "THE DEFAULT".
 *
 * THE TRAP. With the `database` queue driver, the connection a job is written to
 * follows `database.default` — and tenancy repoints `database.default` at the open
 * estate for the whole of an estate or `/api/v1` request. An estate database has no
 * `jobs` table, so anything queued from inside one is written nowhere and throws; if
 * the caller rescued the throw, as anything on the path of a panic must, the work
 * simply never happens and nothing says so.
 *
 * `config/queue.php`'s `central` connection is the same `jobs` table the worker
 * already reads, with the database connection NAMED instead of inferred.
 * `PushNotifier` has pinned its sender this way since M4 §3.2; this is the same
 * three lines for anything else queued from inside an estate — a notification to a
 * console user, a mail, a report.
 *
 * It is written as a no-op when the connection is missing or misconfigured, because
 * pinning is a correction, not a requirement: on a Redis or SQS deployment there is
 * no tenancy problem to correct and the application's own default is right.
 */
final class CentralQueue
{
    /**
     * @template T of object
     *
     * @param  T  $queueable  a job, mailable or notification using `Illuminate\Bus\Queueable`
     * @return T the same object, pinned where it can be pinned
     */
    public static function pin(object $queueable): object
    {
        $connection = config('queue.central_connection', 'central');

        if (! is_string($connection) || $connection === '' || config("queue.connections.{$connection}") === null) {
            return $queueable;
        }

        if (in_array(Queueable::class, class_uses_recursive($queueable), true)) {
            $queueable->onConnection($connection);
        }

        return $queueable;
    }
}
