@extends('documents.layout')

{{--
    A unit's statement of account.

    EVERY LINE IS THE LEDGER'S. `DocumentRenderer` reads the same `unitBoard()`
    the screen reads, so a statement cannot disagree with the ledger it was
    printed from — and the balance printed here is the balance at the moment the
    worker ran, which is why the bytes are stored and hashed rather than this
    being re-rendered on demand.
--}}

@section('body')
    <table width="100%">
        <tr>
            <td width="55%">
                <div class="label">Account</div>
                <div class="value">{{ $unit['reference'] }}</div>
                <div class="contact">{{ $unit['meta'] }}</div>
                <div class="contact">{{ $unit['resident'] }}</div>
            </td>
            <td width="45%" align="right">
                <div class="label">Balance owing</div>
                <div class="value">{{ \App\Support\MoneyFormatter::fromMinor($balanceMinor) }}</div>
                <div class="contact">{{ $bucketLabel }}</div>
            </td>
        </tr>
    </table>

    <div class="panel">
        <table width="100%">
            <tr>
                @foreach ($strip as $band)
                    <td align="center">
                        <div class="label">{{ $band['label'] }}</div>
                        <div class="value">{{ \App\Support\MoneyFormatter::fromMinor($band['value_minor']) }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    </div>

    <table class="grid">
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="num">Charge</th>
                <th class="num">Payment</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($statement as $line)
                <tr>
                    <td>{{ $line['date'] ?? '' }}</td>
                    <td>{{ $line['description'] ?? '' }}</td>
                    {{-- A reversal is a negative charge (EST-B-04), so any charge that is not nought is printed. --}}
                    <td class="num">{{ ($line['charge_minor'] ?? 0) !== 0 ? \App\Support\MoneyFormatter::fromMinor($line['charge_minor']) : '' }}</td>
                    <td class="num">{{ ($line['payment_minor'] ?? 0) > 0 ? \App\Support\MoneyFormatter::fromMinor($line['payment_minor']) : '' }}</td>
                    <td class="num">{{ \App\Support\MoneyFormatter::fromMinor($line['balance_minor'] ?? 0) }}</td>
                </tr>
            @empty
                {{-- An account with no movement is a real account, and saying so
                     is better than a table with a blank body under a heading. --}}
                <tr>
                    <td colspan="5">Nothing has been charged to or paid against this account.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
