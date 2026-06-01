<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradeSignal extends Model
{
    /** @use HasFactory<\Database\Factories\TradeSignalFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pasted_signal_id',
        'user_id',
        'trader_name',
        'exchange',
        'symbol',
        'pair',
        'market_type',
        'direction',
        'leverage',
        'margin_mode',
        'entry_min',
        'entry_max',
        'entry_type',
        'stop_loss',
        'tp1',
        'tp2',
        'tp3',
        'tp4',
        'signal_time',
        'expires_at',
        'status',
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
            'leverage' => 'decimal:2',
            'entry_min' => 'decimal:12',
            'entry_max' => 'decimal:12',
            'stop_loss' => 'decimal:12',
            'tp1' => 'decimal:12',
            'tp2' => 'decimal:12',
            'tp3' => 'decimal:12',
            'tp4' => 'decimal:12',
            'signal_time' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pastedSignal(): BelongsTo
    {
        return $this->belongsTo(PastedSignal::class);
    }

    public function simulatedTrades(): HasMany
    {
        return $this->hasMany(SimulatedTrade::class);
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
