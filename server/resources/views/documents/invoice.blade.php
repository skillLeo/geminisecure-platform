{{--
    A platform invoice, as the client receives it.

    NOT AN ESTATE DOCUMENT and not stored as bytes. An estate statement is a
    snapshot of a moving balance, so it is rendered once and kept; an invoice is
    immutable — its lines, its total and its reference never change, and a
    correction is a credit note of its own — so it is reproducible from the row
    for as long as the row exists, and the row is the retained record.

    THE CREDIT NOTES ARE ON IT AND ARE NOT NETTED AWAY. A client holding an
    invoice for one figure and a statement showing another has been given two
    answers; showing the charge, the credits and what remains gives them one.

    This is Gemini Security's own letterhead rather than an estate's, so there
    is no logo slot: the estate on it is the CLIENT, and printing their mark on
    a bill from their supplier would read as their own invoice to themselves.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['reference'] }}</title>
    <style>
        @page { margin: 26mm 18mm 22mm; }

        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1F2A44; line-height: 1.5; }

        .head { border-bottom: 1.5pt solid #1F2A44; padding-bottom: 10pt; margin-bottom: 18pt; }
        .head td { vertical-align: top; }
        .issuer { font-size: 15pt; font-weight: bold; }
        .muted { font-size: 8.5pt; color: #5A6478; }
        .ref { font-size: 13pt; font-weight: bold; }

        .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #5A6478; }
        .value { font-size: 11pt; font-weight: bold; }
        /* An invoice past its date says so in the console's overdue colour (BIL-B-04; red stays urgent's, D-185). */
        .value.status-overdue { color: #8F5E06; }

        table.grid { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        table.grid th {
            text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt;
            color: #5A6478; border-bottom: 0.75pt solid #D8DDE8; padding: 5pt 4pt;
        }
        table.grid td { padding: 5pt 4pt; border-bottom: 0.5pt solid #EDF0F5; font-size: 9.5pt; }
        table.grid td.num, table.grid th.num { text-align: right; }
        table.grid tr.total td { font-weight: bold; border-top: 1pt solid #1F2A44; border-bottom: none; }

        .panel { background: #F4F6FA; border-radius: 4pt; padding: 10pt 12pt; margin-top: 14pt; }
    </style>
</head>
<body>
    <table class="head" width="100%">
        <tr>
            <td width="55%">
                <div class="issuer">Gemini Security Limited</div>
                <div class="muted">Kingston, Jamaica</div>
            </td>
            <td width="45%" align="right">
                <div class="ref">Invoice {{ $invoice['reference'] }}</div>
                <div class="muted">{{ $invoice['period'] }}</div>
                <div class="muted">Due {{ $invoice['due_on'] }}</div>
            </td>
        </tr>
    </table>

    <table width="100%">
        <tr>
            <td width="55%">
                <div class="label">Billed to</div>
                <div class="value">{{ $invoice['client'] }}</div>
            </td>
            <td width="45%" align="right">
                <div class="label">Status</div>
                <div class="value status-{{ $invoice['status_tone'] ?? 'due' }}">{{ $invoice['status_label'] ?? ucfirst($invoice['status']) }}</div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>Item</th>
                <th>Detail</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td>{{ $line['detail'] }}</td>
                    <td class="num">{{ $line['amount'] }}</td>
                </tr>
            @endforeach

            <tr class="total">
                <td colspan="2">Invoiced</td>
                <td class="num">{{ $invoice['total'] }}</td>
            </tr>
        </tbody>
    </table>

    @if ($notes)
        <h3 style="font-size: 10pt; margin-top: 18pt;">Credit notes against this invoice</h3>

        <table class="grid">
            <thead>
                <tr>
                    <th>Note</th>
                    <th>Reason</th>
                    <th>Issued</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($notes as $note)
                    <tr>
                        <td>{{ $note['reference'] }}</td>
                        <td>{{ $note['reason'] }}</td>
                        <td>{{ $note['issued_on'] }}</td>
                        <td class="num">−{{ \App\Support\MoneyFormatter::fromMinor($note['amount_minor']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="panel">
            <table width="100%">
                <tr>
                    <td><div class="label">Invoiced</div><div class="value">{{ $invoice['total'] }}</div></td>
                    <td align="center">
                        <div class="label">Credited</div>
                        <div class="value">−{{ \App\Support\MoneyFormatter::fromMinor($creditedMinor) }}</div>
                    </td>
                    <td align="right">
                        <div class="label">Payable</div>
                        <div class="value">
                            {{ \App\Support\MoneyFormatter::fromMinor(($invoice['total_minor'] ?? 0) - $creditedMinor) }}
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    @endif

    <p style="margin-top: 20pt; font-size: 8.5pt; color: #5A6478;">
        An invoice is never edited once issued. A correction is a credit note, which is a record of its own and is
        shown above where one exists.
    </p>
</body>
</html>
