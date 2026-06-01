<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeTrackingEvent extends Model
{
    /** @use HasFactory<\Database\Factories\TradeTrackingEventFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'simulated_trade_id',
        'trade_signal_id',
        'event_type',
        'event_price',
        'actual_price_move_percent',
        'leveraged_pnl_percent',
        'event_timestamp',
        'metadata',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_price' => 'decimal:12',
            'actual_price_move_percent' => 'decimal:4',
            'leveraged_pnl_percent' => 'decimal:4',
            'event_timestamp' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function simulatedTrade(): BelongsTo
    {
        return $this->belongsTo(SimulatedTrade::class);
    }

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }
}
