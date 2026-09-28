<?php

namespace Tests\Feature;

use App\Models\TicketType;
use App\Services\TicketPortalService;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketRecoveryPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
        Mail::fake();
    }

    public function test_recover_is_enumeration_safe_and_opens_portal_without_email(): void
    {
        $this->postJson('/api/tickets/recover', ['email' => 'nobody@test.ke'])
            ->assertOk()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('portal_token', fn ($t) => is_string($t) && $t !== '');
        Mail::assertNothingSent();

        $type = TicketType::where('price', 0)->first();
        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Portal Buyer',
            'buyer_email' => 'portal@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->postJson('/api/tickets/recover', ['email' => 'portal@test.ke'])
            ->assertOk()
            ->assertJsonPath('portal_token', fn ($t) => is_string($t) && $t !== '');
        Mail::assertNothingSent();
    }

    public function test_portal_token_lists_orders_and_rejects_tampering(): void
    {
        $type = TicketType::where('price', 0)->first();
        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Token Buyer',
            'buyer_email' => 'token@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $token = app(TicketPortalService::class)->mintToken('token@test.ke');
        $this->getJson('/api/tickets/portal/' . $token)
            ->assertOk()
            ->assertJsonPath('email', 'token@test.ke')
            ->assertJsonCount(1, 'orders');

        $this->getJson('/api/tickets/portal/not-a-token')->assertStatus(401);
    }

    public function test_lookup_order_requires_matching_email(): void
    {
        $type = TicketType::where('price', 0)->first();
        $checkout = $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Lookup',
            'buyer_email' => 'lookup@test.ke',
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $ref = $checkout->json('reference');
        $this->postJson('/api/tickets/lookup-order', [
            'email' => 'wrong@test.ke',
            'reference' => $ref,
        ])->assertStatus(404);

        $this->postJson('/api/tickets/lookup-order', [
            'email' => 'lookup@test.ke',
            'reference' => $ref,
        ])->assertOk()->assertJsonPath('order.reference', $ref);
    }
}
