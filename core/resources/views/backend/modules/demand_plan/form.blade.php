@extends('backend.layouts.master')

@php
    $editing = $method === 'PUT';
    $selectedBranchId = (int) old('branch_id', $plan->branch_id);
    $selectedPeriodId = (int) old('reporting_period_id', $plan->reporting_period_id);
    $selectedHistoryMonths = (int) old('history_months', $plan->history_months ?: 3);
@endphp

@section('meta')
    <title>{{ $title }}</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">{{ $title }}</h6>
                <x-backend.page-note title="Demand Plan quantities" label="View quantity note">
                    Historical Net Sales is total Posted Sales minus Posted Returns during the selected lookback.
                    Suggested quantity is its monthly average. Planned quantity is the target you approve; Safety
                    quantity is an optional extra Finished Product buffer. This screen does not change stock or Finance.
                </x-backend.page-note>
            </div>
            <p class="text-muted mb-0">Review sales history and set the target Finished Product quantities.</p>
        </div>
        <a href="{{ route('planning.demand-plans.index') }}"
           class="btn btn-outline-secondary btn-sm px-14 py-8 radius-8 app-action-button">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to list</span>
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Please correct the Demand Plan information.</strong>
            <ul class="mb-0 mt-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if ($canReloadSuggestions)
        <form method="GET" action="{{ route('planning.demand-plans.create') }}"
              class="card mb-24" id="demandPlanContextForm">
            <div class="card-header d-flex align-items-center gap-2">
                <h5 class="card-title mb-0">Planning Context</h5>
                <x-backend.field-note label="Planning context information">
                    Select a branch, target month and sales-history window, then load the suggestions.
                </x-backend.field-note>
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                        <select name="branch_id" class="form-select form-select-sm js-s2"
                                placeholder="Select branch" required>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected($selectedBranchId === (int) $branch->id)>
                                    {{ $branch->name }} ({{ $branch->code }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label">Target Month <span class="text-danger">*</span></label>
                        <select name="reporting_period_id" class="form-select form-select-sm js-s2"
                                placeholder="Select target month" required>
                            @foreach ($periods as $period)
                                <option value="{{ $period->id }}" @selected($selectedPeriodId === (int) $period->id)>
                                    {{ sprintf('%04d-%02d', $period->year, $period->month) }}
                                    · {{ $period->starts_on->format('d M') }}–{{ $period->ends_on->format('d M Y') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <label class="form-label">Sales History</label>
                        <select name="history_months" class="form-select form-select-sm">
                            @foreach ([1, 3, 6, 12] as $months)
                                <option value="{{ $months }}" @selected($selectedHistoryMonths === $months)>
                                    Previous {{ $months }} {{ Str::plural('month', $months) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2">
                        <button type="submit"
                                class="btn btn-outline-info btn-sm w-100 px-14 py-8 radius-8 app-action-button fw-semibold">
                            <iconify-icon icon="mdi:chart-line"></iconify-icon>
                            <span>Load Suggestions</span>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    @else
        <div class="card mb-24">
            <div class="card-header"><h5 class="card-title mb-0">Planning Context</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <small class="text-muted d-block">Branch</small>
                        <span class="fw-semibold">{{ $plan->branch->name }} ({{ $plan->branch->code }})</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Target Month</small>
                        <span class="fw-semibold">{{ sprintf('%04d-%02d', $plan->period->year, $plan->period->month) }}</span>
                    </div>
                    <div class="col-md-4">
                        <small class="text-muted d-block">Sales History</small>
                        <span class="fw-semibold">Previous {{ $selectedHistoryMonths }} {{ Str::plural('month', $selectedHistoryMonths) }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($branches->isEmpty() || $periods->isEmpty())
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <iconify-icon icon="mdi:alert-outline" class="text-xl"></iconify-icon>
            An active branch and an Open Reporting Period are required before creating a Demand Plan.
        </div>
    @elseif ($suggestions->isEmpty())
        <div class="alert alert-warning d-flex align-items-center gap-2">
            <iconify-icon icon="mdi:alert-outline" class="text-xl"></iconify-icon>
            No active Finished Products are available for planning.
        </div>
    @else
        <form method="POST" action="{{ $action }}" id="demandPlanForm">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif
            <input type="hidden" name="branch_id" value="{{ $selectedBranchId }}">
            <input type="hidden" name="reporting_period_id" value="{{ $selectedPeriodId }}">
            <input type="hidden" name="history_months" value="{{ $selectedHistoryMonths }}">

            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Finished Product Forecast</h5>
                        <span class="badge bg-primary-focus text-primary-600 px-10 py-6 radius-6">
                            {{ $suggestions->count() }} products
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="position-relative" style="min-width: 240px">
                            <iconify-icon icon="mdi:magnify"
                                          class="position-absolute top-50 translate-middle-y ms-12 text-muted"></iconify-icon>
                            <input type="search" id="demandProductSearch"
                                   class="form-control form-control-sm ps-36"
                                   placeholder="Search product or code">
                        </div>
                        <button type="button" id="useAllDemandSuggestions"
                                class="btn btn-outline-success btn-sm px-14 py-8 radius-8 app-action-button fw-semibold">
                            <iconify-icon icon="mdi:auto-fix"></iconify-icon>
                            <span>Use All Suggestions</span>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive demand-plan-product-table-wrap">
                        <table class="table bordered-table align-middle mb-0" id="demandPlanProductTable">
                            <thead>
                                <tr>
                                    <th style="min-width: 260px">Finished Product</th>
                                    <th class="text-end" style="min-width: 145px">Historical Net Sales</th>
                                    <th class="text-end" style="min-width: 130px">Suggested / Month</th>
                                    <th class="text-end" style="min-width: 155px">Planned Qty</th>
                                    <th class="text-end" style="min-width: 145px">Safety Qty</th>
                                    <th style="min-width: 220px">Planning Basis</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($suggestions as $row)
                                    @php
                                        $product = $row['finished_product'];
                                        $existing = $existingItems->get($product->id);
                                        $oldRow = old('items.'.$product->id, []);
                                        $planned = array_key_exists('forecast_quantity', $oldRow)
                                            ? $oldRow['forecast_quantity']
                                            : ($existing?->forecast_quantity ?? $row['suggested_quantity']);
                                        $safety = array_key_exists('safety_quantity', $oldRow)
                                            ? $oldRow['safety_quantity']
                                            : ($existing?->safety_quantity ?? 0);
                                        $basis = array_key_exists('basis', $oldRow)
                                            ? $oldRow['basis']
                                            : ($existing?->basis ?? '');
                                        $displayQuantity = fn ($value) => rtrim(rtrim(
                                            number_format((float) $value, 3, '.', ','),
                                            '0'
                                        ), '.');
                                        $inputQuantity = fn ($value) => rtrim(rtrim(
                                            number_format((float) $value, 3, '.', ''),
                                            '0'
                                        ), '.');
                                        $suggestedInput = $inputQuantity($row['suggested_quantity']);
                                        $searchText = Str::lower($product->name.' '.$product->product_code);
                                    @endphp
                                    <tr data-product-search="{{ $searchText }}">
                                        <td>
                                            <span class="fw-semibold d-block">{{ $product->name }}</span>
                                            <code>{{ $product->product_code }}</code>
                                            <small class="text-muted ms-1">{{ $product->baseUnit?->code }}</small>
                                        </td>
                                        <td class="text-end">
                                            <span class="fw-medium">{{ $displayQuantity($row['historical_net_quantity']) }}</span>
                                            <small class="text-muted d-block">{{ $product->baseUnit?->code }}</small>
                                        </td>
                                        <td class="text-end">
                                            <button type="button"
                                                    class="btn btn-sm bg-info-focus text-info-main px-10 py-6 radius-6 use-demand-suggestion"
                                                    data-suggestion="{{ $suggestedInput }}"
                                                    title="Copy suggestion to Planned Qty">
                                                {{ $displayQuantity($row['suggested_quantity']) }}
                                            </button>
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="items[{{ $product->id }}][forecast_quantity]"
                                                   class="form-control form-control-sm text-end demand-planned-quantity"
                                                   value="{{ $inputQuantity($planned) }}" min="0" step="0.001">
                                        </td>
                                        <td>
                                            <input type="number"
                                                   name="items[{{ $product->id }}][safety_quantity]"
                                                   class="form-control form-control-sm text-end"
                                                   value="{{ $inputQuantity($safety) }}" min="0" step="0.001">
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="items[{{ $product->id }}][basis]"
                                                   class="form-control form-control-sm"
                                                   value="{{ $basis }}" maxlength="1000"
                                                   placeholder="Optional reason / assumption">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-body border-top">
                    <label class="form-label">Plan Note</label>
                    <textarea name="note" class="form-control" rows="2"
                              placeholder="Optional note for this monthly plan">{{ old('note', $plan->note) }}</textarea>
                </div>
                <div class="card-footer d-flex align-items-center justify-content-center gap-3">
                    <a href="{{ route('planning.demand-plans.index') }}"
                       class="btn border border-danger-600 text-danger-600 px-36 py-10 radius-8 app-action-button">
                        Cancel
                    </a>
                    <button type="submit"
                            class="btn btn-primary px-44 py-10 radius-8 app-action-button app-primary-action">
                        <iconify-icon icon="mdi:content-save-outline"></iconify-icon>
                        <span>{{ $editing ? 'Update Draft' : 'Save Draft' }}</span>
                    </button>
                </div>
            </div>
        </form>
    @endif
@endsection

@section('script')
    <script>
        S2.auto($('#demandPlanContextForm'));

        $('#demandProductSearch').on('input', function() {
            const keyword = String($(this).val() || '').trim().toLowerCase();
            $('#demandPlanProductTable tbody tr').each(function() {
                $(this).toggle(String($(this).data('product-search') || '').includes(keyword));
            });
        });

        $(document).on('click', '.use-demand-suggestion', function() {
            $(this).closest('tr').find('.demand-planned-quantity').val($(this).data('suggestion'));
        });

        $('#useAllDemandSuggestions').on('click', function() {
            $('#demandPlanProductTable tbody tr').each(function() {
                const suggestion = $(this).find('.use-demand-suggestion').data('suggestion');
                $(this).find('.demand-planned-quantity').val(suggestion);
            });
        });
    </script>
@endsection
