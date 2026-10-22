<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connection;

/**
 * Reading the central database's grants back out of MySQL.
 *
 * Shared by `grants:append-only`, which rebuilds the grants and then verifies
 * them, and by `ReapplyCentralGrantsAfterMigration`, which tops up whatever a
 * migration has just left ungranted. Both need the same two questions answered,
 * and a second copy of the answer is how the two halves drift apart.
 */
final class CentralGrants
{
    /**
     * MySQL treats `gs_app@localhost` and `gs_app@127.0.0.1` as two separate
     * logins with two separate sets of privileges, and which one a connection
     * gets depends on how `DB_HOST` happens to be written. Both must be right,
     * always, or the application works until somebody edits one line of `.env`.
     */
    public const HOSTS = ['localhost', '127.0.0.1'];

    /** The privileges withheld at database level and granted back per table (D-017). */
    public const MUTATING = ['UPDATE', 'DELETE'];

    /**
     * Every real table in one database, views excluded.
     *
     * @return list<string>
     */
    public static function baseTables(Connection $connection, string $database): array
    {
        return array_map(
            static fn ($row): string => (string) $row->name,
            $connection->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE'],
            ),
        );
    }

    /**
     * The tables one login may UPDATE **and** DELETE in one database.
     *
     * ASKED WITH `SHOW GRANTS`, BECAUSE THE TWO OBVIOUS SOURCES BOTH REFUSE.
     * `information_schema.TABLE_PRIVILEGES` shows only what the CONNECTED user
     * can see, and the schema owner is not the grantee, so it answers with
     * nothing — which an earlier version of the estate check read as "forty-two
     * tables are ungranted" while every write on the platform was plainly
     * working. Reading `mysql.tables_priv` directly is refused too: `gs_owner`
     * holds no SELECT on the `mysql` schema, and it should not.
     *
     * `SHOW GRANTS FOR user@host` works, because the owner holds GRANT OPTION
     * here — the same privilege that let it issue the grants in the first place.
     * Its output is text, so this parses it, which is the price of asking the
     * only source that will answer.
     *
     * BOTH privileges, deliberately. A table carrying UPDATE but not DELETE is
     * still half-granted, and the missing half fails at some later hour.
     *
     * @return list<string>
     */
    public static function grantedMutation(
        Connection $connection,
        string $user,
        string $host,
        string $database,
    ): array {
        $tables = [];

        foreach ($connection->select("SHOW GRANTS FOR `{$user}`@`{$host}`") as $row) {
            // One column, named after the user, so it cannot be addressed by a
            // fixed key — take whatever the row holds.
            $line = (string) (array_values((array) $row)[0] ?? '');

            if (! preg_match('/^GRANT (.+?) ON `'.preg_quote($database, '/').'`\.`(.+?)` TO /', $line, $m)) {
                continue;
            }

            $privileges = strtoupper($m[1]);

            if (str_contains($privileges, 'UPDATE') && str_contains($privileges, 'DELETE')) {
                $tables[] = $m[2];
            }
        }

        return array_values(array_unique($tables));
    }
}
