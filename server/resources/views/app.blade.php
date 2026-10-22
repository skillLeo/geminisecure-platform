<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Every console page, its doors included, is for staff: none is ever to be listed by a search engine (M24 §7). --}}
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('app.name', 'GeminiSecure') }}</title>

    {{-- Fonts are self-hosted through @fontsource and bundled by Vite.
         Nothing is fetched from a CDN at runtime. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- The live-alert socket client (M11 A3), FETCHED with a signed-in page rather than
         after it has drawn, so the panic pop-up's channel comes up sooner. Fetched, not run:
         nothing connects until one of the composables that listens imports it, so the sign-in
         form and a reader with nothing to watch still open no socket. Only when the build has
         it as a file of its own: a build without one must not take every page down with it. --}}
    @auth
        @php
            try {
                $socketClient = Vite::asset('resources/js/echo.js');
            } catch (\Illuminate\Foundation\ViteException) {
                $socketClient = null;
            }
        @endphp
        @if ($socketClient)
            <link rel="modulepreload" href="{{ $socketClient }}">
        @endif
    @endauth
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
