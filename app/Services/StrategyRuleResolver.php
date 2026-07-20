<?php

namespace App\Services;

use App\Models\SimulatedTrade;
use App\Models\StrategyDefinition;
use App\Models\TradeTrackingEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StrategyRuleResolver
{
    private const STRATEGY_TARGET_BEFORE_STOP = 'TARGET_BEFORE_STOP';
    private const STRATEGY_RECOVERY_HOLD = 'RECOVERY_HOLD';
    private const RESULT_WIN = 'win';
    private const RESULT_LOSS = 'loss';
    private const RESULT_SKIPPED = 'skipped';
    private const RESULT_OPEN = 'open';
    private const FINAL_EXIT_EVENT_TYPE = 'FINAL_EXIT';
    private const POST_SL_TP_EVENT_TYPES = [
        TradeTrackingEvent::EVENT_POST_SL_TP1_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP2_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP3_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP4_HIT,
    ];

    /**
     * Resolve the strategy outcome from already supplied trade and tracking data.
     *
     * @return array{eligible: bool, entry_price: mixed, entry_time: mixed, exit_price: mixed, exit_time: mixed, exit_event_type: ?string, exit_leveraged_pnl_percent: mixed, result_status: 'win'|'loss'|'skipped'|'open', post_sl_recovery_data: array<string, mixed>}
     */
    public function resolve(
        StrategyDefinition $strategy,
        SimulatedTrade $trade,
        iterable $events
    ): array {
        $orderedEvents = $this->normalizeEvents($events);

        if (! $this->isSupportedStrategy($strategy)) {
            return $this->result(false, null, null, null, null, null, null, self::RESULT_SKIPPED);
        }

        $entry = $this->resolveEntry($trade, $orderedEvents);

        if ($entry === null) {
            return $this->result(false, null, null, null, null, null, null, self::RESULT_SKIPPED);
        }

        $eventsAfterEntry = $this->eventsAtOrAfter($orderedEvents, $entry['time']);
        $postSlRecoveryData = $this->resolvePostSlRecoveryData($strategy, $eventsAfterEntry);

        return match ($strategy->strategy_type) {
            self::STRATEGY_TARGET_BEFORE_STOP => $this->resolveTargetBeforeStop($strategy, $entry, $eventsAfterEntry, $postSlRecoveryData),
            self::STRATEGY_RECOVERY_HOLD => $this->resolveRecoveryHold($strategy, $trade, $entry, $eventsAfterEntry, $postSlRecoveryData),
            default => $this->result(false, null, null, null, null, null, null, self::RESULT_SKIPPED),
        };
    }

    private function isSupportedStrategy(StrategyDefinition $strategy): bool
    {
        if (! $strategy->is_active || blank($strategy->strategy_type)) {
            return false;
        }

        if ($strategy->strategy_type === self::STRATEGY_TARGET_BEFORE_STOP) {
            return filled($strategy->target_event_type) && filled($strategy->stop_event_type);
        }

        if ($strategy->strategy_type === self::STRATEGY_RECOVERY_HOLD) {
            return filled($strategy->target_event_type);
        }

        return false;
    }

    private function normalizeEvents(iterable $events): Collection
    {
        return collect($events)
            ->filter(fn ($event): bool => $event instanceof TradeTrackingEvent
                && filled($event->event_type)
                && $this->asTimestamp($event->event_timestamp) !== null)
            ->sort(function (TradeTrackingEvent $a, TradeTrackingEvent $b): int {
                $timeComparison = $this->asTimestamp($a->event_timestamp) <=> $this->asTimestamp($b->event_timestamp);

                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                // When two persisted events share an exact timestamp, the first persisted event wins.
                return ((int) ($a->getKey() ?? 0)) <=> ((int) ($b->getKey() ?? 0));
            })
            ->values();
    }

    /**
     * @return array{price: mixed, time: CarbonInterface}|null
     */
    private function resolveEntry(SimulatedTrade $trade, Collection $orderedEvents): ?array
    {
        $entryEvent = $orderedEvents->first(fn (TradeTrackingEvent $event): bool => $event->event_type === TradeTrackingEvent::EVENT_ENTRY_TRIGGERED
            && $event->event_price !== null);

        if ($entryEvent instanceof TradeTrackingEvent) {
            return ['price' => $entryEvent->event_price, 'time' => $entryEvent->event_timestamp];
        }

        if ($trade->entry_price === null || $trade->entry_triggered_at === null) {
            return null;
        }

        return ['price' => $trade->entry_price, 'time' => $trade->entry_triggered_at];
    }

    private function resolveTargetBeforeStop(StrategyDefinition $strategy, array $entry, Collection $events, array $postSlRecoveryData): array
    {
        $target = $events->first(fn (TradeTrackingEvent $event): bool => $event->event_type === $strategy->target_event_type);
        $stop = $events->first(fn (TradeTrackingEvent $event): bool => $event->event_type === $strategy->stop_event_type);

        if (! $target instanceof TradeTrackingEvent && ! $stop instanceof TradeTrackingEvent) {
            return $this->openResult($entry, $postSlRecoveryData);
        }

        if ($target instanceof TradeTrackingEvent && ! $stop instanceof TradeTrackingEvent) {
            return $this->eventExitResult($entry, $target, self::RESULT_WIN, $postSlRecoveryData);
        }

        if ($stop instanceof TradeTrackingEvent && ! $target instanceof TradeTrackingEvent) {
            return $this->eventExitResult($entry, $stop, self::RESULT_LOSS, $postSlRecoveryData);
        }

        return $this->eventExitResult(
            $entry,
            $this->compareEvents($target, $stop) <= 0 ? $target : $stop,
            $this->compareEvents($target, $stop) <= 0 ? self::RESULT_WIN : self::RESULT_LOSS,
            $postSlRecoveryData
        );
    }

    private function resolveRecoveryHold(StrategyDefinition $strategy, SimulatedTrade $trade, array $entry, Collection $events, array $postSlRecoveryData): array
    {
        $winningExitTypes = [$strategy->target_event_type, TradeTrackingEvent::EVENT_POST_SL_TP1_HIT];
        $winningExit = $events->first(fn (TradeTrackingEvent $event): bool => in_array($event->event_type, $winningExitTypes, true));

        if ($winningExit instanceof TradeTrackingEvent) {
            return $this->eventExitResult($entry, $winningExit, self::RESULT_WIN, $postSlRecoveryData);
        }

        if (! $this->isFinalized($trade)) {
            return $this->openResult($entry, $postSlRecoveryData);
        }

        if ($trade->exit_price !== null) {
            $pnl = $this->finalExitPnl($trade);

            return $this->result(
                true,
                $entry['price'],
                $entry['time'],
                $trade->exit_price,
                $trade->closed_at,
                self::FINAL_EXIT_EVENT_TYPE,
                $pnl,
                $this->isGreaterThanZero($pnl) ? self::RESULT_WIN : self::RESULT_LOSS,
                $postSlRecoveryData
            );
        }

        $lastEvent = $events->last();

        if ($lastEvent instanceof TradeTrackingEvent) {
            return $this->eventExitResult(
                $entry,
                $lastEvent,
                $this->isGreaterThanZero($lastEvent->leveraged_pnl_percent) ? self::RESULT_WIN : self::RESULT_LOSS,
                $postSlRecoveryData
            );
        }

        return $this->openResult($entry, $postSlRecoveryData);
    }

    private function resolvePostSlRecoveryData(StrategyDefinition $strategy, Collection $eventsAfterEntry): array
    {
        $stop = $eventsAfterEntry->first(fn (TradeTrackingEvent $event): bool => $event->event_type === TradeTrackingEvent::EVENT_SL_HIT);

        if (! $stop instanceof TradeTrackingEvent) {
            return $this->defaultPostSlRecoveryData();
        }

        $eventsAfterStop = $eventsAfterEntry
            ->filter(fn (TradeTrackingEvent $event): bool => $this->compareEvents($event, $stop) > 0)
            ->values();
        $firstRecovery = $eventsAfterStop->first(
            fn (TradeTrackingEvent $event): bool => in_array($event->event_type, self::POST_SL_TP_EVENT_TYPES, true)
        );
        $maxGain = $this->resolvePostSlMaxGainEvent($eventsAfterStop);

        return [
            'sl_hit_first' => $this->isStopBeforeNormalTarget($strategy, $stop, $eventsAfterEntry),
            'sl_hit_time' => $stop->event_timestamp,
            'post_sl_recovered' => $firstRecovery instanceof TradeTrackingEvent,
            'post_sl_first_recovery_event' => $firstRecovery instanceof TradeTrackingEvent ? $firstRecovery->event_type : null,
            'post_sl_first_recovery_price' => $firstRecovery instanceof TradeTrackingEvent ? $firstRecovery->event_price : null,
            'post_sl_first_recovery_time' => $firstRecovery instanceof TradeTrackingEvent ? $firstRecovery->event_timestamp : null,
            'post_sl_max_gain_percent' => $maxGain instanceof TradeTrackingEvent ? $maxGain->leveraged_pnl_percent : null,
            'post_sl_max_gain_price' => $maxGain instanceof TradeTrackingEvent ? $maxGain->event_price : null,
        ];
    }

    private function isStopBeforeNormalTarget(StrategyDefinition $strategy, TradeTrackingEvent $stop, Collection $eventsAfterEntry): bool
    {
        $targetEventType = $strategy->target_event_type;

        if ($strategy->strategy_type === self::STRATEGY_RECOVERY_HOLD) {
            $targetEventType = TradeTrackingEvent::EVENT_TP1_HIT;
        }

        $target = $eventsAfterEntry->first(fn (TradeTrackingEvent $event): bool => $event->event_type === $targetEventType);

        return ! $target instanceof TradeTrackingEvent || $this->compareEvents($stop, $target) < 0;
    }

    private function resolvePostSlMaxGainEvent(Collection $eventsAfterStop): ?TradeTrackingEvent
    {
        return $eventsAfterStop
            ->filter(fn (TradeTrackingEvent $event): bool => is_numeric($event->leveraged_pnl_percent))
            ->sort(function (TradeTrackingEvent $a, TradeTrackingEvent $b): int {
                $gainComparison = (float) $b->leveraged_pnl_percent <=> (float) $a->leveraged_pnl_percent;

                return $gainComparison ?: $this->compareEvents($a, $b);
            })
            ->first();
    }

    private function isFinalized(SimulatedTrade $trade): bool
    {
        return in_array($trade->status, [
            SimulatedTrade::STATUS_CLOSED_SL,
            SimulatedTrade::STATUS_CLOSED_TP,
            SimulatedTrade::STATUS_EXPIRED,
            SimulatedTrade::STATUS_COMPLETED,
        ], true);
    }

    private function finalExitPnl(SimulatedTrade $trade): mixed
    {
        return match ($trade->exit_reason) {
            SimulatedTrade::EXIT_REASON_TP => $trade->max_leveraged_pnl_percent,
            SimulatedTrade::EXIT_REASON_SL => $trade->min_leveraged_pnl_percent,
            default => null,
        };
    }

    private function eventsAtOrAfter(Collection $events, mixed $entryTime): Collection
    {
        $entryTimestamp = $this->asTimestamp($entryTime);

        return $events->filter(fn (TradeTrackingEvent $event): bool => $this->asTimestamp($event->event_timestamp) >= $entryTimestamp)->values();
    }

    private function compareEvents(TradeTrackingEvent $a, TradeTrackingEvent $b): int
    {
        return ($this->asTimestamp($a->event_timestamp) <=> $this->asTimestamp($b->event_timestamp))
            ?: (((int) ($a->getKey() ?? 0)) <=> ((int) ($b->getKey() ?? 0)));
    }

    private function asTimestamp(mixed $value): ?int
    {
        if ($value instanceof CarbonInterface) {
            return $value->getTimestamp();
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function isGreaterThanZero(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return preg_match('/^\s*\+?0*(?:\.0*)?\s*$/', (string) $value) !== 1
            && str_starts_with(ltrim((string) $value), '-') === false;
    }

    private function eventExitResult(array $entry, TradeTrackingEvent $event, string $status, ?array $postSlRecoveryData = null): array
    {
        return $this->result(true, $entry['price'], $entry['time'], $event->event_price, $event->event_timestamp, $event->event_type, $event->leveraged_pnl_percent, $status, $postSlRecoveryData);
    }

    private function openResult(array $entry, ?array $postSlRecoveryData = null): array
    {
        return $this->result(true, $entry['price'], $entry['time'], null, null, null, null, self::RESULT_OPEN, $postSlRecoveryData);
    }

    private function result(bool $eligible, mixed $entryPrice, mixed $entryTime, mixed $exitPrice, mixed $exitTime, ?string $exitEventType, mixed $exitLeveragedPnlPercent, string $status, ?array $postSlRecoveryData = null): array
    {
        return [
            'eligible' => $eligible,
            'entry_price' => $entryPrice,
            'entry_time' => $entryTime,
            'exit_price' => $exitPrice,
            'exit_time' => $exitTime,
            'exit_event_type' => $exitEventType,
            'exit_leveraged_pnl_percent' => $exitLeveragedPnlPercent,
            'result_status' => $status,
            'post_sl_recovery_data' => $postSlRecoveryData ?? $this->defaultPostSlRecoveryData(),
        ];
    }

    private function defaultPostSlRecoveryData(): array
    {
        return [
            'sl_hit_first' => false,
            'sl_hit_time' => null,
            'post_sl_recovered' => false,
            'post_sl_first_recovery_event' => null,
            'post_sl_first_recovery_price' => null,
            'post_sl_first_recovery_time' => null,
            'post_sl_max_gain_percent' => null,
            'post_sl_max_gain_price' => null,
        ];
    }
}
