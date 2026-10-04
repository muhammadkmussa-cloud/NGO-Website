<?php

namespace App\Services;

use App\Models\Ticket;

class TicketCheckInService
{
    public static function normalizeCode(?string $raw): string
    {
        $value = strtoupper(trim((string) $raw));
        if (preg_match('/DEMO-[A-Z0-9]{4}-[A-Z0-9]{4}/', $value, $m)) {
            return $m[0];
        }

        return preg_replace('/\s+/', '', $value) ?: '';
    }

    public function find(string $raw): ?Ticket
    {
        $code = self::normalizeCode($raw);
        if ($code === '') {
            return null;
        }

        return Ticket::with(['ticketType', 'order.event'])->where('code', $code)->first();
    }

    public function inspectPayload(?Ticket $ticket): array
    {
        if (!$ticket) {
            return [
                'result' => 'not_found',
                'admissible' => false,
                'detail' => 'Ticket not found',
            ];
        }

        $base = $this->gateArray($ticket);
        if ($ticket->status === 'void') {
            return $base + ['result' => 'void', 'admissible' => false, 'detail' => 'Ticket has been voided.'];
        }
        if ($ticket->status === 'checked_in') {
            $when = $ticket->checked_in_at;
            $detail = $when
                ? 'Ticket already checked in at '
                    . $when->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i') . ' EAT.'
                : 'Ticket already checked in.';

            return $base + ['result' => 'already', 'admissible' => false, 'detail' => $detail];
        }
        if ($ticket->order && $ticket->order->status !== 'Completed') {
            return $base + ['result' => 'unpaid', 'admissible' => false, 'detail' => 'Order is not paid.'];
        }

        return $base + ['result' => 'ready', 'admissible' => true, 'detail' => 'Ready for admission.'];
    }

    public function gateArray(Ticket $ticket): array
    {
        $ticket->loadMissing(['ticketType', 'order.event']);

        return $ticket->toApiArray() + [
            'event_title' => $ticket->order?->event?->title,
            'event_date' => $ticket->order?->event?->date,
            'event_location' => $ticket->order?->event?->location,
            'order_reference' => $ticket->order?->reference,
            'order_status' => $ticket->order?->status,
        ];
    }
}
