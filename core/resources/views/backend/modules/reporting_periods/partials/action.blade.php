<div class="d-inline-flex flex-wrap justify-content-end align-items-center gap-2">
    @if ($period->status === \App\Enums\Reporting\ReportingPeriodStatus::Open)
        @perm('reporting.periods.close', 'edit')
            @if (now()->toDateString() <= $period->ends_on->toDateString())
                <span
                    class="badge bg-warning-focus text-warning-main px-10 py-7 radius-6"
                    title="Close is available after {{ $period->ends_on->format('d M Y') }}"
                >
                    Available after {{ $period->ends_on->format('d M Y') }}
                </span>
            @else
                <form
                    method="POST"
                    action="{{ route('reporting.periods.close', $period) }}"
                    class="d-inline-flex flex-nowrap align-items-center justify-content-end gap-2"
                >
                    @csrf
                    @method('PATCH')
                    <input
                        type="text"
                        name="reason"
                        class="form-control form-control-sm"
                        style="width: 180px; min-width: 180px;"
                        placeholder="Reason to close"
                        maxlength="1000"
                        required
                    >
                    <button
                        type="submit"
                        class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-1 flex-shrink-0"
                    >
                        <iconify-icon icon="mdi:calendar-lock-outline"></iconify-icon>
                        <span>Close Period</span>
                    </button>
                </form>
            @endif
        @endperm
    @else
        @perm('reporting.period-reopen.reopen', 'edit')
            <form
                method="POST"
                action="{{ route('reporting.period-reopen.reopen', $period) }}"
                class="d-inline-flex flex-nowrap align-items-center justify-content-end gap-2"
            >
                @csrf
                @method('PATCH')
                <input
                    type="text"
                    name="reason"
                    class="form-control form-control-sm"
                    style="width: 180px; min-width: 180px;"
                    placeholder="Reason to reopen"
                    maxlength="1000"
                    required
                >
                <button
                    type="submit"
                    class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 flex-shrink-0"
                >
                    <iconify-icon icon="mdi:calendar-refresh-outline"></iconify-icon>
                    <span>Reopen Period</span>
                </button>
            </form>
        @endperm
    @endif
</div>
