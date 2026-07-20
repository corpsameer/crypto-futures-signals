<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyTradeResult extends Model
{
    /** @use HasFactory<\Database\Factories\StrategyTradeResultFactory> */
    use HasFactory;

    public const RESULT_STATUS_WIN = 'win';
    public const RESULT_STATUS_LOSS = 'loss';
    public const RESULT_STATUS_SKIPPED = 'skipped';
    public const RESULT_STATUS_OPEN = 'open';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'strategy_backtest_run_id',
        'strategy_definition_id',
        'trade_signal_id',
        'simulated_trade_id',
        'symbol',
        'direction',
        'trader_name',
        'capital_before',
        'allocation_percent',
        'allocated_capital',
        'entry_price',
        'entry_time',
        'exit_price',
        'exit_time',
        'exit_event_type',
        'exit_leveraged_pnl_percent',
        'gross_pnl',
        'fees',
        'net_pnl',
        'capital_after',
        'return_percent',
        'result_status',
        'sl_hit_first',
        'sl_hit_time',
        'post_sl_recovered',
        'post_sl_first_recovery_event',
        'post_sl_first_recovery_price',
        'post_sl_first_recovery_time',
        'post_sl_max_gain_percent',
        'post_sl_max_gain_price',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'capital_before' => 'decimal:8',
        'allocation_percent' => 'decimal:4',
        'allocated_capital' => 'decimal:8',
        'entry_price' => 'decimal:12',
        'entry_time' => 'datetime',
        'exit_price' => 'decimal:12',
        'exit_time' => 'datetime',
        'exit_leveraged_pnl_percent' => 'decimal:8',
        'gross_pnl' => 'decimal:8',
        'fees' => 'decimal:8',
        'net_pnl' => 'decimal:8',
        'capital_after' => 'decimal:8',
        'return_percent' => 'decimal:8',
        'sl_hit_first' => 'boolean',
        'sl_hit_time' => 'datetime',
        'post_sl_recovered' => 'boolean',
        'post_sl_first_recovery_price' => 'decimal:12',
        'post_sl_first_recovery_time' => 'datetime',
        'post_sl_max_gain_percent' => 'decimal:8',
        'post_sl_max_gain_price' => 'decimal:12',
    ];

    public function strategyDefinition(): BelongsTo
    {
        return $this->belongsTo(StrategyDefinition::class);
    }

    public function backtestRun(): BelongsTo
    {
        return $this->belongsTo(StrategyBacktestRun::class, 'strategy_backtest_run_id');
    }

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }

    public function simulatedTrade(): BelongsTo
    {
        return $this->belongsTo(SimulatedTrade::class);
    }
}
