<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\MarketSnapshotFactory> */
    use HasFactory;

    public const SNAPSHOT_SIGNAL_RECEIVED = 'signal_received';
    public const SNAPSHOT_SIGNAL_SAVED = 'signal_saved';
    public const SNAPSHOT_ENTRY_TRIGGERED = 'entry_triggered';
    public const SNAPSHOT_TP_HIT = 'tp_hit';
    public const SNAPSHOT_SL_HIT = 'sl_hit';
    public const SNAPSHOT_TRADE_CLOSED = 'trade_closed';
    public const SNAPSHOT_PERIODIC = 'periodic';

    public const MARKET_CONDITION_BULLISH = 'bullish';
    public const MARKET_CONDITION_BEARISH = 'bearish';
    public const MARKET_CONDITION_SIDEWAYS = 'sideways';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'trade_signal_id',
        'simulated_trade_id',
        'symbol',
        'snapshot_type',
        'price',
        'btc_price',
        'btc_24h_change_percent',
        'eth_price',
        'eth_24h_change_percent',
        'market_condition',
        'volume_24h',
        'price_change_24h_percent',
        'funding_rate',
        'open_interest',
        'raw_payload',
        'snapshot_at',
        'captured_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:12',
        'btc_price' => 'decimal:12',
        'btc_24h_change_percent' => 'decimal:4',
        'eth_price' => 'decimal:12',
        'eth_24h_change_percent' => 'decimal:4',
        'volume_24h' => 'decimal:8',
        'price_change_24h_percent' => 'decimal:4',
        'funding_rate' => 'decimal:6',
        'open_interest' => 'decimal:8',
        'raw_payload' => 'array',
        'snapshot_at' => 'datetime',
        'captured_at' => 'datetime',
    ];

    public function tradeSignal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class);
    }

    public function simulatedTrade(): BelongsTo
    {
        return $this->belongsTo(SimulatedTrade::class);
    }
}
