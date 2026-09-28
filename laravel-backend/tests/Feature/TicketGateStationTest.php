<?php

namespace Tests\Feature;

use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketGateStationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
    }

    private function issueCode(): string
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Gate Buyer',
            'buyer_email' => 'gate@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        return $checkout->json('tickets.0.code');
    }

    public function test_gate_is_public_and_admits_then_undo(): void
    {
        $code = $this->issueCode();

        // No admin Authorization header — the gate station is intentionally open.
        $this->getJson("/api/gate/tickets/{$code}")
            ->assertOk()
            ->assertJsonPath('code', $code)
            ->assertJsonPath('result', 'ready');

        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertOk()
            ->assertJsonPath('result', 'admitted');

        // Second scan is a duplicate, not a fresh admit.
        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'already');

        $this->postJson("/api/gate/tickets/{$code}/undo-check-in")
            ->assertOk()
            ->assertJsonPath('result', 'ready');

        // After undo it can be admitted again.
        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertOk()
            ->assertJsonPath('result', 'admitted');
    }

    public function test_gate_rejects_unknown_codes_without_leaking(): void
    {
        $this->getJson('/api/gate/tickets/ROI-NOPE-0000')->assertNotFound();
        $this->postJson('/api/gate/tickets/ROI-NOPE-0000/check-in')->assertNotFound();
    }
}
