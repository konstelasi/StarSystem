@if (($when = $entry->date($field['key'])) !== '')
    <div class="field field--datetime">
        <p class="field-label">{{ $field['label'] }}</p>
        <p><time datetime="{{ $entry->date($field['key'], 'Y-m-d') }}">{{ $when }}</time></p>
    </div>
@endif
