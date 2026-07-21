@props([
    'selected' => null,
    'id' => 'source',
    'label' => 'Source',
])

<select name="source" id="{{ $id }}" {{ $attributes->class(['form-select']) }}>
    <option value="" @selected($selected === null || $selected === '')>All Sources</option>
    <option value="{{ \App\Models\TradeSignal::SOURCE_TELEGRAM }}" @selected($selected === \App\Models\TradeSignal::SOURCE_TELEGRAM)>Telegram</option>
    <option value="{{ \App\Models\TradeSignal::SOURCE_COINDCX }}" @selected($selected === \App\Models\TradeSignal::SOURCE_COINDCX)>CoinDCX Expert Pick</option>
</select>
