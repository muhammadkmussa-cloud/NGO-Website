<?php

namespace App\Services;

use App\Models\TicketOrder;
use Illuminate\Support\Facades\Log;

class TicketDeliveryService
{
    /**
     * Mark tickets as issued (no email). Idempotent unless $force is true.
     */
    public function deliver(TicketOrder $order, bool $force = false): TicketOrder
    {
        $order->loadMissing(['tickets.ticketType', 'event']);

        if ($order->status !== 'Completed' || $order->tickets->isEmpty()) {
            return $order;
        }

        if (!$force && $order->issued_at) {
            return $order;
        }

        $order->issued_at = now();
        $order->save();

        return $order->fresh(['tickets.ticketType', 'event', 'items.ticketType']);
    }
}
