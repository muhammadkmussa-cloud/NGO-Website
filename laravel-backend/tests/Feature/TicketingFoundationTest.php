<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Ticket;
use App\Models\TicketOrder;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
    }

    protected function login(): string
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ]);
        $response->assertOk();

        return $response->json('access_token');
    }

    protected function withAdmin(): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $this->login());
    }

    public function test_public_event_tickets_lists_seeded_types(): void
    {
        $event = Event::where('title', 'like', 'Vijana Na Maadili%')->first();
        $this->assertNotNull($event);

        $this->getJson("/api/public/events/{$event->id}/tickets")
            ->assertOk()
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonCount(3, 'ticket_types')
            ->assertJsonPath('ticket_types.0.name', 'Youth Delegate')
            ->assertJsonPath('ticket_types.0.remaining', 400)
            ->assertJsonPath('ticket_types.0.on_sale', true);
    }

    public function test_public_event_tickets_404_for_unknown_event(): void
    {
        $this->getJson('/api/public/events/99999/tickets')
            ->assertStatus(404)
            ->assertJsonPath('detail', 'Event not found');
    }

    public function test_admin_ticket_type_crud(): void
    {
        $event = Event::first();
        $created = $this->withAdmin()->postJson('/api/admin/ticket-types', [
            'event_id' => $event->id,
            'name' => 'Press Pass',
            'description' => 'Media desk',
            'price' => 0,
            'quantity' => 10,
        ])->assertStatus(201)->assertJsonPath('name', 'Press Pass');

        $id = $created->json('id');

        $this->withAdmin()->putJson("/api/admin/ticket-types/{$id}", ['price' => 250])
            ->assertOk()->assertJsonPath('price', 250);

        $this->withAdmin()->getJson("/api/admin/ticket-types?event_id={$event->id}")
            ->assertOk();

        $this->withAdmin()->deleteJson("/api/admin/ticket-types/{$id}")->assertStatus(204);
        $this->assertDatabaseMissing('ticket_types', ['id' => $id]);
    }

    public function test_cannot_delete_ticket_type_with_sales(): void
    {
        $type = TicketType::where('price', '>', 0)->first();
        $type->sold_count = 1;
        $type->save();

        $this->withAdmin()->deleteJson("/api/admin/ticket-types/{$type->id}")
            ->assertStatus(409);
    }

    public function test_paid_checkout_reserves_inventory_and_sandbox_verify_issues_tickets(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $this->assertNotNull($type);

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Amina Ali',
            'buyer_email' => 'amina@test.ke',
            'buyer_phone' => '0712345678',
            'gateway' => 'Paystack',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 2],
            ],
        ])->assertStatus(201);

        $reference = $response->json('reference');
        $this->assertMatchesRegularExpression('/^ROI-TCK-[0-9A-F]{32}$/', $reference);
        $this->assertEqualsWithDelta(1000.0, $response->json('amount'), 0.001);
        $this->assertSame(0, count($response->json('tickets')));

        $this->assertDatabaseHas('ticket_types', ['id' => $type->id, 'sold_count' => 2]);

        $verified = $this->getJson("/api/tickets/orders/{$reference}/verify?email=amina@test.ke")->assertOk();
        $this->assertSame('Completed', $verified->json('status'));
        $this->assertCount(2, $verified->json('tickets'));
        $this->assertStringStartsWith('ROI-', $verified->json('tickets.0.code'));

        $this->getJson("/api/tickets/lookup/" . $verified->json('tickets.0.code'))
            ->assertOk()
            ->assertJsonPath('status', 'valid');
    }

    public function test_free_checkout_issues_tickets_immediately(): void
    {
        $type = TicketType::where('price', 0)->first();
        $this->assertNotNull($type);

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Juma Guest',
            'buyer_email' => 'juma@test.ke',
            'gateway' => 'Paystack',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 1],
            ],
        ])->assertStatus(201)
            ->assertJsonPath('status', 'Completed')
            ->assertJsonPath('amount', 0);

        $this->assertCount(1, $response->json('tickets'));
        $this->assertDatabaseHas('ticket_types', ['id' => $type->id, 'sold_count' => 1]);
    }

    public function test_checkout_rejects_oversell(): void
    {
        $type = TicketType::where('name', 'VIP Patron')->first();
        $type->sold_count = 19;
        $type->save();

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@test.ke',
            'gateway' => 'Paystack',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 2],
            ],
        ])->assertStatus(409)->assertJsonPath('detail', 'Only 1 "VIP Patron" ticket(s) remaining.');
    }

    public function test_checkout_rejects_wrong_event_ticket_type(): void
    {
        $flagship = Event::where('title', 'like', 'Vijana Na Maadili%')->first();
        $bootcampType = TicketType::where('name', 'Free Workshop Pass')->first();

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $flagship->id,
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@test.ke',
            'gateway' => 'Paystack',
            'items' => [
                ['ticket_type_id' => $bootcampType->id, 'quantity' => 1],
            ],
        ])->assertStatus(404);
    }

    public function test_checkout_rejects_unsupported_gateway(): void
    {
        $type = TicketType::where('price', '>', 0)->first();
        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Buyer',
            'buyer_email' => 'buyer@test.ke',
            'gateway' => 'Bitcoin',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_mpesa_ticket_checkout_and_webhook_fulfills(): void
    {
        $type = TicketType::where('name', 'Standard Seat')->first();

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'STK Buyer',
            'buyer_email' => 'stk@test.ke',
            'buyer_phone' => '0712345678',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame('STK Prompt Dispatched', $response->json('status'));
        $checkoutId = $response->json('checkout_request_id');
        $this->assertStringStartsWith('ws_CO_SIM_', (string) $checkoutId);

        config(['roi.mpesa_webhook_token' => '']);

        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 0,
                'CheckoutRequestID' => $checkoutId,
            ]],
        ])->assertOk();

        $order = TicketOrder::where('reference', $response->json('reference'))->first();
        $this->assertSame('Completed', $order->status);
        $this->assertSame(1, $order->tickets()->count());
    }

    public function test_mpesa_webhook_failure_releases_inventory(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $before = $type->sold_count;

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Fail Buyer',
            'buyer_email' => 'fail@test.ke',
            'buyer_phone' => '0712345678',
            'gateway' => 'M-Pesa',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 3]],
        ])->assertStatus(201);

        $this->assertDatabaseHas('ticket_types', ['id' => $type->id, 'sold_count' => $before + 3]);

        config(['roi.mpesa_webhook_token' => '']);
        $this->postJson('/api/payments/webhook/mpesa', [
            'Body' => ['stkCallback' => [
                'ResultCode' => 1032,
                'CheckoutRequestID' => $response->json('checkout_request_id'),
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('ticket_types', ['id' => $type->id, 'sold_count' => $before]);
        $this->assertDatabaseHas('ticket_orders', [
            'reference' => $response->json('reference'),
            'status' => 'Failed (Daraja ResultCode 1032)',
        ]);
    }

    public function test_paystack_webhook_fulfills_ticket_order(): void
    {
        $type = TicketType::where('name', 'Standard Seat')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Paystack Buyer',
            'buyer_email' => 'ps@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $reference = $checkout->json('reference');
        config(['roi.paystack_secret_key' => 'sk_test_tickets']);
        $raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $signature = hash_hmac('sha512', $raw, 'sk_test_tickets');

        $this->call(
            'POST',
            '/api/payments/webhook/paystack',
            [],
            [],
            [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature],
            $raw
        )->assertOk();

        $this->assertDatabaseHas('ticket_orders', ['reference' => $reference, 'status' => 'Completed']);
        $this->assertSame(1, Ticket::whereHas('order', fn ($q) => $q->where('reference', $reference))->count());
    }

    public function test_admin_orders_and_check_in(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Check In',
            'buyer_email' => 'ci@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $code = $checkout->json('tickets.0.code');

        $this->getJson('/api/admin/ticket-orders')->assertStatus(401);

        $this->withAdmin()->getJson('/api/admin/ticket-orders')
            ->assertOk()
            ->assertJsonPath('0.buyer_email', 'ci@test.ke');

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/check-in")
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/check-in")
            ->assertStatus(409);
    }

    public function test_show_order_404(): void
    {
        $this->getJson('/api/tickets/orders/MISSING')->assertStatus(404)
            ->assertJsonPath('detail', 'Ticket order not found');
    }
}
