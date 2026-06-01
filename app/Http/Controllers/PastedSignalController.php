<?php

namespace App\Http\Controllers;

use App\Models\PastedSignal;
use App\Services\SignalParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PastedSignalController extends Controller
{
    public function index(): View
    {
        $signals = PastedSignal::query()
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

        PastedSignal::create([
            'user_id' => auth()->id(),
            'trader_name' => $validated['trader_name'] ?? null,
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
            ? 'Signal pasted and parsed successfully.'
            : 'Signal pasted, but parser could not fully understand it. Preview/edit will be handled in next step.';

        return redirect()
            ->route('cryptofuturesignals.signals.index')
            ->with('success', $message);
    }
}
