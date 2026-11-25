@extends('backend.layouts.master')

@section('meta')
    <title>Demand Plan {{ $plan->plan_no }}</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <h6 class="fw-semibold mb-0">Demand Plan {{ $plan->plan_no }}</h6>
                <span class="badge text-sm fw-semibold px-12 py-7 radius-4 {{ $plan->status->badgeClass() }}">
                    {{ $plan->status->label() }}
                </span>
                <x-backend.page-note title="Demand Plan effect" label="View Demand Plan effect">
                    This is a planning document only. Approval freezes its quantities for the next Material
                    Requirement Plan; it does not reserve stock, create a Purchase Order or post Finance.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Branch-wise Finished Product forecast and safety target.</p>
        </div>
        <a href="{{ route('planning.demand-plans.index') }}"
           class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to list</span>
        </a>
    </div>

    <div class="card mb-24">
        <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
            <h5 class="card-title mb-0">Plan Information</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                @if ($plan->status === \App\Enums\Reporting\DemandPlanStatus::Draft)
                    @perm('planning.demand-plans.edit', 'edit')
                        <a href="{{ route('planning.demand-plans.edit', $plan) }}"
                           class="btn btn-outline-primary btn-sm px-14 py-8 radius-8 app-action-button">
                            <iconify-icon icon="lucide:edit"></iconify-icon>
                            <span>Edit Draft</span>
                        </a>
                    @endperm
                    @perm('planning.demand-plans.approve', 'edit')
                        <form method="POST" action="{{ route('planning.demand-plans.approve', $plan) }}"
                              class="d-inline demand-plan-confirm-form"
                              data-title="Approve this Demand Plan?"
                              data-text="Approved quantities will be frozen for Material Requirement Planning.">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="btn btn-success btn-sm px-14 py-8 radius-8 app-action-button">
                                <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon>
                                <span>Approve Plan</span>
                            </button>
                        </form>
                    @endperm
                @endif

                @if ($plan->status === \App\Enums\Reporting\DemandPlanStatus::Approved)
                    @if (! $plan->materialRequirementPlan)
                        @perm('planning.material-requirement-plans.create', 'add')
                            <form method="POST" action="{{ route('planning.material-requirement-plans.generate-from-demand', $plan) }}"
                                  class="d-inline demand-plan-confirm-form"
                                  data-title="Generate Material Requirement Plan?"
                                  data-text="This will calculate component needs via BOM and current stock.">
                                @csrf
                                <button type="submit"
                                        class="btn btn-primary btn-sm px-14 py-8 radius-8 app-action-button">
                                    <iconify-icon icon="mdi:cogs"></iconify-icon>
                                    <span>Generate MRP</span>
                                </button>
                            </form>
                        @endperm
                    @else
                        <a href="{{ route('planning.material-requirement-plans.show', $plan->materialRequirementPlan) }}"
                           class="btn btn-outline-info btn-sm px-14 py-8 radius-8 app-action-button">
                            <iconify-icon icon="mdi:clipboard-list-outline"></iconify-icon>
                            <span>View MRP</span>
                        </a>
                    @endif
                @endif

                @if ($plan->status !== \App\Enums\Reporting\DemandPlanStatus::Cancelled
                    && ! $plan->materialRequirementPlan)
                    @perm('planning.demand-plans.cancel', 'edit')
                        <form method="POST" action="{{ route('planning.demand-plans.cancel', $plan) }}"
                              class="d-inline demand-plan-confirm-form"
                              data-title="Cancel this Demand Plan?"
                              data-text="A Cancelled plan cannot be used for Material Requirement Planning.">
                            @csrf
                            @method('PATCH')
                            <button type="submit"
                                    class="btn btn-outline-danger btn-sm px-14 py-8 radius-8 app-action-button">
                                <iconify-icon icon="mdi:cancel"></iconify-icon>
                                <span>Cancel</span>
                            </button>
                        </form>
                    @endperm
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Target Month</small>
                    <span class="fw-semibold">{{ sprintf('%04d-%02d', $plan->period->year, $plan->period->month) }}</span>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Branch</small>
                    <span class="fw-semibold">{{ $plan->branch->name }} ({{ $plan->branch->code }})</span>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Suggestion Basis</small>
                    <span class="fw-semibold">Previous {{ $plan->history_months }} {{ Str::plural('month', $plan->history_months) }}</span>
                </div>
                <div class="col-lg-3 col-sm-6">
                    <small class="text-muted d-block">Created By</small>
                    <span class="fw-semibold">{{ $plan->creator?->name ?: 'System' }}</span>
                    <small class="text-muted d-block">{{ $plan->created_at?->format('d M Y, h:i A') }}</small>
                </div>
                @if ($plan->approved_at)
                    <div class="col-lg-3 col-sm-6">
                        <small class="text-muted d-block">Approved By</small>
                        <span class="fw-semibold">{{ $plan->approver?->name ?: 'System' }}</span>
                        <small class="text-muted d-block">{{ $plan->approved_at->format('d M Y, h:i A') }}</small>
                    </div>
                @endif
                @if ($plan->note)
                    <div class="col-12">
                        <small class="text-muted d-block">Plan Note</small>
                        <span>{{ $plan->note }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <h5 class="card-title mb-0">Planned Finished Products</h5>
            <span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">
                {{ $plan->items->count() }} products
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table bordered-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 65px">#</th>
                            <th>Finished Product</th>
                            <th class="text-end">Historical Net Sales</th>
                            <th class="text-end">Suggested / Month</th>
                            <th class="text-end">Planned Qty</th>
                            <th class="text-end">Safety Qty</th>
                            <th>Planning Basis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($plan->items as $item)
                            @php
                                $format = fn ($value) => rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
                            @endphp
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    <span class="fw-semibold d-block">{{ $item->finishedProduct->name }}</span>
                                    <code>{{ $item->finishedProduct->product_code }}</code>
                                </td>
                                <td class="text-end">{{ $format($item->historical_net_quantity) }}</td>
                                <td class="text-end">{{ $format($item->suggested_quantity) }}</td>
                                <td class="text-end fw-semibold text-primary-600">
                                    {{ $format($item->forecast_quantity) }} {{ $item->finishedProduct->baseUnit?->code }}
                                </td>
                                <td class="text-end">{{ $format($item->safety_quantity) }}</td>
                                <td>{{ $item->basis ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="4" class="text-end">Total</th>
                            <th class="text-end">
                                {{ rtrim(rtrim(number_format((float) $plan->items->sum('forecast_quantity'), 3, '.', ','), '0'), '.') }}
                            </th>
                            <th class="text-end">
                                {{ rtrim(rtrim(number_format((float) $plan->items->sum('safety_quantity'), 3, '.', ','), '0'), '.') }}
                            </th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        $('.demand-plan-confirm-form').on('submit', function(event) {
            event.preventDefault();
            const form = this;
            Swal.fire({
                icon: 'question',
                title: $(form).data('title'),
                text: $(form).data('text'),
                showCancelButton: true,
                confirmButtonText: 'Confirm',
            }).then(function(result) {
                if (result.isConfirmed) form.submit();
            });
        });
    </script>
@endsection
