@props(['label', 'description', 'value', 'color', 'gradient', 'icon', 'currency' => true])

<div class="col-xl-3 col-sm-6">
    <div class="px-20 py-16 shadow-none radius-8 h-100 gradient-deep-{{ $gradient }} left-line line-bg-{{ $color }} position-relative overflow-hidden">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-8">
            <div>
                <span class="fw-medium text-secondary-light text-md">{{ $label }}</span>
                <h6 class="fw-semibold mb-1">
                    @if ($currency)
                        ৳ {{ number_format($value, 2) }}
                    @else
                        {{ number_format($value) }}
                    @endif
                </h6>
            </div>
            <span class="w-44-px h-44-px d-inline-flex flex-shrink-0 justify-content-center align-items-center text-2xl text-{{ $color }}-600" aria-hidden="true">
                <iconify-icon icon="{{ $icon }}"></iconify-icon>
            </span>
        </div>
        <p class="text-sm text-secondary-light mb-0">{{ $description }}</p>
    </div>
</div>
