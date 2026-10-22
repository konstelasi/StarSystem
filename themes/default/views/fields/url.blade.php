@php($address = $entry->text($field['key']))
<div class="field field--url">
    <p class="field-label">{{ $field['label'] }}</p>
    {{-- Only web addresses become links, so a stored javascript: address never does. --}}
    @if (preg_match('~^https?://~i', $address))
        <p><a href="{{ $address }}" rel="noopener">{{ $address }}</a></p>
    @else
        <p>{{ $address }}</p>
    @endif
</div>
