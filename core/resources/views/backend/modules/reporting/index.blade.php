@extends('backend.layouts.master')

@section('meta')
    <title>Reporting</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <h6 class="fw-semibold mb-1">Reporting</h6>
            <p class="text-muted m-0">Open a report you have permission to access.</p>
        </div>
        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Reporting</li>
        </ul>
    </div>

    @if ($reports->isNotEmpty())
        <h6 class="fw-semibold mb-12">Reports</h6>
        <div class="row g-2 mb-24">
            @foreach ($reports as $report)
                <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
                    <a href="{{ route($report['route']) }}" class="card h-100 text-decoration-none"
                       style="background: linear-gradient(135deg, #ffffff 0%, {{ $report['wash'] }} 100%);">
                        <div class="card-body d-flex flex-column p-3">
                            <iconify-icon icon="{{ $report['icon'] }}" class="text-{{ $report['tone'] }}-main mb-2"
                                          style="font-size: 24px; line-height: 1;"></iconify-icon>
                            <h6 class="text-dark fs-6 fw-semibold mb-1">{{ $report['title'] }}</h6>
                            <p class="text-secondary-light mb-2" style="font-size: 12px; line-height: 1.35; min-height: 2.7em;">
                                {{ $report['description'] }}
                            </p>
                            <span class="mt-auto d-inline-flex align-items-center gap-1 text-primary-600" style="font-size: 12px; line-height: 1;">
                                Open report <iconify-icon icon="mdi:arrow-top-right"></iconify-icon>
                            </span>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    @else
        <div class="card mb-24">
            <div class="card-body text-center py-32">
                <iconify-icon icon="mdi:file-chart-outline" class="text-secondary-light" style="font-size: 32px"></iconify-icon>
                <h6 class="mt-2 mb-1">No reports available</h6>
                <p class="text-muted mb-0">Your account does not currently have access to any report.</p>
            </div>
        </div>
    @endif

    @if ($canManageRateSheets)
        <h6 class="fw-semibold mb-12">Monthly Controls</h6>
        <div class="row g-2 mb-24">
            <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
                <a href="{{ route('reporting.rate-sheets.index') }}" class="card h-100 text-decoration-none"
                   style="background: linear-gradient(135deg, #ffffff 0%, #f0f7ff 100%);">
                    <div class="card-body d-flex flex-column p-3">
                        <iconify-icon icon="mdi:file-table-outline" class="text-primary-main mb-2"
                                      style="font-size:24px; line-height:1;"></iconify-icon>
                        <h6 class="text-dark fs-6 fw-semibold mb-1">Monthly Rate Sheets</h6>
                        <p class="text-secondary-light mb-2" style="font-size:12px; line-height:1.35; min-height:2.7em;">
                            Prepare, approve and lock company-wide monthly rates.
                        </p>
                        <span class="mt-auto d-inline-flex align-items-center gap-1 text-primary-600" style="font-size:12px; line-height:1;">
                            Open rate sheets <iconify-icon icon="mdi:arrow-top-right"></iconify-icon>
                        </span>
                    </div>
                </a>
            </div>
        </div>
    @endif

    @if ($canManagePeriods)
        <h6 class="fw-semibold mb-12">Period Controls</h6>
        <div class="row g-2">
            <div class="col-xxl-2 col-xl-3 col-md-4 col-sm-6">
                <a href="{{ route('reporting.periods.index') }}" class="card h-100 text-decoration-none"
                   style="background: linear-gradient(135deg, #ffffff 0%, #eafaff 100%);">
                    <div class="card-body d-flex flex-column p-3">
                        <iconify-icon icon="mdi:calendar-range-outline" class="text-info-main mb-2"
                                      style="font-size: 24px; line-height: 1;"></iconify-icon>
                        <h6 class="text-dark fs-6 fw-semibold mb-1">Reporting Periods</h6>
                        <p class="text-secondary-light mb-2" style="font-size: 12px; line-height: 1.35; min-height: 2.7em;">
                            Open, close or reopen company reporting months.
                        </p>
                        <span class="mt-auto d-inline-flex align-items-center gap-1 text-primary-600" style="font-size: 12px; line-height: 1;">
                            Manage periods <iconify-icon icon="mdi:arrow-top-right"></iconify-icon>
                        </span>
                    </div>
                </a>
            </div>
        </div>
    @endif
@endsection
