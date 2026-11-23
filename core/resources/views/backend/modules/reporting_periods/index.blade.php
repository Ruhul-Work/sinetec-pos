@extends('backend.layouts.master')

@section('meta')
    <title>Reporting Periods</title>
@endsection

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <div>
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-semibold mb-0">Reporting Periods</h6>
                <x-backend.page-note title="Period controls" label="View period rules" align="start">
                    Open periods are available for monthly rate-sheet and report work. Closing records the actor,
                    time and reason; reopening requires separate permission and keeps the full activity history.
                </x-backend.page-note>
            </div>
            <p class="text-muted m-0">Manage company-wide reporting months and their open/closed state.</p>
        </div>

        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a
                    href="{{ route('backend.dashboard') }}"
                    class="d-flex align-items-center gap-1 hover-text-primary"
                >
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Reporting Periods</li>
        </ul>
    </div>

    @perm('reporting.periods.store', 'add')
        <div class="card mb-24">
            <div class="card-header">
                <h5 class="card-title mb-0">Open a Reporting Month</h5>
            </div>
            <div class="card-body">
                <form
                    method="POST"
                    action="{{ route('reporting.periods.store') }}"
                    class="row gy-3 align-items-end"
                >
                    @csrf
                    <div class="col-xl-3 col-md-4">
                        <label for="reportingPeriodYear" class="form-label text-sm">Year</label>
                        <input
                            id="reportingPeriodYear"
                            type="number"
                            name="year"
                            class="form-control form-control-sm"
                            min="2000"
                            max="9999"
                            step="1"
                            value="{{ old('year', now()->year) }}"
                            required
                        >
                    </div>
                    <div class="col-xl-3 col-md-4">
                        <label for="reportingPeriodMonth" class="form-label text-sm">Month</label>
                        <select
                            id="reportingPeriodMonth"
                            name="month"
                            class="form-control form-control-sm"
                            required
                        >
                            @foreach (range(1, 12) as $month)
                                <option
                                    value="{{ $month }}"
                                    @selected((int) old('month', now()->month) === $month)
                                >
                                    {{ \Illuminate\Support\Carbon::create()->month($month)->format('F') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-4">
                        <button
                            type="submit"
                            class="btn btn-primary app-primary-action px-14 py-8 radius-8 d-inline-flex align-items-center gap-1"
                        >
                            <iconify-icon icon="mdi:calendar-plus-outline"></iconify-icon>
                            <span>Open Period</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endperm

    <div class="card reporting-period-table-card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h5 class="card-title mb-0">Reporting Months</h5>

            <div class="actions-bar d-flex align-items-center gap-2 flex-wrap">
                <div class="search-set me-2">
                    <div id="tableSearch" class="search-input"></div>
                </div>
                <ul class="table-top-head list-unstyled d-flex align-items-center gap-2 mb-0">
                    @include('backend.include.buttons')
                </ul>
            </div>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table
                    id="reportingPeriodTable"
                    class="table bordered-table table-scroll mb-0 align-middle AjaxDataTable"
                    data-has-action-column="false"
                    style="width: 100%;"
                >
                    <thead>
                        <tr>
                            <th style="width: 65px;">S.L</th>
                            <th>Period</th>
                            <th>Date Range</th>
                            <th>Status</th>
                            <th>Closed By</th>
                            <th>Last Activity</th>
                            <th class="no-export text-end" style="min-width: 310px;">Action</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        var DATATABLE_URL = @json(route('reporting.periods.list.ajax'));
    </script>
@endsection
