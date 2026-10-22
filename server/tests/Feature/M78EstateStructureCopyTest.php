<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\AccountingController;
use App\Http\Controllers\Estate\DashboardController;
use App\Http\Controllers\Estate\PayablesController;
use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Services\Estate\EstateStructure;
use App\Services\Estate\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| M78 §3: the Estate structure page in plain words (10 October 2026)
|--------------------------------------------------------------------------
|
| Hassam's exact copy. The figures read Allowed, On the register, On the site plan and Left. Under them, only "Your
| estate is full. Please contact your service provider to add more." when nothing is left. The lots off the site plan
| were named under them too ("Lot 1999A is not on the site plan.") until Hassam, 12 October: "remove the text"; the
| two figures already differ, and the lot itself is untouched. A phase card's line is "19 blocks · 3 officers", its
| blocks open from "Show blocks", and the add tile, when nothing can be added, is visibly off and says "No lots left."
| No number moves.
|
| THE ESTATE DATABASE IS COMMITTED AND SHARED: the one phase row this file makes is taken back.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();
});

it('never names the lots off the site plan on the page (Hassam, 12 Oct: "remove the text")', function () {
    // The sentence is gone from the server and the page; the list of lots stays, as data nobody reads aloud.
    expect(method_exists(EstateStructure::class, 'outsideLine'))->toBeFalse()
        ->and(array_key_exists('outside_line', app(EstateStructure::class)->standing()))->toBeFalse();

    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Structure/Index.vue'));
    expect(str_contains($page, 'outside_line'))->toBeFalse()
        ->and(str_contains($page, 'not on the site plan'))->toBeFalse();
});

it('says a full estate is full in Hassam\'s words, and nothing about the contract', function () {
    // A site plan of one lot, the estate's lowest, so the plan is full and every other unit is off it.
    $lowest = (int) Unit::query()->get(['reference'])
        ->map(static fn (Unit $u): int => preg_match('/^lot\s+(\d+)$/i', (string) $u->reference, $m) === 1 ? (int) $m[1] : PHP_INT_MAX)
        ->min();
    expect($lowest)->toBeLessThan(PHP_INT_MAX);

    $id = (int) DB::connection('tenant')->table('estate_phases')->insertGetId([
        'name' => 'M78 Plan', 'sequence' => 990, 'block_count' => 0, 'officers_assigned' => null, 'status' => 'active',
        'first_lot' => $lowest, 'last_lot' => $lowest, 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $standing = app(EstateStructure::class)->standing();

        expect($standing['built'])->toBe(1)
            ->and($standing['units'])->toBe(1)
            ->and($standing['register'])->toBe(Unit::query()->count())
            ->and($standing['left'])->toBe(0)
            ->and($standing['full'])->toBeTrue()
            ->and($standing['sentence'])->toBe('Your estate is full. Please contact your service provider to add more.')
            ->and($standing['add_refusal'])->toBe('No lots left.')
            // Every other unit is off this one-lot plan: listed, and said nowhere.
            ->and($standing['outside_plan'])->not->toBe([])
            ->and(array_key_exists('outside_line', $standing))->toBeFalse()
            ->and(array_key_exists('differs', $standing))->toBeFalse();

        foreach ([$standing['sentence'], $standing['add_refusal']] as $line) {
            expect(strtolower((string) $line))->not->toContain('contract')->not->toContain('geminisecure');
        }
    } finally {
        DB::connection('tenant')->table('estate_phases')->where('id', $id)->delete();
    }
});

it('reads a phase card as blocks and officers, nothing more', function () {
    $letters = static fn (int $n): array => array_map(static fn (string $l): array => ['letter' => $l, 'units' => 1, 'lots' => [[1, 1]]], array_slice(range('A', 'Z'), 0, $n));

    expect((new Phase(['first_lot' => 1, 'last_lot' => 356, 'blocks' => $letters(19), 'officers_assigned' => 3]))->footLine())->toBe('19 blocks · 3 officers')
        ->and((new Phase(['first_lot' => 1, 'last_lot' => 9, 'blocks' => $letters(1), 'officers_assigned' => 1]))->footLine())->toBe('1 block · 1 officer')
        ->and((new Phase(['first_lot' => 1, 'last_lot' => 9, 'blocks' => [], 'officers_assigned' => 2]))->footLine())->toBe('No blocks drawn · 2 officers')
        ->and((new Phase(['first_lot' => 1, 'last_lot' => 9, 'blocks' => [], 'officers_assigned' => null, 'footnote' => 'new phase, officers pending']))->footLine())->toBe('No blocks drawn · officers pending')
        ->and((new Phase(['block_count' => 6, 'officers_assigned' => null, 'footnote' => 'new phase, officers pending']))->footLine())->toBe('6 blocks · officers pending');
});

it('says the rest of this lane\'s refusals and empty pages in plain words (M78 §1, §4)', function () {
    expect(Payroll::PAID_EXCEPTION)->toBe('This run has been paid, so its exceptions cannot change.')
        ->and(PayablesController::NO_TICKET_ACCESS)->toBe('Opening the work order needs view access on Facilities.');

    // Each changed line, present; the words it replaced, gone.
    $lines = [
        'js/Pages/Estate/Payroll/Exceptions.vue' => [["reason: 'Already resolved.'"], ['the record kept']],
        'js/Pages/Estate/Dashboard.vue' => [['title="No activity yet"', 'body="Payments, incidents and bookings appear here as they happen."'], ['may read the record it comes from']],
        'js/Pages/Estate/Activity/Index.vue' => [['title="No activity yet"', 'body="Every row is on an earlier page."'], ['may read the record it comes from', 'invented to fill the space']],
        'js/Pages/Estate/Structure/Index.vue' => [
            ['units allowed</template>', "'Import a unit list from a CSV file.'", 'body="Add a phase above, or import the unit list."', 'body="Nothing has changed. Please try again."', 'body="Ask your estate administrator if you need it."'],
            ['contracted units</template>', 'A phase is what gives a block', 'one bad row is rejected whole', 'The estate database did not answer', 'role access matrix'],
        ],
        'js/Pages/Estate/Security/Index.vue' => [['<template v-if="live">Live.</template>', 'Live alerts are down. This list is checked every'], ['Connecting to the live alert channel', 'is re-read every']],
    ];

    foreach ($lines as $path => [$present, $gone]) {
        $source = (string) file_get_contents(resource_path($path));

        foreach ($present as $words) {
            expect(str_contains($source, $words))->toBeTrue($path.' is missing: '.$words);
        }

        foreach ($gone as $words) {
            expect(str_contains($source, $words))->toBeFalse($path.' still says: '.$words);
        }
    }

    expect(str_contains((string) file_get_contents(app_path('Services/Estate/Ledger.php')), 'used is on the record'))->toBeFalse();

    // The pay run's approve reasons, one line each; the first-run statement without its em dash.
    $payroll = (string) file_get_contents(app_path('Services/Estate/Payroll.php'));
    expect(str_contains($payroll, "'Approving a pay run needs the Approve pay runs permission on Payroll & HR.'"))->toBeTrue()
        ->and(str_contains($payroll, 'releases money to four people'))->toBeFalse()
        ->and(str_contains($payroll, 'carries no verified TAJ periodic'))->toBeFalse()
        ->and(str_contains($payroll, 'TAJ tables — rate card'))->toBeFalse();

    // The reports' refusals, one line each, naming the access and nothing about how the console works.
    $reports = (string) file_get_contents(app_path('Services/Estate/Reports.php')).(string) file_get_contents(app_path('Http/Controllers/Estate/ReportsController.php'));
    foreach (["'Running the Profit & Loss needs view access on Accounting.'", "'Generating a report needs Reports export access.'",
        "'Budgets are coming.'"] as $words) {
        expect(str_contains($reports, $words))->toBeTrue('reports are missing: '.$words);
    }
    foreach (['Your role does not hold', 'takes a copy of the estate', 'nothing on this platform records one', 'summed from posted journal lines'] as $words) {
        expect(str_contains($reports, $words))->toBeFalse('reports still say: '.$words);
    }
});

it('gives every tooltip in accounting, reports and the dashboard one short line saying what the control does (M78 §2)', function () {
    $tooltips = [
        'js/Pages/Estate/Dashboard.vue' => [['`${notifications.count} unread`', 'title="Open the full activity log."'], ['you have not looked at yet', 'The full log —']],
        'js/Pages/Estate/Reports/Show.vue' => [['title="Download this report as a CSV."'], ['how many rows it held']],
        // M97: Add vendor is drawn only for a role that may add (M96 level 3), so its tooltip is a plain attribute; the
        // modal's greyed button says why on the screen (`confirm-blocked-reason`) where the panel's button said it on hover.
        'js/Pages/Estate/Accounting/Vendors.vue' => [['title="Add a supplier."', "'Enter the supplier’s name.'"], ['The TRN can follow', 'open their record']],
        'js/Pages/Estate/Accounting/Vendor.vue' => [['`Record a bill from ${vendor.name}.`', '`Edit ${vendor.name}.`', "'Enter the invoice amount.'"], ['posts the liability', 'the TRN decides', 'as a draft on the register']],
        'js/Pages/Estate/Accounting/ChartOfAccounts.vue' => [['title="Add an account."', "'Tick the review first.'", 'Nothing new can post to it.`'], ['It is read back to you', 'It is never removed.`']],
        'js/Pages/Estate/Accounting/Bills.vue' => [["'Record a bill.'", "'Enter the invoice amount.'", "'Approve this bill.'", "'Void this draft bill.'", '`Pay ${row.vendor.name}.`'], ['debit 2000 Accounts Payable', 'Nothing has posted, so nothing is reversed', 'approving it is what posts']],
        'js/Pages/Estate/Payroll/Run.vue' => [["'Say what is wrong first.'", "'Download the accountant’s summary or the bank file.'", 'has not been approved yet.`'], ['guessing which of four payslips', 'they are different documents', 'an instruction to pay figures']],
        'js/Pages/Estate/Payroll/Runs.vue' => [['`Start the ${calendar.label} pay run as a draft.`'], ['nothing is calculated or paid yet']],
        'js/Pages/Estate/Payroll/Employees.vue' => [["'Add someone to the payroll.'", "'Tick the consent box first.'", 'title="An approved pension contribution, if any. Leave it empty if none."'], ['hold for seven years', 'comes off statutory income']],
        'js/Pages/Estate/Payroll/Filings.vue' => [['title="Every return due in the year ahead."'], ['projected for periods it does not hold']],
    ];

    foreach ($tooltips as $path => [$present, $gone]) {
        $source = (string) file_get_contents(resource_path($path));

        foreach ($present as $words) {
            expect(str_contains($source, $words))->toBeTrue($path.' is missing: '.$words);
        }

        foreach ($gone as $words) {
            expect(str_contains($source, $words))->toBeFalse($path.' still says: '.$words);
        }
    }
});

it('says why a control is off in one line, the access it needs, on this lane\'s screens (M78 §2)', function () {
    // The dashboard's quick actions say nothing now: a role without the access is not shown the action at all (M98).
    expect(defined(DashboardController::class.'::NO_NOTICE_ACCESS'))->toBeFalse()
        ->and(PayablesController::NO_CREATE_ACCESS)->toBe('Adding a supplier or a bill needs Accounting create access.')
        ->and(AccountingController::NO_CONFIGURE_ACCESS)->toBe('Changing the chart of accounts needs Accounting configure access.');

    foreach (['Dashboard', 'Payables', 'Payroll', 'Accounting', 'EstateStructure'] as $name) {
        $source = (string) file_get_contents(app_path('Http/Controllers/Estate/'.$name.'Controller.php'));

        expect(str_contains($source, 'You are able to read'))->toBeFalse($name.' still says "You are able to read"')
            ->and(str_contains($source, 'does not carry it'))->toBeFalse($name)
            ->and(str_contains($source, 'imported whole or not at all'))->toBeFalse($name);
    }
});

it('keeps every empty, refused and failed page in this lane to one plain line (M78 §2)', function () {
    $pages = ['Activity/Index', 'Reports/Index', 'Reports/Show', 'Security/Index', 'Structure/Index', 'Dashboard', 'Payroll/Filings', 'Payroll/Employees',
        'Payroll/Runs', 'Payroll/Run', 'Payroll/Exceptions', 'Accounting/Bills', 'Accounting/Vendors', 'Accounting/Vendor', 'Accounting/Reconciliation', 'Accounting/ChartOfAccounts'];

    foreach ($pages as $page) {
        $source = (string) file_get_contents(resource_path('js/Pages/Estate/'.$page.'.vue'));

        // Two lines and more is how the system works, not what the reader needs.
        expect(preg_match_all('/\bbody="[^"]{140,}"/u', $source))->toBe(0, $page.' has an empty-page text over one line');
    }

    $refused = (string) file_get_contents(resource_path('js/Pages/Estate/Accounting/Bills.vue'));
    expect(str_contains($refused, 'body="Ask your estate administrator if you need it."'))->toBeTrue()
        ->and(str_contains($refused, 'by platform rule'))->toBeFalse();
});

it('says "contract" nowhere a person reads in the estate console (M81 §2.4)', function () {
    // Every estate page, the shell and the shared components. "contractor" is another word and stays.
    $files = collect(File::allFiles(resource_path('js/Pages/Estate')))
        ->merge([resource_path('js/Layouts/EstateConsole.vue')])
        ->merge(File::allFiles(resource_path('js/Components')));

    /*
     * And the ⓘ texts an estate page opens. infoTopics.js itself stays as it is: it is pinned with the classic Gemini
     * pages (ClassicWorkforcePagesAreUnchangedTest, M81 §1.2.5), so an estate page stops opening a topic rather than the
     * file changing. Each topic an estate page names is read here.
     */
    $topics = File::get(resource_path('js/lib/infoTopics.js'));
    foreach (File::allFiles(resource_path('js/Pages/Estate')) as $page) {
        preg_match_all('/topic="(\w+)"/', File::get((string) $page), $used);

        foreach ($used[1] as $topic) {
            /*
             * THE ONE EXCEPTION, written down: "lotRanges" (the lots ⓘ in the add-phase form) still names the contracted
             * units. Its words live in infoTopics.js, pinned with the classic Gemini pages, and every topic must stand on
             * a page (info-buttons.test.mjs), so neither can move tonight; Hassam decides (STATE, 10 Oct 18:10). The form
             * never opens on a plan-built estate such as Phoenix Park.
             */
            if ($topic === 'lotRanges') {
                continue;
            }

            preg_match('/\n    '.$topic.': \{.*?\n    \},/s', $topics, $block);
            expect($block)->not->toBe([], 'no ⓘ topic called '.$topic)
                ->and(preg_match('/\b[Cc]ontract(?:s|ed|ual)?\b/', $block[0] ?? ''))->toBe(0, $page.' opens the ⓘ "'.$topic.'", which says "contract"');
        }
    }

    foreach ($files as $file) {
        $path = (string) $file;
        $source = (string) file_get_contents($path);

        // What a person reads: a page's template (not its script or style), without HTML comments.
        $readable = str_ends_with($path, '.vue')
            ? (string) preg_replace('/<!--.*?-->/s', '', (string) preg_replace('/^.*?<template>|<style\b.*$/s', '', $source))
            : $source;

        preg_match_all('/.{0,30}\b[Cc]ontract(?:s|ed|ual)?\b.{0,30}/', $readable, $hits);
        // A data value or a prop's name is not something a person reads.
        $hits = array_values(array_filter($hits[0], static fn (string $hit): bool => ! preg_match('/standing\.contracted|contract\.(units|contracted)|v-if="contract"|value="contract"/', $hit)));

        expect($hits)->toBe([], $path.' says "contract" where a person reads it');
    }
});

it('draws the figures, the lines under them and the add tile in Hassam\'s words', function () {
    $page = (string) file_get_contents(resource_path('js/Pages/Estate/Structure/Index.vue'));

    foreach (['<dt>Allowed</dt>', '<dt>On the register</dt>', '<dt>On the site plan</dt>', '<dt>Left</dt>', '<summary>Show blocks</summary>',
        '{{ standing.add_refusal }}', 'button.phase-card[disabled]'] as $words) {
        expect(str_contains($page, $words))->toBeTrue('missing: '.$words);
    }

    // The header's sentence, the three old lines, the contract's words, the ⓘ beside the figure and (Hassam, 12 Oct)
    // the line naming the lots off the site plan are gone.
    foreach (['class="str-full-note"', 'standing.differs', 'Contracted', 'contracted units in use', 'lots on the site plan in use',
        'One unit on the register is', '<dt>Built</dt>', 'Show the {{', 'topic="contractedLimit"', 'standing.outside_line'] as $gone) {
        expect(str_contains($page, $gone))->toBeFalse('still there: '.$gone);
    }
});
