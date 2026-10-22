<?php

declare(strict_types=1);

/*
 * A stand-in for GNU `date`, for tests/Unit/Deploy/BackupAtEachEstatesNightTest.php.
 *
 * The bash this suite finds on Windows has no zone files, and `TZ=America/New_York date` there is
 * silently UTC, so the backup's clock cannot be proved with it. This answers from PHP's copy of the
 * IANA database (date-lib.php); "now" is GS_FAKE_NOW, so a test can stand the machine at any hour.
 *
 *   php date.php <TZ, or empty> <date's own arguments…>
 */

require __DIR__.'/date-lib.php';

[$status, $out] = gs_fake_date($argv[1] ?? '', array_slice($argv, 2), (int) getenv('GS_FAKE_NOW'));

if ($status !== 0) {
    fwrite(STDERR, $out."\n");
    exit($status);
}

echo $out, "\n";
