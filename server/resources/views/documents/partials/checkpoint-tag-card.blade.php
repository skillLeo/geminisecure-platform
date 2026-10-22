{{--
    One checkpoint's tag (M21 §1.1): what goes on the wall. Hung where residents walk past, so it names no app
    (M24 §1.4, D-223).

    A SPARE IS THE SAME TAG (M26 §3.2). Printing again gives the same code, so a copy kept at the gate house replaces a
    torn one with nothing changed in the console. A copy is no way round the patrol: a scan counts only within the
    checkpoint's radius of where it is placed, so a tag scanned anywhere else is refused.
--}}
<div class="tag">
    <div class="estate">{{ $tag['estateName'] }}</div>
    <div class="route">{{ $tag['route'] }}</div>

    <img class="qr" src="{{ $tag['qr'] }}" alt="QR code for checkpoint {{ $tag['label'] }}">

    <div class="label">{{ $tag['label'] }}</div>
    @if ($tag['place'])
        <div class="place">{{ $tag['place'] }}</div>
    @endif

    <div class="code">{{ $tag['code'] }}</div>

    <div class="how">
        Officer on patrol: scan this tag here.
        @if ($tag['placed'])
            The scan counts within {{ $tag['radius'] }} m of this tag.
        @endif
    </div>

    <div class="foot">Printed {{ $tag['printedAt'] }} by {{ $tag['printedBy'] }}. Do not move this tag: it is the proof a guard stood here. A spare copy is the same tag, for replacing a damaged one.</div>
</div>
