@extends('theme::layout')

@section('content')
    @if ($entry)
        @include('theme::partials.entry')
    @else
        <h1>{{ $site['name'] }}</h1>
        <p>Nothing has been published here yet.</p>
    @endif
@endsection
