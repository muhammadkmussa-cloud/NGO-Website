<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use App\Services\AuditLogger;
use App\Services\EmailExistenceService;
use App\Services\PaystackService;
use App\Services\TicketCheckInService;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function __construct(
        protected TicketService $tickets,
        protected PaystackService $paystack,
        protected AuditLogger $audit,
        protected EmailExistenceService $emails,
    ) {
    }

    /** GET /api/public/events/{eventId}/tickets */
    public function publicEventTickets(int $eventId): JsonResponse
    {
        $event = Event::where('id', $eventId)->where('is_active', true)->first();
        if (!$event) {
            return response()->json(['detail' => 'Event not found'], 404);
        }

        $types = TicketType::where('event_id', $eventId)
            ->where('is_active', true)
            ->with('event')
            ->orderBy('price')
            ->get()
            ->map->toApiArray()
            ->values();

        return response()->json([
            'event' => $event->toApiArray(),
            'ticket_types' => $types,
        ]);
    }

    /** POST /api/tickets/checkout */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'buyer_name' => ['required', 'string', 'max:200'],
            'buyer_email' => ['required', 'email:rfc'],
            'buyer_phone' => ['nullable', 'string'],
            'gateway' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ticket_type_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $this->emails->assertAcceptable($data['buyer_email']);

        $origin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();
        $result = $this->tickets->checkout($data, $origin);

        return response()->json(
            $result['order']->toApiArray($result['authorization_url'], $result['customer_message']),
            201
        );
    }

    /**
     * H-3: resolve an order by reference AND require the caller to confirm the
     * buyer's email address. References alone are not a credential. A mismatched
     * or missing email returns the same 404 as an unknown reference so order
     * existence cannot be probed.
     */
    protected function orderForEmail(Request $request, string $reference, ?string $email = null): ?TicketOrder
    {
        $confirmed = strtolower(trim((string) ($email ?? $request->query('email', ''))));
        if ($confirmed === '') {
            return null;
        }

        return TicketOrder::where('reference', $reference)
            ->whereRaw('lower(buyer_email) = ?', [$confirmed])
            ->first();
    }

    protected function orderNotFound(): JsonResponse
    {
        return response()->json(['detail' => 'Ticket order not found'], 404);
    }

    /** GET /api/tickets/orders/{reference}?email= */
    public function showOrder(Request $request, string $reference): JsonResponse
    {
        $order = $this->orderForEmail($request, $reference);
        if (!$order) {
            return $this->orderNotFound();
        }

        return response()->json($order->toApiArray());
    }

    /** GET /api/tickets/orders/{reference}/verify?email= */
    public function verifyOrder(Request $request, string $reference): JsonResponse
    {
        $order = $this->orderForEmail($request, $reference);
        if (!$order) {
            return $this->orderNotFound();
        }

        // Settled orders (incl. complimentary) are a read-only check — no
        // gateway call — so they must verify even while payments are disabled.
        if ($order->status === 'Completed') {
            return response()->json($order->toApiArray());
        }

        if (! config('roi.payments_enabled')) {
            return response()->json(['detail' => 'Payments are temporarily unavailable.'], 503);
        }

        if (strtolower($order->gateway) === 'paystack') {
            if (\App\Support\DevBypass::enabled()) {
                return response()->json($this->tickets->fulfill($order)->toApiArray());
            }

            try {
                $status = $this->paystack->verify($reference);
                if ($status === 'Completed') {
                    return response()->json($this->tickets->fulfill($order)->toApiArray());
                }
                if (str_starts_with($status, 'Failed')) {
                    // Terminal failure: release reserved capacity.
                    return response()->json($this->tickets->markFailed($order, $status)->toApiArray());
                }
                // In-flight (Pending (...)): persist only under lock so a terminal
                // status set by a concurrent webhook is never clobbered.
                $this->tickets->markPending($order, $status);
            } catch (\Throwable $e) {
                return response()->json(['detail' => $e->getMessage()], 502);
            }
        }

        return response()->json($order->fresh(['items.ticketType', 'tickets', 'event'])->toApiArray());
    }

    /** POST /api/tickets/recover — always 200 (no email enumeration).
     *  Mints a signed portal token (buyer opens their tickets on-screen). */
    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'reference' => ['nullable', 'string'],
        ]);

        $portal = app(\App\Services\TicketPortalService::class);
        $token = $portal->mintToken($data['email']);

        return response()->json([
            'status' => 'accepted',
            'portal_token' => $token,
            'message' => 'If tickets exist for that email, your access is ready.',
        ]);
    }

    /** POST /api/tickets/orders/{reference}/stk-retry {email, buyer_phone?} */
    public function retryStk(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'buyer_phone' => ['nullable', 'string'],
        ]);

        $order = $this->orderForEmail($request, $reference, $data['email']);
        if (!$order) {
            return $this->orderNotFound();
        }

        $origin = $request->headers->get('origin') ?: $request->getSchemeAndHttpHost();

        try {
            $updated = $this->tickets->retryStk($order, $data['buyer_phone'] ?? null, $origin);
        } catch (\App\Services\Exceptions\PaymentGatewayException $e) {
            return response()->json(['detail' => $e->getMessage()], $e->statusCode);
        }

        return response()->json($updated->toApiArray(
            customerMessage: 'Daraja STK Push re-dispatched.',
        ));
    }

    /** GET /api/tickets/lookup/{code} — public confirmation by ticket code */
    public function lookupTicket(string $code): JsonResponse
    {
        $ticket = Ticket::where('code', strtoupper($code))->first();
        if (!$ticket) {
            return response()->json(['detail' => 'Ticket not found'], 404);
        }

        return response()->json($ticket->toApiArray());
    }

    /** GET /api/tickets/portal/{token} */
    public function portal(string $token): JsonResponse
    {
        $portal = app(\App\Services\TicketPortalService::class);
        $email = $portal->emailFromToken($token);
        if (!$email) {
            return response()->json(['detail' => 'Portal link is invalid or expired.'], 401);
        }

        $orders = $portal->ordersForEmail($email);

        return response()->json([
            'email' => $email,
            'orders' => $orders->map->toApiArray()->values(),
        ]);
    }

    /** POST /api/tickets/lookup-order — reference + email must both match */
    public function lookupOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc'],
            'reference' => ['required', 'string'],
        ]);

        $order = TicketOrder::where('reference', $data['reference'])
            ->whereRaw('lower(buyer_email) = ?', [strtolower($data['email'])])
            ->first();
        if (!$order) {
            return response()->json(['detail' => 'No ticket order matches that email and reference.'], 404);
        }

        $portal = app(\App\Services\TicketPortalService::class);

        return response()->json([
            'order' => $order->toApiArray(),
            'portal_token' => $portal->mintToken($data['email']),
        ]);
    }

    // --- Public gate station (open, no admin JWT) ---

    /** GET /api/gate/tickets/{code} */
    public function gateInspect(string $code): JsonResponse
    {
        $service = app(TicketCheckInService::class);
        $payload = $service->inspectPayload($service->find($code));
        if ($payload['result'] === 'not_found') {
            return response()->json(['detail' => $payload['detail'], 'result' => 'not_found'], 404);
        }

        return response()->json($payload);
    }

    /** POST /api/gate/tickets/{code}/check-in */
    public function gateCheckIn(string $code): JsonResponse
    {
        $service = app(TicketCheckInService::class);
        $ticket = $service->find($code);
        if ($reject = $this->checkInRejection($service, $ticket)) {
            return $reject;
        }

        return $this->commitCheckIn($service, $ticket, 'gate_station', $code);
    }

    /**
     * Shared gate/admin pre-check: returns the JSON rejection for anything
     * that is not ready for admission, or null when the ticket may proceed.
     */
    private function checkInRejection(TicketCheckInService $service, ?Ticket $ticket): ?JsonResponse
    {
        $inspect = $service->inspectPayload($ticket);

        return match ($inspect['result']) {
            'not_found' => response()->json(['detail' => $inspect['detail'], 'result' => 'not_found'], 404),
            'void' => response()->json(['detail' => 'Ticket has been voided.', 'result' => 'void'] + $inspect, 400),
            'already' => response()->json(['detail' => $inspect['detail'], 'result' => 'already'] + $inspect, 409),
            'unpaid' => response()->json(['detail' => 'Order is not paid.', 'result' => 'unpaid'] + $inspect, 409),
            default => null,
        };
    }

    /**
     * Atomically flips valid → checked_in so two stations racing the same QR
     * can never both admit it; the loser re-inspects and gets the proper
     * already/void rejection instead of a second admit.
     */
    private function commitCheckIn(TicketCheckInService $service, Ticket $ticket, string $actor, string $code): JsonResponse
    {
        $won = Ticket::whereKey($ticket->id)
            ->where('status', 'valid')
            ->whereHas('order', fn ($q) => $q->where('status', 'Completed'))
            ->update(['status' => 'checked_in', 'checked_in_at' => now()]);

        if ($won === 0) {
            $reject = $this->checkInRejection($service, $service->find($code));
            if ($reject) {
                return $reject;
            }

            return response()->json([
                'detail' => 'Ticket state changed. Please scan again.',
                'result' => 'conflict',
            ], 409);
        }

        $this->audit->record($actor, 'ticket checked in', "Code {$ticket->code}");

        return response()->json($service->gateArray($ticket->fresh(['ticketType', 'order.event'])) + [
            'result' => 'admitted',
            'admissible' => false,
            'detail' => 'Admitted.',
        ]);
    }

    // --- Admin ---

    /** GET /api/admin/ticket-types?event_id= */
    public function adminTicketTypes(Request $request): JsonResponse
    {
        $query = TicketType::query()->orderBy('event_id')->orderBy('price');
        if ($request->query('event_id')) {
            $query->where('event_id', (int) $request->query('event_id'));
        }

        return response()->json($query->get()->map->toApiArray()->values());
    }

    /** POST /api/admin/ticket-types */
    public function createTicketType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'in:KES,USD'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'sales_start' => ['nullable', 'date'],
            'sales_end' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'max_per_order' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $data['currency'] ??= 'KES';
        $data['is_active'] ??= true;
        $data['max_per_order'] ??= 10;
        $data['sold_count'] = 0;

        $type = TicketType::create($data);
        $this->audit->record($this->adminEmail($request), 'ticket type created', "Type: {$type->name} (Event #{$type->event_id})");

        return response()->json($type->toApiArray(), 201);
    }

    /** PUT /api/admin/ticket-types/{id} */
    public function updateTicketType(Request $request, int $typeId): JsonResponse
    {
        $type = TicketType::find($typeId);
        if (!$type) {
            return response()->json(['detail' => 'Ticket type not found'], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'in:KES,USD'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'sales_start' => ['nullable', 'date'],
            'sales_end' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'max_per_order' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        if (array_key_exists('quantity', $data) && $data['quantity'] !== null && $data['quantity'] < $type->sold_count) {
            return response()->json([
                'detail' => 'Quantity cannot be less than tickets already reserved or sold.',
            ], 400);
        }

        $type->update($data);
        $this->audit->record($this->adminEmail($request), 'ticket type modified', "Type ID #{$typeId}");

        return response()->json($type->fresh()->toApiArray());
    }

    /** DELETE /api/admin/ticket-types/{id} */
    public function deleteTicketType(Request $request, int $typeId): JsonResponse
    {
        $type = TicketType::find($typeId);
        if (!$type) {
            return response()->json(['detail' => 'Ticket type not found'], 404);
        }
        if ((int) $type->sold_count > 0) {
            return response()->json([
                'detail' => 'Cannot delete a ticket type that already has reservations or sales. Deactivate it instead.',
            ], 409);
        }

        $type->delete();
        $this->audit->record($this->adminEmail($request), 'ticket type deleted', "Type ID #{$typeId} removed");

        return response()->json(null, 204);
    }

    /** GET /api/admin/ticket-stats */
    public function adminStats(): JsonResponse
    {
        $orders = TicketOrder::query();
        $completed = (clone $orders)->where('status', 'Completed');
        $issued = Ticket::count();
        $checkedIn = Ticket::where('status', 'checked_in')->count();
        $revenueKes = 0.0;
        foreach ($completed->get() as $order) {
            $revenueKes += match ($order->currency) {
                'USD' => $order->amount * 130,
                'EUR' => $order->amount * 140,
                'GBP' => $order->amount * 165,
                default => $order->amount,
            };
        }

        return response()->json([
            'ticket_types' => TicketType::count(),
            'orders_total' => TicketOrder::count(),
            'orders_completed' => TicketOrder::where('status', 'Completed')->count(),
            'orders_pending' => TicketOrder::where('status', 'not like', 'Failed%')
                ->where('status', '!=', 'Completed')
                ->count(),
            'tickets_issued' => $issued,
            'tickets_checked_in' => $checkedIn,
            'check_in_rate' => $issued > 0 ? round($checkedIn / $issued, 3) : 0,
            'revenue_kes' => $revenueKes,
        ]);
    }

    /** GET /api/admin/ticket-orders */
    public function adminOrders(Request $request): JsonResponse
    {
        $query = TicketOrder::query()->with(['items.ticketType', 'tickets', 'event'])->orderByDesc('created_at');
        if ($request->query('event_id')) {
            $query->where('event_id', (int) $request->query('event_id'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('buyer_name', 'like', "%{$search}%")
                    ->orWhere('buyer_email', 'like', "%{$search}%");
            });
        }

        return response()->json($query->limit(200)->get()->map->toApiArray()->values());
    }

    /** GET /api/admin/ticket-orders/export */
    public function exportOrdersCsv(Request $request)
    {
        $rows = TicketOrder::with('event')->orderByDesc('created_at')->get();
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['Reference', 'Event', 'Buyer', 'Email', 'Amount', 'Currency', 'Gateway', 'Status', 'Tickets', 'Delivered', 'Created At']);
        foreach ($rows as $order) {
            fputcsv($output, [
                $this->neutralizeCsvFormula((string) $order->reference),
                $this->neutralizeCsvFormula((string) ($order->event?->title ?? '')),
                $this->neutralizeCsvFormula((string) $order->buyer_name),
                $this->neutralizeCsvFormula((string) $order->buyer_email),
                $order->amount,
                $order->currency,
                $this->neutralizeCsvFormula((string) $order->gateway),
                $this->neutralizeCsvFormula((string) $order->status),
                $order->tickets()->count(),
                $order->issued_at ? 'yes' : 'no',
                $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : '',
            ]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $this->audit->record($this->adminEmail($request), 'ticket orders exported', 'CSV downloaded');

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=roi_ticket_orders.csv',
        ]);
    }

    protected function neutralizeCsvFormula(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /** GET /api/admin/tickets/{code} — gate inspect without mutating */
    public function inspectTicket(string $code): JsonResponse
    {
        $service = app(TicketCheckInService::class);
        $payload = $service->inspectPayload($service->find($code));
        $status = match ($payload['result']) {
            'not_found' => 404,
            default => 200,
        };
        if ($status === 404) {
            return response()->json(['detail' => $payload['detail'], 'result' => 'not_found'], 404);
        }

        return response()->json($payload);
    }

    /** POST /api/admin/tickets/{code}/check-in */
    public function checkIn(Request $request, string $code): JsonResponse
    {
        $service = app(TicketCheckInService::class);
        $ticket = $service->find($code);
        if ($reject = $this->checkInRejection($service, $ticket)) {
            return $reject;
        }

        return $this->commitCheckIn($service, $ticket, $this->adminEmail($request), $code);
    }

    /** POST /api/admin/tickets/{code}/undo-check-in */
    public function undoCheckIn(Request $request, string $code): JsonResponse
    {
        $service = app(TicketCheckInService::class);
        $ticket = $service->find($code);
        if (!$ticket) {
            return response()->json(['detail' => 'Ticket not found', 'result' => 'not_found'], 404);
        }

        // Conditional flip: a stale undo can never clobber a re-admit that
        // landed after this request read the ticket.
        $won = Ticket::whereKey($ticket->id)
            ->where('status', 'checked_in')
            ->update(['status' => 'valid', 'checked_in_at' => null]);
        if ($won === 0) {
            return response()->json(['detail' => 'Ticket is not checked in.', 'result' => 'not_checked_in'], 409);
        }

        $this->audit->record($this->adminEmail($request), 'ticket check-in undone', "Code {$ticket->code}");

        return response()->json($service->inspectPayload($ticket->fresh(['ticketType', 'order.event'])));
    }

    protected function adminEmail(Request $request): string
    {
        return (string) ($request->attributes->get('roi_admin_email')
            ?? config('roi.admin_email')
            ?? 'admin@example.com');
    }
}
