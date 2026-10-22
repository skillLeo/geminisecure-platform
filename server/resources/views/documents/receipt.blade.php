@extends('documents.layout')

{{--
    The receipt a resident keeps.

    THE RECEIPT NUMBER IS THE DOCUMENT. It came from the estate's own sequence
    when the payment posted — never reused, gaps recorded — so this piece of
    paper and the row in the register are the same fact, and either can be found
    from the other.
--}}

@section('body')
    {{-- D-179: a test card paid it in the app's demonstration checkout. The receipt is real; the money is not. --}}
    @if (! empty($payment['is_demonstration']))
        <div class="panel">
            <div class="label">Demonstration payment</div>
            <div class="value">Paid in the app with a test card. No money moved: it is recorded against the estate's
                demonstration account, not its bank, and can be reversed.</div>
        </div>
    @endif

    <table width="100%">
        <tr>
            <td width="55%">
                <div class="label">Received from</div>
                <div class="value">{{ $payment['unit'] }}</div>
            </td>
            <td width="45%" align="right">
                <div class="label">Receipt number</div>
                <div class="value">{{ $payment['receipt_no'] }}</div>
            </td>
        </tr>
    </table>

    <div class="panel">
        <table width="100%">
            <tr>
                <td>
                    <div class="label">Amount received</div>
                    <div class="value">{{ \App\Support\MoneyFormatter::fromMinor($payment['amount_minor']) }}</div>
                </td>
                <td align="center">
                    <div class="label">Method</div>
                    <div class="value">{{ $payment['method'] }}</div>
                </td>
                <td align="right">
                    <div class="label">Received on</div>
                    <div class="value">{{ $payment['received_on'] }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="grid">
        <tbody>
            @if ($payment['reference'])
                <tr>
                    <td width="35%">Payment reference</td>
                    <td>{{ $payment['reference'] }}</td>
                </tr>
            @endif
            @if ($payment['received_by'])
                <tr>
                    <td width="35%">Received by</td>
                    <td>{{ $payment['received_by'] }}</td>
                </tr>
            @endif
        </tbody>
    </table>

    {{-- What a receipt is and is not. A resident reading one wants to know
         whether it settles their account, and it does not necessarily. --}}
    <p style="margin-top: 16pt; font-size: 8.5pt; color: #5A6478;">
        This receipt records one payment. It is not a statement of the account, and the balance may have moved since —
        a statement of account can be issued from the estate office.
    </p>
@endsection
