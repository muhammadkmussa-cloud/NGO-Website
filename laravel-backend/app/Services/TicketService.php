<?php

namespace App\Services;

use App\Models\Donation;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketType;
use App\Services\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(
        protected PaystackService $paystack,
        protected MpesaService $mpesa,
        protected TicketDeliveryService $delivery,
    ) {
    }

    /**
     * Reserve inventory, persist the order, and start payment (or issue free tickets).
     *
     * @param  array{event_id:int,buyer_name:string,buyer_email:string,buyer_phone?:?string,gateway:string,items:list<array{ticket_type_id:int,quantity:int}>}  $payload
     * @return array{order: TicketOrder, authorization_url: ?string, customer_message: string}
     */
    public function checkout(array $payload, string $origin): array
    {
        $payload['buyer_email'] = strtolower(trim((string) $payload['buyer_email']));

        $result = DB::transaction(function () use ($payload, $origin) {
            $merged = [];
            foreach ($payload['items'] as $line) {
                $id = (int) $line['ticket_type_id'];
                $merged[$id] = ($merged[$id] ?? 0) + (int) $line['quantity'];
            }
            $items = [];
            foreach ($merged as $ticketTypeId => $quantity) {
                $items[] = ['ticket_type_id' => $ticketTypeId, 'quantity' => $quantity];
            }
            $eventId = (int) $payload['event_id'];
            $event = Event::where('id', $eventId)->lockForUpdate()->first();
            if (!$event || !$event->is_active) {
                abort(response()->json(['detail' => 'Event not found'], 404));
            }
            if (!($event->ticket_sales_enabled ?? true)) {
                abort(response()->json(['detail' => 'Ticket sales are closed for this event.'], 400));
            }

            $wantedTotal = array_sum(array_column($items, 'quantity'));
            $eventRemaining = $event->remainingCapacity();
            if ($eventRemaining !== null && $wantedTotal > $eventRemaining) {
                abort(response()->json([
                    'detail' => "Only {$eventRemaining} seat(s) remaining for this event.",
                ], 409));
            }

            $currency = 'KES';
            $amount = 0.0;
            $resolved = [];

            foreach ($items as $line) {
                $qty = (int) $line['quantity'];
                if ($qty < 1) {
                    abort(response()->json(['detail' => 'Each line quantity must be at least 1.'], 400));
                }

                /** @var TicketType|null $type */
                $type = TicketType::where('id', $line['ticket_type_id'])->lockForUpdate()->first();
                if (!$type || (int) $type->event_id !== $eventId) {
                    abort(response()->json(['detail' => 'Ticket type not found for this event.'], 404));
                }
                $type->setRelation('event', $event);
                $state = $type->saleState();
                if ($state !== 'on_sale') {
                    abort(response()->json([
                        'detail' => "Ticket type \"{$type->name}\" is not on sale ({$state}).",
                    ], 400));
                }
                $max = $type->maxPerOrder();
                if ($qty > $max) {
                    abort(response()->json([
                        'detail' => "You can buy at most {$max} \"{$type->name}\" ticket(s) in one order.",
                    ], 400));
                }
                $remaining = $type->remaining();
                if ($remaining !== null && $qty > $remaining) {
                    abort(response()->json([
                        'detail' => "Only {$remaining} \"{$type->name}\" ticket(s) remaining.",
                    ], 409));
                }

                $lineTotal = (float) $type->price * $qty;
                $amount += $lineTotal;
                $currency = $type->currency ?: 'KES';
                $resolved[] = ['type' => $type, 'quantity' => $qty, 'unit_price' => (float) $type->price];
            }

            foreach ($resolved as $line) {
                $cappedType = $line['type'];
                $cap = $cappedType->maxPerOrder();
                $alreadyPurchased = $this->cumulativePurchasedForEmail(
                    $eventId,
                    (int) $cappedType->id,
                    (string) $payload['buyer_email']
                );
                if ($alreadyPurchased + $line['quantity'] > $cap) {
                    abort(response()->json([
                        'detail' => "This email has already reached the limit of {$cap} \"{$cappedType->name}\" ticket(s) for this event.",
                    ], 400));
                }
            }

            if ($amount > 0 && ! config('roi.payments_enabled')) {
                abort(response()->json(['detail' => 'Payments are temporarily unavailable.'], 503));
            }

            foreach ($resolved as $line) {
                $line['type']->sold_count = (int) $line['type']->sold_count + $line['quantity'];
                $line['type']->save();
            }

            $gateway = $payload['gateway'];
            $isMpesa = in_array(strtolower($gateway), ['m-pesa', 'mpesa', 'm-pesa push'], true);
            if ($isMpesa && strtoupper($currency) !== 'KES') {
                abort(response()->json([
                    'detail' => 'M-Pesa ticket checkout is only available in KES.',
                ], 400));
            }

            $reference = PaystackService::makeReference('TCK');
            $authUrl = null;
            $customerMessage = 'Order reserved.';
            $status = 'Pending Payment';
            $checkoutId = null;
            $merchantId = null;

            if ($amount <= 0) {
                $status = 'Completed';
                $customerMessage = 'Complimentary tickets issued.';
            } elseif (strtolower($gateway) === 'paystack') {
                if (\App\Support\DevBypass::enabled()) {
                    $authUrl = "https://checkout.paystack.com/verified-sandbox-{$reference}";
                    $customerMessage = 'Sandbox verified checkout modal generated.';
                    $status = 'Pending Paystack Checkout';
                } else {
                    $callbackUrl = rtrim($origin, '/') . "/#/tickets/order/{$reference}";
                    try {
                        $result = $this->paystack->initialize(
                            email: (string) $payload['buyer_email'],
                            amount: $amount,
                            currency: $currency,
                            reference: $reference,
                            callbackUrl: $callbackUrl,
                        );
                        $authUrl = $result['authorization_url'];
                        $customerMessage = $result['customer_message'];
                        $status = 'Pending Paystack Checkout';
                    } catch (PaymentGatewayException $e) {
                        abort(response()->json(['detail' => $e->getMessage()], $e->statusCode));
                    }
                }
            } elseif (in_array(strtolower($gateway), ['m-pesa', 'mpesa', 'm-pesa push'], true)) {
                try {
                    if ($amount < 1) {
                        abort(response()->json(['detail' => 'M-Pesa ticket purchases must be at least KES 1.'], 400));
                    }
                    $formattedPhone = $this->mpesa->formatPhone($payload['buyer_phone'] ?? null);
                    $result = $this->mpesa->stkPush(
                        $amount,
                        $formattedPhone,
                        $origin,
                        $reference,
                        'ROI ticket order',
                    );
                    $checkoutId = $result['checkout_request_id'];
                    $merchantId = $result['merchant_request_id'];
                    $customerMessage = $result['customer_message'];
                    $status = 'STK Prompt Dispatched';
                } catch (PaymentGatewayException $e) {
                    abort(response()->json(['detail' => $e->getMessage()], $e->statusCode));
                }
            } else {
                abort(response()->json([
                    'detail' => 'Unsupported payment gateway. Only Paystack and M-Pesa are accepted.',
                ], 400));
            }

            $order = TicketOrder::create([
                'event_id' => $eventId,
                'buyer_name' => $payload['buyer_name'],
                'buyer_email' => $payload['buyer_email'],
                'buyer_phone' => $payload['buyer_phone'] ?? null,
                'gateway' => $gateway,
                'reference' => $reference,
                'checkout_request_id' => $checkoutId,
                'merchant_request_id' => $merchantId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $status,
                'paid_at' => $status === 'Completed' ? now() : null,
            ]);

            foreach ($resolved as $line) {
                TicketOrderItem::create([
                    'ticket_order_id' => $order->id,
                    'ticket_type_id' => $line['type']->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                ]);
            }

            $this->syncPaymentLedger($order, $status);

            if ($status === 'Completed') {
                $this->issueTickets($order);
            }

            return [
                'order' => $order->fresh(['items.ticketType', 'tickets', 'event']),
                'authorization_url' => $authUrl,
                'customer_message' => $customerMessage,
            ];
        });

        if ($result['order']->status === 'Completed') {
            $result['order'] = $this->delivery->deliver($result['order']);
        }

        return $result;
    }

    private function cumulativePurchasedForEmail(int $eventId, int $ticketTypeId, string $buyerEmail): int
    {
        return (int) TicketOrderItem::query()
            ->join('ticket_orders', 'ticket_orders.id', '=', 'ticket_order_items.ticket_order_id')
            ->where('ticket_orders.event_id', $eventId)
            ->where('ticket_order_items.ticket_type_id', $ticketTypeId)
            ->whereRaw('lower(ticket_orders.buyer_email) = ?', [$buyerEmail])
            ->where('ticket_orders.status', 'not like', 'Failed%')
            ->where(function ($query) {
                $query->where('ticket_orders.status', 'Completed')
                    ->orWhere('ticket_orders.created_at', '>=', now()->subMinutes(60));
            })
            ->sum('ticket_order_items.quantity');
    }

    public function retryStk(TicketOrder $order, ?string $phone, string $origin): TicketOrder
    {
        if (! config('roi.payments_enabled')) {
            abort(response()->json(['detail' => 'Payments are temporarily unavailable.'], 503));
        }

        if ($order->status === 'Completed') {
            abort(response()->json(['detail' => 'This ticket order is already paid.'], 409));
        }
        if (str_starts_with((string) $order->status, 'Failed')) {
            abort(response()->json([
                'detail' => 'This order was released after a failed payment. Start a new checkout.',
            ], 409));
        }
        if ((float) $order->amount < 1) {
            abort(response()->json(['detail' => 'Complimentary orders do not require M-Pesa.'], 400));
        }

        $formattedPhone = $this->mpesa->formatPhone($phone ?: $order->buyer_phone);
        $result = $this->mpesa->stkPush(
            (float) $order->amount,
            $formattedPhone,
            $origin,
            $order->reference,
            'ROI ticket order',
        );

        $order->gateway = 'M-Pesa';
        $order->buyer_phone = $phone ?: $order->buyer_phone;
        $order->checkout_request_id = $result['checkout_request_id'];
        $order->merchant_request_id = $result['merchant_request_id'];
        $order->status = 'STK Prompt Dispatched';
        $order->save();
        $this->syncPaymentLedger($order, $order->status, $result['checkout_request_id'], $result['merchant_request_id']);

        return $order->fresh(['items.ticketType', 'tickets', 'event']);
    }

    public function fulfill(TicketOrder $order): TicketOrder
    {
        if ($order->status === 'Completed' && $order->tickets()->exists()) {
            return $this->delivery->deliver($order->fresh(['items.ticketType', 'tickets', 'event']));
        }

        return DB::transaction(function () use ($order) {
            $locked = TicketOrder::where('id', $order->id)->lockForUpdate()->first();
            if (!$locked) {
                return $order;
            }
            // A concurrent webhook may have already marked this order failed;
            // never issue tickets for a failed payment (prevents double-mint too).
            if (str_starts_with((string) $locked->status, 'Failed')) {
                return $locked;
            }
            // Already fulfilled by a concurrent caller — deliver exactly once.
            if ($locked->status === 'Completed') {
                return $locked->fresh(['items.ticketType', 'tickets', 'event']);
            }
            $locked->status = 'Completed';
            $locked->paid_at = $locked->paid_at ?? now();
            $locked->save();
            $this->issueTickets($locked);
            $this->syncPaymentLedger($locked, 'Completed');

            return $this->delivery->deliver($locked->fresh(['items.ticketType', 'tickets', 'event']));
        });
    }

    public function markFailed(TicketOrder $order, string $status): TicketOrder
    {
        // Idempotent: never re-process a terminal order (Completed OR already Failed*).
        if ($order->status === 'Completed' || str_starts_with((string) $order->status, 'Failed')) {
            return $order;
        }

        return DB::transaction(function () use ($order, $status) {
            $locked = TicketOrder::where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === 'Completed' || str_starts_with((string) $locked->status, 'Failed')) {
                return $locked ?: $order;
            }

            foreach ($locked->items as $item) {
                $type = TicketType::where('id', $item->ticket_type_id)->lockForUpdate()->first();
                if ($type) {
                    $type->sold_count = max(0, (int) $type->sold_count - (int) $item->quantity);
                    $type->save();
                }
            }

            $locked->status = $status;
            $locked->save();
            $this->syncPaymentLedger($locked, $status);

            return $locked->fresh(['items.ticketType', 'tickets', 'event']);
        });
    }

    /**
     * Persist an in-flight (non-terminal) payment status without clobbering a
     * terminal status a concurrent webhook may have already written. Guarded by
     * a row lock so a race between verifyOrder polling and a webhook cannot
     * revert a Completed/Failed order back to a pending state.
     */
    public function markPending(TicketOrder $order, string $status): void
    {
        if (str_starts_with($status, 'Failed') || $status === 'Completed') {
            return;
        }

        DB::transaction(function () use ($order, $status) {
            $locked = TicketOrder::where('id', $order->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === 'Completed' || str_starts_with((string) $locked->status, 'Failed')) {
                return;
            }
            $locked->status = $status;
            $locked->save();
        });
    }

    /**
     * Mirror ticket payments onto the donations ledger so Paystack/M-Pesa
     * verify + webhook pipelines stay a single source of truth.
     */
    public function syncPaymentLedger(
        TicketOrder $order,
        string $status,
        ?string $checkoutId = null,
        ?string $merchantId = null,
    ): void {
        $donation = Donation::where('reference', $order->reference)->first();
        $payload = [
            'donor_name' => $order->buyer_name,
            'email' => $order->buyer_email,
            'amount' => (float) $order->amount,
            'currency' => $order->currency,
            'gateway' => $order->gateway,
            'frequency' => 'ticket',
            'reference' => $order->reference,
            'checkout_request_id' => $checkoutId ?? $order->checkout_request_id,
            'merchant_request_id' => $merchantId ?? $order->merchant_request_id,
            'status' => $status,
        ];

        if ($donation) {
            $donation->fill($payload);
            $donation->save();
        } else {
            Donation::create($payload);
        }
    }

    public function findByPaymentHint(?string $checkoutReqId, ?string $merchantReqId, ?string $reference): ?TicketOrder
    {
        if ($checkoutReqId) {
            $found = TicketOrder::where('checkout_request_id', $checkoutReqId)->first();
            if ($found) {
                return $found;
            }
        }
        if ($merchantReqId) {
            $found = TicketOrder::where('merchant_request_id', $merchantReqId)->first();
            if ($found) {
                return $found;
            }
        }
        if ($reference) {
            return TicketOrder::where('reference', $reference)->first();
        }

        return null;
    }

    protected function issueTickets(TicketOrder $order): void
    {
        if ($order->tickets()->exists()) {
            return;
        }

        $order->loadMissing('items');
        foreach ($order->items as $item) {
            for ($i = 0; $i < (int) $item->quantity; $i++) {
                Ticket::create([
                    'ticket_order_id' => $order->id,
                    'ticket_type_id' => $item->ticket_type_id,
                    'code' => $this->uniqueCode(),
                    'attendee_name' => $order->buyer_name,
                    'attendee_email' => $order->buyer_email,
                    'status' => 'valid',
                    'issued_at' => now(),
                ]);
            }
        }
    }

    protected function uniqueCode(): string
    {
        do {
            $code = 'ROI-' . strtoupper(Str::random(4)) . '-' . strtoupper(Str::random(4));
        } while (Ticket::where('code', $code)->exists());

        return $code;
    }
}
