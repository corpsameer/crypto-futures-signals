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

    public const DIRECTION_LONG = 'LONG';
    public const DIRECTION_SHORT = 'SHORT';

    public const MARKET_TYPE_FUTURES = 'futures';

    public const SOURCE_TELEGRAM = 'telegram';
    public const SOURCE_COINDCX = 'coindcx';

    public const STATUS_PENDING_ENTRY = 'pending_entry';
    public const STATUS_ENTRY_TRIGGERED = 'entry_triggered';
    public const STATUS_ENTRY_MISSED = 'entry_missed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CLOSED_SL = 'closed_sl';
    public const STATUS_CLOSED_TP = 'closed_tp';
    public const STATUS_TRACKING_AFTER_SL = 'tracking_after_sl';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INVALID = 'invalid';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'pasted_signal_id',
        'user_id',
        'trader_name',
        'signal_source',
        'source_image_path',
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
        'entry_price',
        'entry_price_min',
        'entry_price_max',
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
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'leverage' => 'decimal:2',
        'entry_min' => 'decimal:12',
        'entry_max' => 'decimal:12',
        'entry_price' => 'decimal:12',
        'entry_price_min' => 'decimal:12',
        'entry_price_max' => 'decimal:12',
        'stop_loss' => 'decimal:12',
        'tp1' => 'decimal:12',
        'tp2' => 'decimal:12',
        'tp3' => 'decimal:12',
        'tp4' => 'decimal:12',
        'signal_time' => 'datetime',
        'expires_at' => 'datetime',
    ];

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

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TradeTrackingEvent::class);
    }

    public function marketSnapshots(): HasMany
    {
        return $this->hasMany(MarketSnapshot::class);
    }

    public function isLong(): bool
    {
        return $this->direction === self::DIRECTION_LONG;
    }

    public function isShort(): bool
    {
        return $this->direction === self::DIRECTION_SHORT;
    }

    public function isPendingEntry(): bool
    {
        return $this->status === self::STATUS_PENDING_ENTRY;
    }

    public function isActiveLike(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_ENTRY,
            self::STATUS_ENTRY_TRIGGERED,
            self::STATUS_ACTIVE,
            self::STATUS_TRACKING_AFTER_SL,
        ], true);
    }

    public function hasEntryRange(): bool
    {
        return ($this->entry_type === 'range')
            || ($this->entry_price_min !== null
                && $this->entry_price_max !== null
                && $this->entry_price_min != $this->entry_price_max)
            || ($this->entry_min !== null
                && $this->entry_max !== null
                && $this->entry_min != $this->entry_max);
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_TELEGRAM, null, '' => 'Telegram',
            self::SOURCE_COINDCX => 'CoinDCX Expert Pick',
            default => 'Unknown',
        };
    }

    public static function sourceBadgeClass(?string $source): string
    {
        return match ($source) {
            self::SOURCE_TELEGRAM, null, '' => 'text-bg-info',
            self::SOURCE_COINDCX => 'text-bg-primary',
            default => 'text-bg-secondary',
        };
    }

    public function getSourceLabelAttribute(): string
    {
        return self::sourceLabel($this->signal_source);
    }

    public function getSourceBadgeClassAttribute(): string
    {
        return self::sourceBadgeClass($this->signal_source);
    }

    public function getEntryDisplayAttribute(): string
    {
        if ($this->hasEntryRange()) {
            $minimum = $this->entry_price_min ?? $this->entry_min;
            $maximum = $this->entry_price_max ?? $this->entry_max;

            if ($minimum !== null && $maximum !== null) {
                return $minimum.' – '.$maximum;
            }
        }

        foreach (['entry_price', 'entry_price_min', 'entry_min', 'entry_price_max', 'entry_max'] as $field) {
            if ($this->{$field} !== null) {
                return (string) $this->{$field};
            }
        }

        return 'N/A';
    }

    /**
     * @return array<string, mixed>
     */
    public function getTpLevelsAttribute(): array
    {
        return array_filter([
            'tp1' => $this->tp1,
            'tp2' => $this->tp2,
            'tp3' => $this->tp3,
            'tp4' => $this->tp4,
        ], fn ($value): bool => $value !== null);
    }
}
