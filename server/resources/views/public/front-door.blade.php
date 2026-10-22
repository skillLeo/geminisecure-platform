@extends('public.layout')

{{--
    THE LOG-IN PAGE (M24 §4, D-223): two plain choices, staff or resident, and then an email address. No explanation,
    no description, and nothing about any app. Every sentence is short, plain and grammatical, with no dashes or
    slashes, because the client asked for proper English and read every word. What a step says never differs
    between an address that has an account and one that does not.
--}}

@section('content')
    <div class="door">
        @if ($step === 'start')
            <h1>Log in</h1>

            <div class="card">
                <h2>Log in to your community as staff</h2>
                <a class="button" href="{{ route('front-door.staff') }}">Continue</a>
            </div>

            <div class="card">
                <h2>Log in to your community as a resident</h2>
                <a class="button" href="{{ route('front-door.resident') }}">Continue</a>
            </div>

        @elseif ($step === 'staff-email')
            <h1>Log in as staff</h1>

            <form method="post" action="{{ route('front-door.email') }}" class="card" novalidate>
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="username" inputmode="email" required autofocus value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p class="error" id="email-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit">Continue</button>
            </form>

            <div class="row-links"><a href="{{ route('front-door.show') }}">Back</a></div>

        @elseif ($step === 'staff-password')
            <h1>Log in as staff</h1>

            <form method="post" action="{{ route('front-door.password') }}" class="card" novalidate>
                @csrf
                <p style="margin-top:0">You are logging in as <strong>{{ $email }}</strong>.</p>
                <input type="hidden" name="email" value="{{ $email }}">
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')<p class="error" id="password-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit">Log in</button>
            </form>

            <div class="row-links">
                <form method="post" action="{{ route('front-door.restart') }}">
                    @csrf
                    <button type="submit" class="quiet">Use a different email</button>
                </form>
                <a href="{{ route('front-door.forgot') }}">Forgot your password?</a>
            </div>

        @elseif ($step === 'staff-forgot')
            <h1>Reset your password</h1>

            @if (session('status'))
                <p class="notice" role="status">If that address belongs to a staff account, we have emailed it a link to set a new password. The link works once, for 30 minutes.</p>
            @endif

            <form method="post" action="{{ route('password.email') }}" class="card" novalidate>
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="username" inputmode="email" required autofocus value="{{ old('email', $email) }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p class="error" id="email-error" role="alert">Enter the email address you log in with.</p>@enderror
                </div>
                <button type="submit">Send me a link</button>
            </form>

            <div class="row-links"><a href="{{ route('front-door.show') }}">Back</a></div>

        @elseif ($step === 'choose')
            <h1>Choose your community</h1>
            <p class="lead">{{ $email }} belongs to more than one community. Choose the one you want to open.</p>

            @error('estate')<p class="error" role="alert">{{ $message }}</p>@enderror

            <ul class="choices">
                @foreach ($estates as $estate)
                    <li>
                        <form method="post" action="{{ route('front-door.chosen') }}">
                            @csrf
                            <input type="hidden" name="estate" value="{{ $estate['id'] }}">
                            <button type="submit" class="choice">{{ $estate['name'] }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>

            <div class="row-links">
                <form method="post" action="{{ route('front-door.restart') }}">
                    @csrf
                    <button type="submit" class="quiet">Log in as someone else</button>
                </form>
            </div>

        @elseif ($step === 'resident-email')
            <h1>Log in as a resident</h1>

            <form method="post" action="{{ route('front-door.resident.email') }}" class="card" novalidate>
                @csrf
                <div class="field">
                    <label for="email">Enter the email address you signed up with</label>
                    <input id="email" name="email" type="email" autocomplete="email" inputmode="email" required autofocus value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<p class="error" id="email-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit">Continue</button>
            </form>

            <div class="row-links"><a href="{{ route('front-door.show') }}">Back</a></div>

        @elseif ($step === 'resident-code')
            <h1>Log in as a resident</h1>

            @if (session('status'))
                <p class="notice" role="status">{{ session('status') }}</p>
            @endif

            <form method="post" action="{{ route('front-door.resident.code') }}" class="card" novalidate>
                @csrf
                <p style="margin-top:0">Check your email. If <strong>{{ $email }}</strong> is the address you signed up with, we have sent it a 6 digit code.</p>
                <div class="field">
                    <label for="code">Code</label>
                    <input id="code" name="code" type="text" class="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                    @error('code')<p class="error" id="code-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button type="submit">Continue</button>
            </form>

            <div class="row-links">
                <form method="post" action="{{ route('front-door.resident.again') }}">
                    @csrf
                    <button type="submit" class="quiet">Send a new code</button>
                </form>
                <form method="post" action="{{ route('front-door.resident.restart') }}">
                    @csrf
                    <button type="submit" class="quiet">Use a different email</button>
                </form>
            </div>
        @endif
    </div>
@endsection
