@if ($period->events->isNotEmpty())
    <details>
        <summary class="text-primary-600 fw-medium" style="cursor: pointer">
            {{ $period->events->count() }} event(s)
        </summary>
        <ul class="mb-0 mt-2 ps-3">
            @foreach ($period->events as $event)
                <li>
                    {{ str($event->action)->replace('_', ' ')->title() }}
                    &middot; {{ $event->actor?->name ?: 'User unavailable' }}
                    &middot; {{ $event->created_at->format('d M Y, h:i A') }}
                    @if ($event->reason)
                        <br>
                        <small class="text-muted">{{ $event->reason }}</small>
                    @endif
                </li>
            @endforeach
        </ul>
    </details>
@else
    <span class="text-muted">&mdash;</span>
@endif
