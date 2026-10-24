@props([
    'label' => 'View field information',
    'align' => 'start',
])

@php
    $noteId = 'field-note-' . \Illuminate\Support\Str::uuid();
@endphp

<span {{ $attributes->class(['page-note', 'field-note', 'field-note--' . $align]) }} data-page-note>
    <button type="button"
            class="page-note__toggle field-note__toggle"
            data-page-note-toggle
            aria-expanded="false"
            aria-controls="{{ $noteId }}"
            aria-label="{{ $label }}">
        <iconify-icon icon="mdi:information-variant" aria-hidden="true"></iconify-icon>
    </button>

    <span id="{{ $noteId }}"
          class="page-note__panel field-note__panel"
          data-page-note-panel
          role="tooltip"
          hidden>
        <span class="field-note__body">{{ $slot }}</span>
    </span>
</span>
