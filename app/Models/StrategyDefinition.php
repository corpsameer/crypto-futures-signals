<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyDefinition extends Model
{
    /** @use HasFactory<\Database\Factories\StrategyDefinitionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'description',
        'strategy_type',
        'target_event_type',
        'stop_event_type',
        'allocation_percent',
        'starting_capital',
        'current_capital',
        'loss_cap_percent',
        'uses_post_sl_recovery',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'allocation_percent' => 'decimal:4',
        'starting_capital' => 'decimal:8',
        'current_capital' => 'decimal:8',
        'loss_cap_percent' => 'decimal:4',
        'uses_post_sl_recovery' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tradeResults(): HasMany
    {
        return $this->hasMany(StrategyTradeResult::class);
    }

    public function backtestRuns(): BelongsToMany
    {
        return $this->belongsToMany(
            StrategyBacktestRun::class,
            'strategy_trade_results',
            'strategy_definition_id',
            'strategy_backtest_run_id'
        )->distinct();
    }
}
