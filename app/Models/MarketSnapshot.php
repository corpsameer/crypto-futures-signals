<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSnapshot extends Model
{
    /** @use HasFactory<\Database\Factories\MarketSnapshotFactory> */
    use HasFactory;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:12',
            'volume_24h' => 'decimal:8',
            'price_change_24h_percent' => 'decimal:4',
            'funding_rate' => 'decimal:6',
            'open_interest' => 'decimal:8',
            'raw_payload' => 'array',
            'snapshot_at' => 'datetime',
        ];
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
