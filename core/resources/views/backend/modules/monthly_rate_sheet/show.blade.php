@extends('backend.layouts.master')

@section('meta')
    <title>{{ $sheet->sheet_no }}</title>
@endsection

@section('content')
    @php
        $editable = $sheet->status === \App\Enums\Reporting\MonthlyRateSheetStatus::Draft
            && $sheet->period->status === \App\Enums\Reporting\ReportingPeriodStatus::Open;
    @endphp
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                <h6 class="fw-semibold mb-0">Monthly Rate Sheet {{ $sheet->sheet_no }}</h6>
                <span class="badge text-sm fw-semibold {{ $sheet->status->badgeClass() }} px-12 py-7 radius-4">{{ $sheet->status->label() }}</span>
            </div>
            <p class="text-muted mb-0">{{ sprintf('%04d-%02d', $sheet->period->year, $sheet->period->month) }} · {{ $sheet->period->starts_on->format('d M Y') }} – {{ $sheet->period->ends_on->format('d M Y') }}</p>
        </div>
        <a href="{{ route('reporting.rate-sheets.index') }}"
           class="btn btn-outline-secondary px-14 py-8 radius-8 app-action-button d-inline-flex align-items-center gap-1">
            <iconify-icon icon="mdi:arrow-left"></iconify-icon>
            <span>Back to List</span>
        </a>
    </div>

    @if ($editable)
        @perm('reporting.rate-sheets.update', 'edit')
            <form method="POST" action="{{ route('reporting.rate-sheets.update', $sheet) }}">
                @csrf
                @method('PUT')
        @endperm
    @endif

    @foreach ([
        ['Raw Materials', $sheet->rawMaterialRates, 'raw_material_rates', 'raw_material_id'],
        ['Parts', $sheet->partRates, 'part_rates', 'part_id'],
        ['Finished Products', $sheet->finishedProductRates, 'finished_product_rates', 'finished_product_id'],
    ] as [$title, $rates, $field, $itemColumn])
        <div class="card mb-24">
            <div class="card-header"><h5 class="card-title mb-0">{{ $title }}</h5></div>
            <div class="card-body">
                @if ($rates->isEmpty())
                    <div class="text-muted text-sm">No active items.</div>
                @else
                    <div class="table-responsive">
                        <table class="table bordered-table mb-0 align-middle">
                            <thead><tr><th>Item</th><th>Base Unit</th><th>Basis</th><th class="text-end">Monthly Rate</th></tr></thead>
                            <tbody>
                                @foreach ($rates as $rate)
                                    <tr>
                                        <td><strong>{{ $rate->item->name }}</strong><br><code>{{ $rate->item->material_code ?? $rate->item->part_code ?? $rate->item->product_code }}</code></td>
                                        <td>{{ $rate->item->baseUnit?->name }} ({{ $rate->item->baseUnit?->code }})</td>
                                        <td><span class="badge bg-light text-dark">{{ str_replace('_', ' ', $rate->rate_basis) }}</span></td>
                                        <td class="text-end">
                                            @if ($editable)
                                                <input type="number" name="{{ $field }}[{{ $rate->getAttribute($itemColumn) }}]"
                                                       value="{{ number_format((float) $rate->rate, 4, '.', '') }}"
                                                       min="0" step="0.0001" class="form-control form-control-sm text-end ms-auto" style="max-width:180px" required>
                                            @else
                                                <strong>{{ number_format((float) $rate->rate, 4) }}</strong>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endforeach

    @if ($editable)
        @perm('reporting.rate-sheets.update', 'edit')
            <div class="card mb-24">
                <div class="card-header"><h5 class="card-title mb-0">Sheet Note</h5></div>
                <div class="card-body">
                    <textarea name="note" class="form-control" maxlength="5000" rows="3">{{ old('note', $sheet->note) }}</textarea>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary app-primary-action px-18 py-9 radius-8 d-inline-flex align-items-center gap-1">
                    <iconify-icon icon="mdi:content-save-outline"></iconify-icon><span>Save Sheet</span>
                </button>
            </div>
            </form>
        @endperm
    @else
        <div class="card mb-24">
            <div class="card-header"><h5 class="card-title mb-0">Sheet Note</h5></div>
            <div class="card-body">{{ $sheet->note ?: '—' }}</div>
        </div>
    @endif

    @if ($sheet->status === \App\Enums\Reporting\MonthlyRateSheetStatus::Draft)
        @perm('reporting.rate-sheets.approve', 'edit')
            <form method="POST" action="{{ route('reporting.rate-sheets.approve', $sheet) }}" class="d-inline">
                @csrf @method('PATCH')
                <button type="submit" class="btn btn-success d-inline-flex align-items-center gap-1">
                    <iconify-icon icon="mdi:check-decagram-outline"></iconify-icon><span>Approve Sheet</span>
                </button>
            </form>
        @endperm
    @elseif ($sheet->status === \App\Enums\Reporting\MonthlyRateSheetStatus::Approved)
        @perm('reporting.rate-sheets.lock', 'edit')
            <form method="POST" action="{{ route('reporting.rate-sheets.lock', $sheet) }}" class="d-inline">
                @csrf @method('PATCH')
                <button type="submit" class="btn btn-primary d-inline-flex align-items-center gap-1">
                    <iconify-icon icon="mdi:lock-outline"></iconify-icon><span>Lock Sheet</span>
                </button>
            </form>
        @endperm
    @endif
@endsection
