<div class="d-inline-flex justify-content-end gap-1 w-100">
    @if ($user?->canDo('planning.material-requirement-plans.show', 'view'))
        <a
            href="{{ route('planning.material-requirement-plans.show', $plan) }}"
            class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-focus text-primary-main"
            title="View MRP"
        >
            <iconify-icon icon="solar:eye-outline"></iconify-icon>
        </a>
    @endif

    @if (in_array($plan->status, [
        \App\Enums\Reporting\MrpStatus::Draft,
        \App\Enums\Reporting\MrpStatus::Cancelled,
    ], true) && $user?->canDo('planning.material-requirement-plans.destroy', 'delete'))
        <button
            type="button"
            class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-mrp-delete"
            data-url="{{ route('planning.material-requirement-plans.destroy', $plan) }}"
            title="Delete MRP"
        >
            <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
        </button>
    @endif
</div>
