<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * Every assumption in the code has an entry in the queue, and every entry has an
 * assumption in the code. Both directions.
 *
 * WHY THIS EXISTS. The project's convention, stated in README: anything
 * undecided in money, restriction, biometrics or voting sits behind a flag
 * defaulting to the safest option, the affected code carries
 * `// ASSUMPTION Q-0xx`, and QUESTIONS.md carries the entry a client rules on.
 * The two halves were never checked against each other, and when somebody
 * finally did, FOUR assumptions — Q-008, Q-009, Q-010, Q-011 — turned out to be
 * live in the source with no entry in the queue at all. The file went Q-007,
 * then jumped to Q-012. One of them was referenced by three docblocks reading
 * "see QUESTIONS.md Q-009", at a file with no Q-009 in it (D-074).
 *
 * The defaults were all safe and all sensible. That is not a mitigation: good
 * defaults nobody was told about are still decisions taken on a client's behalf,
 * and the client is the one who has to live with them.
 *
 * SO THE CONVENTION IS NOW CHECKED RATHER THAN OBSERVED, and it is checked in
 * both directions, because the two failures are different and only one of them
 * is obvious:
 *
 *   - a MARKER WITH NO ENTRY is an assumption the client was never asked about;
 *   - an ENTRY WITH NO MARKER is a question whose answer would have nowhere to
 *     land — either the code moved on and the queue did not, or the entry names
 *     a rule nobody actually implemented.
 *
 * WHAT COUNTS AS AN ENTRY. A heading in QUESTIONS.md naming the question, or a
 * row in its answered table. An answered question is still expected to carry its
 * marker: the marker is what tells the next reader that this line of code is
 * where a ruling landed, and deleting it on the day the answer arrives is how
 * the reasoning gets lost.
 *
 * IT ALSO AUDITS THE DECISION LOG, for the same reason and after the same kind
 * of finding. `DECISIONS.md` is cited by number from forty-two places in the
 * source, and it turned out to have EIGHTY headings numbered D-001 to D-079 —
 * one too many, because D-033 was the heading of two entirely different
 * decisions. Every citation of it was ambiguous (D-081). So: no number is the
 * heading of two decisions, and no `D-xxx` cited from the source is missing from
 * the log.
 *
 * THIS GATE PROVES A PROCESS, NOT A BEHAVIOUR, which is why it is not a test.
 * The other four gates run against a live database and assert what the
 * application does. This one reads three files and asserts that a habit was
 * kept — twice now, it was not.
 */
class GateAssumptions extends Command
{
    protected $signature = 'gate:assumptions';

    protected $description = 'Fail when the ASSUMPTION convention or the decision log is out of step with the source';

    /** Where the markers live. Tests included: an assumption asserted is still an assumption. */
    private const SOURCE_DIRS = ['app', 'database', 'routes', 'tests', 'resources/js'];

    /**
     * Questions that are allowed to carry no marker in the source, with why.
     *
     * DELIBERATELY ALMOST EMPTY, and every entry here weakens the gate. A
     * question earns a place only when it genuinely governs no single line —
     * Q-007 asked whether the seventh permission verb was `view`, and the answer
     * shaped an enum that would look absurd with an ASSUMPTION comment on it.
     *
     * @var array<string, string>
     */
    private const NO_MARKER_EXPECTED = [
        'Q-007' => 'Named the seventh permission verb. The answer is the PermissionVerb enum itself.',
    ];

    public function handle(): int
    {
        $questionsFile = base_path('QUESTIONS.md');

        if (! File::exists($questionsFile)) {
            $this->error('QUESTIONS.md is missing. The convention has nowhere to live.');

            return self::FAILURE;
        }

        $inCode = $this->markersInSource();
        $inQueue = $this->questionsInQueue(File::get($questionsFile));

        $this->line('  <fg=gray>markers in source:</> '.($inCode === [] ? 'none' : implode(', ', array_keys($inCode))));
        $this->line('  <fg=gray>entries in queue: </> '.($inQueue === [] ? 'none' : implode(', ', $inQueue)));
        $this->newLine();

        $failures = 0;

        // ---- direction 1: a marker the client was never asked about --------
        foreach ($inCode as $question => $places) {
            if (in_array($question, $inQueue, true)) {
                $this->line(" <fg=green>PASS</> {$question} is marked in the source and asked in the queue");

                continue;
            }

            $failures++;

            $this->line(" <fg=red>FAIL</> {$question} is assumed in the source and appears NOWHERE in QUESTIONS.md");

            foreach (array_slice($places, 0, 4) as $place) {
                $this->line("        {$place}");
            }

            if (count($places) > 4) {
                $this->line('        ... and '.(count($places) - 4).' more');
            }
        }

        // ---- direction 2: an entry whose answer has nowhere to land --------
        foreach ($inQueue as $question) {
            if (isset($inCode[$question])) {
                continue;
            }

            if (isset(self::NO_MARKER_EXPECTED[$question])) {
                $this->line(" <fg=yellow>SKIP</> {$question} — ".self::NO_MARKER_EXPECTED[$question]);

                continue;
            }

            $failures++;

            $this->line(" <fg=red>FAIL</> {$question} is asked in QUESTIONS.md and marked NOWHERE in the source");
            $this->line('        A ruling on it would have nothing to change. Either the code moved on');
            $this->line('        and the queue did not, or the entry describes a rule nobody built.');
        }

        $this->newLine();

        $failures += $this->auditDecisionLog();

        if ($failures > 0) {
            $this->error(sprintf(
                'GATE FAILED - %d item%s out of step. Every ASSUMPTION marker needs an entry in '.
                'QUESTIONS.md, every entry needs a marker, and every D-xxx cited in the source needs '.
                'exactly one entry in DECISIONS.md. See D-074 and D-081 for what happened the two times '.
                'nobody checked.',
                $failures,
                $failures === 1 ? '' : 's',
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'GATE PASSED - %d question%s marked and asked, and the decision log resolves.',
            count($inQueue),
            count($inQueue) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * The decision log identifies each decision exactly once, and answers
     * everything the source asks of it.
     *
     * TWO CHECKS, AND THE FIRST ONE CAUGHT A REAL COLLISION. `DECISIONS.md` had
     * eighty headings numbered D-001 to D-079 — one too many, because **D-033
     * appeared twice**, on two entirely different Phase 2 decisions. Forty-two
     * places in the source cite the log by number, so a duplicate means a reader
     * following one of them cannot tell which entry they were sent to (D-081).
     *
     * The second check is the same shape as the Q-009 failure that started all
     * of this: a docblock pointing at a document section that does not exist. A
     * `D-xxx` cited from code and absent from the log is a reference into
     * nothing.
     *
     * WHAT IS DELIBERATELY NOT CHECKED: that every decision is cited from
     * somewhere. Thirty-nine are not, and that is correct — a decision about
     * process, a fidelity residual or a client ruling on a document has no line
     * of code to sit on, and demanding one would push citations into places they
     * do not belong.
     *
     * @return int the number of failures found
     */
    private function auditDecisionLog(): int
    {
        $file = base_path('DECISIONS.md');

        if (! File::exists($file)) {
            $this->line(' <fg=red>FAIL</> DECISIONS.md is missing');

            return 1;
        }

        $log = File::get($file);

        preg_match_all('/^### (D-\d{3})\b/m', $log, $headings);

        $defined = $headings[1];
        $duplicates = array_filter(array_count_values($defined), static fn (int $n): bool => $n > 1);

        $failures = 0;

        foreach ($duplicates as $number => $count) {
            $failures++;

            $this->line(" <fg=red>FAIL</> {$number} is the heading of {$count} different decisions");
            $this->line('        A decision number identifies one decision. Every citation of this one');
            $this->line('        is ambiguous. Renumber the LATER entry to the next free number, leave');
            $this->line('        it where it sits, and say so in it — see D-081.');
        }

        $cited = $this->decisionsCitedInSource();
        $dangling = array_diff(array_keys($cited), $defined);

        foreach ($dangling as $number) {
            $failures++;

            $this->line(" <fg=red>FAIL</> {$number} is cited in the source and has no entry in DECISIONS.md");

            foreach (array_slice($cited[$number], 0, 3) as $place) {
                $this->line("        {$place}");
            }
        }

        if ($failures === 0) {
            $this->line(sprintf(
                ' <fg=green>PASS</> %d decisions, each numbered once; all %d cited from the source resolve',
                count($defined),
                count($cited),
            ));
        }

        return $failures;
    }

    /**
     * Every `D-xxx` the source cites, and where.
     *
     * @return array<string, list<string>> number => list of "path:line"
     */
    private function decisionsCitedInSource(): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $path) {
            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match_all('/\b(D-\d{3})\b/', $line, $hits) < 1) {
                    continue;
                }

                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

                foreach ($hits[1] as $number) {
                    $found[$number][] = $relative.':'.($index + 1);
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every `ASSUMPTION Q-0xx` in the source, and where each one is.
     *
     * The marker is matched with or without a comment leader, because it appears
     * in `//` lines, in `/* *\/` docblocks and in Vue comments, and a pattern
     * tied to one of those would quietly miss the others.
     *
     * @return array<string, list<string>> question => list of "path:line"
     */
    private function markersInSource(): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $path) {
            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match('/\bASSUMPTION\s+(Q-\d{3})\b/', $line, $match) !== 1) {
                    continue;
                }

                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

                $found[$match[1]][] = $relative.':'.($index + 1);
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every source file both audits read, walked once.
     *
     * @return list<string> absolute paths
     */
    private function sourceFiles(): array
    {
        $dirs = array_values(array_filter(
            array_map(static fn (string $dir): string => base_path($dir), self::SOURCE_DIRS),
            static fn (string $path): bool => is_dir($path),
        ));

        if ($dirs === []) {
            return [];
        }

        $paths = [];

        $finder = (new Finder)
            ->files()
            ->in($dirs)
            ->name(['*.php', '*.vue', '*.js'])
            ->notPath('vendor');

        foreach ($finder as $file) {
            $paths[] = (string) $file->getRealPath();
        }

        return $paths;
    }

    /**
     * Every question QUESTIONS.md actually asks — open or answered.
     *
     * A heading names an open one; the answered table lists the rest. Both count,
     * because a marker on a question that has been RULED is still doing its job:
     * it says this line is where the ruling landed.
     *
     * @return list<string>
     */
    private function questionsInQueue(string $markdown): array
    {
        $questions = [];

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trimmed = ltrim($line);

            // "### Q-014 · May a role locked out of the ledger ..."
            if (preg_match('/^#{2,4}\s+(Q-\d{3})\b/', $trimmed, $match) === 1) {
                $questions[] = $match[1];

                continue;
            }

            // "| Q-005 | Arrears threshold | 90 days, ... | D-024 |"
            if (preg_match('/^\|\s*\*{0,2}(Q-\d{3})\*{0,2}\s*\|/', $trimmed, $match) === 1) {
                $questions[] = $match[1];
            }
        }

        $questions = array_values(array_unique($questions));

        sort($questions);

        return $questions;
    }
}
