@extends('theme::layout')

@section('content')
    <h1>Page not found</h1>
    <p>There is nothing at this address.</p>
    <p><a href="{{ $site['url'] }}">Go to the home page</a></p>
@endsection
