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
    ];

    protected $casts = ['amount' => 'float', 'created_at' => 'datetime'];

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
            'authorization_url' => $authorizationUrl,
            'customer_message' => $customerMessage,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
