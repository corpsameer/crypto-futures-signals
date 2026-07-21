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

        $validated['signal_source'] = $parsedData['signal_source'] ?? TradeSignal::SOURCE_TELEGRAM;
        if ($validated['signal_source'] === TradeSignal::SOURCE_COINDCX) {
            $validated['source_image_path'] = null;
        }

        [$validated['entry_min'], $validated['entry_max']] = [
            min((float) $validated['entry_min'], (float) $validated['entry_max']),
            max((float) $validated['entry_min'], (float) $validated['entry_max']),
        ];

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
        $entryOne = (float) $validated['coindcx_entry_price_1'];
        $entryTwo = (float) $validated['coindcx_entry_price_2'];
        $entryMin = min($entryOne, $entryTwo);
        $entryMax = max($entryOne, $entryTwo);
        $entryMidpoint = $this->normalizeDecimal(($entryMin + $entryMax) / 2);

        return [
            'success' => true,
            'data' => [
                'symbol' => $symbol,
                'pair' => $pair,
                'trader_name' => null,
                'direction' => $validated['coindcx_direction'],
                'leverage' => (int) $validated['coindcx_leverage'],
                'margin_mode' => null,
                'entry_min' => $entryMidpoint,
                'entry_max' => $entryMidpoint,
                'entry_type' => 'single',
                'stop_loss' => $this->normalizeDecimal((float) $validated['coindcx_stop_loss']),
                'tp1' => $this->normalizeDecimal((float) $validated['coindcx_take_profit']),
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
                'entry_range_min' => $this->normalizeDecimal($entryMin),
                'entry_range_max' => $this->normalizeDecimal($entryMax),
                'expected_profit_percent' => $validated['coindcx_expected_profit'] !== null
                    ? $this->normalizeDecimal((float) $validated['coindcx_expected_profit'])
                    : null,
            ],
        ];
    }

    /** @param array<string, mixed> $validated */
    private function validateCoinDcxPriceRelationship(array $validated): ?string
    {
        $entryOne = (float) $validated['coindcx_entry_price_1'];
        $entryTwo = (float) $validated['coindcx_entry_price_2'];
        $entryMin = min($entryOne, $entryTwo);
        $entryMax = max($entryOne, $entryTwo);
        $stopLoss = (float) $validated['coindcx_stop_loss'];
        $takeProfit = (float) $validated['coindcx_take_profit'];

        if ($validated['coindcx_direction'] === TradeSignal::DIRECTION_LONG) {
            return $stopLoss < $entryMin && $takeProfit > $entryMax
                ? null
                : 'For a LONG signal, Stop Loss must be below the entry range and Take Profit must be above it.';
        }

        return $stopLoss > $entryMax && $takeProfit < $entryMin
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

    private function normalizeDecimal(float $value): int|float
    {
        $normalized = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
    }

    private function authorizePastedSignalOwner(PastedSignal $pastedSignal): void
    {
        if ((int) $pastedSignal->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
