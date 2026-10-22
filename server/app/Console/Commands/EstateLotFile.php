<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Estate\LotRangeFile;
use DomainException;
use Illuminate\Console\Command;

/**
 *   php artisan estate:lot-file --out=phoenix-homes.csv \
 *       --phase="Phase 1: 1-150, 200-336" --phase="Phase 2: 151-199, 337-700" ... --phase="Phase 5: 1750-1999, 2000"
 *
 * The file the estate console's importer takes (Estate structure → Import), for an estate whose homes are numbered,
 * phase by phase (M25 §2, M27 §1). A phase is a place, so its lots may run in several blocks; a home that is not a
 * plain number is given by its name ("1999A", "Club House"). The boundaries above are an EXAMPLE, not Phoenix
 * Park's: they come from the client's map, read and then confirmed by him in writing (M27 §2, D-225), and they are
 * never guessed. Every number from 1 to the highest given must be in exactly one phase, none past --last, and the
 * homes must add up to --homes, or nothing is written.
 *
 * It prints the short list the client confirms before the import ("Phase 1: lots 1 to 150, and 200 to 336 (287
 * homes)"). The console then previews the file, phase by phase, before anything is saved.
 */
class EstateLotFile extends Command
{
    protected $signature = 'estate:lot-file
        {--phase=* : one per phase, "Phase 1: 1-150, 200-336"}
        {--homes=2000 : how many homes the estate has, all told}
        {--last=2000 : the highest lot number the estate has}
        {--out= : where to write the file}';

    protected $description = 'Write the unit-import file for an estate whose homes are numbered phase by phase, each phase one or more ranges';

    public function handle(): int
    {
        $out = (string) $this->option('out');

        if ($out === '') {
            $this->error('Say where to write the file: --out=phoenix-homes.csv');

            return self::FAILURE;
        }

        try {
            $phases = LotRangeFile::phases(array_values((array) $this->option('phase')), (int) $this->option('homes'), (int) $this->option('last'));
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        file_put_contents($out, LotRangeFile::csv($phases));

        $this->table(['Phase', 'Homes', 'Lots as written'], array_map(static fn (array $p): array => [$p['phase'], number_format($p['count']), $p['written']], $phases));

        $this->info(sprintf('%s homes in %d phases written to %s.', number_format(array_sum(array_column($phases, 'count'))), count($phases), $out));
        $this->newLine();
        $this->line('For the client to confirm against the map, before the import:');

        foreach (LotRangeFile::confirmation($phases) as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('Then import it in the estate console: Estate structure, then Import CSV, and check the preview phase by phase before Import.');

        return self::SUCCESS;
    }
}
