<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SimulatedTrade extends Model
{
    /** @use HasFactory<\Database\Factories\SimulatedTradeFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED_SL = 'closed_sl';
    public const STATUS_CLOSED_TP = 'closed_tp';
    public const STATUS_TRACKING_AFTER_SL = 'tracking_after_sl';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';

    public const EXIT_REASON_SL = 'SL_HIT';
    public const EXIT_REASON_TP = 'TP_HIT';
    public const EXIT_REASON_EXPIRED = 'TRADE_EXPIRED';
    public const EXIT_REASON_MANUAL = 'MANUAL_CLOSE';

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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TradeTrackingEvent::class);
    }

    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(TradeTrackingEvent::class)->latestOfMany('event_timestamp');
    }

    public function entryEvent(): HasOne
    {
        return $this->hasOne(TradeTrackingEvent::class)
            ->where('event_type', TradeTrackingEvent::EVENT_ENTRY_TRIGGERED);
    }

    public function slEvent(): HasOne
    {
        return $this->hasOne(TradeTrackingEvent::class)
            ->where('event_type', TradeTrackingEvent::EVENT_SL_HIT);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [
            self::STATUS_CLOSED_SL,
            self::STATUS_CLOSED_TP,
            self::STATUS_EXPIRED,
            self::STATUS_COMPLETED,
        ], true);
    }

    public function isTrackingAfterSl(): bool
    {
        return $this->status === self::STATUS_TRACKING_AFTER_SL;
    }
}
