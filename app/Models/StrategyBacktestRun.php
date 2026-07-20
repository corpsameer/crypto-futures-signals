<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyBacktestRun extends Model
{
    /** @use HasFactory<\Database\Factories\StrategyBacktestRunFactory> */
    use HasFactory;

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'started_at',
        'completed_at',
        'starting_capital',
        'status',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'starting_capital' => 'decimal:8',
    ];

    public function tradeResults(): HasMany
    {
        return $this->hasMany(StrategyTradeResult::class);
    }

    public function strategies(): BelongsToMany
    {
        return $this->belongsToMany(
            StrategyDefinition::class,
            'strategy_trade_results',
            'strategy_backtest_run_id',
            'strategy_definition_id'
        )->distinct();
    }
}
