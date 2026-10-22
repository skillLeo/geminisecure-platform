{{--
    A GUARD'S PAYSLIP, AS A DOCUMENT THEY CAN KEEP (M8 §5.3).

    Self-contained rather than extending `documents.layout`: that layout is an ESTATE's letterhead —
    its logo, its enquiries address, its retention line — and a wage slip is Gemini Security's
    document about its own employee, addressed to that employee. Borrowing the estate's letterhead
    would put a community's branding on a payroll record that is nothing to do with them.

    It prints what the screen shows and in the same order, because an officer who checks the paper
    against the app and finds a different arrangement has to work out whether the figures differ too.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip · {{ $slip['period_label'] }} · {{ $slip['employee']['name'] }}</title>
    <style>
        @page { margin: 34pt 40pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #1B2733; }
        h1 { font-size: 15pt; margin: 0 0 2pt; }
        .muted { color: #5A6478; }
        .small { font-size: 8pt; }
        .rule { border-top: 1pt solid #C9D4E2; margin: 12pt 0 10pt; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 7.5pt; letter-spacing: .4pt; text-transform: uppercase; color: #5A6478; padding: 0 0 4pt; }
        td { padding: 3.5pt 0; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .panel { background: #F2F6FB; padding: 9pt 11pt; margin: 8pt 0; }
        .total td { border-top: 1pt solid #C9D4E2; font-weight: bold; padding-top: 6pt; }
        .net { background: #123B6B; color: #FFFFFF; padding: 10pt 11pt; margin-top: 10pt; }
        .net .amount { font-size: 15pt; font-weight: bold; }
    </style>
</head>
<body>

<table>
    <tr>
        <td width="60%">
            <h1>{{ $employer['name'] }}</h1>
            <div class="small muted">{{ $employer['address'] }}</div>
            <div class="small muted">{{ $employer['email'] }}</div>
        </td>
        <td width="40%" align="right">
            <div class="small muted">PAYSLIP</div>
            <div style="font-size: 12pt; font-weight: bold;">{{ $slip['period_label'] }}</div>
            <div class="small muted">{{ $slip['period_start'] }} to {{ $slip['period_end'] }}</div>
            <div class="small muted">Run {{ $slip['run_reference'] }}</div>
        </td>
    </tr>
</table>

<div class="rule"></div>

<table>
    <tr>
        <td width="50%">
            <div class="small muted">Employee</div>
            <div style="font-weight: bold;">{{ $slip['employee']['name'] }}</div>
            <div class="small muted">Employee number {{ $slip['employee']['employee_number'] }}</div>
        </td>
        <td width="50%" align="right">
            <div class="small muted">TRN</div>
            <div>{{ $slip['employee']['trn'] ?? 'Not on file' }}</div>
            <div class="small muted" style="margin-top: 4pt;">NIS number</div>
            <div>{{ $slip['employee']['nis_number'] ?? 'Not on file' }}</div>
        </td>
    </tr>
</table>

@if ($slip['hours'])
    <div class="panel">
        <table>
            <thead>
                <tr><th width="46%">Hours</th><th width="18%" style="text-align:right;">Hours</th><th width="18%" style="text-align:right;">Rate</th><th width="18%" style="text-align:right;">Amount</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>Regular</td>
                    <td class="num">{{ $slip['hours']['regular'] }}</td>
                    <td class="num">{{ $slip['hours']['regular_rate'] }}</td>
                    <td class="num">{{ $slip['earnings']['basic'] }}</td>
                </tr>
                <tr>
                    <td>Overtime <span class="small muted">at time and a half</span></td>
                    <td class="num">{{ $slip['hours']['overtime'] }}</td>
                    <td class="num">{{ $slip['hours']['overtime_rate'] }}</td>
                    <td class="num">{{ $slip['earnings']['overtime'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@else
    <div class="panel small muted">The hours behind this period are not on record. The figures below are the ones that were approved and paid.</div>
@endif

<table>
    <thead><tr><th>Earnings</th><th style="text-align:right;">Amount ({{ $slip['currency'] }})</th></tr></thead>
    <tbody>
        <tr><td>Basic pay</td><td class="num">{{ $slip['earnings']['basic'] ?? '—' }}</td></tr>
        <tr><td>Overtime</td><td class="num">{{ $slip['earnings']['overtime'] ?? '—' }}</td></tr>
        <tr><td>Allowances</td><td class="num">{{ $slip['earnings']['allowances'] ?? '—' }}</td></tr>
        <tr class="total"><td>Gross pay</td><td class="num">{{ $slip['earnings']['gross'] }}</td></tr>
    </tbody>
</table>

<div style="height: 10pt;"></div>

<table>
    <thead><tr><th width="34%">Deductions</th><th width="12%" style="text-align:right;">Rate</th><th width="27%" style="text-align:right;">Calculated on</th><th width="27%" style="text-align:right;">Amount</th></tr></thead>
    <tbody>
        @foreach ($slip['lines'] as $line)
            <tr>
                <td>{{ $line['label'] }}<div class="small muted">{{ $line['note'] }}</div></td>
                <td class="num">{{ $line['rate'] ?? '—' }}</td>
                <td class="num">{{ $line['base'] ?? '—' }}</td>
                <td class="num">{{ $line['amount'] }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="3">Total deductions</td><td class="num">{{ $slip['total_deductions'] }}</td></tr>
    </tbody>
</table>

<div class="net">
    <table>
        <tr>
            <td><div class="small">NET PAY · {{ $slip['period_label'] }}</div></td>
            <td align="right"><span class="amount">{{ $slip['currency'] }} {{ $slip['net'] }}</span></td>
        </tr>
    </table>
</div>

@if ($slip['paye_note'])
    <p class="small muted">{{ $slip['paye_note'] }}</p>
@endif

<div class="rule"></div>

<table>
    <tr>
        <td width="50%">
            <table>
                <thead><tr><th colspan="2">Paid by {{ $employer['name'] }} on your behalf</th></tr></thead>
                <tbody>
                    <tr><td>NIS</td><td class="num">{{ $slip['employer_contributions']['nis'] }}</td></tr>
                    <tr><td>NHT</td><td class="num">{{ $slip['employer_contributions']['nht'] }}</td></tr>
                    <tr><td>Education Tax</td><td class="num">{{ $slip['employer_contributions']['education_tax'] }}</td></tr>
                    <tr><td>HEART</td><td class="num">{{ $slip['employer_contributions']['heart'] }}</td></tr>
                    <tr class="total"><td>Total</td><td class="num">{{ $slip['employer_contributions']['total'] }}</td></tr>
                </tbody>
            </table>
            <p class="small muted">{{ $slip['employer_contributions']['note'] }}</p>
        </td>
        <td width="4%"></td>
        <td width="46%">
            <table>
                <thead><tr><th colspan="2">Year to date · {{ $slip['year_to_date']['year'] }} ({{ $slip['year_to_date']['periods'] }} period{{ $slip['year_to_date']['periods'] === 1 ? '' : 's' }})</th></tr></thead>
                <tbody>
                    <tr><td>Gross</td><td class="num">{{ $slip['year_to_date']['gross'] }}</td></tr>
                    <tr><td>NIS</td><td class="num">{{ $slip['year_to_date']['nis'] }}</td></tr>
                    <tr><td>NHT</td><td class="num">{{ $slip['year_to_date']['nht'] }}</td></tr>
                    <tr><td>Education Tax</td><td class="num">{{ $slip['year_to_date']['education_tax'] }}</td></tr>
                    <tr><td>PAYE</td><td class="num">{{ $slip['year_to_date']['paye'] }}</td></tr>
                    <tr class="total"><td>Net</td><td class="num">{{ $slip['year_to_date']['net'] }}</td></tr>
                </tbody>
            </table>
        </td>
    </tr>
</table>

<p class="small muted" style="margin-top: 14pt;">
    Issued {{ $issuedAt }}. A question about this payslip goes to Gemini Security's payroll office, not to the community.
</p>

</body>
</html>
