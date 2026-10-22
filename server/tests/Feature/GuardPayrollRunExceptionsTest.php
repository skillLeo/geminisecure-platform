<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\PayrollController;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\StatutoryRateVersion;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The guard payroll's exceptions — the run page, and the list's counts — M11 A2
|--------------------------------------------------------------------------
|
| Two things are proved here.
|
| THE RUN PAGE ANSWERS FOR A SUSPENDED OFFICER WHO WAS PAID. It did not: `show()` selects
| the guard's standing as `guard_status`, and the sentence explaining the exception read
| `status` — so any paid guard who was suspended, inactive or marked licence-expired while
| their licence was still in date made `/payroll/{run}` a 500, on exactly the row that page
| exists to point at.
|
| THE LIST'S EXCEPTIONS COME FROM ONE PAIR OF READS, AND THEY ARE THE SAME EXCEPTIONS. Every
| run still under review used to ask its own two queries; `exceptionsForRuns()` asks them once
| for every run. The oracle is the pair of queries it replaced, written out longhand below,
| run for each run on its own.
|
*/

beforeEach(function (): void {
    FacilitiesFixture::platform();
    DB::connection('mysql')->beginTransaction();
    $this->withoutVite();

    if (! DB::connection('mysql')->table('statutory_rate_versions')->exists()) {
        Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);
    }

    $this->suffix = (string) random_int(100000, 999999);
    $site = FacilitiesFixture::ESTATE;
    $db = DB::connection('mysql');

    $august = Carbon::parse('2026-08-31');
    $july = Carbon::parse('2026-07-31');

    $run = static fn (string $ref, Carbon $end, string $status): int => (int) $db->table('payroll_runs')->insertGetId([
        'reference' => $ref, 'period_label' => $end->format('F Y'),
        'period_start' => $end->copy()->startOfMonth()->toDateString(), 'period_end' => $end->toDateString(),
        'periods_per_year' => 12, 'statutory_rate_version_id' => StatutoryRateVersion::forPayDate($end)->id,
        'status' => $status, 'currency' => 'JMD', 'gross_minor' => 0, 'net_minor' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->august = $run('PRX-'.$this->suffix.'-08', $august, 'calculated');
    $this->july = $run('PRX-'.$this->suffix.'-07', $july, 'calculated');

    $guard = function (string $name, string $status, string $licence, ?string $hired) use ($db, $site): int {
        return (int) $db->table('guards')->insertGetId([
            'full_name' => $name.' '.$this->suffix, 'employee_number' => 'PRX'.$this->suffix.substr(md5($name), 0, 6),
            'psra_number' => 'PRX'.$this->suffix.substr(md5($name), 0, 6), 'psra_expires_on' => $licence,
            'employment_type' => 'full_time', 'status' => $status, 'reviewer' => false,
            'hired_on' => $hired, 'tenant_id' => $site, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->g = [
        'active' => $guard('Andre Active', 'active', '2027-06-30', '2025-01-10'),
        'suspended' => $guard('Brenda Suspended', 'suspended', '2027-06-30', '2025-01-10'),
        'lapsed' => $guard('Carl Lapsed', 'active', '2026-08-15', '2025-01-10'),
        'leave' => $guard('Dionne OnLeave', 'on_leave', '2027-06-30', '2025-01-10'),
        'expired' => $guard('Errol Expired', 'licence_expired', '2026-07-20', null),
        'late' => $guard('Fay HiredLate', 'active', '2027-06-30', '2026-08-10'),
        'gone' => $guard('Glen Inactive', 'inactive', '2027-06-30', '2025-01-10'),
    ];

    $slip = static fn (int $runId, int $guardId) => $db->table('payslips')->insert([
        'payroll_run_id' => $runId, 'guard_id' => $guardId, 'tenant_id' => $site,
        'gross_minor' => 100_000_00, 'net_minor' => 80_000_00, 'currency' => 'JMD', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // August: the active, the suspended, the lapsed and the inactive are paid.
    foreach (['active', 'suspended', 'lapsed', 'gone'] as $who) {
        $slip($this->august, $this->g[$who]);
    }

    // July: only the active and the one on leave; Fay was not yet hired.
    foreach (['active', 'leave'] as $who) {
        $slip($this->july, $this->g[$who]);
    }
});

afterEach(function (): void {
    DB::connection('mysql')->rollBack();
});

/**
 * The two queries `exceptionsFor()` asked of one run before M11, written out longhand.
 *
 * @return list<array{name: string, reason: string}>
 */
function exceptionsByTheOldQueries(PayrollRun $run): array
{
    $controller = app(PayrollController::class);
    $paidReason = new ReflectionMethod($controller, 'paidWhileLapsedReason');
    $unpaidReason = new ReflectionMethod($controller, 'unpaidReason');
    $end = $run->period_end->toDateString();

    $paid = DB::connection('mysql')->table('payslips')
        ->join('guards', 'guards.id', '=', 'payslips.guard_id')
        ->where('payslips.payroll_run_id', $run->id)
        ->where(fn ($q) => $q->whereIn('guards.status', ['suspended', 'licence_expired', 'inactive'])->orWhere('guards.psra_expires_on', '<', $end))
        ->orderBy('guards.full_name')
        ->select('guards.full_name', 'guards.status', 'guards.psra_expires_on')
        ->get();

    $unpaid = DB::connection('mysql')->table('guards')
        ->whereIn('guards.status', ['active', 'on_leave', 'licence_expired', 'suspended'])
        ->where(fn ($q) => $q->whereNull('guards.hired_on')->orWhere('guards.hired_on', '<=', $end))
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payslips')->whereColumn('payslips.guard_id', 'guards.id')->where('payslips.payroll_run_id', $run->id))
        ->orderBy('guards.full_name')
        ->select('guards.full_name', 'guards.status', 'guards.psra_expires_on')
        ->get();

    $out = [];

    foreach ($paid as $guard) {
        $out[] = ['name' => (string) $guard->full_name, 'reason' => $paidReason->invoke($controller, $guard, $run->period_end)];
    }

    foreach ($unpaid as $guard) {
        $out[] = ['name' => (string) $guard->full_name, 'reason' => $unpaidReason->invoke($controller, $guard)];
    }

    usort($out, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

    return $out;
}

it('opens a run that paid a suspended officer whose licence is in date, and says why that payslip is an exception', function (): void {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $slips = [];
    $this->actingAs($director)->get('/payroll/'.$this->august)->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$slips): void {
            $slips = collect($page->toArray()['props']['payslips'])->keyBy('guard_id')->all();
        });

    expect($slips[$this->g['suspended']]['exception'])->toBeTrue()
        ->and($slips[$this->g['suspended']]['exception_reason'])->toBe('Suspended on the roster, but paid for the whole period.')
        ->and($slips[$this->g['gone']]['exception_reason'])->toBe('Inactive on the roster, but paid for the whole period.')
        ->and($slips[$this->g['lapsed']]['exception_reason'])->toStartWith('PSRA licence expired 15 Aug 2026, before the period closed on 31 Aug 2026')
        ->and($slips[$this->g['active']]['exception'])->toBeFalse();
});

it('finds, for every run under review at once, exactly the exceptions each run\'s own two queries found', function (): void {
    $runs = PayrollRun::query()->whereIn('id', [$this->august, $this->july])->get();
    $batched = (new ReflectionMethod(PayrollController::class, 'exceptionsForRuns'))->invoke(app(PayrollController::class), $runs);

    foreach ($runs as $run) {
        $mine = array_values(array_filter($batched[$run->id], fn (array $e): bool => str_ends_with($e['name'], $this->suffix)));
        $theirs = array_values(array_filter(exceptionsByTheOldQueries($run), fn (array $e): bool => str_ends_with($e['name'], $this->suffix)));

        // The whole list — every guard on the platform the old queries would have named, not only this file's.
        expect($batched[$run->id])->toBe(exceptionsByTheOldQueries($run), "run {$run->reference}")
            ->and($mine)->not->toBe([]);
    }

    // And the file's own guards, by name: who is an exception on which run, and why.
    $names = static fn (array $list): array => array_map(static fn (array $e): string => strtok($e['name'], ' ').' — '.$e['reason'], $list);
    $august = array_values(array_filter($batched[$this->august], fn (array $e): bool => str_ends_with($e['name'], $this->suffix)));

    expect($names($august))->toBe([
        'Brenda — Suspended on the roster, but paid for the whole period.',
        'Carl — PSRA licence expired 15 Aug 2026, before the period closed on 31 Aug 2026, but paid for the whole period.',
        'Dionne — On the roster for this period, with no payslip in this run.',
        'Errol — PSRA licence expired 20 Jul 2026: no payslip in this run, so the period is unpaid.',
        'Fay — On the roster for this period, with no payslip in this run.',
        'Glen — Inactive on the roster, but paid for the whole period.',
    ]);
});

it('hands the run page what it reads: the guards link, whether the role approves, the run\'s state (the payroll pass)', function (): void {
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->get('/payroll/'.$this->august)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Payroll/Show')
            // Sent as `can_open_guards`, it never reached the page's `canOpenGuards`: no name linked for any role.
            ->where('canOpenGuards', true)
            ->where('approval.holds', true)
            ->where('run.badge_label', fn ($label): bool => str_starts_with((string) $label, 'Action required · '))
            // The sentence the approver ticks, without an em dash (Law 3).
            ->where('approval.acknowledgement.statement', fn ($statement): bool => ! str_contains((string) $statement, '—')));

    $this->actingAs(FacilitiesFixture::geminiViewer(Role::OPERATIONS_MANAGER))->get('/payroll/'.$this->august)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('approval.holds', false));
});

it('badges a run approved but not yet paid as Confirmed with Unpaid beside it (Hassam\'s ruling, 11 Oct)', function (): void {
    // The newest period, so it is on the list's first page whatever else the platform holds; rolled back after.
    $approved = (int) DB::connection('mysql')->table('payroll_runs')->insertGetId([
        'reference' => 'PRX-'.$this->suffix.'-10', 'period_label' => 'October 2026',
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'periods_per_year' => 12,
        'statutory_rate_version_id' => StatutoryRateVersion::forPayDate(Carbon::parse('2026-10-31'))->id,
        'status' => 'approved', 'currency' => 'JMD', 'gross_minor' => 0, 'net_minor' => 0,
        'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $runs = [];
    $this->actingAs(FacilitiesFixture::geminiViewer(Role::DIRECTOR))->get('/payroll')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$runs): void {
            $runs = collect($page->toArray()['props']['runs'])->keyBy('id')->all();
        });

    // A workflow state and a money flag beside it; it read "Approved Oct 11", then "Confirmed · Oct 11".
    expect($runs[$approved]['badge_label'])->toBe('Confirmed · Unpaid');
});

it('offers approving on the run list only to a role that ever approves a run (the payroll pass, 11 Oct)', function (): void {
    $label = function (string $role): string {
        $runs = [];
        $this->actingAs(FacilitiesFixture::geminiViewer($role))->get('/payroll')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$runs): void {
                $runs = collect($page->toArray()['props']['runs'])->keyBy('id')->all();
            });

        return (string) $runs[$this->august]['action_label'];
    };

    // The Director approves guard runs; the Operations Manager reads payroll and never approves (the role grid).
    expect($label(Role::DIRECTOR))->toBe('Review & approve')
        ->and($label(Role::OPERATIONS_MANAGER))->toBe('Review');
});

it('draws the payroll list with every run under review badged from the one pair of reads', function (): void {
    $director = FacilitiesFixture::geminiViewer(Role::DIRECTOR);

    $runs = [];
    $this->actingAs($director)->get('/payroll')->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$runs): void {
            $runs = collect($page->toArray()['props']['runs'])->keyBy('id')->all();
        });

    $count = static fn (int $runId): int => count(exceptionsByTheOldQueries(PayrollRun::query()->findOrFail($runId)));

    // Each badge carries its run's own exception count, whatever the other runs hold, after the one vocabulary's word
    // for the state (M96, M99: it read "3 exceptions" alone).
    foreach ([$this->august, $this->july] as $runId) {
        $n = $count($runId);

        expect($n)->toBeGreaterThan(0)
            ->and($runs[$runId]['badge_label'])->toBe('Action required · '.$n.' exception'.($n === 1 ? '' : 's'));
    }
});
