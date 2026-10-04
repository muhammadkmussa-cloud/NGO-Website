<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketCapacityControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
        Mail::fake();
    }

    protected function withAdmin(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->json('access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_closed_event_sales_rejected(): void
    {
        $type = TicketType::where('price', 0)->first();
        Event::where('id', $type->event_id)->update(['ticket_sales_enabled' => false]);

        $this->getJson("/api/public/events/{$type->event_id}/tickets")
            ->assertOk()
            ->assertJsonPath('ticket_types.0.sale_state', 'event_closed')
            ->assertJsonPath('ticket_types.0.on_sale', false);

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Closed',
            'buyer_email' => 'closed@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(400)->assertJsonPath('detail', 'Ticket sales are closed for this event.');
    }

    public function test_event_capacity_and_max_per_order(): void
    {
        $type = TicketType::where('name', 'Youth Delegate')->first();
        $type->max_per_order = 2;
        $type->save();
        Event::where('id', $type->event_id)->update(['capacity' => 3]);

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Too Many',
            'buyer_email' => 'too@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 3]],
        ])->assertStatus(400);

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Ok Qty',
            'buyer_email' => 'ok@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Over cap',
            'buyer_email' => 'cap@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertStatus(409);
    }

    public function test_sales_window_not_started(): void
    {
        $type = TicketType::where('name', 'VIP Patron')->first();
        $type->sales_start = now()->addDay();
        $type->save();

        $this->getJson("/api/public/events/{$type->event_id}/tickets")
            ->assertOk();

        $catalog = $this->getJson("/api/public/events/{$type->event_id}/tickets")->json('ticket_types');
        $vip = collect($catalog)->firstWhere('name', 'VIP Patron');
        $this->assertSame('not_started', $vip['sale_state']);

        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Early',
            'buyer_email' => 'early@test.example',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(400);
    }

    public function test_admin_can_set_capacity_and_max_per_order(): void
    {
        $event = Event::first();
        $this->withAdmin()->putJson("/api/admin/events/{$event->id}", [
            'capacity' => 50,
            'ticket_sales_enabled' => true,
        ])->assertOk()->assertJsonPath('capacity', 50);

        $type = TicketType::first();
        $this->withAdmin()->putJson("/api/admin/ticket-types/{$type->id}", [
            'max_per_order' => 4,
        ])->assertOk()->assertJsonPath('max_per_order', 4);
    }
}
