<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketType extends Model
{
    use ApiSerializable;

    protected $fillable = [
        'event_id', 'name', 'description', 'price', 'currency',
        'quantity', 'sold_count', 'sales_start', 'sales_end', 'is_active', 'max_per_order',
    ];

    protected $casts = [
        'price' => 'float',
        'quantity' => 'integer',
        'sold_count' => 'integer',
        'max_per_order' => 'integer',
        'is_active' => 'boolean',
        'sales_start' => 'datetime',
        'sales_end' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function remaining(): ?int
    {
        if ($this->quantity === null) {
            return null;
        }

        return max(0, (int) $this->quantity - (int) $this->sold_count);
    }

    public function maxPerOrder(): int
    {
        return max(1, (int) ($this->max_per_order ?: 10));
    }

    public function saleState(): string
    {
        $event = $this->relationLoaded('event') ? $this->event : $this->event()->first();
        if ($event && !($event->ticket_sales_enabled ?? true)) {
            return 'event_closed';
        }
        if (!$this->is_active) {
            return 'inactive';
        }

        $now = now();
        if ($this->sales_start && $now->lt($this->sales_start)) {
            return 'not_started';
        }
        if ($this->sales_end && $now->gt($this->sales_end)) {
            return 'ended';
        }

        $remaining = $this->remaining();
        if ($remaining !== null && $remaining <= 0) {
            return 'sold_out';
        }

        return 'on_sale';
    }

    public function isOnSale(): bool
    {
        return $this->saleState() === 'on_sale';
    }

    public function toApiArray(): array
    {
        $remaining = $this->remaining();
        $state = $this->saleState();

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => (float) $this->price,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'sold_count' => (int) $this->sold_count,
            'remaining' => $remaining,
            'unlimited' => $this->quantity === null,
            'sales_start' => $this->iso($this->sales_start),
            'sales_end' => $this->iso($this->sales_end),
            'is_active' => (bool) $this->is_active,
            'on_sale' => $state === 'on_sale',
            'sale_state' => $state,
            'max_per_order' => $this->maxPerOrder(),
            'created_at' => $this->iso($this->created_at),
            'updated_at' => $this->iso($this->updated_at),
        ];
    }
}
