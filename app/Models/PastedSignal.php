<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PastedSignal extends Model
{
    /** @use HasFactory<\Database\Factories\PastedSignalFactory> */
    use HasFactory;

    public const PARSE_STATUS_PENDING = 'pending';
    public const PARSE_STATUS_PARSED = 'parsed';
    public const PARSE_STATUS_FAILED = 'failed';
    public const PARSE_STATUS_MANUALLY_CORRECTED = 'manually_corrected';

    public const SOURCE_MANUAL_PASTE = 'manual_paste';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'trader_name',
        'raw_text',
        'parsed_payload',
        'parse_status',
        'parse_error',
        'source',
        'pasted_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'parsed_payload' => 'array',
        'pasted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeSignals(): HasMany
    {
        return $this->hasMany(TradeSignal::class);
    }

    public function latestTradeSignal(): HasOne
    {
        return $this->hasOne(TradeSignal::class)->latestOfMany();
    }

    public function isParsed(): bool
    {
        return $this->parse_status === self::PARSE_STATUS_PARSED;
    }

    public function isFailed(): bool
    {
        return $this->parse_status === self::PARSE_STATUS_FAILED;
    }
}
