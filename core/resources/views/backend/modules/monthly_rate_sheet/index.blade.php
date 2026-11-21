@extends('backend.layouts.master')

@section('meta')
    <title>Monthly Rate Sheets</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-1">Monthly Rate Sheets</h6>
            <p class="text-muted mb-0">Prepare company-wide base rates for Raw Material, Part and Finished Product.</p>
        </div>
    </div>

    <div class="row g-3 mb-24">
        @foreach ($periods as $period)
            @if ($period->status === \App\Enums\Reporting\ReportingPeriodStatus::Open)
                <div class="col-xl-3 col-md-4 col-sm-6">
                    <div class="card h-100" style="background: linear-gradient(135deg,#fff 0%,#edf8ff 100%)">
                        <div class="card-body p-3 d-flex flex-column">
                            <iconify-icon icon="mdi:file-table-outline" class="text-info-main mb-2" style="font-size:24px"></iconify-icon>
                            <h6 class="text-dark fs-6 fw-semibold mb-1">{{ sprintf('%04d-%02d', $period->year, $period->month) }}</h6>
                            <p class="text-muted text-sm mb-2">{{ $period->starts_on->format('d M') }} – {{ $period->ends_on->format('d M Y') }}</p>
                            @php
                                $sheet = $sheets->firstWhere('reporting_period_id', $period->id);
                            @endphp
                            @if ($sheet)
                                <a href="{{ route('reporting.rate-sheets.show', $sheet) }}"
                                   class="btn btn-sm btn-outline-primary mt-auto d-inline-flex align-items-center justify-content-center gap-1">
                                    <iconify-icon icon="mdi:open-in-new"></iconify-icon>
                                    Open Sheet
                                </a>
                            @else
                                @perm('reporting.rate-sheets.store', 'add')
                                    <form method="POST" action="{{ route('reporting.rate-sheets.store', $period) }}" class="mt-auto">
                                        @csrf
                                                <button type="submit" class="btn btn-primary app-primary-action w-100 d-inline-flex align-items-center justify-content-center gap-1 px-14 py-8 radius-8">
                                            <iconify-icon icon="mdi:plus"></iconify-icon>
                                            Prepare Sheet
                                        </button>
                                    </form>
                                @endperm
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    <div class="card">
        <div class="card-header"><h5 class="card-title mb-0">Rate Sheet History</h5></div>
        <div class="card-body">
            @if ($sheets->isEmpty())
                <div class="text-center text-muted py-24">No Monthly Rate Sheets have been prepared.</div>
            @else
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead><tr><th>Sheet</th><th>Period</th><th>Status</th><th>Approved</th><th class="text-end">Action</th></tr></thead>
                        <tbody>
                            @foreach ($sheets as $sheet)
                                <tr>
                                    <td class="fw-semibold">{{ $sheet->sheet_no }}</td>
                                    <td>{{ sprintf('%04d-%02d', $sheet->period->year, $sheet->period->month) }}</td>
                                    <td><span class="badge text-sm fw-semibold {{ $sheet->status->badgeClass() }} px-12 py-7 radius-4">{{ $sheet->status->label() }}</span></td>
                                    <td>{{ $sheet->approved_at?->format('d M Y, h:i A') ?: '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('reporting.rate-sheets.show', $sheet) }}"
                                           class="w-32-px h-32-px rounded-circle d-inline-flex align-items-center justify-content-center bg-info-focus text-info-main"
                                           title="Open"><iconify-icon icon="mdi:eye-outline"></iconify-icon></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
