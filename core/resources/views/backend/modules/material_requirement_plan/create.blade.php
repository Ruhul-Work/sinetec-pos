@extends('backend.layouts.master')

@section('meta')
    <title>Create Material Requirement Plan</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Create Material Requirement Plan</h6>
                <x-backend.page-note title="BOM Explosion & Stock Offset" label="View guide">
                    Select an Approved Demand Plan. The system will look up active BOMs for each product,
                    calculate required raw materials and parts, and deduct current on-hand stock to generate net procurement and assembly needs.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Generate a new MRP from an Approved Demand Plan.</p>
        </div>
        <a href="{{ route('planning.material-requirement-plans.index') }}"
           class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to list</span>
        </a>
    </div>

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row justify-content-center">
        <div class="col-xl-8 col-lg-10">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Select Approved Demand Plan</h5>
                </div>
                <div class="card-body">
                    @if ($demandPlans->isEmpty())
                        <div class="text-center py-40">
                            <iconify-icon icon="mdi:alert-circle-outline" class="text-muted" style="font-size: 48px"></iconify-icon>
                            <h6 class="mt-16 mb-8 text-secondary">No eligible Demand Plans found</h6>
                            <p class="text-muted mb-16">
                                An MRP requires an <strong>Approved</strong> Demand Plan that has not already been used for material planning.
                            </p>
                            <a href="{{ route('planning.demand-plans.index') }}" class="btn btn-primary btn-sm px-14 py-8 radius-8">
                                <iconify-icon icon="mdi:arrow-right"></iconify-icon>
                                <span>Go to Demand Plans</span>
                            </a>
                        </div>
                    @else
                        <form method="POST" action="{{ route('planning.material-requirement-plans.store') }}">
                            @csrf
                            <div class="mb-24">
                                <label for="demand_plan_id" class="form-label text-sm fw-semibold">
                                    Approved Demand Plan <span class="text-danger">*</span>
                                </label>
                                <select id="demand_plan_id" name="demand_plan_id" class="form-select js-s2" required>
                                    <option value="">Choose an approved Demand Plan...</option>
                                    @foreach ($demandPlans as $dp)
                                        <option value="{{ $dp->id }}" @selected((int) $selectedDemandPlanId === $dp->id)>
                                            {{ $dp->plan_no }} &mdash; {{ $dp->branch->name }} &bull; {{ sprintf('%04d-%02d', $dp->period->year, $dp->period->month) }} ({{ $dp->items_count }} products)
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="alert alert-info d-flex align-items-center gap-2 mb-24">
                                <iconify-icon icon="mdi:information-outline" class="text-xl flex-shrink-0"></iconify-icon>
                                <div>
                                    Clicking <strong>Generate MRP</strong> will explode the active Bill of Materials (BOM) for each planned product and offset current on-hand warehouse stock for that branch.
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2">
                                <a href="{{ route('planning.material-requirement-plans.index') }}" class="btn btn-outline-secondary px-16 py-8 radius-8">
                                    Cancel
                                </a>
                                <button type="submit" class="btn btn-primary px-16 py-8 radius-8 d-inline-flex align-items-center gap-1">
                                    <iconify-icon icon="mdi:cogs"></iconify-icon>
                                    <span>Generate MRP</span>
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        if (typeof S2 !== 'undefined') {
            S2.auto($('.card-body'));
        }
    </script>
@endsection
