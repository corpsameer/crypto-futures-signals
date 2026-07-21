<?php

namespace App\Services;

use App\Models\SimulatedTrade;
use App\Models\StrategyBacktestRun;
use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use App\Models\TradeSignal;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class StrategyBacktestService
{
    private const MONEY_SCALE = 8;
    private const PERCENT_SCALE = 8;
    private const ZERO_MONEY = '0.00000000';
    private const ZERO_PERCENT = '0.00000000';

    public function __construct(private readonly StrategyRuleResolver $resolver) {}

    /**
     * Run a strategy backtest against entry-triggered simulated trades.
     *
     * Supported options:
     * - strategy_code: nullable exact StrategyDefinition code.
     * - from: nullable Carbon/date-compatible lower bound for simulated_trades.entry_triggered_at.
     * - starting_capital: nullable decimal-compatible override for non-incremental runs.
     * - incremental: boolean; when true, skips trades already processed for the strategy in any run.
     *
     * @param  array{strategy_code?: ?string, from?: mixed, starting_capital?: mixed, incremental?: bool}  $options
     * @return array<string, mixed>
     */
    public function run(StrategyBacktestRun $run, array $options = []): array
    {
        $incremental = (bool) ($options['incremental'] ?? false);
        $strategies = $this->loadStrategies($options['strategy_code'] ?? null);

        $summary = [
            'backtest_run_id' => (int) $run->getKey(),
            'strategies_processed' => 0,
            'trades_loaded' => 0,
            'results_created' => 0,
            'results_skipped_as_duplicates' => 0,
            'warnings' => 0,
            'strategies' => [],
        ];

        foreach ($strategies as $strategy) {
            $strategySummary = DB::transaction(function () use ($strategy, $run, $options, $incremental): array {
                /** @var StrategyDefinition $lockedStrategy */
                $lockedStrategy = StrategyDefinition::query()
                    ->whereKey($strategy->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $capital = $this->resolveStartingCapital($lockedStrategy, $run, $options['starting_capital'] ?? null, $incremental);
                $startingCapital = $capital;
                $created = 0;
                $duplicates = 0;
                $wins = 0;
                $losses = 0;
                $open = 0;
                $skipped = 0;
                $netPnl = self::ZERO_MONEY;
                $warnings = 0;

                $trades = $this->loadEligibleTrades($options['from'] ?? null, $lockedStrategy, $run, $incremental);

                foreach ($trades as $trade) {
                    if ($this->hasExistingResult($lockedStrategy, $run, $trade, $incremental)) {
                        $duplicates++;
                        continue;
                    }

                    $resolved = $this->resolver->resolve($lockedStrategy, $trade, $this->orderedTrackingEvents($trade));

                    if (($resolved['eligible'] ?? false) === false && $trade->tradeSignal?->signal_source === TradeSignal::SOURCE_COINDCX) {
                        continue;
                    }

                    $pnl = $this->calculatePnl($capital, $lockedStrategy, $resolved);

                    if (($pnl['warning'] ?? null) !== null) {
                        $warnings++;
                        Log::warning('Strategy backtest result stored with zero P&L because a closed result lacked usable exit leveraged P&L.', [
                            'backtest_run_id' => $run->getKey(),
                            'strategy_definition_id' => $lockedStrategy->getKey(),
                            'strategy_code' => $lockedStrategy->code,
                            'simulated_trade_id' => $trade->getKey(),
                            'result_status' => $resolved['result_status'] ?? null,
                            'exit_leveraged_pnl_percent' => $resolved['exit_leveraged_pnl_percent'] ?? null,
                        ]);
                    }
                    $attributes = $this->resultAttributes($run, $lockedStrategy, $trade, $resolved, $pnl, $capital);

                    try {
                        StrategyTradeResult::query()->create($attributes);
                    } catch (QueryException $exception) {
                        if (! $this->isUniqueConstraintViolation($exception)) {
                            throw $exception;
                        }

                        $duplicates++;
                        continue;
                    }

                    $created++;
                    $capital = $pnl['capital_after'];
                    $netPnl = $this->add($netPnl, $pnl['net_pnl']);

                    match ($attributes['result_status']) {
                        StrategyTradeResult::RESULT_STATUS_WIN => $wins++,
                        StrategyTradeResult::RESULT_STATUS_LOSS => $losses++,
                        StrategyTradeResult::RESULT_STATUS_OPEN => $open++,
                        default => $skipped++,
                    };
                }

                if (! $incremental || $created > 0) {
                    $lockedStrategy->forceFill(['current_capital' => $capital])->save();
                }

                return [
                    'strategy_id' => (int) $lockedStrategy->getKey(),
                    'strategy_code' => (string) $lockedStrategy->code,
                    'starting_capital' => $startingCapital,
                    'ending_capital' => $capital,
                    'trades_processed' => $created,
                    'wins' => $wins,
                    'losses' => $losses,
                    'open' => $open,
                    'skipped' => $skipped,
                    'net_pnl' => $netPnl,
                    'warnings' => $warnings,
                    '_trades_loaded' => $trades->count(),
                    '_results_created' => $created,
                    '_duplicates' => $duplicates,
                ];
            });

            $summary['strategies_processed']++;
            $summary['trades_loaded'] += $strategySummary['_trades_loaded'];
            $summary['results_created'] += $strategySummary['_results_created'];
            $summary['results_skipped_as_duplicates'] += $strategySummary['_duplicates'];
            $summary['warnings'] += $strategySummary['warnings'];
            unset($strategySummary['_results_created'], $strategySummary['_duplicates'], $strategySummary['_trades_loaded']);
            $summary['strategies'][] = $strategySummary;
        }

        return $summary;
    }

    private function loadStrategies(?string $strategyCode): Collection
    {
        $query = StrategyDefinition::query()->where('is_active', true)->orderBy('id');

        if ($strategyCode !== null && $strategyCode !== '') {
            $strategy = (clone $query)->where('code', $strategyCode)->first();

            if (! $strategy instanceof StrategyDefinition) {
                throw new RuntimeException("Active strategy [{$strategyCode}] was not found.");
            }

            return collect([$strategy]);
        }

        return $query->get();
    }

    private function loadEligibleTrades(mixed $from, StrategyDefinition $strategy, StrategyBacktestRun $run, bool $incremental): Collection
    {
        return SimulatedTrade::query()
            ->with([
                'tradeSignal:id,trader_name,signal_source,symbol,direction,stop_loss,tp1,tp2,tp3,tp4',
                'trackingEvents' => fn ($query) => $query->orderBy('event_timestamp')->orderBy('id'),
            ])
            ->whereNotNull('entry_triggered_at')
            ->when(! $incremental, function ($query): void {
                $query->whereHas('tradeSignal', fn ($query) => $query
                    ->where(fn ($query) => $query
                        ->whereNull('signal_source')
                        ->orWhere('signal_source', '')
                        ->orWhere('signal_source', TradeSignal::SOURCE_TELEGRAM)));
            })
            ->when($from !== null, fn ($query) => $query->where('entry_triggered_at', '>=', $this->date($from)))
            ->whereNotExists(function ($query) use ($strategy, $run, $incremental): void {
                $query->selectRaw('1')
                    ->from('strategy_trade_results')
                    ->whereColumn('strategy_trade_results.simulated_trade_id', 'simulated_trades.id')
                    ->where('strategy_trade_results.strategy_definition_id', $strategy->getKey())
                    ->when(! $incremental, fn ($query) => $query->where('strategy_trade_results.strategy_backtest_run_id', $run->getKey()));
            })
            ->orderBy('entry_triggered_at')
            ->orderBy('id')
            ->get();
    }

    private function hasExistingResult(StrategyDefinition $strategy, StrategyBacktestRun $run, SimulatedTrade $trade, bool $incremental): bool
    {
        return StrategyTradeResult::query()
            ->where('strategy_definition_id', $strategy->getKey())
            ->where('simulated_trade_id', $trade->getKey())
            ->when(! $incremental, fn ($query) => $query->where('strategy_backtest_run_id', $run->getKey()))
            ->exists();
    }

    private function resolveStartingCapital(StrategyDefinition $strategy, StrategyBacktestRun $run, mixed $override, bool $incremental): string
    {
        if ($incremental) {
            return $this->money($strategy->current_capital ?? $strategy->starting_capital);
        }

        return $this->money($override ?? $run->starting_capital ?? $strategy->starting_capital);
    }

    private function orderedTrackingEvents(SimulatedTrade $trade): Collection
    {
        return $trade->trackingEvents
            ->sortBy([['event_timestamp', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function resultAttributes(StrategyBacktestRun $run, StrategyDefinition $strategy, SimulatedTrade $trade, array $resolved, array $pnl, string $capitalBefore): array
    {
        $signal = $trade->tradeSignal;
        $recovery = $resolved['post_sl_recovery_data'] ?? [];

        return [
            'strategy_backtest_run_id' => $run->getKey(),
            'strategy_definition_id' => $strategy->getKey(),
            'trade_signal_id' => $trade->trade_signal_id,
            'simulated_trade_id' => $trade->getKey(),
            'symbol' => $trade->symbol ?? $signal?->symbol,
            'direction' => $trade->direction ?? $signal?->direction,
            'trader_name' => $signal?->trader_name,
            'capital_before' => $capitalBefore,
            'allocation_percent' => $this->percent($strategy->allocation_percent, 4),
            'allocated_capital' => $pnl['allocated_capital'],
            'entry_price' => $resolved['entry_price'] ?? $trade->entry_price,
            'entry_time' => $resolved['entry_time'] ?? $trade->entry_triggered_at,
            'exit_price' => $resolved['exit_price'],
            'exit_time' => $resolved['exit_time'],
            'exit_event_type' => $resolved['exit_event_type'],
            'exit_leveraged_pnl_percent' => $resolved['exit_leveraged_pnl_percent'],
            'gross_pnl' => $pnl['gross_pnl'],
            'fees' => $pnl['fees'],
            'net_pnl' => $pnl['net_pnl'],
            'capital_after' => $pnl['capital_after'],
            'return_percent' => $pnl['return_percent'],
            'result_status' => $resolved['result_status'],
            'sl_hit_first' => (bool) ($recovery['sl_hit_first'] ?? false),
            'sl_hit_time' => $recovery['sl_hit_time'] ?? null,
            'post_sl_recovered' => (bool) ($recovery['post_sl_recovered'] ?? false),
            'post_sl_first_recovery_event' => $recovery['post_sl_first_recovery_event'] ?? null,
            'post_sl_first_recovery_price' => $recovery['post_sl_first_recovery_price'] ?? null,
            'post_sl_first_recovery_time' => $recovery['post_sl_first_recovery_time'] ?? null,
            'post_sl_max_gain_percent' => $recovery['post_sl_max_gain_percent'] ?? null,
            'post_sl_max_gain_price' => $recovery['post_sl_max_gain_price'] ?? null,
        ];
    }

    private function calculatePnl(string $capitalBefore, StrategyDefinition $strategy, array $resolved): array
    {
        $status = $resolved['result_status'] ?? StrategyTradeResult::RESULT_STATUS_SKIPPED;
        $allocatedCapital = $status === StrategyTradeResult::RESULT_STATUS_SKIPPED
            ? self::ZERO_MONEY
            : $this->money($this->div($this->mul($capitalBefore, $strategy->allocation_percent), '100', self::MONEY_SCALE + 4));

        if (! in_array($status, [StrategyTradeResult::RESULT_STATUS_WIN, StrategyTradeResult::RESULT_STATUS_LOSS], true)) {
            return $this->zeroPnl($allocatedCapital, $capitalBefore);
        }

        if (! is_numeric($resolved['exit_leveraged_pnl_percent'] ?? null)) {
            return $this->zeroPnl(
                $allocatedCapital,
                $capitalBefore,
                'closed_result_missing_exit_leveraged_pnl_percent'
            );
        }

        $returnPercent = $this->effectiveReturnPercent($strategy, $resolved['exit_leveraged_pnl_percent']);
        $grossPnl = $this->money($this->div($this->mul($allocatedCapital, $returnPercent), '100', self::MONEY_SCALE + 4));
        $netPnl = $grossPnl;

        return [
            'allocated_capital' => $allocatedCapital,
            'gross_pnl' => $grossPnl,
            'fees' => self::ZERO_MONEY,
            'net_pnl' => $netPnl,
            'capital_after' => $this->money($this->add($capitalBefore, $netPnl)),
            'return_percent' => $returnPercent,
            'warning' => null,
        ];
    }

    private function zeroPnl(string $allocatedCapital, string $capitalBefore, ?string $warning = null): array
    {
        return [
            'allocated_capital' => $allocatedCapital,
            'gross_pnl' => self::ZERO_MONEY,
            'fees' => self::ZERO_MONEY,
            'net_pnl' => self::ZERO_MONEY,
            'capital_after' => $capitalBefore,
            'return_percent' => self::ZERO_PERCENT,
            'warning' => $warning,
        ];
    }

    private function effectiveReturnPercent(StrategyDefinition $strategy, mixed $exitLeveragedPnlPercent): string
    {
        $returnPercent = $this->percent($exitLeveragedPnlPercent);

        if ($strategy->strategy_type !== 'RECOVERY_HOLD' || ! is_numeric($strategy->loss_cap_percent)) {
            return $returnPercent;
        }

        $cap = $this->percent($strategy->loss_cap_percent);
        $lossFloor = str_starts_with($cap, '-') ? $cap : '-' . $cap;

        return bccomp($returnPercent, $lossFloor, self::PERCENT_SCALE) < 0 ? $lossFloor : $returnPercent;
    }

    private function date(mixed $value): CarbonInterface
    {
        return $value instanceof CarbonInterface ? $value : Carbon::parse($value);
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', self::MONEY_SCALE);
    }

    private function percent(mixed $value, int $scale = self::PERCENT_SCALE): string
    {
        return bcadd((string) $value, '0', $scale);
    }

    private function add(mixed $left, mixed $right): string
    {
        return bcadd((string) $left, (string) $right, self::MONEY_SCALE);
    }

    private function mul(mixed $left, mixed $right): string
    {
        return bcmul((string) $left, (string) $right, self::MONEY_SCALE + 8);
    }

    private function div(mixed $left, mixed $right, int $scale): string
    {
        return bcdiv((string) $left, (string) $right, $scale);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true);
    }
}
