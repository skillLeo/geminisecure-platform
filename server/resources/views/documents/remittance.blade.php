@extends('documents.layout')

{{--
    A remittance advice — what the estate sends a supplier it has paid.

    NOT A RECEIPT, and the difference is the point: a receipt is issued by
    whoever RECEIVED the money, so the estate cannot issue one for a bill it
    paid. This says what was paid, against which invoice, when and how — which
    is what a supplier's accounts department needs to clear their own ledger.

    THE INVOICE TOTAL AND THE AMOUNT PAID ARE BOTH HERE, because they are not
    always the same number. A part payment that printed only what was sent would
    let a supplier mark an invoice settled that is not.
--}}

@section('body')
    <table width="100%">
        <tr>
            <td width="55%">
                <div class="label">To</div>
                <div class="value">{{ $payment['vendor'] }}</div>
                @if ($payment['vendor_trn'])
                    <div class="contact">TRN {{ $payment['vendor_trn'] }}</div>
                @endif
            </td>
            <td width="45%" align="right">
                <div class="label">Amount paid</div>
                <div class="value">{{ \App\Support\MoneyFormatter::fromMinor($payment['paid_minor']) }}</div>
                <div class="contact">{{ $payment['paid_on'] }}</div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>Invoice</th>
                <th>For</th>
                <th class="num">Invoiced</th>
                <th class="num">Paid</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $payment['invoice'] }}</td>
                <td>{{ $payment['description'] }}</td>
                <td class="num">{{ \App\Support\MoneyFormatter::fromMinor($payment['invoice_minor']) }}</td>
                <td class="num">{{ \App\Support\MoneyFormatter::fromMinor($payment['paid_minor']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="panel">
        <table width="100%">
            <tr>
                <td>
                    <div class="label">Method</div>
                    <div>{{ $payment['method'] }}</div>
                </td>
                @if ($payment['reference'])
                    <td align="center">
                        <div class="label">Reference</div>
                        <div>{{ $payment['reference'] }}</div>
                    </td>
                @endif
                @if ($payment['paid_by'])
                    <td align="right">
                        <div class="label">Authorised by</div>
                        <div>{{ $payment['paid_by'] }}</div>
                    </td>
                @endif
            </tr>
        </table>
    </div>

    @if ($payment['paid_minor'] < $payment['invoice_minor'])
        {{-- Said out loud, because a supplier reading a remittance for less
             than the invoice will otherwise assume one of the two is wrong. --}}
        <p style="margin-top: 14pt; font-size: 8.5pt; color: #5A6478;">
            This payment is less than the invoiced amount. The balance remains outstanding on the estate's payables
            ledger.
        </p>
    @endif
@endsection
