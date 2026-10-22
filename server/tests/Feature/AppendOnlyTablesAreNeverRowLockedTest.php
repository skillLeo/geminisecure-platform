<?php

declare(strict_types=1);

use App\Support\GrantedTables;

/*
|--------------------------------------------------------------------------
| A locking read is a write, and an append-only table refuses writes
|--------------------------------------------------------------------------
|
| MySQL implements `SELECT ... FOR UPDATE` as a write INTENTION and demands the
| UPDATE privilege on the table. Every table in GrantedTables::CENTRAL_APPEND_ONLY
| and ESTATE_APPEND_ONLY deliberately withholds exactly that privilege. The two
| are irreconcilable, and where they met the result was:
|
|   SQLSTATE[42000]: 1142 SELECT with locking clause command denied to user
|   'gs_app'@'localhost' for table 'platform_journals'
|
| `PlatformLedger::post()` took its next journal reference under
| ->lockForUpdate(), so raising ANY invoice answered 500 and Gemini could not
| bill a single client from its own console. It reached production because both
| halves are individually correct — the books are append-only, and a sequence
| allocator reaching for a row lock is the obvious thing to write — and because
| the test suite runs as a privileged user for whom the grant is not in force.
| That is the important part: no functional test could have caught it, and a
| functional test still cannot. This one reads the source instead.
|
| It is deliberately a GREP and not a runtime assertion. The failure is a
| property of the code as written, and the only way to observe it at runtime is
| to hold the production grants, which the suite does not and should not.
|
*/

/** Every .php file under app/, as path => contents. */
function sourceFiles(): array
{
    $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';

    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never takes a row lock on a table the application may not update', function () {
    $appendOnly = [...GrantedTables::CENTRAL_APPEND_ONLY, ...GrantedTables::ESTATE_APPEND_ONLY];

    expect($appendOnly)->not->toBeEmpty();

    $offences = [];

    foreach (sourceFiles() as $path => $source) {
        if (! str_contains($source, 'lockForUpdate')) {
            continue;
        }

        $lines = preg_split('/\R/', $source) ?: [];

        foreach ($lines as $number => $line) {
            if (! str_contains($line, 'lockForUpdate')) {
                continue;
            }

            /*
             * The statement this lock belongs to. A query builder chain is
             * written over several lines, and the table is named at the top of
             * it — so the window looks BACK from the lock to the start of the
             * statement, which is the previous semicolon.
             */
            $window = [];

            for ($i = $number; $i >= 0 && $i > $number - 12; $i--) {
                array_unshift($window, $lines[$i]);

                if ($i < $number && str_contains($lines[$i], ';')) {
                    break;
                }
            }

            $statement = implode("\n", $window);

            foreach ($appendOnly as $table) {
                // Quoted, so `journals` does not match `platform_journal_lines`
                // and `charges` does not match `charge_runs`.
                if (str_contains($statement, "'".$table."'") || str_contains($statement, '"'.$table.'"')) {
                    $offences[] = sprintf(
                        '%s:%d locks %s, which is append-only and grants the application no UPDATE',
                        str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $path),
                        $number + 1,
                        $table,
                    );
                }
            }
        }
    }

    expect($offences)->toBe([]);
});

it('still has the lock it is allowed to take, so the scan above is not vacuous', function () {
    /*
     * The counterweight. A test that only ever asserts an empty array passes
     * just as happily when its own search is broken — and this one greps source,
     * which is exactly the kind of check that rots into a no-op.
     *
     * `device_rebind_requests` is in CENTRAL_MUTABLE and IS locked, legitimately,
     * by DeviceEnrolment: a rebind is decided once, and two supervisors deciding
     * at the same moment must not both win. So the scan must be finding
     * lockForUpdate calls and clearing them, not finding none at all.
     */
    $found = false;

    foreach (sourceFiles() as $source) {
        if (str_contains($source, 'lockForUpdate') && str_contains($source, "'device_rebind_requests'")) {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue(
        'DeviceEnrolment no longer locks device_rebind_requests. If that was deliberate, '
        .'point this test at another legitimate lock — it exists to prove the scan above still sees them.'
    );
});

it('keeps the platform ledger free of the lock that broke invoicing', function () {
    $ledger = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Services/Gemini/PlatformLedger.php'
    );

    /*
     * CODE ONLY, because the comment above the fix names the call it removed —
     * and it should. The history of a defect belongs beside the thing that
     * replaced it; rewording a comment so a grep passes would delete the one
     * explanation of why this looks the way it does.
     *
     * token_get_all is the right instrument rather than a regex: it knows a
     * comment from a string from an identifier, which is the whole distinction
     * being drawn.
     */
    $code = '';

    foreach (token_get_all($ledger) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    /*
     * And the number comes from the mutable counter, not from a retry against the
     * books (B085): the retry this used to pin could never succeed inside its
     * callers' transactions, whose snapshot does not move.
     */
    expect($code)->not->toContain('lockForUpdate')
        ->and($code)->toContain("'platform_journal_sequences'")
        ->and($code)->not->toContain('REFERENCE_ATTEMPTS')
        ->and($ledger)->toContain('lockForUpdate'); // the comment that explains why

    expect(GrantedTables::CENTRAL_MUTABLE)->toContain('platform_journal_sequences')
        ->and(GrantedTables::CENTRAL_APPEND_ONLY)->not->toContain('platform_journal_sequences');
});
