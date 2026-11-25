<div class="d-inline-flex justify-content-end gap-1 w-100">
    @if ($user?->canDo('planning.demand-plans.show', 'view'))
        <a
            href="{{ route('planning.demand-plans.show', $plan) }}"
            class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-primary-focus text-primary-main"
            title="View Demand Plan"
        >
            <iconify-icon icon="solar:eye-outline"></iconify-icon>
        </a>
    @endif

    @if ($plan->status === \App\Enums\Reporting\DemandPlanStatus::Draft
        && $user?->canDo('planning.demand-plans.edit', 'edit'))
        <a
            href="{{ route('planning.demand-plans.edit', $plan) }}"
            class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-success-focus text-success-main"
            title="Edit Draft"
        >
            <iconify-icon icon="lucide:edit"></iconify-icon>
        </a>
    @endif

    @if (in_array($plan->status, [
        \App\Enums\Reporting\DemandPlanStatus::Draft,
        \App\Enums\Reporting\DemandPlanStatus::Cancelled,
    ], true) && $user?->canDo('planning.demand-plans.destroy', 'delete'))
        <button
            type="button"
            class="border-0 w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-danger-focus text-danger-main btn-demand-plan-delete"
            data-url="{{ route('planning.demand-plans.destroy', $plan) }}"
            title="Delete Demand Plan"
        >
            <iconify-icon icon="solar:trash-bin-minimalistic-outline"></iconify-icon>
        </button>
    @endif
</div>
