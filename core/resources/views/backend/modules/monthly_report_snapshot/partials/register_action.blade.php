@php
    $current = $registerRow['current_snapshot'];
    $period = $registerRow['period'];
@endphp

<div class="d-flex align-items-center justify-content-end gap-2 flex-nowrap">
    @if ($current)
        <a href="{{ route('reporting.snapshots.show', $current) }}"
           class="btn btn-outline-primary btn-sm app-action-button">
            <iconify-icon icon="mdi:eye-outline"></iconify-icon>
            <span>View</span>
        </a>
    @endif

    @if ($registerRow['can_create'])
        <button type="button"
                class="btn btn-primary btn-sm app-primary-action d-inline-flex align-items-center justify-content-center gap-1 flex-shrink-0"
                data-bs-toggle="modal"
                data-bs-target="#snapshotModal{{ $period->id }}">
            <iconify-icon icon="mdi:archive-lock-outline" class="text-lg lh-1"></iconify-icon>
            <span>{{ $current ? 'New Version' : 'Generate' }}</span>
        </button>
    @elseif (! $current)
        <div class="d-flex flex-column align-items-end gap-1" style="max-width: 240px;">
            <span class="text-muted text-sm text-end">
                {{ $registerRow['next_action'] }}
            </span>
            @if ($registerRow['next_route'])
                <a href="{{ $registerRow['next_route'] }}"
                   class="btn btn-outline-primary btn-sm app-action-button">
                    <iconify-icon icon="mdi:arrow-right-circle-outline"></iconify-icon>
                    <span>Continue setup</span>
                </a>
            @endif
        </div>
    @endif
</div>
