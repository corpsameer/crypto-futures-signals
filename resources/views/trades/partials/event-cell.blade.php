@php
    $formatPercent = fn ($value) => $value === null ? 'N/A' : rtrim(rtrim(number_format((float) $value, 4), '0'), '.').'%';
@endphp

@if ($event)
    <div class="small text-nowrap">
        <div><span class="text-muted">Price:</span> {{ $event->event_price ?? 'N/A' }}</div>
        <div><span class="text-muted">Actual:</span> {{ $formatPercent($event->actual_price_move_percent) }}</div>
        <div><span class="text-muted">Lev:</span> {{ $formatPercent($event->leveraged_pnl_percent) }}</div>
        <div><span class="text-muted">Time:</span> {{ $event->event_timestamp?->format('Y-m-d H:i') ?? 'N/A' }}</div>
    </div>
@else
    <span class="text-muted">—</span>
@endif
