@extends('theme::layout')

@section('content')
    <h1>{{ $route['label'] }}</h1>

    @if (count($entries) > 0)
        <ul class="entry-list">
            @foreach ($entries as $item)
                <li>
                    <a href="{{ $item->url }}">{{ $item->title }}</a>
                    <time datetime="{{ $item->created('Y-m-d') }}">{{ $item->created() }}</time>
                </li>
            @endforeach
        </ul>
    @else
        <p>Nothing has been published here yet.</p>
    @endif

    @if ($next)
        <p class="pager"><a href="{{ $next }}" rel="next">Older</a></p>
    @endif
@endsection
