<article class="entry">
    <h1>{{ $entry->title }}</h1>

    @foreach ($entry->fields() as $field)
        @continue(! $entry->has($field['key']))

        @include($theme->has('fields.'.$field['type']) ? 'theme::fields.'.$field['type'] : 'theme::fields.default')
    @endforeach
</article>
