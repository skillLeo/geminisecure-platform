{{-- A page taken down for good (M24 §7, routes/public.php): the framework's plain error page, which names nothing. --}}
@extends('errors::minimal')

@section('title', 'Gone')
@section('code', '410')
@section('message', 'Gone')
