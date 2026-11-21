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
                <a href="{{ route('backend.dashboard') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dashboard
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Reporting Periods</li>
        </ul>
    </div>

    @perm('reporting.periods.store', 'add')
        <div class="card mb-24">
            <div class="card-header"><h5 class="card-title mb-0">Open a Reporting Month</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('reporting.periods.store') }}" class="row gy-3 align-items-end">
                    @csrf
                    <div class="col-xl-3 col-md-4">
                        <label for="reportingPeriodYear" class="form-label text-sm">Year</label>
                        <input id="reportingPeriodYear" type="number" name="year" class="form-control form-control-sm"
                               min="2000" max="9999" step="1" value="{{ old('year', now()->year) }}" required>
                    </div>
                    <div class="col-xl-3 col-md-4">
                        <label for="reportingPeriodMonth" class="form-label text-sm">Month</label>
                        <select id="reportingPeriodMonth" name="month" class="form-control form-control-sm" required>
                            @foreach (range(1, 12) as $month)
                                <option value="{{ $month }}" @selected((int) old('month', now()->month) === $month)>
                                    {{ \Illuminate\Support\Carbon::create()->month($month)->format('F') }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-4">
                        <button type="submit"
                                class="btn btn-primary app-primary-action px-14 py-8 radius-8 d-inline-flex align-items-center gap-1">
                            <iconify-icon icon="mdi:calendar-plus-outline"></iconify-icon>
                            <span>Open Period</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endperm

    <div class="card basic-data-table">
        <div class="card-header">
            <h5 class="card-title mb-0">Reporting Months</h5>
        </div>
        <div class="card-body">
            @if ($periods->isEmpty())
                <div class="text-center text-muted py-24">No reporting periods have been opened.</div>
            @else
                <div class="table-responsive">
                    <table class="table bordered-table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Date Range</th>
                                <th>Status</th>
                                <th>Closed By</th>
                                <th>Last Activity</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($periods as $period)
                                <tr>
                                    <td class="fw-semibold">{{ sprintf('%04d-%02d', $period->year, $period->month) }}</td>
                                    <td>{{ $period->starts_on->format('d M Y') }} – {{ $period->ends_on->format('d M Y') }}</td>
                                    <td>
                                        <span class="badge text-sm fw-semibold px-12 py-7 radius-4 {{ $period->status->badgeClass() }}">
                                            {{ $period->status->label() }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($period->closed_at)
                                            {{ $period->closedBy?->name ?: 'User unavailable' }}
                                            <br><small class="text-muted">{{ $period->closed_at->format('d M Y, h:i A') }}</small>
                                        @else
                                            @php
                                                $closeEvent = $period->events->where('action', 'CLOSED')->sortByDesc('id')->first();
                                            @endphp
                                            @if ($period->status === \App\Enums\Reporting\ReportingPeriodStatus::Closed && $closeEvent)
                                                {{ $closeEvent->actor?->name ?: 'User unavailable' }}
                                                <br><small class="text-muted">{{ $closeEvent->created_at->format('d M Y, h:i A') }}</small>
                                            @else
                                                —
                                            @endif
                                        @endif
                                    </td>
                                    <td>
                                        @if ($period->events->isNotEmpty())
                                            <details>
                                                <summary class="text-primary-600 fw-medium" style="cursor:pointer">
                                                    {{ $period->events->count() }} event(s)
                                                </summary>
                                                <ul class="mb-0 mt-2 ps-3">
                                                    @foreach ($period->events as $event)
                                                        <li>
                                                            {{ str($event->action)->replace('_', ' ')->title() }}
                                                            · {{ $event->actor?->name ?: 'User unavailable' }}
                                                            · {{ $event->created_at->format('d M Y, h:i A') }}
                                                            @if ($event->reason)
                                                                <br><small class="text-muted">{{ $event->reason }}</small>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end align-items-center gap-2">
                                            @if ($period->status === \App\Enums\Reporting\ReportingPeriodStatus::Open)
                                                @perm('reporting.periods.close', 'edit')
                                                    @if (now()->toDateString() <= $period->ends_on->toDateString())
                                                        <span class="badge bg-warning-focus text-warning-main px-10 py-7 radius-6"
                                                              title="Close is available after {{ $period->ends_on->format('d M Y') }}">
                                                            Available after {{ $period->ends_on->format('d M Y') }}
                                                        </span>
                                                    @else
                                                        <form method="POST" action="{{ route('reporting.periods.close', $period) }}"
                                                              class="d-flex align-items-center gap-1">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="text" name="reason" class="form-control form-control-sm"
                                                               placeholder="Reason to close" maxlength="1000" required>
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Close</button>
                                                    </form>
                                                    @endif
                                                @endperm
                                            @else
                                                @perm('reporting.period-reopen.reopen', 'edit')
                                                    <form method="POST" action="{{ route('reporting.period-reopen.reopen', $period) }}"
                                                          class="d-flex align-items-center gap-1">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="text" name="reason" class="form-control form-control-sm"
                                                               placeholder="Reason to reopen" maxlength="1000" required>
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">Reopen</button>
                                                    </form>
                                                @endperm
                                            @endif
                                        </div>
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
