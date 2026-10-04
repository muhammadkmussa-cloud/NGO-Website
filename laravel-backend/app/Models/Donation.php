<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'donor_name', 'email', 'amount', 'currency', 'gateway', 'frequency',
        'reference', 'checkout_request_id', 'merchant_request_id', 'status',
        'subscription_code', 'subscription_token', 'subscription_status',
        'subscription_manage_url', 'manage_link_expires_at', 'next_payment_date',
    ];

    protected $casts = [
        'amount' => 'float',
        'created_at' => 'datetime',
        'manage_link_expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $donation) {
            $donation->donor_name ??= 'Anonymous';
            $donation->currency ??= 'KES';
            $donation->frequency ??= 'one-time';
            $donation->status ??= 'Completed';
            $donation->created_at ??= now();
        });
    }

    public function toApiArray(?string $authorizationUrl = null, ?string $customerMessage = null): array
    {
        return [
            'id' => $this->id,
            'donor_name' => $this->donor_name,
            'email' => $this->email,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'frequency' => $this->frequency,
            'reference' => $this->reference,
            'checkout_request_id' => $this->checkout_request_id,
            'merchant_request_id' => $this->merchant_request_id,
            'status' => $this->status,
            // Non-sensitive pledge state for the return page badge. The hosted
            // manage/cancel URL is deliberately NOT included — it is served
            // only by GET /api/payments/subscription/{reference}/manage.
            'subscription' => $this->subscription_status !== null ? [
                'status' => $this->subscription_status,
                'next_payment_date' => $this->next_payment_date,
            ] : null,
            'authorization_url' => $authorizationUrl,
            'customer_message' => $customerMessage,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
