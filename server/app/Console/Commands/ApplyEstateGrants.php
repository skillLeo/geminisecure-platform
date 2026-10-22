<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Tenancy\ApplyAppendOnlyGrants;
use App\Models\Tenant;
use App\Support\GrantedTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-applies append-only grants across every estate.
 *
 *   php artisan grants:estates
 *
 * ApplyAppendOnlyGrants runs once, at provisioning. A migration that adds a
 * table afterwards leaves that table with NO update or delete grant for the
 * estate's user, because those privileges are withheld at database level and
 * granted back per table (D-017). The symptom is subtle: reads work, inserts
 * work, and only an update fails - possibly weeks later.
 *
 * So this must run after every `tenants:migrate`. It is idempotent.
 */
class ApplyEstateGrants extends Command
{
    protected $signature = 'grants:estates';

    protected $description = 'Re-apply per-table append-only grants to every estate database';

    public function handle(): int
    {
        $estates = Tenant::estates();

        if ($estates->isEmpty()) {
            $this->warn('No estates provisioned.');

            return self::SUCCESS;
        }

        $failed = 0;
        $undeclared = [];

        foreach ($estates as $estate) {
            try {
                (new ApplyAppendOnlyGrants($estate))->handle();
                $this->line("  <fg=green>OK</> {$estate->getTenantKey()} ({$estate->database()->getName()})");
                $undeclared = array_merge($undeclared, $this->undeclaredTablesIn($estate->database()->getName()));
            } catch (Throwable $e) {
                $failed++;
                $this->line("  <fg=red>FAIL</> {$estate->getTenantKey()}: ".$e->getMessage());
            }
        }

        $this->info('Append-only tables: '.implode(', ', ApplyAppendOnlyGrants::APPEND_ONLY_TABLES));

        /*
         * An estate table nobody classified. It IS granted above - this command
         * reads information_schema, not a fixed list - but nobody decided whether
         * it should have been append-only, and that decision is far harder to take
         * once every estate has been running with the grant for a month.
         */
        $undeclared = array_values(array_unique($undeclared));
        sort($undeclared);

        if ($undeclared !== []) {
            $this->warn('UNDECLARED: '.implode(', ', $undeclared));
            $this->warn('            add each to App\Support\GrantedTables::ESTATE_MUTABLE');
            $this->warn('            (or to ESTATE_APPEND_ONLY if it must never be edited)');

            return self::FAILURE;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Tables present in one estate database that `GrantedTables` never mentions.
     *
     * @return list<string>
     */
    private function undeclaredTablesIn(string $database): array
    {
        $tables = array_map(
            static fn ($row): string => (string) $row->name,
            DB::connection('mysql_owner')->select(
                'SELECT TABLE_NAME AS name FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$database, 'BASE TABLE'],
            ),
        );

        return GrantedTables::undeclaredEstate($tables);
    }
}
