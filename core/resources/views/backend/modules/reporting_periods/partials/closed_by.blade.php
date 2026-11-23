@if ($period->closed_at)
    {{ $period->closedBy?->name ?: 'User unavailable' }}
    <br>
    <small class="text-muted">{{ $period->closed_at->format('d M Y, h:i A') }}</small>
@elseif ($period->status === \App\Enums\Reporting\ReportingPeriodStatus::Closed && $closeEvent)
    {{ $closeEvent->actor?->name ?: 'User unavailable' }}
    <br>
    <small class="text-muted">{{ $closeEvent->created_at->format('d M Y, h:i A') }}</small>
@else
    <span class="text-muted">&mdash;</span>
@endif
