@extends('public.layout')

{{--
    A RESIDENT, SIGNED IN ON THE WEB (M24 §4.4, ResidentDoorController). The web version of the community platform
    does not exist yet, so this page says so plainly, sends the resident to their app, and gives the estate office's
    contact. Resident words only: "community platform", never "console".
--}}

@section('content')
    <div class="door">
        <h1>{{ $name ? 'Welcome, '.$name : 'Welcome' }}</h1>

        <div class="card">
            <h2>Your community platform</h2>
            <p style="margin-bottom:0">The web version of your community platform is not open yet. For now, please use the GeminiSecure app on your phone. Everything you need is there.</p>
        </div>

        @foreach ($communities as $community)
            <div class="card">
                <h2>{{ $community['name'] }}</h2>
                @if ($community['email'] || $community['phone'])
                    <p>Your estate office is here to help.</p>
                    @if ($community['email'])
                        <p style="margin:0 0 6px">Email <a href="mailto:{{ $community['email'] }}">{{ $community['email'] }}</a></p>
                    @endif
                    @if ($community['phone'])
                        <p style="margin:0">Phone <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $community['phone']) }}">{{ $community['phone'] }}</a></p>
                    @endif
                @else
                    <p style="margin-bottom:0">If you need help, please contact your estate office.</p>
                @endif
            </div>
        @endforeach

        <p>In an emergency, call 119.</p>

        <form method="post" action="{{ route('community.logout') }}">
            @csrf
            <button type="submit">Log out</button>
        </form>
    </div>
@endsection
