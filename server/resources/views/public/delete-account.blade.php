@extends('public.layout')

{{--
    The account-deletion page Google Play loads itself (M5 §4). For residents only, and linked from nowhere on the
    website (M24, D-223): it names no other app and lists no community.
--}}
@section('content')
    <h1>Delete your account</h1>
    <p class="lead">This page is for residents who use the {{ $product }} app in their community. It is provided by <strong>{{ $company }}</strong>. You do not need the app, or to log in, to ask for your account to be deleted.</p>

    <div class="card">
        <h2>Two ways to delete your account</h2>
        <ol style="margin-bottom:0">
            <li><strong>In the app.</strong> This is the fastest. Open <strong>Profile → Delete my account</strong>. It happens at once.</li>
            <li><strong>Here.</strong> Fill in the form below. Anyone can type an email address into a public form, so we first confirm the request with the email address or phone number <em>on the account</em>. Once you confirm, the account is deleted, normally within 7 days.</li>
        </ol>
    </div>

    <h2 style="margin-top:32px">What is deleted, and what is kept</h2>
    <p>Deleting your account removes what identifies you. Some records must be kept by law or belong to your community, not to you. Those are kept <strong>without your name, phone number, email address or photo</strong>.</p>
    <table>
        <thead><tr><th>Deleted at once</th><th>Kept, and why</th></tr></thead>
        <tbody>
        <tr>
            <td>Your log in and access to the app, on every phone<br>Your name, email address and phone number on the account<br>Your profile photo, if any<br>Notification tokens for your phones<br>Your app settings and preferences</td>
            <td><strong>Gate and security records</strong> (visitors admitted, passes used, alerts and reports you made). They are kept as your community's security record, for up to 7 years, with your identity removed from them.<br><br><strong>Your community's accounts</strong> (charges, payments and receipts for a home). They are your community's financial records, kept for as long as accounting law requires. They stay with the <em>home</em>, not with you.<br><br><strong>Votes.</strong> A vote is stored with nothing that links it to a household, so there is nothing of yours to delete. The record that a home voted stays with the election.</td>
        </tr>
        </tbody>
    </table>
    <p>The full details are in the <a href="{{ route('public.privacy') }}">Privacy Policy</a>.</p>

    <h2 style="margin-top:32px">Ask us to delete your account</h2>
    <form method="post" action="{{ route('public.requests.store') }}" class="card" novalidate>
        @csrf
        <input type="hidden" name="kind" value="account_deletion">
        <input type="hidden" name="app" value="resident">
        <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="field">
            <label for="full_name">Your name, as your community knows it</label>
            <input id="full_name" name="full_name" type="text" value="{{ old('full_name') }}" autocomplete="name" required>
            @error('full_name')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label for="contact">The email address or phone number your account uses</label>
            <input id="contact" name="contact" type="text" value="{{ old('contact') }}" autocomplete="email" required>
            <p class="hint">We use it only to confirm this request is really from you.</p>
            @error('contact')<p class="error" role="alert">{{ $message }}</p>@enderror
        </div>

        <div class="field">
            <label for="estate">Your community</label>
            <input id="estate" name="estate" type="text" value="{{ old('estate') }}">
        </div>

        <div class="field">
            <label for="message">Anything else we should know</label>
            <textarea id="message" name="message">{{ old('message') }}</textarea>
            @error('website')<p class="error" role="alert">That could not be sent. Please try again.</p>@enderror
        </div>

        <button type="submit" class="danger">Request account deletion</button>
    </form>
    <p class="hint">This form does not delete anything by itself. You will be given a reference to quote. The request is kept only until it has been dealt with, and for no more than {{ $retentionDays }} days after that.</p>
@endsection
