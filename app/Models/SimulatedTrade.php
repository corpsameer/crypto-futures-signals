<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimulatedTrade extends Model
{
    /** @use HasFactory<\Database\Factories\SimulatedTradeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'trade_signal_id',
        'user_id',
        'symbol',
        'direction',
        'leverage',
        'entry_price',
        'entry_triggered_at',
        'stop_loss',
        'current_price',
        'max_price',
        'min_price',
        'max_actual_price_move_percent',
        'max_leveraged_pnl_percent',
        'min_actual_price_move_percent',
        'min_leveraged_pnl_percent',
        'exit_price',
        'exit_reason',
        'closed_at',
        'status',
        'tracking_until',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leverage' => 'decimal:2',
            'entry_price' => 'decimal:12',
            'entry_triggered_at' => 'datetime',
            'stop_loss' => 'decimal:12',
            'current_price' => 'decimal:12',
            'max_price' => 'decimal:12',
            'min_price' => 'decimal:12',
            'max_actual_price_move_percent' => 'decimal:4',
            'max_leveraged_pnl_percent' => 'decimal:4',
            'min_actual_price_move_percent' => 'decimal:4',
            'min_leveraged_pnl_percent' => 'decimal:4',
            'exit_price' => 'decimal:12',
            'closed_at' => 'datetime',
            'tracking_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }

    public function tradeTrackingEvents(): HasMany
    {
        return $this->hasMany(TradeTrackingEvent::class);
    }

    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }
}
