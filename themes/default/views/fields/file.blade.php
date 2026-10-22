@if ($files = $entry->files($field['key']))
    <div class="field field--file">
        <p class="field-label">{{ $field['label'] }}</p>
        @foreach ($files as $file)
            @if ($file->isImage)
                <img src="{{ $file->url }}" alt="{{ $file->alt }}" loading="lazy">
            @else
                <p><a href="{{ $file->url }}">{{ $file->name }}</a></p>
            @endif
        @endforeach
    </div>
@endif
