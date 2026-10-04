<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single transaction attempt against one month's obligation (spec §8):
 * the audit trail for retries — every STK push / charge gets its own row
 * with its own unique reference, while the obligation stays singular.
 */
class PledgePaymentAttempt extends Model
{
    use ApiSerializable;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'pledge_payment_id', 'pledge_id', 'reference', 'amount', 'currency',
        'method', 'channel', 'status', 'paystack_transaction_id',
        'initiated_at', 'responded_at', 'failure_reason', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'initiated_at' => 'datetime',
        'responded_at' => 'datetime',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->status ??= self::STATUS_INITIATED;
            $attempt->currency ??= 'KES';
            $attempt->method ??= Pledge::METHOD_MPESA;
            $attempt->initiated_at ??= now();
        });
    }

    public function pledgePayment(): BelongsTo
    {
        return $this->belongsTo(PledgePayment::class);
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'pledge_payment_id' => $this->pledge_payment_id,
            'reference' => $this->reference,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'method' => $this->method,
            'channel' => $this->channel,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'initiated_at' => $this->iso($this->initiated_at),
            'responded_at' => $this->iso($this->responded_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
