@props(['source' => null])

@php
    $sourceValue = $source instanceof \App\Models\TradeSignal ? $source->signal_source : $source;
    $sourceValue = is_string($sourceValue) ? trim($sourceValue) : null;
    $label = \App\Models\TradeSignal::sourceLabel($sourceValue);
    $badgeClass = \App\Models\TradeSignal::sourceBadgeClass($sourceValue);
@endphp

<span {{ $attributes->class(['badge', $badgeClass]) }}>{{ $label }}</span>
