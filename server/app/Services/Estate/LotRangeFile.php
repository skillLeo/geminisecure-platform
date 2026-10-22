<?php

declare(strict_types=1);

namespace App\Services\Estate;

use DomainException;

/**
 * AN ESTATE'S HOMES, PHASE BY PHASE, AS THE CONSOLE'S IMPORTER READS THEM (M25 §2, M27 §1).
 *
 * The client: 2,000 homes, "1999 plus one additional one"; "phase one goes up to 336 or something like that"; and
 * "phase one has two sides of it… but it's all the phase one". So a phase is a PLACE, and its lot numbers may run in
 * more than one block. Each phase is given as a list of ranges and single lots, as the client's map shows them:
 *
 *   Phase 1: 1-150, 200-336
 *   Phase 2: 151-199, 337-700
 *   Phase 5: 1750-1999, 2000
 *
 * The 2,000th home is not assumed to be lot 2000: a token that is not a plain number is a home by that name, "1999A"
 * (written as "Lot 1999A", like the others) or "Club House" (as written). Nothing is guessed. The list is refused, with
 * the lot named, when a lot is in two phases, when a number between 1 and the highest given is in no phase, when a lot
 * is outside 1 to the estate's last, and when the homes do not add up to the estate's total. What comes out is the
 * importer's own file (`EstateStructure::parseUnits`): every home vacant and residential, with no street.
 */
final class LotRangeFile
{
    /**
     * Read the phases.
     *
     * @param  list<string>  $specs  one per phase: "Phase 1: 1-150, 200-336" (or "Phase 1=1-400")
     * @return list<array{phase: string, written: string, ranges: list<array{0: int, 1: int}>, named: list<string>, count: int}> in the order given
     */
    public static function phases(array $specs, int $homes, int $last): array
    {
        if ($homes < 1 || $last < 1) {
            throw new DomainException('An estate has at least one home, numbered from 1.');
        }

        if ($specs === []) {
            throw new DomainException('Give each phase its lots, one per phase: --phase="Phase 1: 1-150, 200-336".');
        }

        $phases = [];
        $names = [];
        $owner = [];      // lot number => phase
        $namedOwner = []; // folded name => phase

        foreach ($specs as $spec) {
            if (preg_match('/^\s*([^:=]+?)\s*[:=]\s*(.+?)\s*$/u', $spec, $m) !== 1) {
                throw new DomainException('"'.$spec.'" is not a phase and its lots. Write it as Phase 1: 1-150, 200-336.');
            }

            [$name, $written] = [trim($m[1]), trim($m[2])];

            if (mb_strlen($name) > EstateStructure::PHASE_NAME_MAX) {
                throw new DomainException(sprintf('"%s" is longer than a phase name may be (%d characters): every lot in it carries the name.', $name, EstateStructure::PHASE_NAME_MAX));
            }

            if (isset($names[mb_strtolower($name)])) {
                throw new DomainException($name.' is given twice. Each phase is named once, with all its lots.');
            }

            $names[mb_strtolower($name)] = true;
            $ranges = [];
            $named = [];

            foreach (preg_split('/\s*(?:,|;|\band\b)\s*/iu', $written) ?: [] as $token) {
                $token = trim($token);

                if ($token === '') {
                    continue;
                }

                if (preg_match('/^(?:lots?\s+)?(\d+)\s*(?:-|–|—|to)\s*(\d+)$/iu', $token, $r) === 1) {
                    [$from, $to] = [(int) $r[1], (int) $r[2]];

                    if ($to < $from) {
                        throw new DomainException(sprintf('%s: "%s" runs from %d down to %d. Write the lower lot first.', $name, $token, $from, $to));
                    }
                } elseif (preg_match('/^(?:lot\s+)?(\d+)$/iu', $token, $r) === 1) {
                    $from = $to = (int) $r[1];
                } else {
                    // A home that is not a plain number: "1999A" is Lot 1999A; "Club House" is as written.
                    $ref = preg_match('/^\d+[A-Za-z]+$/u', $token) === 1 ? 'Lot '.$token : (preg_match('/^lot\s+/iu', $token) === 1 ? 'Lot '.trim(mb_substr($token, 4)) : $token);

                    if (mb_strlen($ref) > EstateStructure::REFERENCE_MAX) {
                        throw new DomainException(sprintf('%s: "%s" is longer than a home\'s reference may be (%d characters).', $name, $ref, EstateStructure::REFERENCE_MAX));
                    }

                    $key = mb_strtolower($ref);

                    if (isset($namedOwner[$key])) {
                        throw new DomainException(sprintf('%s is in two phases, %s and %s. Every home is in exactly one.', $ref, $namedOwner[$key], $name));
                    }

                    $namedOwner[$key] = $name;
                    $named[] = $ref;

                    continue;
                }

                if ($from < 1 || $to > $last) {
                    throw new DomainException(sprintf('%s has lot %d, outside the estate\'s lots 1 to %d.', $name, $from < 1 ? $from : $to, $last));
                }

                for ($lot = $from; $lot <= $to; $lot++) {
                    if (isset($owner[$lot])) {
                        throw new DomainException(sprintf('Lot %d is in two phases, %s and %s. Every lot is in exactly one.', $lot, $owner[$lot], $name));
                    }

                    $owner[$lot] = $name;
                }

                $ranges[] = [$from, $to];
            }

            if ($ranges === [] && $named === []) {
                throw new DomainException($name.' has no lots. Give it its lots, or leave it out.');
            }

            $count = array_sum(array_map(static fn (array $r): int => $r[1] - $r[0] + 1, $ranges)) + count($named);
            $phases[] = ['phase' => $name, 'written' => $written, 'ranges' => $ranges, 'named' => $named, 'count' => $count];
        }

        // A home named like a number is that number: "Lot 1999A" is a name, "Lot 2000" is not.
        foreach ($namedOwner as $key => $phase) {
            if (preg_match('/^lot (\d+)$/u', $key, $n) === 1 && isset($owner[(int) $n[1]])) {
                throw new DomainException(sprintf('Lot %d is in two phases, %s and %s. Every lot is in exactly one.', (int) $n[1], $owner[(int) $n[1]], $phase));
            }
        }

        // Every number from 1 to the highest given is in a phase: a lot in no phase is a gap in the map's reading.
        $highest = $owner === [] ? 0 : max(array_keys($owner));
        $missing = [];

        for ($lot = 1; $lot <= $highest; $lot++) {
            if (! isset($owner[$lot])) {
                $missing[] = $lot;
            }
        }

        if ($missing !== []) {
            throw new DomainException(sprintf('No phase has %s. Every lot from 1 to %d is in exactly one phase.', self::listed($missing), $highest));
        }

        $total = count($owner) + count($namedOwner);

        if ($total !== $homes) {
            throw new DomainException(sprintf(
                'The phases hold %s home%s; the estate has %s. %s',
                number_format($total),
                $total === 1 ? '' : 's',
                number_format($homes),
                $total < $homes
                    ? 'Add the missing one'.($homes - $total === 1 ? '' : 's').' to '.($homes - $total === 1 ? 'its phase' : 'their phases').' by number or by name — "Phase 5: 1750-1999, 2000", or "…, 1999A" — once the client has said what '.($homes - $total === 1 ? 'it is' : 'they are').'.'
                    : 'Take out the lots the estate does not have.',
            ));
        }

        return $phases;
    }

    /**
     * The importer's file: a header and one row per home, phase by phase, numbers in order and named homes after.
     *
     * @param  list<array{phase: string, written: string, ranges: list<array{0: int, 1: int}>, named: list<string>, count: int}>  $phases
     */
    public static function csv(array $phases): string
    {
        $lines = ['reference,phase,street,type,status'];
        $cell = static fn (string $v): string => '"'.str_replace('"', '""', $v).'"';

        foreach ($phases as $phase) {
            $ranges = $phase['ranges'];
            usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            foreach ($ranges as [$from, $to]) {
                for ($lot = $from; $lot <= $to; $lot++) {
                    $lines[] = 'Lot '.$lot.','.$cell($phase['phase']).',,residential,vacant';
                }
            }

            foreach ($phase['named'] as $ref) {
                $lines[] = $cell($ref).','.$cell($phase['phase']).',,residential,vacant';
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The list a client checks against the map in thirty seconds (M27 §2.2): one line a phase, then the total.
     *
     *   Phase 1: lots 1 to 150, and 200 to 336 (287 homes)
     *   Phase 5: lots 1750 to 1999, and Lot 2000 (251 homes)
     *   Total 2,000
     *
     * @param  list<array{phase: string, written: string, ranges: list<array{0: int, 1: int}>, named: list<string>, count: int}>  $phases
     * @return list<string>
     */
    public static function confirmation(array $phases): array
    {
        $lines = [];

        foreach ($phases as $phase) {
            $ranges = $phase['ranges'];
            usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $parts = array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0].' to '.$r[1], $ranges);
            $parts = [...$parts, ...$phase['named']];
            $last = count($parts) > 1 ? array_pop($parts) : null;
            $said = implode(', ', $parts).($last === null ? '' : ', and '.$last);

            $lines[] = sprintf('%s: lot%s %s (%s home%s)', $phase['phase'], $phase['count'] === 1 ? '' : 's', $said, number_format($phase['count']), $phase['count'] === 1 ? '' : 's');
        }

        $lines[] = 'Total '.number_format(array_sum(array_column($phases, 'count')));

        return $lines;
    }

    /**
     * "lot 151", "lots 151 to 199", or a few of them: a gap named exactly, never rounded.
     *
     * @param  list<int>  $lots  in order
     */
    private static function listed(array $lots): string
    {
        $runs = [];

        foreach ($lots as $lot) {
            $lastRun = array_key_last($runs);

            if ($lastRun !== null && $runs[$lastRun][1] === $lot - 1) {
                $runs[$lastRun][1] = $lot;
            } else {
                $runs[] = [$lot, $lot];
            }
        }

        $said = array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0].' to '.$r[1], array_slice($runs, 0, 5));
        $more = count($runs) > 5 ? ' and '.(count($runs) - 5).' more gaps' : '';

        return (count($lots) === 1 ? 'lot ' : 'lots ').implode(', ', $said).$more;
    }
}
