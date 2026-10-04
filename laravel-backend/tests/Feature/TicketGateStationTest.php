<?php

namespace Tests\Feature;

use App\Models\Ticket;
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

    protected function withAdmin(): self
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => config('roi.admin_email'),
            'password' => 'admin123',
        ])->json('access_token');

        return $this->withHeader('Authorization', 'Bearer ' . $token);
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

    public function test_gate_admits_then_re_scan_is_permanently_rejected(): void
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

        $first = Ticket::where('code', $code)->firstOrFail();

        // Second scan is a duplicate, never a fresh admit, and says when it
        // was admitted.
        $rescan = $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'already');
        $expected = $first->checked_in_at->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i');
        $this->assertStringContainsString("already checked in at {$expected} EAT.", $rescan->json('detail'));

        // Still permanently rejected on a third scan.
        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'already');

        $after = Ticket::where('code', $code)->firstOrFail();
        $this->assertSame('checked_in', $after->status);
        $this->assertTrue($first->checked_in_at->eq($after->checked_in_at));
    }

    public function test_gate_undo_route_is_removed(): void
    {
        $code = $this->issueCode();
        $this->postJson("/api/gate/tickets/{$code}/check-in")->assertOk();

        $this->postJson("/api/gate/tickets/{$code}/undo-check-in")->assertNotFound();

        // The ticket stays admitted — only the admin can undo it now.
        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'already');
    }

    public function test_admin_can_undo_a_gate_check_in_and_re_admit(): void
    {
        $code = $this->issueCode();
        $this->postJson("/api/gate/tickets/{$code}/check-in")->assertOk();
        $this->postJson("/api/gate/tickets/{$code}/check-in")->assertStatus(409);

        // Anonymous callers can never undo — that is the whole point of
        // moving undo behind admin auth.
        $this->postJson("/api/admin/tickets/{$code}/undo-check-in")->assertStatus(401);
        $this->assertSame('checked_in', Ticket::where('code', $code)->firstOrFail()->status);

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/undo-check-in")
            ->assertOk()
            ->assertJsonPath('result', 'ready')
            ->assertJsonPath('status', 'valid');

        $this->postJson("/api/gate/tickets/{$code}/check-in")
            ->assertOk()
            ->assertJsonPath('result', 'admitted');
    }

    public function test_admin_undo_of_never_checked_in_ticket_conflicts(): void
    {
        $code = $this->issueCode();

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/undo-check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'not_checked_in');

        $this->assertSame('valid', Ticket::where('code', $code)->firstOrFail()->status);
    }

    public function test_inspect_falls_back_when_timestamp_is_missing(): void
    {
        $code = $this->issueCode();
        $this->postJson("/api/gate/tickets/{$code}/check-in")->assertOk();
        Ticket::where('code', $code)->update(['checked_in_at' => null]);

        $this->getJson("/api/gate/tickets/{$code}")
            ->assertOk()
            ->assertJsonPath('result', 'already')
            ->assertJsonPath('detail', 'Ticket already checked in.');
    }

    public function test_gate_rejects_unknown_codes_without_leaking(): void
    {
        $this->getJson('/api/gate/tickets/ROI-NOPE-0000')->assertNotFound();
        $this->postJson('/api/gate/tickets/ROI-NOPE-0000/check-in')->assertNotFound();
    }
}
