<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Support\Decimal;
use App\Support\PlatformCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The guard's own payslips (13 D2, and M8 §5.3 in full). Board guard-app-05 screen 20.
 *
 * THE ONE MONEY A GUARD'S HANDSET MAY READ, AND ONLY THEIR OWN. The query is keyed on the token's
 * guard and nothing in the request can widen it. No household figure is reachable from here:
 * payslips are central and hold wages. `ApiContract` exempts this route, by name, from the
 * invariant-2 money scan and no other guard route.
 *
 * APPROVED RUNS ONLY. A draft run's figures are a calculation in progress; a guard shown one would
 * plan around a net pay that can still change.
 *
 * WHY IT CARRIES THE ARITHMETIC AND NOT ONLY THE ANSWER (M8 §5.3). The client's words were
 * "overtime, their payments, how it is calculated — all of that is on those screens". A slip that
 * shows five deductions and a net asks an officer to take the figure on trust: they cannot see the
 * hours they were paid for, the rate each was paid at, or — the one that matters most — what each
 * deduction was actually taken ON, which is a different figure for every one of them. So the hours,
 * the rates, the earnings that make up the gross, and each deduction's rate AND base are all here.
 *
 * WHAT IS NOT HERE BECAUSE THE PLATFORM DOES NOT HOLD IT: the officer's TRN and NIS number. They
 * are HR's, they are not in `guards`, and inventing a place for them on a wage document would be
 * worse than the blank. The screen says "not on file" and `QUESTIONS.md` asks for them.
 *
 * DECIMAL STRINGS, NOT FLOATS. "38450.00" is exactly what the payslip says; a float is what a phone
 * rounds. Hours are decimal strings for the same reason — 6.25 is a quarter hour, not 6.249999.
 */
class PayslipsController extends Controller
{
    public function mine(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $slips = Payslip::query()->with(['run.rateVersion'])
            ->where('guard_id', $guard->id)
            ->whereHas('run', static fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->get()
            ->sortByDesc(static fn (Payslip $p) => $p->run->period_end)
            ->values();

        $items = $slips->take(24)->map(fn (Payslip $p): array => $this->shape($p, $guard->full_name, $guard->employee_number, $slips))->all();

        return response()->json(['items' => $items]);
    }

    /**
     * The same payslip as a PDF, to keep or to send on (M8 §5.3).
     *
     * ONLY THEIR OWN, and the check is the same one `mine()` makes: the payslip is found by id AND
     * by the token's guard, so an id belonging to a colleague is a 404 and not a document.
     */
    public function pdf(int $payslip, DeviceContext $context): Response
    {
        $guard = $context->guardOrFail();

        $slip = Payslip::query()->with(['run.rateVersion'])
            ->whereKey($payslip)
            ->where('guard_id', $guard->id)
            ->whereHas('run', static fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->first();

        if (! $slip instanceof Payslip) {
            throw ApiError::notFound('not_found', 'No payslip of yours with that id, on an approved run.');
        }

        $all = Payslip::query()->with('run')
            ->where('guard_id', $guard->id)
            ->whereHas('run', static fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->get();

        $view = Pdf::loadView('documents.payslip', [
            'slip' => $this->shape($slip, $guard->full_name, $guard->employee_number, $all),
            'employer' => $this->employer(),
            /*
             * Gemini's paper, on Gemini's clock (M11 §4.3). This was the server's UTC with nothing saying so. The clock's
             * name is not printed (Hassam's clock-name rule, 12 Oct 2026: "the clock name is DROPPED where the reader is
             * certainly at the estate"; "The guard is standing in Jamaica reading it."). It read "Jamaica time, America/
             * Jamaica time", twice.
             */
            'issuedAt' => PlatformCalendar::local(Carbon::now())->format('F j, Y \a\t g:i A'),
        ])->setPaper('a4');

        $name = 'payslip-'.Carbon::parse($slip->run->period_end)->format('Y-m').'-'.$guard->employee_number.'.pdf';

        return $view->download($name);
    }

    /**
     * One payslip, in full.
     *
     * @param  Collection<int, Payslip>|\Illuminate\Database\Eloquent\Collection<int, Payslip>  $all
     * @return array<string, mixed>
     */
    private function shape(Payslip $p, string $name, string $employeeNumber, $all): array
    {
        $run = $p->run;
        $rates = $run->rateVersion;
        $periods = max(1, (int) $run->periods_per_year);

        return [
            'id' => $p->id,
            'run_reference' => $run->reference,
            'period_label' => $run->period_label,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'status' => $run->status,
            'currency' => $p->currency,

            // The gross, at the top level, exactly where it has always been. Everything below is
            // ADDED to this shape, never instead of it: an app in somebody's hand still reads these.
            'gross' => $this->decimal($p->gross_minor),

            'employee' => [
                'name' => $name,
                'employee_number' => $employeeNumber,
                // Not held by this platform; HR's record. Never invented onto a wage document.
                'trn' => null,
                'nis_number' => null,
            ],

            'employer' => $this->employer(),

            /*
             * THE HOURS, or null when the slip predates them being recorded. A confident "0.00
             * hours" over a real wage would be a worse answer than "not on record for this period".
             */
            'hours' => $p->regular_hours === null && $p->overtime_hours === null ? null : [
                'regular' => $this->hours($p->regular_hours),
                'overtime' => $this->hours($p->overtime_hours),
                'regular_rate' => $this->decimal($p->regular_rate_minor),
                'overtime_rate' => $this->decimal($p->overtime_rate_minor),
                'overtime_note' => 'Overtime is paid at time and a half of the standard rate.',
            ],

            'earnings' => [
                'basic' => $this->decimal($p->basic_minor),
                'overtime' => $this->decimal($p->overtime_minor),
                'allowances' => $this->decimal($p->allowances_minor),
                'gross' => $this->decimal($p->gross_minor),
                // Everything a guard is paid here is taxable. Said plainly rather than left implied.
                'taxable_note' => 'Basic pay, overtime and allowances are all taxable earnings.',
            ],

            // Kept for the apps that already read it; the same five figures as `lines` below.
            'deductions' => [
                'nis' => $this->decimal($p->nis_minor),
                'nht' => $this->decimal($p->nht_minor),
                'education_tax' => $this->decimal($p->education_tax_minor),
                'paye' => $this->decimal($p->paye_minor),
                'pension' => $this->decimal($p->pension_minor),
            ],

            /*
             * EACH DEDUCTION WITH ITS RATE AND ITS BASE. Four different bases, which is exactly why
             * they are shown: NIS on gross up to the period's insurable ceiling, NHT on gross with
             * no ceiling, Education Tax and PAYE on statutory income (gross less NIS), and PAYE only
             * on what is above the threshold.
             */
            // A run always has its rate version (a required foreign key), so every rate is read off it directly.
            'lines' => [
                $this->line('nis', 'NIS', $p->nis_minor, $rates->nis_employee_bp, $p->nis_base_minor, 'On gross pay, up to the insurable ceiling for the period.'),
                $this->line('nht', 'NHT', $p->nht_minor, $rates->nht_employee_bp, $p->nht_base_minor, 'On gross pay, with no ceiling.'),
                $this->line('education_tax', 'Education Tax', $p->education_tax_minor, $rates->education_tax_employee_bp, $p->education_tax_base_minor, 'On statutory income: gross less NIS.'),
                $this->line('paye', 'PAYE (income tax)', $p->paye_minor, $rates->paye_bp, $p->paye_base_minor, $p->paye_threshold_minor === null ? 'On statutory income above the tax threshold.' : 'On statutory income above the threshold of '.$this->decimal($p->paye_threshold_minor).' for this period.'),

                // Pension only when the officer pays into a scheme; the list stays a list either way.
                ...($p->pension_minor > 0 ? [$this->line('pension', 'Approved pension', $p->pension_minor, null, null, 'Your own contribution to an approved scheme.')] : []),
            ],

            'total_deductions' => $this->decimal($p->nis_minor + $p->nht_minor + $p->education_tax_minor + $p->paye_minor + $p->pension_minor),
            'net' => $this->decimal($p->net_minor),

            /*
             * THE EMPLOYER'S OWN CONTRIBUTIONS, shown for transparency and never as a deduction:
             * none of it came out of the officer's pay. Q-002 ruled that they appear beside the
             * employee's on the S01, and an officer is entitled to see what is paid on their behalf.
             */
            'employer_contributions' => [
                'nis' => $this->decimal($p->employer_nis_minor),
                'nht' => $this->decimal($p->employer_nht_minor),
                'education_tax' => $this->decimal($p->employer_education_tax_minor),
                'heart' => $this->decimal($p->employer_heart_minor),
                'total' => $this->decimal($p->employer_nis_minor + $p->employer_nht_minor + $p->employer_education_tax_minor + $p->employer_heart_minor),
                'note' => 'Paid by Gemini Security on top of your wage. Not taken from it.',
            ],

            'year_to_date' => $this->yearToDate($p, $all),
            'paye_note' => $p->paye_note,
            'periods_per_year' => $periods,
        ];
    }

    /**
     * The tax year so far, up to and including this period.
     *
     * JAMAICA'S TAX YEAR IS THE CALENDAR YEAR, so this is every approved slip of the same year whose
     * period ends no later than this one's. It counts the slips it actually has: a guard who joined
     * in March has a year-to-date from March, which is the true answer for them.
     *
     * @param  Collection<int, Payslip>|\Illuminate\Database\Eloquent\Collection<int, Payslip>  $all
     * @return array<string, mixed>
     */
    private function yearToDate(Payslip $current, $all): array
    {
        $end = Carbon::parse($current->run->period_end);

        $inYear = collect($all)->filter(function (Payslip $p) use ($end): bool {
            $periodEnd = Carbon::parse($p->run->period_end);

            return $periodEnd->year === $end->year && $periodEnd->lessThanOrEqualTo($end);
        });

        $sum = static fn (string $column): int => (int) $inYear->sum(static fn (Payslip $p): int => (int) $p->{$column});

        return [
            'year' => (string) $end->year,
            'periods' => $inYear->count(),
            'gross' => $this->decimal($sum('gross_minor')),
            'nis' => $this->decimal($sum('nis_minor')),
            'nht' => $this->decimal($sum('nht_minor')),
            'education_tax' => $this->decimal($sum('education_tax_minor')),
            'paye' => $this->decimal($sum('paye_minor')),
            'net' => $this->decimal($sum('net_minor')),
        ];
    }

    /**
     * @return array{key: string, label: string, amount: string, rate: ?string, base: ?string, note: string}
     */
    private function line(string $key, string $label, int $amountMinor, ?int $rateBp, ?int $baseMinor, string $note): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'amount' => $this->decimal($amountMinor),
            // Basis points into the percentage a person reads: 250 → "2.5%".
            'rate' => $rateBp === null ? null : rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.').'%',
            'base' => $this->decimal($baseMinor),
            'note' => $note,
        ];
    }

    /** @return array<string, string> */
    private function employer(): array
    {
        return [
            'name' => (string) config('legal.company'),
            'address' => (string) config('legal.address'),
            'email' => (string) config('legal.support_email'),
        ];
    }

    private function decimal(?int $minor): ?string
    {
        return $minor === null ? null : Decimal::of($minor);
    }

    private function hours(int|float|string|null $hours): ?string
    {
        return $hours === null ? null : number_format((float) $hours, 2, '.', '');
    }
}
