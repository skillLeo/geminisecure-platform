@extends('documents.layout')

{{--
    A meeting's agenda, or its minutes. One template, because they are the same
    paper with different content — and keeping them together is what stops the
    two drifting into different letterheads for one meeting.

    MINUTES ARE ONLY ISSUED ONCE THEY EXIST. `DocumentRenderer` refuses a minutes
    document for a meeting with none: a blank page that looks like a record of a
    meeting is worse than no page.
--}}

@section('body')
    <div class="value">{{ $meeting['title'] }}</div>
    <div class="contact">{{ $meeting['type'] }}</div>

    <div class="panel">
        <table width="100%">
            <tr>
                <td width="55%">
                    <div class="label">When</div>
                    <div>{{ $meeting['starts_at'] }}</div>
                </td>
                <td width="45%">
                    <div class="label">Where</div>
                    <div>{{ $meeting['location'] ?? 'To be confirmed' }}</div>
                </td>
            </tr>
        </table>
    </div>

    @if ($kind === 'agenda')
        <h3 style="font-size: 10.5pt; margin-top: 18pt;">Agenda</h3>

        <table class="grid">
            <tbody>
                @forelse ($meeting['agenda'] as $item)
                    <tr>
                        <td width="15%">{{ $item['time'] ? \Illuminate\Support\Carbon::parse($item['time'])->format('g:i A').' '.\App\Support\SiteTime::clockTag(\App\Support\SiteTime::zone()) : '' }}</td>
                        <td>
                            {{ $item['text'] }}
                            @if ($item['motion'])
                                <div class="contact">Motion {{ $item['motion'] }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td>No agenda has been set for this meeting.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{-- The quorum belongs on the agenda and not on the minutes: before the
             meeting it is a condition to be met, afterwards it is a fact the
             minutes themselves record. --}}
        @if ($meeting['quorum'])
            <p style="margin-top: 14pt; font-size: 8.5pt; color: #5A6478;">
                This meeting requires a quorum of {{ $meeting['quorum'] }}% of units to transact business.
            </p>
        @endif
    @else
        <h3 style="font-size: 10.5pt; margin-top: 18pt;">Minutes</h3>
        <div style="white-space: pre-wrap;">{{ $meeting['minutes'] }}</div>

        <p style="margin-top: 20pt; font-size: 8.5pt; color: #5A6478;">
            @if ($meeting['minutes_by'])
                Recorded by {{ $meeting['minutes_by'] }}.
            @endif
            @if ($meeting['minutes_adopted'])
                Adopted {{ $meeting['minutes_adopted'] }}.
            @else
                Not yet adopted — minutes are adopted at the following meeting, and this copy is the record as it
                stood when it was issued.
            @endif
        </p>
    @endif
@endsection
