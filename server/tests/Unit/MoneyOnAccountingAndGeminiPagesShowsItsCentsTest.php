<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| An amount that can carry cents shows them (Hassam, 12 Oct, batch 1: Residents' SERIOUS finding, money)
|--------------------------------------------------------------------------
|
| "$6,201" for $6,200.50 is a wrong figure on a money page. Every amount that can carry cents is written with its two
| decimals, always, on the Accounting pages and the Gemini console. The one thing kept short is a KPI tile's
| abbreviation ("$4.30M", "$412k": ChartOfAccounts.vue's `abbreviated`), which is a summary read at a glance; its
| under-a-thousand fallback still writes cents.
|
| Read from each page's source: no formatter in these pages rounds to the dollar.
|
*/

function moneyPagesInLane(): array
{
    $root = dirname(__DIR__, 2).'/resources/js/Pages/';
    $pages = [];

    foreach (['Estate/Accounting', 'Gemini'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'vue') {
                $pages[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
            }
        }
    }

    sort($pages);

    return $pages;
}

it('never formats an amount to whole dollars on a page of the Accounting or Gemini lane', function (): void {
    $root = dirname(__DIR__, 2).'/resources/js/Pages/';
    $found = [];

    foreach (moneyPagesInLane() as $page) {
        foreach (explode("\n", (string) file_get_contents($root.$page)) as $number => $line) {
            if (preg_match('/(minimum|maximum)FractionDigits:\s*0\b/', $line) === 1 || str_contains($line, '.toFixed(0)')) {
                $found[] = $page.':'.($number + 1).'  '.trim($line);
            }
        }
    }

    expect($found)->toBe([], "Whole-dollar formatters:\n".implode("\n", $found));
});

it('writes the under-a-thousand part of the chart tile with its cents too', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/Pages/Estate/Accounting/ChartOfAccounts.vue');

    // The bare `toLocaleString('en-JM')` wrote "$412.5" and "$412".
    expect(str_contains($source, "value.toLocaleString('en-JM')"))->toBeFalse('the tile writes a sum under a thousand with no fixed cents');
});
