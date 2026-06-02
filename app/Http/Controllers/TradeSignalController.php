<?php

namespace App\Http\Controllers;

use App\Models\TradeSignal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TradeSignalController extends Controller
{
    /**
     * @var list<string>
     */
    private const AVAILABLE_STATUSES = [
        TradeSignal::STATUS_PENDING_ENTRY,
        TradeSignal::STATUS_ENTRY_TRIGGERED,
        TradeSignal::STATUS_ENTRY_MISSED,
        TradeSignal::STATUS_ACTIVE,
        TradeSignal::STATUS_CLOSED_SL,
        TradeSignal::STATUS_CLOSED_TP,
        TradeSignal::STATUS_TRACKING_AFTER_SL,
        TradeSignal::STATUS_EXPIRED,
        TradeSignal::STATUS_COMPLETED,
        TradeSignal::STATUS_INVALID,
    ];

    /**
     * @var list<string>
     */
    private const AVAILABLE_DIRECTIONS = [
        TradeSignal::DIRECTION_LONG,
        TradeSignal::DIRECTION_SHORT,
    ];

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'status' => trim((string) $request->query('status', '')),
            'direction' => trim((string) $request->query('direction', '')),
            'trader_name' => trim((string) $request->query('trader_name', '')),
        ];

        $tradeSignals = TradeSignal::query()
            ->with('pastedSignal')
            ->withCount('simulatedTrades')
            ->where('user_id', auth()->id())
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function ($query) use ($search): void {
                    $query->where('symbol', 'like', "%{$search}%")
                        ->orWhere('pair', 'like', "%{$search}%")
                        ->orWhere('trader_name', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['direction'] !== '', fn ($query) => $query->where('direction', $filters['direction']))
            ->when($filters['trader_name'] !== '', fn ($query) => $query->where('trader_name', 'like', "%{$filters['trader_name']}%"))
            ->orderByDesc('signal_time')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('trade_signals.index', [
            'tradeSignals' => $tradeSignals,
            'filters' => $filters,
            'availableStatuses' => self::AVAILABLE_STATUSES,
            'availableDirections' => self::AVAILABLE_DIRECTIONS,
        ]);
    }

    public function show(TradeSignal $tradeSignal): View
    {
        if ((int) $tradeSignal->user_id !== (int) auth()->id()) {
            abort(403);
        }

        $tradeSignal->load([
            'pastedSignal',
            'simulatedTrades',
            'trackingEvents',
            'marketSnapshots',
        ]);

        return view('trade_signals.show', [
            'tradeSignal' => $tradeSignal,
        ]);
    }
}
