<?php

declare(strict_types=1);

use App\Api\Catalogue;
use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Post;
use App\Models\StatutoryRateVersion;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Payroll\PayrollCalculator;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The payslip a guard can actually check — M8 §5.3
|--------------------------------------------------------------------------
|
| The client's words: "Guards … supposed to be able to download their payslip …
| overtime, their payments, how it is calculated — all of that is on those
| screens." The slip had five deductions and a net, every figure correct and
| none of them checkable.
|
| WHAT THIS FILE ASSERTS, and why each one is worth a test:
|
|   THE FIGURES ARE THE ENGINE'S. Not "look plausible": the same
|   `PayrollCalculator` the payroll run itself uses is called here with the same
|   inputs, and every line the handset receives is compared with its output to
|   the cent. A second implementation that agreed today and drifted in April is
|   exactly what this prevents.
|
|   EACH DEDUCTION CARRIES ITS BASE, and no two bases are the same figure. NIS
|   is capped at the period's insurable ceiling, NHT is on the whole gross,
|   Education Tax and PAYE are on statutory income. An officer checking "2.5% of
|   my pay" against a capped NIS would otherwise conclude the platform is wrong.
|
|   THE EMPLOYER'S CONTRIBUTIONS ARE NOT DEDUCTIONS. They are reported, and they
|   are never inside the deduction total — none of that money came out of the
|   wage.
|
|   IT IS STILL ONLY THEIR OWN. A colleague's payslip id answers 404, for the
|   JSON and for the PDF alike.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    $this->calculator = new PayrollCalculator;

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Payslip Gate', 'type' => 'gate', 'is_active' => true]);

    $this->guard = payslipGuard($this, 'GS-PAY-1', 'Andre Campbell');
    $this->colleague = payslipGuard($this, 'GS-PAY-2', 'Simone Grant');

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Payslip handset')['token'];

    [$this->run, $this->slip, $this->figures] = payslipRun($this, $this->guard, 176.0, 12.5);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();
});

function payslipGuard(object $test, string $number, string $name): Guard
{
    return Guard::create([
        'full_name' => $name,
        'employee_number' => $number,
        'psra_number' => 'PSRA-'.$number,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $test->post->id,
        'standard_rate_minor' => 1_050_00,
        'standard_rate_currency' => 'JMD',
    ]);
}

/**
 * One approved run with one slip on it, built through the engine.
 *
 * @return array{0: PayrollRun, 1: Payslip, 2: array<string, mixed>}
 */
function payslipRun(object $test, Guard $guard, float $regular, float $overtime): array
{
    $end = now()->startOfMonth()->subDay();
    $rates = StatutoryRateVersion::forPayDate($end);

    $run = PayrollRun::create([
        'reference' => 'TEST-'.$guard->employee_number.'-'.$end->format('Y-m'),
        'period_label' => $end->format('F Y'),
        'period_start' => $end->copy()->startOfMonth()->toDateString(),
        'period_end' => $end->toDateString(),
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $rates->id,
        'status' => 'calculated',
        'currency' => 'JMD',
        'gross_minor' => 0,
        'net_minor' => 0,
    ]);

    $earnings = $test->calculator->earnings($regular, $overtime, (int) $guard->standard_rate_minor, 15_000, 3_500_00);
    $slipFigures = $test->calculator->payslip($earnings['gross_minor'], $rates, 12);
    $employer = $test->calculator->employerCost($earnings['gross_minor'], $rates, 12);

    $slip = Payslip::create([
        'payroll_run_id' => $run->id,
        'guard_id' => $guard->id,
        'tenant_id' => FacilitiesFixture::ESTATE,
        ...$earnings,
        ...$slipFigures,
        'employer_nis_minor' => $employer['nis_minor'],
        'employer_nht_minor' => $employer['nht_minor'],
        'employer_education_tax_minor' => $employer['education_tax_minor'],
        'employer_heart_minor' => $employer['heart_minor'],
        'currency' => 'JMD',
    ]);

    $run->forceFill(['gross_minor' => $earnings['gross_minor'], 'net_minor' => $slipFigures['net_minor'], 'status' => 'approved', 'approved_at' => now()])->save();

    return [$run, $slip, ['earnings' => $earnings, 'slip' => $slipFigures, 'employer' => $employer, 'rates' => $rates]];
}

function payslipCall(TestCase $test, string $token, string $name, array $params = [], ?int $status = null): TestResponse
{
    app('auth')->forgetGuards();

    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    $response = $test->withToken($token)->get($uri);
    $response->assertStatus($status ?? $entry['status']);

    FacilitiesFixture::boot();

    return $response;
}

/** The money a decimal string carries, in minor units — so a test compares cents, never strings. */
function minor(string $decimal): int
{
    return (int) round(((float) $decimal) * 100);
}

it('carries the hours, the rates and the earnings the gross is made of — all from the engine', function () {
    app('auth')->forgetGuards();
    $slip = $this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items.0');

    $earnings = $this->figures['earnings'];

    expect($slip['hours']['regular'])->toBe('176.00')
        ->and($slip['hours']['overtime'])->toBe('12.50')
        ->and(minor($slip['hours']['regular_rate']))->toBe(1_050_00)
        // Time and a half, worked out by the engine and never keyed as a second rate.
        ->and(minor($slip['hours']['overtime_rate']))->toBe(1_575_00)
        ->and(minor($slip['earnings']['basic']))->toBe($earnings['basic_minor'])
        ->and(minor($slip['earnings']['overtime']))->toBe($earnings['overtime_minor'])
        ->and(minor($slip['earnings']['allowances']))->toBe($earnings['allowances_minor'])
        ->and(minor($slip['earnings']['gross']))->toBe($earnings['gross_minor'])
        // The three earnings ARE the gross. A slip where they do not add up is unreadable.
        ->and($earnings['basic_minor'] + $earnings['overtime_minor'] + $earnings['allowances_minor'])->toBe($earnings['gross_minor']);
});

it('gives every deduction its rate AND the figure it was taken on, and no two bases are the same', function () {
    app('auth')->forgetGuards();
    $slip = $this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items.0');

    $engine = $this->figures['slip'];
    $lines = collect($slip['lines'])->keyBy('key');

    expect(minor($lines['nis']['amount']))->toBe($engine['nis_minor'])
        ->and(minor($lines['nht']['amount']))->toBe($engine['nht_minor'])
        ->and(minor($lines['education_tax']['amount']))->toBe($engine['education_tax_minor'])
        ->and(minor($lines['paye']['amount']))->toBe($engine['paye_minor'])

        // The bases, each the engine's own — and each a DIFFERENT figure, which is the whole point.
        ->and(minor($lines['nis']['base']))->toBe($engine['nis_base_minor'])
        ->and(minor($lines['nht']['base']))->toBe($engine['nht_base_minor'])
        ->and(minor($lines['education_tax']['base']))->toBe($engine['education_tax_base_minor'])
        ->and(minor($lines['paye']['base']))->toBe($engine['paye_base_minor'])
        ->and($engine['nht_base_minor'])->not->toBe($engine['education_tax_base_minor'])

        // A rate a person reads, not basis points.
        ->and($lines['nis']['rate'])->toMatch('/^\d+(\.\d+)?%$/')
        ->and($lines['paye']['rate'])->toMatch('/^\d+(\.\d+)?%$/');

    // And the total is the five lines, with nothing of the employer's in it.
    expect(minor($slip['total_deductions']))->toBe($engine['nis_minor'] + $engine['nht_minor'] + $engine['education_tax_minor'] + $engine['paye_minor'] + $engine['pension_minor'])
        ->and(minor($slip['net']))->toBe($engine['net_minor'])
        ->and(minor($slip['earnings']['gross']) - minor($slip['total_deductions']))->toBe(minor($slip['net']));
});

it('reports the employer’s own contributions, and never inside the deductions', function () {
    app('auth')->forgetGuards();
    $slip = $this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items.0');

    $employer = $this->figures['employer'];

    expect(minor($slip['employer_contributions']['nis']))->toBe($employer['nis_minor'])
        ->and(minor($slip['employer_contributions']['nht']))->toBe($employer['nht_minor'])
        ->and(minor($slip['employer_contributions']['education_tax']))->toBe($employer['education_tax_minor'])
        ->and(minor($slip['employer_contributions']['heart']))->toBe($employer['heart_minor'])
        ->and(minor($slip['employer_contributions']['total']))->toBe($employer['nis_minor'] + $employer['nht_minor'] + $employer['education_tax_minor'] + $employer['heart_minor'])

        // Not one cent of it is in the officer's deductions.
        ->and(minor($slip['total_deductions']))->toBeLessThan(minor($slip['earnings']['gross']))
        ->and($slip['employer_contributions']['note'])->toContain('Not taken from it');
});

it('adds up the tax year so far, and counts only the periods it actually has', function () {
    // A second, earlier period for the same officer — so year-to-date is two periods, not one.
    $end = now()->startOfMonth()->subMonth()->subDay();
    $rates = StatutoryRateVersion::forPayDate($end);
    $earlier = PayrollRun::create([
        'reference' => 'TEST-EARLIER-'.$end->format('Y-m'),
        'period_label' => $end->format('F Y'),
        'period_start' => $end->copy()->startOfMonth()->toDateString(),
        'period_end' => $end->toDateString(),
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $rates->id,
        'status' => 'calculated',
        'currency' => 'JMD',
        'gross_minor' => 0,
        'net_minor' => 0,
    ]);

    $earnings = $this->calculator->earnings(160.0, 0.0, 1_050_00, 15_000, 0);
    $figures = $this->calculator->payslip($earnings['gross_minor'], $rates, 12);

    Payslip::create([
        'payroll_run_id' => $earlier->id, 'guard_id' => $this->guard->id, 'tenant_id' => FacilitiesFixture::ESTATE,
        ...$earnings, ...$figures,
        'employer_nis_minor' => 0, 'employer_nht_minor' => 0, 'employer_education_tax_minor' => 0, 'employer_heart_minor' => 0,
        'currency' => 'JMD',
    ]);

    $earlier->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

    app('auth')->forgetGuards();
    $newest = $this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items.0');

    $bothInSameYear = now()->startOfMonth()->subDay()->year === $end->year;

    expect($newest['year_to_date']['periods'])->toBe($bothInSameYear ? 2 : 1);

    if ($bothInSameYear) {
        expect(minor($newest['year_to_date']['gross']))->toBe($this->figures['earnings']['gross_minor'] + $earnings['gross_minor']);
    }
});

it('says plainly what it does not hold, rather than printing a blank that reads as zero', function () {
    app('auth')->forgetGuards();
    $slip = $this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items.0');

    // HR's record, not this platform's. Null is the honest answer and the app draws "Not on file".
    expect($slip['employee']['trn'])->toBeNull()
        ->and($slip['employee']['nis_number'])->toBeNull()
        ->and($slip['employee']['employee_number'])->toBe('GS-PAY-1')
        // The employer is the security company, never the community the officer stands at.
        ->and($slip['employer']['name'])->not->toBe('')
        ->and($slip['employer']['name'])->not->toContain('Phoenix');
});

it('draws no hours at all for a slip written before they were recorded', function () {
    /*
     * A slip from before this build: a gross, five deductions and a net, and no breakdown at all.
     * Written as a NEW slip rather than by blanking the one above, because a payslip on an approved
     * run is append-only — the database refuses the update, which is the correct answer and worth
     * knowing: a wage that was approved cannot be quietly rewritten, by this test or by anybody.
     */
    $end = now()->startOfMonth()->subMonths(2)->subDay();
    $rates = StatutoryRateVersion::forPayDate($end);

    $older = PayrollRun::create([
        'reference' => 'TEST-OLDSHAPE-'.$end->format('Y-m'),
        'period_label' => $end->format('F Y'),
        'period_start' => $end->copy()->startOfMonth()->toDateString(),
        'period_end' => $end->toDateString(),
        'periods_per_year' => 12,
        'statutory_rate_version_id' => $rates->id,
        'status' => 'calculated',
        'currency' => 'JMD',
        'gross_minor' => 38_450_00,
        'net_minor' => 33_450_00,
    ]);

    Payslip::create([
        'payroll_run_id' => $older->id, 'guard_id' => $this->guard->id, 'tenant_id' => FacilitiesFixture::ESTATE,
        'gross_minor' => 38_450_00, 'nis_minor' => 1_153_50, 'nht_minor' => 769_00, 'education_tax_minor' => 839_03,
        'paye_minor' => 2_238_47, 'pension_minor' => 0, 'net_minor' => 33_450_00,
        'employer_nis_minor' => 0, 'employer_nht_minor' => 0, 'employer_education_tax_minor' => 0, 'employer_heart_minor' => 0,
        'currency' => 'JMD',
    ]);

    $older->forceFill(['status' => 'approved', 'approved_at' => now()])->save();

    app('auth')->forgetGuards();
    $items = collect($this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items'));
    $old = $items->firstWhere('run_reference', $older->reference);

    expect($old['hours'])->toBeNull()
        ->and($old['earnings']['basic'])->toBeNull()
        // The wage itself is still there and still right.
        ->and($old['gross'])->toBe('38450.00')
        ->and($old['net'])->toBe('33450.00');
});

it('downloads as a PDF — and only the officer’s own', function () {
    app('auth')->forgetGuards();

    $pdf = $this->withToken($this->token)->get('/api/v1/payslips/'.$this->slip->id.'/pdf');
    $pdf->assertOk();

    expect($pdf->headers->get('content-type'))->toContain('pdf')
        ->and($pdf->headers->get('content-disposition'))->toContain('payslip-')
        // A PDF, not an error page served with a PDF's content type.
        ->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    // A colleague's slip, with this officer's token: the same answer as one that does not exist.
    [, $theirs] = payslipRun($this, $this->colleague, 160.0, 0.0);

    app('auth')->forgetGuards();
    $this->withToken($this->token)->get('/api/v1/payslips/'.$theirs->id.'/pdf')->assertStatus(404);

    app('auth')->forgetGuards();
    $mine = collect($this->withToken($this->token)->getJson('/api/v1/payslips/me')->assertOk()->json('items'))->pluck('id');
    expect($mine)->not->toContain($theirs->id);
});

it('says when the PDF was issued without naming the clock: the guard reading it is in Jamaica (Hassam, 12 Oct 2026)', function () {
    // Hassam's clock-name rule: the clock's name is dropped where the reader is certainly at the estate. The PDF said
    // "Issued October 12, 2026 at 6:20 AM Jamaica time, America/Jamaica time.", naming it twice.
    $data = null;

    View::composer('documents.payslip', function ($view) use (&$data): void {
        $data = $view->getData();
    });

    app('auth')->forgetGuards();
    $this->withToken($this->token)->get('/api/v1/payslips/'.$this->slip->id.'/pdf')->assertOk();

    expect($data)->toBeArray()
        ->and($data['issuedAt'])->toMatch('/^[A-Z][a-z]+ \d{1,2}, \d{4} at \d{1,2}:\d{2} (AM|PM)$/');

    $page = view('documents.payslip', $data)->render();

    // The clock's two names, the plain one and the zone's; "Jamaica" itself stays where the page names the country.
    expect(str_contains($page, 'Jamaica time'))->toBeFalse()
        ->and(str_contains($page, 'America/Jamaica'))->toBeFalse()
        ->and(str_contains($page, 'Issued '.$data['issuedAt'].'.'))->toBeTrue();
});

it('answers the catalogue it publishes', function () {
    $response = payslipCall($this, $this->token, 'payslips.me');

    ApiContract::assertMatches($response, 'payslips.me');
});
