@extends('public.layout')

@section('content')
    @if ($draft)
        <div class="draft" role="note"><strong>Draft.</strong> This page describes accurately what the {{ $product }} app does with your information, but its wording has not yet been reviewed by {{ $company }}'s lawyer. The final text will replace it before the app is published.</div>
    @endif
    <article>{!! $html !!}</article>
@endsection
