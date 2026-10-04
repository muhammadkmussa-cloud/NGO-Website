<?php

namespace Tests\Feature;

use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
        Mail::fake();
    }

    public function test_free_checkout_issues_tickets(): void
    {
        $type = TicketType::where('price', 0)->first();

        $response = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Amina Guest',
            'buyer_email' => 'amina.guest@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // Tickets are issued (downloadable) but never emailed.
        $this->assertTrue($response->json('delivered'));
        $this->assertNotEmpty($response->json('tickets.0.code'));
        $this->assertStringNotContainsString('qr_url', $response->getContent());
        $this->assertStringNotContainsString('create-qr-code', $response->getContent());

        Mail::assertNothingSent();
    }

    public function test_paid_verify_issues_once(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Paid Guest',
            'buyer_email' => 'paid.guest@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        Mail::assertNothingSent();

        $reference = $checkout->json('reference');
        $this->getJson("/api/tickets/orders/{$reference}/verify?email=paid.guest@test.example")
            ->assertOk()
            ->assertJsonPath('delivered', true);

        // Re-verifying must not throw and remains issued (idempotent).
        $this->getJson("/api/tickets/orders/{$reference}/verify?email=paid.guest@test.example")
            ->assertOk()
            ->assertJsonPath('delivered', true);

        Mail::assertNothingSent();
    }

    public function test_resend_route_removed(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Resend Me',
            'buyer_email' => 'resend@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->postJson('/api/tickets/orders/' . $checkout->json('reference') . '/resend', ['email' => 'resend@test.example'])
            ->assertNotFound();
    }

    public function test_printable_pass_route_removed(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Pass Holder',
            'buyer_email' => 'pass@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->get('/api/tickets/orders/' . $checkout->json('reference') . '/pass?email=pass@test.example')
            ->assertNotFound();
    }
}
