<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use ApiSerializable;

    public $timestamps = false;

    protected $fillable = [
        'title', 'date', 'time', 'location', 'description',
        'category', 'image_url', 'is_active',
        'ticket_sales_enabled', 'capacity',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ticket_sales_enabled' => 'boolean',
        'capacity' => 'integer',
    ];

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class);
    }

    public function ticketsSold(): int
    {
        return (int) $this->ticketTypes()->sum('sold_count');
    }

    public function remainingCapacity(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, (int) $this->capacity - $this->ticketsSold());
    }

    public function toApiArray(): array
    {
        $remaining = $this->remainingCapacity();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'date' => $this->date,
            'time' => $this->time,
            'location' => $this->location,
            'description' => $this->description,
            'category' => $this->category,
            'image_url' => $this->image_url,
            'is_active' => (bool) $this->is_active,
            'ticket_sales_enabled' => (bool) ($this->ticket_sales_enabled ?? true),
            'capacity' => $this->capacity,
            'tickets_sold' => $this->ticketsSold(),
            'remaining_capacity' => $remaining,
        ];
    }
}
