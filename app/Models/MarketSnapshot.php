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
    public const SNAPSHOT_ENTRY_TRIGGERED = 'entry_triggered';
    public const SNAPSHOT_TP_HIT = 'tp_hit';
    public const SNAPSHOT_SL_HIT = 'sl_hit';
    public const SNAPSHOT_PERIODIC = 'periodic';

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
        'volume_24h',
        'price_change_24h_percent',
        'funding_rate',
        'open_interest',
        'raw_payload',
        'snapshot_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:12',
        'volume_24h' => 'decimal:8',
        'price_change_24h_percent' => 'decimal:4',
        'funding_rate' => 'decimal:6',
        'open_interest' => 'decimal:8',
        'raw_payload' => 'array',
        'snapshot_at' => 'datetime',
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
