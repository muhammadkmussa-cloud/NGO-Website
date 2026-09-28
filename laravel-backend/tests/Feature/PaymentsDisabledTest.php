<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\TicketOrder;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentsDisabledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test', 'roi.payments_enabled' => false]);
    }

    public function test_payment_capabilities_withhold_paybill_details_when_disabled(): void
    {
        $this->getJson('/api/payments/paybills')
            ->assertOk()
            ->assertExactJson([
                'enabled' => false,
                'message' => 'Our secure contribution channels are being prepared. Please contact our team in the meantime.',
            ]);
    }

    public function test_donation_checkout_returns_503_without_creating_a_record(): void
    {
        $donationsBefore = Donation::count();

        $this->postJson('/api/payments/checkout', [
            'amount' => 500,
            'currency' => 'KES',
            'gateway' => 'Paystack',
        ])->assertStatus(503)
            ->assertJsonPath('detail', 'Payments are temporarily unavailable.');

        $this->assertSame($donationsBefore, Donation::count());
    }

    public function test_paid_ticket_checkout_returns_503_without_reserving_inventory(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->firstOrFail();
        $soldBefore = (int) $type->sold_count;
        $donationsBefore = Donation::count();

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Disabled Payments Buyer',
            'buyer_email' => 'disabled-payments@example.test',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(503)
            ->assertJsonPath('detail', 'Payments are temporarily unavailable.');

        $this->assertSame($soldBefore, (int) $type->fresh()->sold_count);
        $this->assertSame(0, TicketOrder::count());
        $this->assertSame($donationsBefore, Donation::count());
    }

    public function test_complimentary_ticket_checkout_still_works(): void
    {
        $type = TicketType::where('price', 0)->firstOrFail();

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Complimentary Guest',
            'buyer_email' => 'complimentary@example.test',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('status', 'Completed')
            ->assertJsonPath('amount', 0);
    }

    public function test_stk_retry_returns_503_without_changing_the_order(): void
    {
        config(['roi.payments_enabled' => true]);
        $type = TicketType::where('name', 'Youth Delegate')->firstOrFail();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Retry Guard Buyer',
            'buyer_email' => 'retry-guard@example.test',
            'buyer_phone' => '0712345678',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertCreated();

        $order = TicketOrder::where('reference', $checkout->json('reference'))->firstOrFail();
        $originalCheckoutId = $order->checkout_request_id;
        config(['roi.payments_enabled' => false]);

        $this->postJson("/api/tickets/orders/{$order->reference}/stk-retry", [
            'email' => 'retry-guard@example.test',
            'buyer_phone' => '0722333444',
        ])->assertStatus(503)
            ->assertJsonPath('detail', 'Payments are temporarily unavailable.');

        $this->assertSame($originalCheckoutId, $order->fresh()->checkout_request_id);
    }

    public function test_verification_returns_503_without_fulfilling_a_pending_order(): void
    {
        $order = $this->createPendingPaystackOrder();
        config(['roi.payments_enabled' => false]);

        $this->getJson("/api/payments/verify/{$order->reference}")
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Payments are temporarily unavailable.');

        $this->assertSame('Pending Paystack Checkout', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_ticket_verification_returns_503_without_fulfilling_a_pending_order(): void
    {
        $order = $this->createPendingPaystackOrder();
        config(['roi.payments_enabled' => false]);

        $this->getJson("/api/tickets/orders/{$order->reference}/verify?email=pending-paystack@example.test")
            ->assertStatus(503)
            ->assertJsonPath('detail', 'Payments are temporarily unavailable.');

        $this->assertSame('Pending Paystack Checkout', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_paystack_webhook_returns_503_without_mutating_records(): void
    {
        $order = $this->createPendingPaystackOrder();
        config([
            'roi.payments_enabled' => false,
            'roi.paystack_secret_key' => 'disabled-mode-test-secret',
        ]);
        $payload = json_encode([
            'event' => 'charge.success',
            'data' => ['reference' => $order->reference],
        ]);

        $this->call('POST', '/api/payments/webhook/paystack', [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $payload, 'disabled-mode-test-secret'),
        ], $payload)->assertStatus(503);

        $this->assertSame('Pending Paystack Checkout', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    public function test_mpesa_webhook_returns_503_without_mutating_records(): void
    {
        config(['roi.payments_enabled' => true]);
        $type = TicketType::where('name', 'Youth Delegate')->firstOrFail();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Disabled Callback Buyer',
            'buyer_email' => 'disabled-callback@example.test',
            'buyer_phone' => '0712345678',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertCreated();
        $order = TicketOrder::where('reference', $checkout->json('reference'))->firstOrFail();
        config(['roi.payments_enabled' => false]);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CheckoutRequestID' => $order->checkout_request_id,
            ]],
        ])->assertStatus(503);

        $this->assertSame('STK Prompt Dispatched', $order->fresh()->status);
        $this->assertSame(0, $order->tickets()->count());
    }

    private function createPendingPaystackOrder(): TicketOrder
    {
        config(['roi.payments_enabled' => true]);
        $type = TicketType::where('name', 'Youth Delegate')->firstOrFail();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Pending Paystack Buyer',
            'buyer_email' => 'pending-paystack@example.test',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertCreated();

        return TicketOrder::where('reference', $checkout->json('reference'))->firstOrFail();
    }
}
