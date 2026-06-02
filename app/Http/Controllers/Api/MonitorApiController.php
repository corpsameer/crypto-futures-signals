<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketSnapshot;
use App\Models\SimulatedTrade;
use App\Models\TradeSignal;
use App\Models\TradeTrackingEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class MonitorApiController extends Controller
{
    public function pendingSignals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'symbol' => ['nullable', 'string', 'max:50'],
        ]);

        $signals = TradeSignal::query()
            ->select([
                'id',
                'symbol',
                'pair',
                'direction',
                'leverage',
                'entry_min',
                'entry_max',
                'stop_loss',
                'tp1',
                'tp2',
                'tp3',
                'tp4',
                'status',
                'trader_name',
                'signal_time',
                'expires_at',
                'created_at',
            ])
            ->where('status', TradeSignal::STATUS_PENDING_ENTRY)
            ->when(! empty($validated['symbol']), fn ($query) => $query->where('symbol', $validated['symbol']))
            ->latest('id')
            ->limit($validated['limit'] ?? 100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $signals,
        ]);
    }

    public function markEntryMissed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_signal_id' => ['required', 'integer', 'exists:trade_signals,id'],
            'missed_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'current_price' => ['nullable', 'numeric'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $tradeSignal = TradeSignal::query()->lockForUpdate()->findOrFail($validated['trade_signal_id']);
            $missedAt = $this->dateOrNow($validated['missed_at'] ?? null);
            $reason = $validated['reason'] ?? 'Entry not triggered within configured validity window';
            $currentPrice = $validated['current_price'] ?? null;

            if ($tradeSignal->status !== TradeSignal::STATUS_PENDING_ENTRY) {
                return [
                    'message' => 'Signal is not pending; no change made.',
                    'trade_signal' => $tradeSignal->fresh(),
                    'simulated_trade' => null,
                    'event' => null,
                ];
            }

            $expiryNote = sprintf('[%s] Entry missed: %s', $missedAt->toDateTimeString(), $reason);
            $notes = trim((string) $tradeSignal->notes);

            $tradeSignal->fill([
                'status' => TradeSignal::STATUS_ENTRY_MISSED,
                'expires_at' => $tradeSignal->expires_at ?? $missedAt,
                'notes' => $notes === '' ? $expiryNote : $notes.PHP_EOL.$expiryNote,
            ])->save();

            $simulatedTrade = SimulatedTrade::query()
                ->where('trade_signal_id', $tradeSignal->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $event = null;
            if ($simulatedTrade) {
                $simulatedTrade->update([
                    'status' => SimulatedTrade::STATUS_EXPIRED,
                    'closed_at' => $missedAt,
                    'exit_reason' => TradeTrackingEvent::EVENT_TRADE_EXPIRED,
                    'exit_price' => $currentPrice,
                ]);

                $event = TradeTrackingEvent::updateOrCreate(
                    [
                        'simulated_trade_id' => $simulatedTrade->id,
                        'event_type' => TradeTrackingEvent::EVENT_TRADE_EXPIRED,
                    ],
                    [
                        'trade_signal_id' => $tradeSignal->id,
                        'event_price' => $currentPrice ?? 0,
                        'actual_price_move_percent' => 0,
                        'leveraged_pnl_percent' => 0,
                        'event_timestamp' => $missedAt,
                        'metadata' => [
                            'source' => 'python_monitor',
                            'reason' => $reason,
                            'phase' => 'entry_expiry',
                        ],
                        'notes' => $expiryNote,
                    ]
                );
            }

            return [
                'message' => 'Signal marked entry_missed successfully.',
                'trade_signal' => $tradeSignal->fresh(),
                'simulated_trade' => $simulatedTrade?->fresh('tradeSignal'),
                'event' => $event,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'trade_signal' => $result['trade_signal'],
                'simulated_trade' => $result['simulated_trade'] ? $this->formatTrade($result['simulated_trade']) : null,
                'event' => $result['event'],
            ],
        ]);
    }

    public function activeTrades(Request $request): JsonResponse
    {
        return $this->tradesByStatus($request, SimulatedTrade::STATUS_ACTIVE);
    }

    public function postSlTrackingTrades(Request $request): JsonResponse
    {
        return $this->tradesByStatus($request, SimulatedTrade::STATUS_TRACKING_AFTER_SL, true);
    }

    public function entryTriggered(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_signal_id' => ['required', 'integer', 'exists:trade_signals,id'],
            'entry_price' => ['required', 'numeric'],
            'current_price' => ['nullable', 'numeric'],
            'event_timestamp' => ['nullable', 'date'],
            'actual_price_move_percent' => ['nullable', 'numeric'],
            'leveraged_pnl_percent' => ['nullable', 'numeric'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $tradeSignal = TradeSignal::query()->lockForUpdate()->findOrFail($validated['trade_signal_id']);
            $eventTimestamp = $this->dateOrNow($validated['event_timestamp'] ?? null);
            $actualMove = $validated['actual_price_move_percent'] ?? 0;
            $leveragedPnl = $validated['leveraged_pnl_percent'] ?? 0;
            $entryPrice = $validated['entry_price'];
            $currentPrice = $validated['current_price'] ?? $entryPrice;

            $simulatedTrade = SimulatedTrade::query()
                ->where('trade_signal_id', $tradeSignal->id)
                ->whereIn('status', [SimulatedTrade::STATUS_ACTIVE, SimulatedTrade::STATUS_TRACKING_AFTER_SL])
                ->lockForUpdate()
                ->first();

            if (! $simulatedTrade) {
                $simulatedTrade = SimulatedTrade::query()
                    ->where('trade_signal_id', $tradeSignal->id)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();
            }

            $payload = [
                'trade_signal_id' => $tradeSignal->id,
                'user_id' => $tradeSignal->user_id,
                'symbol' => $tradeSignal->symbol,
                'direction' => $tradeSignal->direction,
                'leverage' => $tradeSignal->leverage,
                'entry_price' => $entryPrice,
                'entry_triggered_at' => $eventTimestamp,
                'stop_loss' => $tradeSignal->stop_loss,
                'current_price' => $currentPrice,
                'max_price_after_entry' => $entryPrice,
                'min_price_after_entry' => $entryPrice,
                'max_price' => $entryPrice,
                'min_price' => $entryPrice,
                'max_actual_price_move_percent' => $actualMove,
                'max_leveraged_pnl_percent' => $leveragedPnl,
                'min_actual_price_move_percent' => $actualMove,
                'min_leveraged_pnl_percent' => $leveragedPnl,
                'max_actual_gain_percent' => $actualMove,
                'max_actual_loss_percent' => $actualMove,
                'max_gain_percent' => $leveragedPnl,
                'max_loss_percent' => $leveragedPnl,
                'status' => SimulatedTrade::STATUS_ACTIVE,
                'tracking_until' => now()->addDays($this->trackingDays()),
            ];

            if ($simulatedTrade) {
                $payload = array_merge($payload, [
                    'max_price_after_entry' => $this->greaterValue($entryPrice, $simulatedTrade->max_price_after_entry ?? $simulatedTrade->max_price),
                    'min_price_after_entry' => $this->lowerValue($entryPrice, $simulatedTrade->min_price_after_entry ?? $simulatedTrade->min_price),
                    'max_price' => $this->greaterValue($entryPrice, $simulatedTrade->max_price),
                    'min_price' => $this->lowerValue($entryPrice, $simulatedTrade->min_price),
                    'max_actual_price_move_percent' => $this->greaterValue($actualMove, $simulatedTrade->max_actual_price_move_percent),
                    'max_leveraged_pnl_percent' => $this->greaterValue($leveragedPnl, $simulatedTrade->max_leveraged_pnl_percent),
                    'min_actual_price_move_percent' => $this->lowerValue($actualMove, $simulatedTrade->min_actual_price_move_percent),
                    'min_leveraged_pnl_percent' => $this->lowerValue($leveragedPnl, $simulatedTrade->min_leveraged_pnl_percent),
                    'max_actual_gain_percent' => $this->greaterValue($actualMove, $simulatedTrade->max_actual_gain_percent ?? $simulatedTrade->max_actual_price_move_percent),
                    'max_actual_loss_percent' => $this->lowerValue($actualMove, $simulatedTrade->max_actual_loss_percent ?? $simulatedTrade->min_actual_price_move_percent),
                    'max_gain_percent' => $this->greaterValue($leveragedPnl, $simulatedTrade->max_gain_percent ?? $simulatedTrade->max_leveraged_pnl_percent),
                    'max_loss_percent' => $this->lowerValue($leveragedPnl, $simulatedTrade->max_loss_percent ?? $simulatedTrade->min_leveraged_pnl_percent),
                ]);

                $simulatedTrade->fill($payload);
                $simulatedTrade->save();
            } else {
                $simulatedTrade = SimulatedTrade::create($payload);
            }

            $tradeSignal->update(['status' => TradeSignal::STATUS_ACTIVE]);

            $event = TradeTrackingEvent::updateOrCreate(
                [
                    'simulated_trade_id' => $simulatedTrade->id,
                    'event_type' => TradeTrackingEvent::EVENT_ENTRY_TRIGGERED,
                ],
                [
                    'trade_signal_id' => $tradeSignal->id,
                    'event_price' => $entryPrice,
                    'actual_price_move_percent' => $actualMove,
                    'leveraged_pnl_percent' => $leveragedPnl,
                    'event_timestamp' => $eventTimestamp,
                ]
            );

            return [$simulatedTrade->fresh('tradeSignal'), $event];
        });

        return response()->json([
            'success' => true,
            'message' => 'Entry trigger recorded successfully.',
            'data' => [
                'simulated_trade' => $this->formatTrade($result[0]),
                'event' => $result[1],
            ],
        ]);
    }

    public function storeTradeEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'simulated_trade_id' => ['required', 'integer', 'exists:simulated_trades,id'],
            'event_type' => ['required', 'string', 'max:100', Rule::in(TradeTrackingEvent::allowedTypes())],
            'event_price' => ['required', 'numeric'],
            'actual_price_move_percent' => ['required', 'numeric'],
            'leveraged_pnl_percent' => ['required', 'numeric'],
            'event_timestamp' => ['required', 'date'],
            'metadata' => ['nullable', 'array'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $simulatedTrade = SimulatedTrade::query()->lockForUpdate()->findOrFail($validated['simulated_trade_id']);
            $eventType = $validated['event_type'];
            $eventTimestamp = Carbon::parse($validated['event_timestamp']);

            $eventPayload = [
                'trade_signal_id' => $simulatedTrade->trade_signal_id,
                'event_price' => $validated['event_price'],
                'actual_price_move_percent' => $validated['actual_price_move_percent'],
                'leveraged_pnl_percent' => $validated['leveraged_pnl_percent'],
                'event_timestamp' => $eventTimestamp,
                'metadata' => $validated['metadata'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            [$event, $message] = $this->storeIdempotentTradeEvent($simulatedTrade, $eventType, $eventPayload);

            $this->applyEventStatus($simulatedTrade, $eventType, $validated['event_price'], $eventTimestamp);

            return [$simulatedTrade->fresh('tradeSignal'), $event, $message];
        });

        return response()->json([
            'success' => true,
            'message' => $result[2],
            'data' => [
                'simulated_trade' => $this->formatTrade($result[0]),
                'event' => $result[1],
            ],
        ]);
    }

    public function updateMetrics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'simulated_trade_id' => ['required', 'integer', 'exists:simulated_trades,id'],
            'current_price' => ['required', 'numeric'],
            'actual_price_move_percent' => ['required', 'numeric'],
            'leveraged_pnl_percent' => ['required', 'numeric'],
            'price_timestamp' => ['nullable', 'date'],
        ]);

        $currentPrice = $validated['current_price'];
        $actualMove = $validated['actual_price_move_percent'];
        $leveragedPnl = $validated['leveraged_pnl_percent'];

        $simulatedTrade = DB::transaction(function () use ($validated, $currentPrice, $actualMove, $leveragedPnl): SimulatedTrade {
            $simulatedTrade = SimulatedTrade::query()->lockForUpdate()->findOrFail($validated['simulated_trade_id']);

            $simulatedTrade->fill([
                'current_price' => $currentPrice,
                'max_price_after_entry' => $this->greaterValue($currentPrice, $simulatedTrade->max_price_after_entry ?? $simulatedTrade->max_price),
                'min_price_after_entry' => $this->lowerValue($currentPrice, $simulatedTrade->min_price_after_entry ?? $simulatedTrade->min_price),
                'max_price' => $this->greaterValue($currentPrice, $simulatedTrade->max_price),
                'min_price' => $this->lowerValue($currentPrice, $simulatedTrade->min_price),
                'max_actual_price_move_percent' => $this->greaterValue($actualMove, $simulatedTrade->max_actual_price_move_percent),
                'max_leveraged_pnl_percent' => $this->greaterValue($leveragedPnl, $simulatedTrade->max_leveraged_pnl_percent),
                'min_actual_price_move_percent' => $this->lowerValue($actualMove, $simulatedTrade->min_actual_price_move_percent),
                'min_leveraged_pnl_percent' => $this->lowerValue($leveragedPnl, $simulatedTrade->min_leveraged_pnl_percent),
                'max_actual_gain_percent' => $this->greaterValue($actualMove, $simulatedTrade->max_actual_gain_percent ?? $simulatedTrade->max_actual_price_move_percent),
                'max_actual_loss_percent' => $this->lowerValue($actualMove, $simulatedTrade->max_actual_loss_percent ?? $simulatedTrade->min_actual_price_move_percent),
                'max_gain_percent' => $this->greaterValue($leveragedPnl, $simulatedTrade->max_gain_percent ?? $simulatedTrade->max_leveraged_pnl_percent),
                'max_loss_percent' => $this->lowerValue($leveragedPnl, $simulatedTrade->max_loss_percent ?? $simulatedTrade->min_leveraged_pnl_percent),
            ])->save();

            return $simulatedTrade;
        });

        return response()->json([
            'success' => true,
            'message' => 'Simulated trade metrics updated successfully.',
            'data' => $this->formatTrade($simulatedTrade->fresh('tradeSignal')),
        ]);
    }

    public function closeTrade(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'simulated_trade_id' => ['required', 'integer', 'exists:simulated_trades,id'],
            'exit_price' => ['required', 'numeric'],
            'exit_reason' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in([
                SimulatedTrade::STATUS_CLOSED_SL,
                SimulatedTrade::STATUS_CLOSED_TP,
                SimulatedTrade::STATUS_EXPIRED,
                SimulatedTrade::STATUS_COMPLETED,
            ])],
            'actual_price_move_percent' => ['required', 'numeric'],
            'leveraged_pnl_percent' => ['required', 'numeric'],
            'closed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $result = DB::transaction(function () use ($validated): array {
            $simulatedTrade = SimulatedTrade::query()->lockForUpdate()->findOrFail($validated['simulated_trade_id']);
            $closedAt = $this->dateOrNow($validated['closed_at'] ?? null);

            $simulatedTrade->update([
                'exit_price' => $validated['exit_price'],
                'exit_reason' => $validated['exit_reason'],
                'status' => $validated['status'],
                'closed_at' => $closedAt,
            ]);

            $simulatedTrade->tradeSignal?->update(['status' => $validated['status']]);

            $event = TradeTrackingEvent::updateOrCreate(
                [
                    'simulated_trade_id' => $simulatedTrade->id,
                    'event_type' => TradeTrackingEvent::EVENT_TRADE_CLOSED,
                ],
                [
                    'trade_signal_id' => $simulatedTrade->trade_signal_id,
                    'event_price' => $validated['exit_price'],
                    'actual_price_move_percent' => $validated['actual_price_move_percent'],
                    'leveraged_pnl_percent' => $validated['leveraged_pnl_percent'],
                    'event_timestamp' => $closedAt,
                    'notes' => $validated['notes'] ?? null,
                ]
            );

            return [$simulatedTrade->fresh('tradeSignal'), $event];
        });

        return response()->json([
            'success' => true,
            'message' => 'Simulated trade closed successfully.',
            'data' => [
                'simulated_trade' => $this->formatTrade($result[0]),
                'event' => $result[1],
            ],
        ]);
    }

    public function storeMarketSnapshot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trade_signal_id' => ['nullable', 'integer', 'exists:trade_signals,id'],
            'simulated_trade_id' => ['nullable', 'integer', 'exists:simulated_trades,id'],
            'symbol' => ['nullable', 'string', 'max:50'],
            'snapshot_type' => ['required', 'string', 'max:100'],
            'price' => ['nullable', 'numeric'],
            'volume_24h' => ['nullable', 'numeric'],
            'price_change_24h_percent' => ['nullable', 'numeric'],
            'funding_rate' => ['nullable', 'numeric'],
            'open_interest' => ['nullable', 'numeric'],
            'btc_price' => ['nullable', 'numeric'],
            'btc_24h_change_percent' => ['nullable', 'numeric'],
            'eth_price' => ['nullable', 'numeric'],
            'eth_24h_change_percent' => ['nullable', 'numeric'],
            'market_condition' => ['nullable', Rule::in([
                MarketSnapshot::MARKET_CONDITION_BULLISH,
                MarketSnapshot::MARKET_CONDITION_BEARISH,
                MarketSnapshot::MARKET_CONDITION_SIDEWAYS,
            ])],
            'captured_at' => ['nullable', 'date'],
            'raw_payload' => ['nullable', 'array'],
            'snapshot_at' => ['nullable', 'date'],
        ]);

        $capturedAt = $this->dateOrNow($validated['captured_at'] ?? $validated['snapshot_at'] ?? null);
        $payload = [
            'trade_signal_id' => $validated['trade_signal_id'] ?? null,
            'simulated_trade_id' => $validated['simulated_trade_id'] ?? null,
            'symbol' => ! empty($validated['symbol']) ? $validated['symbol'] : 'MARKET',
            'snapshot_type' => $validated['snapshot_type'],
            'price' => $validated['price'] ?? $validated['btc_price'] ?? null,
            'volume_24h' => $validated['volume_24h'] ?? null,
            'price_change_24h_percent' => $validated['price_change_24h_percent'] ?? null,
            'funding_rate' => $validated['funding_rate'] ?? null,
            'open_interest' => $validated['open_interest'] ?? null,
            'btc_price' => $validated['btc_price'] ?? null,
            'btc_24h_change_percent' => $validated['btc_24h_change_percent'] ?? null,
            'eth_price' => $validated['eth_price'] ?? null,
            'eth_24h_change_percent' => $validated['eth_24h_change_percent'] ?? null,
            'market_condition' => $validated['market_condition'] ?? null,
            'captured_at' => $capturedAt,
            'raw_payload' => $validated['raw_payload'] ?? null,
            'snapshot_at' => $capturedAt,
        ];

        if (! Schema::hasColumn('market_snapshots', 'price')) {
            unset($payload['price']);
        }

        if (! Schema::hasColumn('market_snapshots', 'snapshot_at')) {
            unset($payload['snapshot_at']);
        }

        $uniqueKeys = $this->marketSnapshotUniqueKeys($payload);
        $snapshot = $uniqueKeys
            ? MarketSnapshot::updateOrCreate($uniqueKeys, $payload)
            : MarketSnapshot::create($payload);

        return response()->json([
            'success' => true,
            'message' => 'Market snapshot stored successfully.',
            'data' => $snapshot,
        ]);
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function marketSnapshotUniqueKeys(array $payload): ?array
    {
        $snapshotType = $payload['snapshot_type'] ?? null;

        if ($snapshotType === MarketSnapshot::SNAPSHOT_SIGNAL_SAVED && ! empty($payload['trade_signal_id'])) {
            return [
                'trade_signal_id' => $payload['trade_signal_id'],
                'snapshot_type' => $snapshotType,
            ];
        }

        if (in_array($snapshotType, [MarketSnapshot::SNAPSHOT_ENTRY_TRIGGERED, MarketSnapshot::SNAPSHOT_TRADE_CLOSED], true)) {
            if (! empty($payload['simulated_trade_id'])) {
                return [
                    'simulated_trade_id' => $payload['simulated_trade_id'],
                    'snapshot_type' => $snapshotType,
                ];
            }

            if (! empty($payload['trade_signal_id'])) {
                return [
                    'trade_signal_id' => $payload['trade_signal_id'],
                    'snapshot_type' => $snapshotType,
                ];
            }
        }

        return null;
    }

    private function tradesByStatus(Request $request, string $status, bool $onlyUnexpiredTracking = false): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'symbol' => ['nullable', 'string', 'max:50'],
        ]);

        $trades = SimulatedTrade::query()
            ->with('tradeSignal')
            ->where('status', $status)
            ->when(! empty($validated['symbol']), fn ($query) => $query->where('symbol', $validated['symbol']))
            ->when($onlyUnexpiredTracking, fn ($query) => $query->where(function ($query): void {
                $query->whereNull('tracking_until')->orWhere('tracking_until', '>=', now());
            }))
            ->latest('id')
            ->limit($validated['limit'] ?? 100)
            ->get()
            ->map(fn (SimulatedTrade $trade): array => $this->formatTrade($trade));

        return response()->json([
            'success' => true,
            'data' => $trades,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTrade(SimulatedTrade $trade): array
    {
        $data = $trade->toArray();
        $signal = $trade->tradeSignal;

        $data['trade_signal_levels'] = [
            'tp1' => $signal?->tp1,
            'tp2' => $signal?->tp2,
            'tp3' => $signal?->tp3,
            'tp4' => $signal?->tp4,
            'stop_loss' => $signal?->stop_loss,
            'direction' => $signal?->direction,
            'leverage' => $signal?->leverage,
        ];

        return $data;
    }


    /**
     * @param array<string, mixed> $eventPayload
     * @return array{0: TradeTrackingEvent, 1: string}
     */
    private function storeIdempotentTradeEvent(SimulatedTrade $simulatedTrade, string $eventType, array $eventPayload): array
    {
        if ($eventType === TradeTrackingEvent::EVENT_POST_SL_MAX_GAIN) {
            $existingEvent = TradeTrackingEvent::query()
                ->where('simulated_trade_id', $simulatedTrade->id)
                ->where('event_type', TradeTrackingEvent::EVENT_POST_SL_MAX_GAIN)
                ->lockForUpdate()
                ->first();

            if ($existingEvent) {
                $existingPnl = $existingEvent->leveraged_pnl_percent;
                $incomingPnl = $eventPayload['leveraged_pnl_percent'];

                if ($existingPnl !== null && (float) $incomingPnl <= (float) $existingPnl) {
                    return [$existingEvent, 'Post-SL max gain unchanged.'];
                }

                $existingEvent->update($eventPayload);

                return [$existingEvent->fresh(), 'Post-SL max gain updated successfully.'];
            }
        }

        $event = TradeTrackingEvent::updateOrCreate(
            [
                'simulated_trade_id' => $simulatedTrade->id,
                'event_type' => $eventType,
            ],
            $eventPayload
        );

        return [$event, 'Trade event stored successfully.'];
    }

    private function applyEventStatus(SimulatedTrade $simulatedTrade, string $eventType, mixed $eventPrice, Carbon $eventTimestamp): void
    {
        if ($eventType === TradeTrackingEvent::EVENT_SL_HIT) {
            $simulatedTrade->status = SimulatedTrade::STATUS_TRACKING_AFTER_SL;

            if ($simulatedTrade->tracking_until === null) {
                $simulatedTrade->tracking_until = now()->addDays($this->trackingDays());
            }

            $simulatedTrade->save();
            $simulatedTrade->tradeSignal?->update(['status' => TradeSignal::STATUS_TRACKING_AFTER_SL]);

            return;
        }

        if ($eventType === TradeTrackingEvent::EVENT_TRADE_EXPIRED) {
            $simulatedTrade->update([
                'status' => SimulatedTrade::STATUS_EXPIRED,
                'exit_price' => $eventPrice,
                'exit_reason' => TradeTrackingEvent::EVENT_TRADE_EXPIRED,
                'closed_at' => $eventTimestamp,
            ]);
            $simulatedTrade->tradeSignal?->update(['status' => TradeSignal::STATUS_EXPIRED]);

            return;
        }

        if ($eventType === TradeTrackingEvent::EVENT_TRADE_CLOSED) {
            $simulatedTrade->update([
                'status' => SimulatedTrade::STATUS_COMPLETED,
                'exit_price' => $eventPrice,
                'exit_reason' => TradeTrackingEvent::EVENT_TRADE_CLOSED,
                'closed_at' => $eventTimestamp,
            ]);
            $simulatedTrade->tradeSignal?->update(['status' => TradeSignal::STATUS_COMPLETED]);
        }
    }

    private function trackingDays(): int
    {
        return (int) env('SIGNAL_TRACKING_DAYS', 7);
    }

    private function dateOrNow(?string $value): Carbon
    {
        return $value ? Carbon::parse($value) : now();
    }

    private function greaterValue(mixed $candidate, mixed $current): mixed
    {
        return $current === null || (float) $candidate > (float) $current ? $candidate : $current;
    }

    private function lowerValue(mixed $candidate, mixed $current): mixed
    {
        return $current === null || (float) $candidate < (float) $current ? $candidate : $current;
    }
}
