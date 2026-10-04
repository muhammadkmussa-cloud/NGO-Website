<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound email ledger for a month's obligation (spec §5 + §11): reminders
 * and the payment confirmation. The (payment, kind, reminder_key) unique key
 * is the anti-spam guarantee — a cron re-run can never double-send.
 */
class PledgeEmailAttempt extends Model
{
    use ApiSerializable;

    public const KIND_REMINDER = 'reminder';

    public const KIND_CONFIRMATION = 'confirmation';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'pledge_id', 'pledge_payment_id', 'kind', 'reminder_key', 'status',
        'sent_at', 'error',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->status ??= self::STATUS_SENT;
        });
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    public function pledgePayment(): BelongsTo
    {
        return $this->belongsTo(PledgePayment::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'pledge_id' => $this->pledge_id,
            'pledge_payment_id' => $this->pledge_payment_id,
            'kind' => $this->kind,
            'reminder_key' => $this->reminder_key,
            'status' => $this->status,
            'sent_at' => $this->iso($this->sent_at),
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
