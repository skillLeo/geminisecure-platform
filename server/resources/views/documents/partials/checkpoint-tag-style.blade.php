{{--
    The tag's own styles, shared by one tag and a whole route's (M21 §1.1, M26 §3.5).

    TWO SIZES. A5 is the tag a guard on foot scans at arm's length. A4 ("large") doubles the code, for a tag read
    from the driver's seat of a patrol vehicle: a phone reads a QR code from roughly ten times its own width, so the
    A5 tag's 78 mm code reads from under a metre and the A4 tag's 150 mm code from about a metre and a half.
--}}
<style>
    @page { size: {{ $large ? 'A4' : 'A5' }} portrait; margin: 12mm; }

    body {
        margin: 0;
        background: #EEF1F6;
        color: #1F2A44;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }

    .hint {
        max-width: {{ $large ? '180mm' : '128mm' }};
        margin: 16px auto 0;
        padding: 10px 14px;
        border-radius: 8px;
        background: #FFF7E0;
        color: #5A4A12;
        font-size: 14px;
        line-height: 1.45;
    }

    .hint a { color: #5A4A12; }

    .tag {
        box-sizing: border-box;
        max-width: {{ $large ? '180mm' : '128mm' }};
        margin: 16px auto 24px;
        padding: 10mm 9mm 8mm;
        background: #FFFFFF;
        border: 1.5pt solid #1F2A44;
        border-radius: 6mm;
        text-align: center;
        break-inside: avoid;
    }

    .estate { font-size: {{ $large ? '16pt' : '12pt' }}; font-weight: 700; letter-spacing: 0.3pt; }
    .route { margin-top: 2pt; font-size: {{ $large ? '13pt' : '10pt' }}; color: #5A6478; }
    .qr { display: block; width: {{ $large ? '150mm' : '78mm' }}; max-width: 100%; height: auto; margin: 7mm auto 5mm; }
    .label { font-size: {{ $large ? '30pt' : '20pt' }}; font-weight: 800; line-height: 1.15; }
    .place { margin-top: 3pt; font-size: {{ $large ? '15pt' : '11pt' }}; color: #5A6478; }
    .code {
        display: inline-block;
        margin-top: 6mm;
        padding: 3pt 8pt;
        border: 0.75pt solid #D8DDE8;
        border-radius: 4pt;
        font-family: ui-monospace, "Cascadia Mono", Consolas, monospace;
        font-size: {{ $large ? '16pt' : '12pt' }};
        letter-spacing: 1pt;
    }
    .how { margin-top: 5mm; font-size: {{ $large ? '12pt' : '9pt' }}; color: #5A6478; line-height: 1.45; }
    .foot { margin-top: 5mm; padding-top: 3mm; border-top: 0.5pt solid #D8DDE8; font-size: {{ $large ? '9pt' : '7.5pt' }}; color: #8A93A6; }

    @media print {
        body { background: #FFFFFF; }
        .hint { display: none; }
        .tag { margin: 0 auto; }
        .tag + .tag { break-before: page; }
    }
</style>
