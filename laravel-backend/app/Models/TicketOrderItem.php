<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketOrderItem extends Model
{
    use ApiSerializable;

    public $timestamps = false;

    protected $fillable = [
        'ticket_order_id', 'ticket_type_id', 'quantity', 'unit_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(TicketOrder::class, 'ticket_order_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'ticket_type_id' => $this->ticket_type_id,
            'ticket_type_name' => $this->ticketType?->name,
            'quantity' => (int) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'line_total' => (float) $this->unit_price * (int) $this->quantity,
        ];
    }
}
