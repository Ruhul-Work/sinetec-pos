@props([
    'title' => 'Page note',
    'label' => 'View note',
    'align' => 'end',
])

@php
    $noteId = 'page-note-' . \Illuminate\Support\Str::uuid();
@endphp

<div {{ $attributes->class(['page-note', 'page-note--' . $align]) }} data-page-note>
    <button type="button"
            class="page-note__toggle"
            data-page-note-toggle
            aria-expanded="false"
            aria-controls="{{ $noteId }}"
            aria-label="{{ $label }}">
        <iconify-icon icon="mdi:information-variant" aria-hidden="true"></iconify-icon>
    </button>

    <div id="{{ $noteId }}"
         class="page-note__panel"
         data-page-note-panel
         role="region"
         aria-label="{{ $title }}"
         hidden>
        <div class="page-note__header">
            <div class="page-note__title">
                <iconify-icon icon="mdi:information-outline" aria-hidden="true"></iconify-icon>
                <span>{{ $title }}</span>
            </div>
            <button type="button" class="page-note__close" data-page-note-close aria-label="Close note">
                <iconify-icon icon="mdi:close" aria-hidden="true"></iconify-icon>
            </button>
        </div>
        <div class="page-note__body">
            {{ $slot }}
        </div>
    </div>
</div>
