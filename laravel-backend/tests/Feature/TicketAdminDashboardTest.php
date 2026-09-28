<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketAdminDashboardTest extends TestCase
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
        ])->assertOk()->json('access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_ticket_stats_and_search_require_admin(): void
    {
        $this->getJson('/api/admin/ticket-stats')->assertStatus(401);
        $this->getJson('/api/admin/ticket-orders')->assertStatus(401);
    }

    public function test_ticket_stats_reflect_completed_orders_and_checkins(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Desk Buyer',
            'buyer_email' => 'desk@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $code = $checkout->json('tickets.0.code');
        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/check-in")->assertOk();

        $this->withAdmin()->getJson('/api/admin/ticket-stats')
            ->assertOk()
            ->assertJsonPath('tickets_issued', 2)
            ->assertJsonPath('tickets_checked_in', 1)
            ->assertJsonPath('orders_completed', 1);
    }

    public function test_admin_can_search_orders_and_export_csv(): void
    {
        $type = TicketType::where('price', 0)->first();
        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Searchable Amina',
            'buyer_email' => 'amina.search@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->withAdmin()->getJson('/api/admin/ticket-orders?search=amina.search')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.buyer_email', 'amina.search@test.ke');

        $csv = $this->withAdmin()->get('/api/admin/ticket-orders/export');
        $csv->assertOk();
        $this->assertStringContainsString('Searchable Amina', $csv->getContent());
        $this->assertStringContainsString('Reference', $csv->getContent());
    }
}
