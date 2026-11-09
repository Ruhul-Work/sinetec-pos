@props([
    'variant',
    'label',
    'summaryKey',
    'description',
    'valueClass' => '',
])

<div class="col-xl-3 col-md-6">
    <div class="card h-100 account-ledger-summary-card account-ledger-summary-card--{{ $variant }}">
        <div class="card-body py-16">
            <small class="text-muted d-block mb-6">{{ $label }}</small>
            <h6 class="mb-0 fw-bold {{ $valueClass }}" data-summary="{{ $summaryKey }}">—</h6>
            <small class="text-muted">{{ $description }}</small>
        </div>
    </div>
</div>
