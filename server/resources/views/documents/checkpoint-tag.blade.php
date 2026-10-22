{{--
    A checkpoint's tag, printed (M21 §1.1).

    A PAGE THE BROWSER PRINTS, NOT A PDF. The office opens it from Sites & geofences, prints it on whatever printer
    is to hand, laminates it and fixes it where the checkpoint is. It stands alone — no console chrome, no script
    (the CSP forbids inline script, and a tag needs none) — and everything on it is for the wall: the QR the Guard
    App reads, the checkpoint's name so it goes on the right wall, and the code in letters so a damaged tag can be
    matched to its row in the console.

    THE QR IS EMBEDDED BYTES, an SVG data: URI (img-src allows data:), so the page prints the same offline.

    `?size=large` prints it on A4 with the code twice the size, for reading from a vehicle (M26).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @include('documents.partials.checkpoint-tag-style', ['large' => $large])
</head>
<body>
    <div class="hint">
        Print this page (Ctrl+P, or ⌘P on a Mac), laminate it, and fix it where {{ $label }} is.
        @unless ($placed)
            This checkpoint is not on the map yet: place it on Sites &amp; geofences so a scan can be checked against where it stands.
        @endunless
        Printing again gives the same code — a replacement for a torn tag, not a new checkpoint.
        @if ($large)
            This is the large tag, A4, for reading from a vehicle. <a href="?">The A5 tag</a> is for a guard on foot.
        @else
            To scan it from a vehicle, print <a href="?size=large">the large A4 tag</a> instead.
        @endif
    </div>

    @include('documents.partials.checkpoint-tag-card', ['tag' => $tag])
</body>
</html>
