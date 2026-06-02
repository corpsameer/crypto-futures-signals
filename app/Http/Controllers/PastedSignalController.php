<?php

namespace App\Http\Controllers;

use App\Models\PastedSignal;
use App\Models\TradeSignal;
use App\Services\SignalParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PastedSignalController extends Controller
{
    public function index(): View
    {
        $signals = PastedSignal::query()
            ->with('tradeSignals')
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
        $validated = $request->validate([
            'trader_name' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['required', 'string', 'min:10'],
        ]);

        $parserResult = $parser->parse($validated['raw_text']);
        $parsedTraderName = $parserResult['data']['trader_name'] ?? null;
        $traderName = $validated['trader_name'] ?? $parsedTraderName;

        $pastedSignal = PastedSignal::create([
            'user_id' => auth()->id(),
            'trader_name' => $traderName,
            'raw_text' => $validated['raw_text'],
            'parsed_payload' => $parserResult,
            'parse_status' => $parserResult['success']
                ? PastedSignal::PARSE_STATUS_PARSED
                : PastedSignal::PARSE_STATUS_FAILED,
            'parse_error' => $parserResult['success'] ? null : implode('; ', $parserResult['errors']),
            'source' => PastedSignal::SOURCE_MANUAL_PASTE,
            'pasted_at' => now(),
        ]);

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

        [$validated['entry_min'], $validated['entry_max']] = [
            min((float) $validated['entry_min'], (float) $validated['entry_max']),
            max((float) $validated['entry_min'], (float) $validated['entry_max']),
        ];

        TradeSignal::updateOrCreate(
            ['pasted_signal_id' => $pastedSignal->id],
            array_merge($validated, [
                'pasted_signal_id' => $pastedSignal->id,
                'user_id' => auth()->id(),
                'status' => TradeSignal::STATUS_PENDING_ENTRY,
            ])
        );

        $parsedPayload = is_array($pastedSignal->parsed_payload) ? $pastedSignal->parsed_payload : [];

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

    private function authorizePastedSignalOwner(PastedSignal $pastedSignal): void
    {
        if ((int) $pastedSignal->user_id !== (int) auth()->id()) {
            abort(403);
        }
    }
}
