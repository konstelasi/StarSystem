@if ($links = $entry->links($field['key']))
    <div class="field field--relation">
        <p class="field-label">{{ $field['label'] }}</p>
        <ul>
            @foreach ($links as $link)
                <li>
                    @if ($link->url)
                        <a href="{{ $link->url }}">{{ $link->title }}</a>
                    @else
                        {{ $link->title }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
