@extends('public.layout')

@section('content')
    <h1>We have your {{ $kind === 'account_deletion' ? 'request' : 'message' }}</h1>
    <div class="card">
        <p style="margin-top:0">Your reference is</p>
        <p class="ref">{{ $reference }}</p>
        <p style="margin-bottom:0">Please keep it, and quote it if you contact us about this.</p>
    </div>

    @if ($kind === 'account_deletion')
        <h2 style="margin-top:32px">What happens next</h2>
        <ol>
            <li>We contact you on the email address or phone number <strong>held on the account</strong> to confirm the request is yours. Nothing is deleted before that.</li>
            <li>Once you confirm, the account is deleted, normally within 7 days, and we tell you in writing what was deleted and what is kept.</li>
        </ol>
        <p>If you still have the app, <strong>Profile → Delete my account</strong> does it at once.</p>
    @else
        <p>We reply to the email address or phone number you gave. For anything about your home, your household, your dues or a new account, your estate office is quicker. They run your community's account.</p>
    @endif
@endsection
