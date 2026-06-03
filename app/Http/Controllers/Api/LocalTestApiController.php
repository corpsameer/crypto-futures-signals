<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SimulatedTrade;
use App\Models\TradeSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocalTestApiController extends Controller
{
    public function signals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'batch' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $batch = $validated['batch'] ?? null;
        if ($batch === 'latest') {
            $batch = $this->latestBatchMarker();
        }

        $signals = TradeSignal::query()
            ->with(['simulatedTrades.trackingEvents', 'simulatedTrades.marketSnapshots'])
            ->where(function ($query): void {
                $query->where('trader_name', 'LocalE2E')
                    ->orWhere('symbol', 'like', 'E2E%');
            })
            ->when($batch, fn ($query) => $query->where('notes', 'like', '%'.$batch.'%'))
            ->latest('id')
            ->limit($validated['limit'] ?? 100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'batch' => $batch,
                'signals' => $signals,
            ],
        ]);
    }

    public function tradeState(SimulatedTrade $simulatedTrade): JsonResponse
    {
        $simulatedTrade->load([
            'tradeSignal',
            'trackingEvents' => fn ($query) => $query->orderBy('id'),
            'marketSnapshots' => fn ($query) => $query->orderBy('id'),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'trade' => $simulatedTrade,
                'trade_signal' => $simulatedTrade->tradeSignal,
                'tracking_events' => $simulatedTrade->trackingEvents,
                'market_snapshots' => $simulatedTrade->marketSnapshots,
            ],
        ]);
    }

    public function signalState(TradeSignal $tradeSignal): JsonResponse
    {
        $tradeSignal->load([
            'simulatedTrades' => fn ($query) => $query->orderBy('id'),
            'simulatedTrades.trackingEvents' => fn ($query) => $query->orderBy('id'),
            'simulatedTrades.marketSnapshots' => fn ($query) => $query->orderBy('id'),
        ]);

        $events = $tradeSignal->simulatedTrades
            ->flatMap(fn (SimulatedTrade $trade) => $trade->trackingEvents)
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'trade_signal' => $tradeSignal,
                'simulated_trades' => $tradeSignal->simulatedTrades,
                'tracking_events' => $events,
            ],
        ]);
    }

    private function latestBatchMarker(): ?string
    {
        $notes = TradeSignal::query()
            ->where('trader_name', 'LocalE2E')
            ->where('notes', 'like', '%LOCAL_E2E_TEST_BATCH_%')
            ->latest('id')
            ->value('notes');

        if (! is_string($notes) || $notes === '') {
            return null;
        }

        if (preg_match('/LOCAL_E2E_TEST_BATCH_\d{14}/', $notes, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
