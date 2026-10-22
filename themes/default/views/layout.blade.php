<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $site['locale'] ?: 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $page['title'] }}</title>
    @if (($page['description'] ?? '') !== '')
        <meta name="description" content="{{ $page['description'] }}">
    @endif
    @if (($page['url'] ?? '') !== '')
        <link rel="canonical" href="{{ $page['url'] }}">
    @endif
    <link rel="stylesheet" href="{{ $theme->assetUrl('theme.css') }}">
</head>
<body>
    <a class="skip" href="#main">Skip to content</a>

    <header class="site-header">
        <a class="site-name" href="{{ $site['url'] }}">{{ $site['name'] }}</a>
        @if (count($menu) > 0)
            <nav aria-label="Main">
                <ul>
                    @foreach ($menu as $item)
                        <li><a href="{{ $item['url'] }}"@if ($item['current']) aria-current="page"@endif>{{ $item['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <p>{{ $site['name'] }}</p>
    </footer>
</body>
</html>
