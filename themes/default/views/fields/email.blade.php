@php($address = $entry->text($field['key']))
<div class="field field--email">
    <p class="field-label">{{ $field['label'] }}</p>
    <p><a href="mailto:{{ $address }}">{{ $address }}</a></p>
</div>
