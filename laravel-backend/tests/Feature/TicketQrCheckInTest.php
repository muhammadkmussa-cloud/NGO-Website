<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\TicketCheckInService;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketQrCheckInTest extends TestCase
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

    public function test_normalize_extracts_code_from_qr_payload(): void
    {
        $this->assertSame(
            'ROI-ABCD-EFGH',
            TicketCheckInService::normalizeCode('https://roi.ke/#/tickets/lookup/ROI-ABCD-EFGH')
        );
    }

    public function test_inspect_then_admit_then_undo(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Gate Guest',
            'buyer_email' => 'gate@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $code = $checkout->json('tickets.0.code');

        $this->getJson("/api/admin/tickets/{$code}")->assertStatus(401);

        $this->withAdmin()->getJson("/api/admin/tickets/{$code}")
            ->assertOk()
            ->assertJsonPath('result', 'ready')
            ->assertJsonPath('admissible', true)
            ->assertJsonPath('attendee_name', 'Gate Guest');

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/check-in")
            ->assertOk()
            ->assertJsonPath('result', 'admitted')
            ->assertJsonPath('status', 'checked_in')
            ->assertJsonPath('event_title', $checkout->json('event.title'));

        $admittedAt = Ticket::where('code', $code)->firstOrFail()->checked_in_at;
        $dup = $this->withAdmin()->postJson("/api/admin/tickets/{$code}/check-in")
            ->assertStatus(409)
            ->assertJsonPath('result', 'already');
        $expected = $admittedAt->copy()->setTimezone('Africa/Nairobi')->format('Y-m-d H:i');
        $this->assertStringContainsString("already checked in at {$expected} EAT.", $dup->json('detail'));

        $this->withAdmin()->postJson("/api/admin/tickets/{$code}/undo-check-in")
            ->assertOk()
            ->assertJsonPath('result', 'ready')
            ->assertJsonPath('status', 'valid');
    }

    public function test_inspect_unknown_ticket(): void
    {
        $this->withAdmin()->getJson('/api/admin/tickets/ROI-ZZZZ-YYYY')
            ->assertStatus(404)
            ->assertJsonPath('result', 'not_found');
    }
}
