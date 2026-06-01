<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PastedSignal extends Model
{
    /** @use HasFactory<\Database\Factories\PastedSignalFactory> */
    use HasFactory;

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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parsed_payload' => 'array',
            'pasted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tradeSignals(): HasMany
    {
        return $this->hasMany(TradeSignal::class);
    }
}
