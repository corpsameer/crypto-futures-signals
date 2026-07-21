<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use App\Models\PastedSignal;
use App\Models\TradeSignal;
use App\Services\SignalParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PastedSignalController extends Controller
{
    public function index(): View
    {
        $signals = PastedSignal::query()
            ->with('latestTradeSignal')
            ->where('user_id', auth()->id())
            ->orderByDesc('pasted_at')
            ->orderByDesc('id')
            ->paginate(15);

        return view('signals.index', [
            'signals' => $signals,
        ]);
    }

    public function create(): View
    {
        return view('signals.create');
    }

    public function store(Request $request, SignalParserService $parser): RedirectResponse
    {
        $sourceValidated = $request->validate([
            'signal_source' => ['nullable', 'in:'.TradeSignal::SOURCE_TELEGRAM.','.TradeSignal::SOURCE_COINDCX],
            'trader_name' => ['nullable', 'string', 'max:255'],
        ]);

        $signalSource = $sourceValidated['signal_source'] ?? TradeSignal::SOURCE_TELEGRAM;

        if ($signalSource === TradeSignal::SOURCE_COINDCX) {
            $request->merge([
                'coindcx_symbol' => trim((string) $request->input('coindcx_symbol')),
                'coindcx_direction' => strtoupper(trim((string) $request->input('coindcx_direction'))),
            ]);

            $validated = $request->validate([
                'trader_name' => ['nullable', 'string', 'max:255'],
                'coindcx_symbol' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]{2,15}\s*(?:\/|\-|\s)?\s*USDT$/i'],
                'coindcx_direction' => ['required', 'in:LONG,SHORT'],
                'coindcx_leverage' => ['required', 'integer', 'min:1'],
                'coindcx_entry_price_1' => ['required', 'numeric', 'gt:0'],
                'coindcx_entry_price_2' => ['required', 'numeric', 'gt:0'],
                'coindcx_stop_loss' => ['required', 'numeric', 'gt:0'],
                'coindcx_take_profit' => ['required', 'numeric', 'gt:0'],
                'coindcx_expected_profit' => ['nullable', 'numeric', 'min:0'],
            ], [
                'coindcx_symbol.regex' => 'Enter a CoinDCX symbol such as SOL/USDT or SOLUSDT.',
            ], [
                'coindcx_symbol' => 'symbol',
                'coindcx_direction' => 'direction',
                'coindcx_leverage' => 'leverage',
                'coindcx_entry_price_1' => 'entry price 1',
                'coindcx_entry_price_2' => 'entry price 2',
                'coindcx_stop_loss' => 'stop loss',
                'coindcx_take_profit' => 'take profit',
                'coindcx_expected_profit' => 'expected profit',
            ]);

            $relationshipError = $this->validateCoinDcxPriceRelationship($validated);
            if ($relationshipError !== null) {
                return back()
                    ->withErrors(['coindcx_stop_loss' => $relationshipError])
                    ->withInput();
            }

            $parserResult = $this->buildManualCoinDcxParserResult($validated);
            $rawText = $this->buildManualCoinDcxRawText($validated);
        } else {
            $validated = $request->validate([
                'trader_name' => ['nullable', 'string', 'max:255'],
                'raw_text' => ['required', 'string', 'min:10'],
            ]);

            $parserResult = $parser->parse($validated['raw_text']);
            $rawText = $validated['raw_text'];
        }

        if (isset($parserResult['data']) && is_array($parserResult['data'])) {
            $parserResult['data']['signal_source'] = $signalSource;
        }

        $parsedTraderName = $parserResult['data']['trader_name'] ?? null;
        $traderName = $validated['trader_name'] ?? $parsedTraderName;

        $pastedSignal = PastedSignal::create([
            'user_id' => auth()->id(),
            'trader_name' => $traderName,
            'raw_text' => $rawText,
            'parsed_payload' => $parserResult,
            'parse_status' => $parserResult['success']
                ? PastedSignal::PARSE_STATUS_PARSED
                : PastedSignal::PARSE_STATUS_FAILED,
            'parse_error' => $parserResult['success'] ? null : implode('; ', $parserResult['errors']),
            'source' => PastedSignal::SOURCE_MANUAL_PASTE,
            'pasted_at' => now(),
        ]);

        if (! $parserResult['success']) {
            Log::warning('[CFS Parser] Parse failed pasted_signal_id='.$pastedSignal->id, [
                'errors' => $parserResult['errors'] ?? [],
                'raw_preview' => substr($rawText, 0, 200),
            ]);
        } elseif (! empty($parserResult['warnings'])) {
            Log::info('[CFS Parser] Parse succeeded with warnings pasted_signal_id='.$pastedSignal->id, [
                'warnings' => $parserResult['warnings'],
            ]);
        } else {
            Log::info('[CFS Parser] Parse succeeded pasted_signal_id='.$pastedSignal->id.' symbol='.($parserResult['data']['symbol'] ?? '').' trader='.($traderName ?? ''));
        }

        $message = $parserResult['success']
            ? 'Signal pasted and parsed successfully. Please review before saving.'
            : 'Signal pasted, but parser could not fully understand it. Please review and complete the fields manually.';

        return redirect()
            ->route('cryptofuturesignals.signals.preview', $pastedSignal)
            ->with('success', $message);
    }

    public function preview(PastedSignal $pastedSignal): View
    {
        $this->authorizePastedSignalOwner($pastedSignal);

        $parsedPayload = is_array($pastedSignal->parsed_payload) ? $pastedSignal->parsed_payload : [];
        $parsedData = isset($parsedPayload['data']) && is_array($parsedPayload['data'])
            ? $parsedPayload['data']
            : [];

        return view('signals.preview', [
            'pastedSignal' => $pastedSignal,
            'parsedData' => $parsedData,
            'parserWarnings' => isset($parsedPayload['warnings']) && is_array($parsedPayload['warnings']) ? $parsedPayload['warnings'] : [],
            'parserErrors' => isset($parsedPayload['errors']) && is_array($parsedPayload['errors']) ? $parsedPayload['errors'] : [],
            'parserMeta' => isset($parsedPayload['meta']) && is_array($parsedPayload['meta']) ? $parsedPayload['meta'] : [],
        ]);
    }

    public function confirm(Request $request, PastedSignal $pastedSignal): RedirectResponse
    {
        $this->authorizePastedSignalOwner($pastedSignal);

        $parsedPayload = is_array($pastedSignal->parsed_payload) ? $pastedSignal->parsed_payload : [];
        $parsedData = isset($parsedPayload['data']) && is_array($parsedPayload['data']) ? $parsedPayload['data'] : [];

        $request->merge([
            'symbol' => strtoupper(str_replace([' ', '/'], '', (string) $request->input('symbol'))),
            'pair' => $request->filled('pair') ? strtoupper((string) $request->input('pair')) : null,
            'direction' => strtoupper((string) $request->input('direction')),
        ]);

        $validated = $request->validate([
            'trader_name' => ['nullable', 'string', 'max:255'],
            'exchange' => ['required', 'string', 'max:50'],
            'symbol' => ['required', 'string', 'max:50'],
            'pair' => ['nullable', 'string', 'max:50'],
            'market_type' => ['required', 'string', 'max:50'],
            'direction' => ['required', 'in:LONG,SHORT'],
            'leverage' => ['nullable', 'numeric', 'min:1', 'max:125'],
            'margin_mode' => ['nullable', 'string', 'max:50'],
            'entry_min' => ['required', 'numeric'],
            'entry_max' => ['required', 'numeric'],
            'entry_type' => ['nullable', 'string', 'max:50'],
            'stop_loss' => ['required', 'numeric'],
            'tp1' => ['required', 'numeric'],
            'tp2' => ['nullable', 'numeric'],
            'tp3' => ['nullable', 'numeric'],
            'tp4' => ['nullable', 'numeric'],
            'signal_time' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $source = $parsedData['signal_source'] ?? TradeSignal::SOURCE_TELEGRAM;
        $validated['entry_type'] = in_array($validated['entry_type'] ?? null, ['single', 'range'], true)
            ? $validated['entry_type']
            : 'single';

        if ($source === TradeSignal::SOURCE_COINDCX) {
            $validated['entry_type'] = 'range';
            $validated['entry_min'] = $parsedData['entry_min'] ?? $validated['entry_min'];
            $validated['entry_max'] = $parsedData['entry_max'] ?? $validated['entry_max'];
            $validated['entry_price_min'] = $parsedData['entry_price_min'] ?? $parsedData['entry_min'] ?? $validated['entry_min'];
            $validated['entry_price_max'] = $parsedData['entry_price_max'] ?? $parsedData['entry_max'] ?? $validated['entry_max'];
            $validated['entry_price'] = $parsedData['entry_price'] ?? $this->decimalMidpoint((string) $validated['entry_price_min'], (string) $validated['entry_price_max']);
            $validated['tp2'] = null;
            $validated['tp3'] = null;
            $validated['tp4'] = null;
        }

        $validated['signal_source'] = $source;
        if ($validated['signal_source'] === TradeSignal::SOURCE_COINDCX) {
            $validated['source_image_path'] = null;
        }

        [$validated['entry_min'], $validated['entry_max']] = $this->normalizeDecimalBounds((string) $validated['entry_min'], (string) $validated['entry_max']);
        $validated['entry_price_min'] = $validated['entry_price_min'] ?? $validated['entry_min'];
        $validated['entry_price_max'] = $validated['entry_price_max'] ?? $validated['entry_max'];
        [$validated['entry_price_min'], $validated['entry_price_max']] = $this->normalizeDecimalBounds((string) $validated['entry_price_min'], (string) $validated['entry_price_max']);
        $validated['entry_price'] = $validated['entry_price'] ?? $this->decimalMidpoint((string) $validated['entry_price_min'], (string) $validated['entry_price_max']);

        if ($validated['signal_source'] !== TradeSignal::SOURCE_COINDCX) {
            $validated['entry_type'] = 'single';
            $validated['entry_price'] = $this->decimalMidpoint((string) $validated['entry_min'], (string) $validated['entry_max']);
            $validated['entry_price_min'] = $validated['entry_price'];
            $validated['entry_price_max'] = $validated['entry_price'];
        }

        $tradeSignal = TradeSignal::updateOrCreate(
            ['pasted_signal_id' => $pastedSignal->id],
            array_merge($validated, [
                'pasted_signal_id' => $pastedSignal->id,
                'user_id' => auth()->id(),
                'status' => TradeSignal::STATUS_PENDING_ENTRY,
            ])
        );

        $capturedAt = now();
        $marketSnapshotPayload = [
            'simulated_trade_id' => null,
            'symbol' => $tradeSignal->symbol ?: 'MARKET',
            'market_condition' => null,
            'btc_price' => null,
            'btc_24h_change_percent' => null,
            'eth_price' => null,
            'eth_24h_change_percent' => null,
            'captured_at' => $capturedAt,
            'raw_payload' => [
                'source' => 'laravel_signal_confirm',
                'note' => 'Market prices will be captured by Python monitor when available',
            ],
        ];

        if (Schema::hasColumn('market_snapshots', 'snapshot_at')) {
            $marketSnapshotPayload['snapshot_at'] = $capturedAt;
        }

        MarketSnapshot::updateOrCreate(
            [
                'trade_signal_id' => $tradeSignal->id,
                'snapshot_type' => MarketSnapshot::SNAPSHOT_SIGNAL_SAVED,
            ],
            $marketSnapshotPayload
        );

        $pastedSignal->update([
            'trader_name' => $validated['trader_name'] ?? null,
            'parse_status' => PastedSignal::PARSE_STATUS_MANUALLY_CORRECTED,
            'parse_error' => null,
            'parsed_payload' => [
                'success' => true,
                'data' => $validated,
                'warnings' => isset($parsedPayload['warnings']) && is_array($parsedPayload['warnings']) ? $parsedPayload['warnings'] : [],
                'errors' => [],
                'confirmed_manually' => true,
                'confirmed_at' => now()->toDateTimeString(),
            ],
        ]);

        return redirect()
            ->route('cryptofuturesignals.signals.index')
            ->with('success', 'Structured trade signal saved successfully.');
    }

    /** @param array<string, mixed> $validated */
    private function buildManualCoinDcxParserResult(array $validated): array
    {
        [$symbol, $pair] = $this->normalizeManualCoinDcxSymbol((string) $validated['coindcx_symbol']);
        [$entryMin, $entryMax] = $this->normalizeDecimalBounds((string) $validated['coindcx_entry_price_1'], (string) $validated['coindcx_entry_price_2']);
        $entryMidpoint = $this->decimalMidpoint($entryMin, $entryMax);

        return [
            'success' => true,
            'data' => [
                'symbol' => $symbol,
                'pair' => $pair,
                'trader_name' => null,
                'direction' => $validated['coindcx_direction'],
                'leverage' => (int) $validated['coindcx_leverage'],
                'margin_mode' => null,
                'entry_min' => $entryMin,
                'entry_max' => $entryMax,
                'entry_type' => 'range',
                'entry_price' => $entryMidpoint,
                'entry_price_min' => $entryMin,
                'entry_price_max' => $entryMax,
                'stop_loss' => $this->normalizeDecimalString((string) $validated['coindcx_stop_loss']),
                'tp1' => $this->normalizeDecimalString((string) $validated['coindcx_take_profit']),
                'tp2' => null,
                'tp3' => null,
                'tp4' => null,
                'market_type' => TradeSignal::MARKET_TYPE_FUTURES,
                'exchange' => 'coindcx',
                'signal_time' => null,
                'notes' => null,
            ],
            'warnings' => [],
            'errors' => [],
            'meta' => [
                'source' => 'coindcx_manual_entry',
                'entry_range_min' => $entryMin,
                'entry_range_max' => $entryMax,
                'expected_profit_percent' => $validated['coindcx_expected_profit'] !== null
                    ? $this->normalizeDecimalString((string) $validated['coindcx_expected_profit'])
                    : null,
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function validateCoinDcxPriceRelationship(array $validated): ?string
    {
        [$entryMin, $entryMax] = $this->normalizeDecimalBounds((string) $validated['coindcx_entry_price_1'], (string) $validated['coindcx_entry_price_2']);
        $stopLoss = $this->normalizeDecimalString((string) $validated['coindcx_stop_loss']);
        $takeProfit = $this->normalizeDecimalString((string) $validated['coindcx_take_profit']);

        if ($validated['coindcx_direction'] === TradeSignal::DIRECTION_LONG) {
            return bccomp($stopLoss, $entryMin, 12) === -1 && bccomp($takeProfit, $entryMax, 12) === 1
                ? null
                : 'For a LONG signal, Stop Loss must be below the entry range and Take Profit must be above it.';
        }

        return bccomp($stopLoss, $entryMax, 12) === 1 && bccomp($takeProfit, $entryMin, 12) === -1
            ? null
            : 'For a SHORT signal, Stop Loss must be above the entry range and Take Profit must be below it.';
    }

    /** @return array{0: string, 1: string} */
    private function normalizeManualCoinDcxSymbol(string $symbol): array
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $symbol) ?? $symbol);
        $base = preg_replace('/USDT$/', '', $normalized) ?? $normalized;

        return [$base.'USDT', $base.'/USDT'];
    }

    /** @param array<string, mixed> $validated */
    private function buildManualCoinDcxRawText(array $validated): string
    {
        $lines = [
            'CoinDCX Expert Pick',
            'Symbol: '.$validated['coindcx_symbol'],
            'Direction: '.$validated['coindcx_direction'],
            'Leverage: '.$validated['coindcx_leverage'].'x',
            'Entry Range: '.$validated['coindcx_entry_price_1'].' - '.$validated['coindcx_entry_price_2'],
            'Stop Loss: '.$validated['coindcx_stop_loss'],
            'Take Profit: '.$validated['coindcx_take_profit'],
        ];

        if ($validated['coindcx_expected_profit'] !== null) {
            $lines[] = 'Expected Profit: '.$validated['coindcx_expected_profit'].'%';
        }

        return implode("\n", $lines);
    }

    /** @return array{0: string, 1: string} */
    private function normalizeDecimalBounds(string $first, string $second): array
    {
        $first = $this->normalizeDecimalString($first);
        $second = $this->normalizeDecimalString($second);

        return bccomp($first, $second, 12) <= 0 ? [$first, $second] : [$second, $first];
    }

    private function decimalMidpoint(string $minimum, string $maximum): string
    {
        return $this->normalizeDecimalString(bcdiv(bcadd($minimum, $maximum, 12), '2', 12));
    }

    private function normalizeDecimalString(string $value): string
    {
        $value = trim($value);

        if (stripos($value, 'e') !== false) {
            $value = rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');
        }

        if (! str_contains($value, '.')) {
            $integer = ltrim($value, '+');
            $integer = ltrim($integer, '0');

            return $integer === '' || $integer === '-0' ? '0' : $integer;
        }

        [$integer, $fraction] = explode('.', ltrim($value, '+'), 2);
        $integer = ltrim($integer, '0');
        $fraction = rtrim(substr($fraction, 0, 12), '0');

        $normalized = ($integer === '' || $integer === '-' ? '0' : $integer).($fraction === '' ? '' : '.'.$fraction);

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function authorizePastedSignalOwner(PastedSignal $pastedSignal): void
    {
        if ((int) $pastedSignal->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
