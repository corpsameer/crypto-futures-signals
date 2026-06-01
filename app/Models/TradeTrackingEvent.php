<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeTrackingEvent extends Model
{
    /** @use HasFactory<\Database\Factories\TradeTrackingEventFactory> */
    use HasFactory;

    public const EVENT_ENTRY_TRIGGERED = 'ENTRY_TRIGGERED';
    public const EVENT_GAIN_3_PERCENT = 'GAIN_3_PERCENT';
    public const EVENT_GAIN_3_5_PERCENT = 'GAIN_3_5_PERCENT';
    public const EVENT_GAIN_5_PERCENT = 'GAIN_5_PERCENT';
    public const EVENT_GAIN_7_PERCENT = 'GAIN_7_PERCENT';
    public const EVENT_TP1_HIT = 'TP1_HIT';
    public const EVENT_TP2_HIT = 'TP2_HIT';
    public const EVENT_TP3_HIT = 'TP3_HIT';
    public const EVENT_TP4_HIT = 'TP4_HIT';
    public const EVENT_SL_HIT = 'SL_HIT';
    public const EVENT_SL_TO_BREAKEVEN = 'SL_TO_BREAKEVEN';
    public const EVENT_POST_SL_TP1_HIT = 'POST_SL_TP1_HIT';
    public const EVENT_POST_SL_TP2_HIT = 'POST_SL_TP2_HIT';
    public const EVENT_POST_SL_TP3_HIT = 'POST_SL_TP3_HIT';
    public const EVENT_POST_SL_TP4_HIT = 'POST_SL_TP4_HIT';
    public const EVENT_POST_SL_MAX_GAIN = 'POST_SL_MAX_GAIN';
    public const EVENT_TRADE_EXPIRED = 'TRADE_EXPIRED';
    public const EVENT_TRADE_CLOSED = 'TRADE_CLOSED';

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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event_price' => 'decimal:12',
        'actual_price_move_percent' => 'decimal:4',
        'leveraged_pnl_percent' => 'decimal:4',
        'event_timestamp' => 'datetime',
        'metadata' => 'array',
    ];

    public function simulatedTrade(): BelongsTo
    {
        return $this->belongsTo(SimulatedTrade::class);
    }

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }

    public function isEntry(): bool
    {
        return $this->event_type === self::EVENT_ENTRY_TRIGGERED;
    }

    public function isStopLoss(): bool
    {
        return $this->event_type === self::EVENT_SL_HIT;
    }

    public function isTakeProfit(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_TP1_HIT,
            self::EVENT_TP2_HIT,
            self::EVENT_TP3_HIT,
            self::EVENT_TP4_HIT,
        ], true);
    }

    public function isCustomGainMilestone(): bool
    {
        return in_array($this->event_type, [
            self::EVENT_GAIN_3_PERCENT,
            self::EVENT_GAIN_3_5_PERCENT,
            self::EVENT_GAIN_5_PERCENT,
            self::EVENT_GAIN_7_PERCENT,
        ], true);
    }

    public function isPostSlEvent(): bool
    {
        return str_starts_with((string) $this->event_type, 'POST_SL_');
    }
}
