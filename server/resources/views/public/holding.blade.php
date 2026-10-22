@extends('public.layout')

{{-- `/` when the client's own site is not built on this server (M24, D-223): his company's name and Log in, nothing else. --}}
@section('content')
    <div class="door">
        <h1>Gemini Security Limited</h1>
        <a class="button" href="{{ route('front-door.show') }}">Log in</a>
    </div>
@endsection
