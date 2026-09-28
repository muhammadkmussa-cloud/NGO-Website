<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\TicketOrder;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
    }

    public function test_paid_checkout_writes_donation_ledger_with_ticket_frequency(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Ledger Buyer',
            'buyer_email' => 'ledger@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $response->json('reference');
        $this->assertDatabaseHas('donations', [
            'reference' => $reference,
            'frequency' => 'ticket',
            'amount' => 500,
            'email' => 'ledger@test.ke',
        ]);
    }

    public function test_payments_verify_fulfills_ticket_order_in_sandbox(): void
    {
        $type = TicketType::where('name', 'Standard Seat')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Verify Path',
            'buyer_email' => 'verify@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        $this->assertSame('Pending Paystack Checkout', $checkout->json('status'));

        $this->getJson("/api/payments/verify/{$reference}")
            ->assertOk()
            ->assertJsonPath('status', 'Completed')
            ->assertJsonPath('frequency', 'ticket');

        $this->assertDatabaseHas('ticket_orders', ['reference' => $reference, 'status' => 'Completed']);
        $this->assertSame(1, TicketOrder::where('reference', $reference)->first()->tickets()->count());
    }

    public function test_stk_retry_updates_checkout_request_ids(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Retry Buyer',
            'buyer_email' => 'retry@test.ke',
            'buyer_phone' => '0712345678',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $firstCheckoutId = $checkout->json('checkout_request_id');
        $reference = $checkout->json('reference');

        $retry = $this->postJson("/api/tickets/orders/{$reference}/stk-retry", [
            'email' => 'retry@test.ke',
            'buyer_phone' => '0722333444',
        ])->assertOk();

        $this->assertNotSame($firstCheckoutId, $retry->json('checkout_request_id'));
        $this->assertSame('STK Prompt Dispatched', $retry->json('status'));
        $this->assertDatabaseHas('donations', [
            'reference' => $reference,
            'checkout_request_id' => $retry->json('checkout_request_id'),
        ]);
    }

    public function test_stk_retry_rejected_after_completion(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Free',
            'buyer_email' => 'free@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->postJson('/api/tickets/orders/' . $checkout->json('reference') . '/stk-retry', ['email' => 'free@test.ke'])
            ->assertStatus(409);
    }

    public function test_paystack_webhook_marks_both_ledger_and_tickets(): void
    {
        $type = TicketType::where('name', 'VIP Patron')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Hook Buyer',
            'buyer_email' => 'hook@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        config(['roi.paystack_secret_key' => 'sk_ticket_pay']);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $signature = hash_hmac('sha512', $raw, 'sk_ticket_pay');

        $this->call(
            'POST',
            '/api/payments/webhook/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            $raw
        )->assertOk();

        $this->assertDatabaseHas('donations', ['reference' => $reference, 'status' => 'Completed', 'frequency' => 'ticket']);
        $this->assertDatabaseHas('ticket_orders', ['reference' => $reference, 'status' => 'Completed']);
        $this->assertNotNull(Donation::where('reference', $reference)->first());
    }
}
