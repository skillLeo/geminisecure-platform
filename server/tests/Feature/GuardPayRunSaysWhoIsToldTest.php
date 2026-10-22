<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\StatutoryRateVersion;
use App\Services\Push\PushAnnouncer;
use App\Services\Push\PushNotifier;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A guard pay run says, before it is approved, who its approval tells and what it pays (DR-073)
|--------------------------------------------------------------------------
|
| Approving a run disburses its net and pushes "Payslip ready" to each guard with a payslip on it and an estate to be
| told through (`PushAnnouncer::payslipsReady`). The QA session's confirmation names both before the press: "Approve
| the September 2026 run, $X net to N guards?". The page is handed both from the server, the count by the very query
| the push sends by, so the question and the push can never count differently.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    $db = DB::connection('mysql');
    $suffix = (string) random_int(100000, 999999);

    // In the past, where no seeded card is: no run is approved against a card before it takes effect (D-234), and it was
    // dated 2041 only to stay clear of the seeded cards (everything here is rolled back after each case).
    $card = StatutoryRateVersion::query()->create([
        'label' => 'Who is told test card '.$suffix, 'effective_from' => '2015-01-01',
        'nis_employee_bp' => 300, 'nis_employer_bp' => 300, 'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200, 'nht_employer_bp' => 300, 'education_tax_employee_bp' => 225, 'education_tax_employer_bp' => 350,
        'paye_bp' => 2500, 'paye_threshold_annual_minor' => 1_800_000_00, 'is_verified' => true,
    ]);

    $run = static fn (string $ref, string $status): int => (int) $db->table('payroll_runs')->insertGetId([
        'reference' => $ref, 'period_label' => 'January 2015', 'period_start' => '2015-01-01', 'period_end' => '2015-01-31',
        'periods_per_year' => 12, 'statutory_rate_version_id' => $card->id, 'status' => $status, 'currency' => 'JMD',
        'gross_minor' => 400_000_00, 'net_minor' => 321_234_56, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->calculated = $run('PRT-'.$suffix.'-C', 'calculated');
    $this->approved = $run('PRT-'.$suffix.'-A', 'approved');

    $guard = static fn (string $name): int => (int) $db->table('guards')->insertGetId([
        'full_name' => $name.' '.$suffix, 'employee_number' => 'PRT'.$suffix.substr(md5($name), 0, 6),
        'psra_number' => 'PRT'.$suffix.substr(md5($name), 0, 6), 'psra_expires_on' => '2045-06-30',
        'employment_type' => 'full_time', 'status' => 'active', 'reviewer' => false, 'hired_on' => '2014-01-10',
        'tenant_id' => FacilitiesFixture::ESTATE, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Three guards at an estate, and one between postings: a payslip with no estate tells nobody.
    foreach (['Ann At An Estate', 'Bev At An Estate', 'Cal At An Estate', 'Dee Between Postings'] as $name) {
        $id = $guard($name);

        foreach ([$this->calculated, $this->approved] as $runId) {
            $db->table('payslips')->insert([
                'payroll_run_id' => $runId, 'guard_id' => $id, 'tenant_id' => str_starts_with($name, 'Dee') ? null : FacilitiesFixture::ESTATE,
                'gross_minor' => 100_000_00, 'net_minor' => 80_308_64, 'currency' => 'JMD', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

it('hands a calculated run\'s page how many guards approving it tells, and its net', function (): void {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/payroll/'.$this->calculated)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Payroll/Show')
            ->where('run.told_count', 3)
            ->where('run.net', MoneyFormatter::fromMinor(321_234_56, 'JMD')));
});

it('tells, on approval, exactly the guards it counted', function (): void {
    $told = [];
    $push = Mockery::mock(PushNotifier::class);
    $push->shouldReceive('toGuards')->andReturnUsing(function (string $tenant, iterable $guards) use (&$told): int {
        foreach ($guards as $id) {
            $told[] = (int) $id;
        }

        return 0;
    });
    app()->instance(PushNotifier::class, $push);

    app(PushAnnouncer::class)->payslipsReady($this->calculated);

    expect(count(array_unique($told)))->toBe(3)
        ->and(PushAnnouncer::payslipAudience($this->calculated)->distinct()->count('guard_id'))->toBe(3);
});

it('counts nobody on a run that is no longer waiting for approval', function (): void {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))
        ->get('/payroll/'.$this->approved)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('run.told_count', null)
            ->where('run.net', MoneyFormatter::fromMinor(321_234_56, 'JMD')));
});
