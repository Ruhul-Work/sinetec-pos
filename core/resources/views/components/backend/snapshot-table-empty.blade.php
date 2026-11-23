@props([
    'rows' => [],
    'message' => 'No frozen rows are available.',
])

@if (count($rows) === 0)
    <div class="text-center text-muted py-32">{{ $message }}</div>
@else
    <div class="table-responsive">
        {{ $slot }}
    </div>
@endif
