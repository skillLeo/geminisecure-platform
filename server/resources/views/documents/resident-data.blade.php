{{--
    "DOWNLOAD MY DATA" (board resident-app-33, M50 §4.3): everything GeminiSecure holds about one person, as a document
    they keep. The sections are `ResidentDataExport::collect()`'s, in its order; this file only lays them out.

    Self-contained, as the payslip is: `documents.layout` is an estate's letterhead, and this is the platform's answer to
    the person about the person — the estate is named, not branded. DejaVu Sans because it holds every character a name
    or a message can carry, and dompdf embeds it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $data['title'] }} — {{ $data['person'] }}</title>
    <style>
        @page { margin: 34pt 40pt 44pt; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #1B2733; }
        h1 { font-size: 16pt; margin: 0 0 3pt; }
        h2 { font-size: 11pt; margin: 0 0 2pt; color: #123B6B; }
        h3 { font-size: 8pt; margin: 8pt 0 3pt; letter-spacing: .4pt; text-transform: uppercase; color: #5A6478; }
        .muted { color: #5A6478; }
        .small { font-size: 7.5pt; }
        .rule { border-top: 1pt solid #C9D4E2; margin: 10pt 0; }
        .section { margin: 0 0 14pt; page-break-inside: auto; }
        .intro { margin: 0 0 5pt; color: #5A6478; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 7pt; letter-spacing: .3pt; text-transform: uppercase; color: #5A6478; padding: 0 6pt 3pt 0; border-bottom: .75pt solid #C9D4E2; }
        td { padding: 3pt 6pt 3pt 0; vertical-align: top; border-bottom: .5pt solid #E3E9F1; word-wrap: break-word; }
        tr { page-break-inside: avoid; }
        .facts td.label { width: 38%; color: #5A6478; }
        .empty { color: #5A6478; font-style: italic; padding: 2pt 0 4pt; }
        .panel { background: #F2F6FB; padding: 9pt 11pt; margin: 10pt 0 0; }
        .panel li { margin: 0 0 4pt; }
        .footer { position: fixed; bottom: -28pt; left: 0; right: 0; font-size: 7pt; color: #5A6478; }
    </style>
</head>
<body>

<div class="footer">{{ $data['title'] }} · {{ $data['person'] }} · made {{ $data['made_at'] }}</div>

<h1>{{ $data['title'] }}</h1>
<div class="muted">{{ $data['person'] }}@if ($data['estate'] !== '') · {{ $data['estate'] }}@endif</div>
<div class="small muted">Made {{ $data['made_at'] }}. {{ $data['clock'] }}</div>
<div class="small muted" style="margin-top: 4pt;">A copy of everything GeminiSecure holds about you, as it stood at that moment. Keep it somewhere private: it has your details in it.</div>

<div class="rule"></div>

@foreach ($data['sections'] as $section)
    <div class="section">
        <h2>{{ $section['title'] }}</h2>
        <div class="intro">{{ $section['intro'] }}</div>

        @if (array_key_exists('facts', $section))
            @if ($section['facts'] === [])
                <div class="empty">{{ $section['empty'] }}</div>
            @else
                <table class="facts">
                    @foreach ($section['facts'] as [$label, $value])
                        <tr><td class="label">{{ $label }}</td><td>{{ $value }}</td></tr>
                    @endforeach
                </table>
            @endif
        @else
            @foreach ($section['tables'] as $table)
                @if ($table['caption'])
                    <h3>{{ $table['caption'] }}</h3>
                @endif
                @if ($table['rows'] === [])
                    <div class="empty">{{ $table['empty'] }}</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                @foreach ($table['headers'] as $header)
                                    <th>{{ $header }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table['rows'] as $row)
                                <tr>
                                    @foreach ($row as $cell)
                                        <td>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        @endif
    </div>
@endforeach

<div class="panel">
    <h2>What this file does not include</h2>
    <ul style="margin: 4pt 0 0; padding-left: 14pt;">
        @foreach ($data['not_included'] as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</div>

</body>
</html>
