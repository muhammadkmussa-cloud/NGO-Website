<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketOrder extends Model
{
    use ApiSerializable;

    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'buyer_name', 'buyer_email', 'buyer_phone',
        'gateway', 'reference', 'checkout_request_id', 'merchant_request_id',
        'amount', 'currency', 'status', 'paid_at', 'issued_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'created_at' => 'datetime',
        'paid_at' => 'datetime',
        'issued_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TicketOrderItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function toApiArray(?string $authorizationUrl = null, ?string $customerMessage = null): array
    {
        $this->loadMissing(['items.ticketType', 'tickets', 'event']);

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'event' => $this->event?->toApiArray(),
            'buyer_name' => $this->buyer_name,
            'buyer_email' => $this->buyer_email,
            'buyer_phone' => $this->buyer_phone,
            'gateway' => $this->gateway,
            'reference' => $this->reference,
            'checkout_request_id' => $this->checkout_request_id,
            'merchant_request_id' => $this->merchant_request_id,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'authorization_url' => $authorizationUrl,
            'customer_message' => $customerMessage,
            'items' => $this->items->map->toApiArray()->values()->all(),
            'tickets' => $this->tickets->map->toApiArray()->values()->all(),
            'paid_at' => $this->iso($this->paid_at),
            'issued_at' => $this->iso($this->issued_at),
            'delivered' => (bool) $this->issued_at,
            'created_at' => $this->iso($this->created_at),
        ];
    }
}
