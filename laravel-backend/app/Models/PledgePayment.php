<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month's obligation for one pledge (spec §1). Exactly one row per
 * (pledge, billing_month) — enforced by a composite unique key — so two PAID
 * records for the same period are structurally impossible.
 */
class PledgePayment extends Model
{
    use ApiSerializable;

    public const STATUS_DUE = 'DUE';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PAID = 'PAID';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_MISSED = 'MISSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'pledge_id', 'billing_month', 'amount_due', 'currency', 'due_date',
        'status', 'method', 'channel', 'paystack_reference',
        'paystack_transaction_id', 'attempts', 'last_attempt_at', 'paid_at',
        'reminder_count', 'last_reminder_at',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'due_date' => 'date',
        'last_attempt_at' => 'datetime',
        'paid_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'attempts' => 'integer',
        'reminder_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->status ??= self::STATUS_DUE;
            $payment->currency ??= 'KES';
            $payment->method ??= Pledge::METHOD_MPESA;
        });
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    /**
     * Individual transaction attempts (spec §8). NOT named attempts() — that
     * would shadow the `attempts` counter column and flip toArray() shapes
     * between eager-loaded and plain models.
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PledgePaymentAttempt::class);
    }

    public function emailAttempts(): HasMany
    {
        return $this->hasMany(PledgeEmailAttempt::class);
    }

    /**
     * Billing month key (YYYY-MM) computed in the pledge timezone — NOT UTC
     * (spec §19: Africa/Nairobi, so a 22:30 UTC instant on the 31st bills the
     * 1st of the following month locally).
     */
    public static function billingMonthFor(\DateTimeInterface|string|null $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return Carbon::parse($date)
            ->timezone(config('roi.pledge_timezone', 'Africa/Nairobi'))
            ->format('Y-m');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'pledge_id' => $this->pledge_id,
            'billing_month' => $this->billing_month,
            'amount_due' => (float) $this->amount_due,
            'currency' => $this->currency,
            'due_date' => $this->due_date?->format('Y-m-d'),
            'status' => $this->status,
            'method' => $this->method,
            'channel' => $this->channel,
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->iso($this->last_attempt_at),
            'paid_at' => $this->iso($this->paid_at),
            'reminder_count' => $this->reminder_count,
            'last_reminder_at' => $this->iso($this->last_reminder_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
