<?php

namespace App\Http\Controllers;

use App\Models\PastedSignal;
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

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'trader_name' => ['nullable', 'string', 'max:255'],
            'raw_text' => ['required', 'string', 'min:10'],
        ]);

        PastedSignal::create([
            'user_id' => auth()->id(),
            'trader_name' => $validated['trader_name'] ?? null,
            'raw_text' => $validated['raw_text'],
            'parse_status' => PastedSignal::PARSE_STATUS_PENDING,
            'source' => PastedSignal::SOURCE_MANUAL_PASTE,
            'pasted_at' => now(),
        ]);

        return redirect()
            ->route('cryptofuturesignals.signals.index')
            ->with('success', 'Signal pasted successfully. Parser will be added in next step.');
    }
}
