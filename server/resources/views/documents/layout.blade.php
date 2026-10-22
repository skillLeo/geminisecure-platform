{{--
    The chrome every issued document shares.

    ONE LAYOUT, AND IT IS NOT A BOARD. No wireframe draws a PDF — these are
    printed paper, and the design is a letterhead rather than a screen. Kept to
    the same palette the consoles use so a statement looks like it came from the
    same estate as the screen it was printed from, but the geometry is a page's.

    THE LOGO IS EMBEDDED BYTES. `$logo` is a data: URI built by `EstateBranding`
    — dompdf fetching a URL would mean the renderer making an HTTP request from
    a queue worker against a host it may not resolve, and a statement whose logo
    silently failed is one nobody notices until a resident holds it.

    THE FOOT SAYS WHAT THIS PAPER IS AND HOW LONG THE ESTATE KEEPS IT. A
    document with no issue date is one nobody can place in a sequence, and the
    retention line is the ruling made visible on the paper it applies to. It
    names the zone its times are in (M11 §4.3): the estate's, which a reader
    in another country holding the paper cannot otherwise know.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 28mm 18mm 24mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #1F2A44;
            line-height: 1.5;
        }

        .head { border-bottom: 1.5pt solid #1F2A44; padding-bottom: 10pt; margin-bottom: 18pt; }
        .head td { vertical-align: top; }
        .logo { max-height: 46pt; max-width: 150pt; }
        .estate { font-size: 15pt; font-weight: bold; }
        .contact { font-size: 8.5pt; color: #5A6478; }
        .doc-title { font-size: 12.5pt; font-weight: bold; margin-bottom: 2pt; }
        .doc-sub { font-size: 8.5pt; color: #5A6478; }

        table.grid { width: 100%; border-collapse: collapse; margin-top: 10pt; }
        table.grid th {
            text-align: left;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
            color: #5A6478;
            border-bottom: 0.75pt solid #D8DDE8;
            padding: 5pt 4pt;
        }
        table.grid td { padding: 5pt 4pt; border-bottom: 0.5pt solid #EDF0F5; font-size: 9.5pt; }
        table.grid td.num, table.grid th.num { text-align: right; }
        table.grid tr.total td { font-weight: bold; border-top: 1pt solid #1F2A44; border-bottom: none; }

        .panel { background: #F4F6FA; border-radius: 4pt; padding: 10pt 12pt; margin-top: 12pt; }
        .label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #5A6478; }
        .value { font-size: 11pt; font-weight: bold; }

        .foot {
            position: fixed;
            bottom: -14mm;
            left: 0;
            right: 0;
            font-size: 7.5pt;
            color: #8A93A6;
            border-top: 0.5pt solid #D8DDE8;
            padding-top: 5pt;
        }
    </style>
</head>
<body>
    <table class="head" width="100%">
        <tr>
            <td width="55%">
                @if ($logo)
                    <img class="logo" src="{{ $logo }}" alt="">
                @endif
                <div class="estate">{{ $estateName }}</div>
                <div class="contact">
                    {{ $enquiries ?? '' }}@if ($enquiries && $phone) · @endif{{ $phone ?? '' }}
                </div>
            </td>
            <td width="45%" align="right">
                <div class="doc-title">{{ $title }}</div>
                <div class="doc-sub">Issued {{ $issuedAt }}</div>
                <div class="doc-sub">by {{ $issuedBy }}</div>
            </td>
        </tr>
    </table>

    @yield('body')

    <div class="foot">
        {{ $estateName }} · {{ $title }} · issued {{ $issuedAt }} by {{ $issuedBy }}.
        Times on this page are {{ $timeZone }} time. This estate keeps a copy until {{ $retainUntil }}.
    </div>
</body>
</html>
