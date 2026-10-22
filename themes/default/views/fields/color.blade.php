@php($color = $entry->text($field['key']))
<div class="field field--color">
    <p class="field-label">{{ $field['label'] }}</p>
    {{-- The swatch is drawn only for a hex colour, so no other CSS can come in through a stored value. --}}
    <p>
        @if (preg_match('/^#[0-9a-f]{3,8}$/i', $color))
            <span class="swatch" style="background: {{ $color }}"></span>
        @endif
        {{ $color }}
    </p>
</div>
