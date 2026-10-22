@extends('public.layout')

{{--
    M18G §5: the invite's "Open the app" link. The app opens at log in with the invited address filled in. Only a
    SIGNED link (from a real invitation email) says anything about the app; any other visit says only that the link
    is not complete (M24, D-223).
--}}
@section('content')
    @if ($valid)
        <h1>Open the app</h1>
        <div class="card">
            <p style="margin-top:0">
                @if ($invitedBy !== '')
                    {{ $invitedBy }} invited you to {{ $estateName }}.
                @else
                    You were invited to {{ $estateName }}.
                @endif
                The app opens with your address already filled in:
            </p>
            <p class="ref" style="word-break:break-all">{{ $email }}</p>
            <a class="button" href="{{ $androidLink }}" data-app="{{ $appLink }}" id="open-app">Open the app</a>
            <p style="margin-bottom:0">It emails a code to that address. There is no password.</p>
        </div>
        @if ($getApp)
            <p>Not installed yet? <a href="{{ $getApp }}">Get the app</a>, then come back to this email and tap the link again.</p>
        @else
            <p>Not installed yet? Your estate office can tell you where to get the app. Then come back to this email and tap the link again.</p>
        @endif
        @if ($lapsesOn !== '')
            <p>This link is for {{ $email }} only and lapses on {{ $lapsesOn }}.</p>
        @endif
        <script>
            // Android opens the `intent:` link; every other phone the app's own scheme.
            (function () {
                var a = document.getElementById('open-app');
                if (!/android/i.test(navigator.userAgent)) a.setAttribute('href', a.getAttribute('data-app'));
            })();
        </script>
    @elseif ($lapsed)
        <h1>This invitation has lapsed</h1>
        <div class="card">
            <p style="margin-top:0">The link was for <strong>{{ $email }}</strong> and has passed its date.</p>
            <p style="margin-bottom:0">Ask {{ $invitedBy !== '' ? $invitedBy : 'the person who invited you' }} to send you a new invitation, or contact your estate office.</p>
        </div>
    @else
        <h1>This link is not complete</h1>
        <div class="card">
            <p style="margin:0">Please open the email again and tap the link there.</p>
        </div>
    @endif
@endsection
