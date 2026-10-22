<?php

declare(strict_types=1);

/*
 * What GNU `date` would print for the calls deploy/gs-backup makes — `date -u +FMT`,
 * `date -u -d @N +FMT`, `TZ=<zone> date -d @N +FMT` — from PHP's copy of the IANA database, the
 * same database `date` reads on the server. Shared by the stand-in (date.php) and by the table
 * tests/Unit/Deploy/BackupAtEachEstatesNightTest.php writes ahead of each run, so the two cannot
 * disagree.
 *
 * @param  list<string>  $args  date's own arguments
 * @return array{0: int, 1: string}  exit status, and what it prints (an error, when not 0)
 */
function gs_fake_date(string $zone, array $args, int $now): array
{
    $utc = false;
    $at = null;
    $format = null;

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];

        if ($arg === '-u') {
            $utc = true;
        } elseif ($arg === '-d') {
            $at = $args[++$i] ?? '';
        } elseif (str_starts_with($arg, '+')) {
            $format = substr($arg, 1);
        } else {
            return [2, "date stand-in: an argument gs-backup does not pass: {$arg}"];
        }
    }

    if ($format === null) {
        return [2, 'date stand-in: no +FORMAT'];
    }

    if ($at === null) {
        $epoch = $now;
    } elseif (preg_match('/^@(\d+)$/', $at, $m) === 1) {
        $epoch = (int) $m[1];
    } else {
        return [2, "date stand-in: -d takes @<epoch> here, not {$at}"];
    }

    // GNU date reads an unknown TZ as UTC, silently — which is why gs-backup checks the zone file first.
    try {
        $tz = new DateTimeZone($utc || $zone === '' ? 'UTC' : $zone);
    } catch (Throwable) {
        $tz = new DateTimeZone('UTC');
    }

    $moment = (new DateTimeImmutable('@'.$epoch))->setTimezone($tz);
    $map = ['Y' => 'Y', 'm' => 'm', 'd' => 'd', 'H' => 'H', 'M' => 'i', 'S' => 's', 'F' => 'Y-m-d', 'Z' => 'T', 's' => 'U', 'T' => 'H:i:s'];
    $unknown = null;

    $out = (string) preg_replace_callback('/%(.)/', static function (array $m) use ($map, $moment, &$unknown): string {
        if ($m[1] === '%') {
            return '%';
        }
        if (! isset($map[$m[1]])) {
            $unknown = $m[1];

            return '';
        }

        return $moment->format($map[$m[1]]);
    }, $format);

    return $unknown === null ? [0, $out] : [2, "date stand-in: %{$unknown} is not a format gs-backup uses"];
}
