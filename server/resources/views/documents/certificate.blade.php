@extends('documents.layout')

{{--
    The election certificate.

    THE OLD REASON ASKED FOR A TEMPLATE AND A SIGNATURE BLOCK, and both are
    here. The figures are the tally's, read back through `resultsBoard()` rather
    than recomputed — a certificate with its own arithmetic over the same
    ballots would be a second answer to a question already decided.

    A CERTIFICATE IS ONLY ISSUED FOR A CERTIFIED COUNT. `Governance` refuses
    otherwise: this document names who was elected and a returning officer
    stands behind it, and a count still open can still move.
--}}

@section('body')
    <div class="value">{{ $election['title'] }}</div>
    <div class="contact">Certified {{ $election['certified_at'] }} by {{ $election['certified_by'] }}</div>

    @if ($election['outcome'])
        <div class="panel">{{ $election['outcome'] }}</div>
    @endif

    <div class="panel">
        <table width="100%">
            <tr>
                <td>
                    <div class="label">Ballots cast</div>
                    <div class="value">{{ $election['turnout']['cast'] ?? 0 }}</div>
                </td>
                <td align="center">
                    <div class="label">Eligible</div>
                    <div class="value">{{ $election['turnout']['eligible'] ?? 0 }}</div>
                </td>
                <td align="right">
                    <div class="label">Turnout</div>
                    <div class="value">{{ $election['turnout']['percent'] ?? 0 }}%</div>
                </td>
            </tr>
        </table>
    </div>

    @foreach ($election['cards'] as $card)
        <h3 style="font-size: 10.5pt; margin-top: 16pt;">{{ $card['heading'] }}</h3>

        <table class="grid">
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Phase</th>
                    <th class="num">Votes</th>
                    <th class="num">Share</th>
                    <th>Outcome</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($card['rows'] as $row)
                    <tr>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['phase'] }}</td>
                        <td class="num">{{ $row['votes'] }}</td>
                        <td class="num">{{ $row['percent'] }}%</td>
                        <td>{{ $row['is_winner'] ? 'Elected' : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- The board says so and so must the paper: in a multi-seat race the
             shares deliberately sum to more than 100, because one voter marks
             several names. A reader who does not know that reads a defect. --}}
        @if ($card['seat_count'] > 1)
            <p style="font-size: 8pt; color: #8A93A6;">
                Shares are of ballots cast, not of votes cast: {{ $card['seat_count'] }} seats means each voter marked
                up to {{ $card['seat_count'] }} names, so these percentages sum to more than 100.
            </p>
        @endif
    @endforeach

    @if ($note)
        <p style="margin-top: 14pt;">{{ $note }}</p>
    @endif

    {{-- The signature block. A certificate is a document somebody stands
         behind, and the space for their hand is part of what makes it one. --}}
    <table width="100%" style="margin-top: 34pt;">
        <tr>
            <td width="48%">
                <div style="border-top: 0.75pt solid #1F2A44; padding-top: 5pt;">
                    <div class="label">Returning officer</div>
                    <div>{{ $election['returning_officer'] ?? '' }}</div>
                </div>
            </td>
            <td width="4%"></td>
            <td width="48%">
                <div style="border-top: 0.75pt solid #1F2A44; padding-top: 5pt;">
                    <div class="label">Date</div>
                </div>
            </td>
        </tr>
    </table>
@endsection
