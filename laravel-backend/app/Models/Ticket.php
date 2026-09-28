<?php

namespace App\Models;

use App\Models\Concerns\ApiSerializable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    use ApiSerializable;

    public $timestamps = false;

    protected $fillable = [
        'ticket_order_id', 'ticket_type_id', 'code',
        'attendee_name', 'attendee_email', 'status',
        'issued_at', 'checked_in_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'checked_in_at' => 'datetime',
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
            'ticket_order_id' => $this->ticket_order_id,
            'ticket_type_id' => $this->ticket_type_id,
            'ticket_type_name' => $this->ticketType?->name,
            'code' => $this->code,
            'attendee_name' => $this->attendee_name,
            'attendee_email' => $this->attendee_email,
            'status' => $this->status,
            'issued_at' => $this->iso($this->issued_at),
            'checked_in_at' => $this->iso($this->checked_in_at),
        ];
    }
}
