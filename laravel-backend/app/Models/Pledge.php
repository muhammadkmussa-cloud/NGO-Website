<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The recurring monthly commitment (spec §1). Status tracks the SUPPORTER's
 * relationship to the pledge — month-level outcomes live on PledgePayment.
 */
class Pledge extends Model
{
    use ApiSerializable;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_PAUSED = 'PAUSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const METHOD_MPESA = 'mpesa';

    public const METHOD_CARD = 'card';

    protected $fillable = [
        'user_id', 'name', 'email', 'phone', 'amount', 'currency', 'method',
        'channel', 'status', 'start_date', 'next_payment_date',
        'last_successful_payment_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'next_payment_date' => 'date',
        'last_successful_payment_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pledge) {
            $pledge->currency ??= 'KES';
            $pledge->method ??= self::METHOD_MPESA;
            $pledge->status ??= self::STATUS_ACTIVE;
            $pledge->name ??= 'Anonymous';
            $pledge->start_date ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PledgePayment::class);
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PledgePaymentAttempt::class);
    }

    public function emailAttempts(): HasMany
    {
        return $this->hasMany(PledgeEmailAttempt::class);
    }

    /**
     * Scheduler eligibility (spec §2 + §17): ACTIVE M-Pesa pledges whose next
     * obligation is due. PAUSED / CANCELLED / card pledges are excluded — no
     * automatic STK requests for them, ever.
     */
    public function scopeCollectable(Builder $query, string $onOrBefore): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('method', self::METHOD_MPESA)
            ->whereNotNull('next_payment_date')
            ->whereDate('next_payment_date', '<=', $onOrBefore);
    }

    public function isCollectableOn(string $onOrBefore): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->method === self::METHOD_MPESA
            && $this->next_payment_date !== null
            && $this->next_payment_date->format('Y-m-d') <= $onOrBefore;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'channel' => $this->channel,
            'status' => $this->status,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'next_payment_date' => $this->next_payment_date?->format('Y-m-d'),
            'last_successful_payment_date' => $this->last_successful_payment_date?->format('Y-m-d'),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
