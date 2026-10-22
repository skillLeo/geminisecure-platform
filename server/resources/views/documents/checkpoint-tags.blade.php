{{--
    Every checkpoint tag on a patrol route, in one print (M26 §3.5).

    One page per tag, in route order, each exactly the tag `checkpoint-tag` prints alone: same code, same layout. The
    office prints the route once to put the tags up, and once more for the spare set kept at the gate house.
    `?size=large` prints them on A4 with the code twice the size, for reading from a vehicle.
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
        {{ $count }} tag{{ $count === 1 ? '' : 's' }} on {{ $route }}, one to a page. Print them all at once (Ctrl+P, or ⌘P
        on a Mac), laminate each, and fix it where its checkpoint is. Print again for a spare set: the codes are the same.
        @if ($unplaced > 0)
            {{ $unplaced }} of them {{ $unplaced === 1 ? 'is' : 'are' }} not on the map yet: place {{ $unplaced === 1 ? 'it' : 'them' }} on Sites &amp; geofences so a scan can be checked against where {{ $unplaced === 1 ? 'it stands' : 'they stand' }}.
        @endif
        @if ($large)
            These are the large tags, A4, for reading from a vehicle. <a href="?">The A5 tags</a> are for a guard on foot.
        @else
            To scan them from a vehicle, print <a href="?size=large">the large A4 tags</a> instead.
        @endif
    </div>

    @foreach ($tags as $tag)
        @include('documents.partials.checkpoint-tag-card', ['tag' => $tag])
    @endforeach
</body>
</html>
