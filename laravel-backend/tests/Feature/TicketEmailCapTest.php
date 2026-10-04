<?php

namespace Tests\Feature;

use App\Models\TicketOrder;
use App\Models\TicketOrderItem;
use App\Models\TicketType;
use Database\Seeders\RoiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TicketEmailCapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoiSeeder::class);
        config(['roi.environment' => 'test']);
        Mail::fake();
    }

    private function freePass(): TicketType
    {
        return TicketType::where('name', 'Free Workshop Pass')->firstOrFail();
    }

    private function buy(string $email, int $qty = 1, ?TicketType $type = null)
    {
        $type ??= $this->freePass();

        return $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Cap Tester',
            'buyer_email' => $email,
            'gateway' => 'Paystack',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => $qty]],
        ]);
    }

    private function makeOrder(string $email, TicketType $type, int $qty, string $status, int $minutesAgo = 0): TicketOrder
    {
        $order = TicketOrder::create([
            'event_id' => $type->event_id,
            'buyer_name' => 'Cap Tester',
            'buyer_email' => $email,
            'gateway' => 'Paystack',
            'reference' => 'ROI-TCK-' . strtoupper(bin2hex(random_bytes(16))),
            'amount' => 0,
            'currency' => 'KES',
            'status' => $status,
        ]);
        TicketOrderItem::create([
            'ticket_order_id' => $order->id,
            'ticket_type_id' => $type->id,
            'quantity' => $qty,
            'unit_price' => 0,
        ]);
        if ($minutesAgo > 0) {
            TicketOrder::where('id', $order->id)->update(['created_at' => now()->subMinutes($minutesAgo)]);
        }

        return $order->refresh();
    }

    public function test_cap_applies_across_orders_and_ignores_email_case(): void
    {
        $this->buy('Cap@Test.ke', 1)->assertStatus(201);
        $this->buy('cap@test.ke', 1)->assertStatus(201);

        $this->buy('CAP@test.ke', 1)
            ->assertStatus(400)
            ->assertJsonPath('detail', 'This email has already reached the limit of 2 "Free Workshop Pass" ticket(s) for this event.');
    }

    public function test_other_emails_are_not_blocked(): void
    {
        $this->buy('first@test.ke', 2)->assertStatus(201);
        $this->buy('second@test.ke', 2)->assertStatus(201);
    }

    public function test_failed_orders_are_excluded_from_the_cap(): void
    {
        $this->makeOrder('buyer@test.ke', $this->freePass(), 1, 'Failed - Card Declined');

        $this->buy('buyer@test.ke', 2)->assertStatus(201);
    }

    public function test_recent_pending_orders_count_toward_the_cap(): void
    {
        $this->makeOrder('pending@test.ke', $this->freePass(), 1, 'Pending Payment');

        $this->buy('pending@test.ke', 2)->assertStatus(400);
    }

    public function test_abandoned_pending_orders_release_the_cap_after_the_window(): void
    {
        $this->makeOrder('abandoned@test.ke', $this->freePass(), 2, 'Pending Payment', 120);

        $this->buy('abandoned@test.ke', 2)->assertStatus(201);
    }

    public function test_completed_orders_count_regardless_of_age(): void
    {
        $this->makeOrder('oldbuyer@test.ke', $this->freePass(), 2, 'Completed', 60 * 24 * 30);

        $this->buy('oldbuyer@test.ke', 1)->assertStatus(400);
    }

    public function test_legacy_mixed_case_orders_still_count_toward_the_cap(): void
    {
        $this->makeOrder('Legacy@Test.KE', $this->freePass(), 2, 'Completed');

        $this->buy('legacy@test.ke', 1)
            ->assertStatus(400)
            ->assertJsonPath('detail', 'This email has already reached the limit of 2 "Free Workshop Pass" ticket(s) for this event.');
    }

    public function test_cap_counts_only_the_matching_type_in_multi_item_orders(): void
    {
        $vip = TicketType::where('name', 'VIP Patron')->firstOrFail();
        $youth = TicketType::where('name', 'Youth Delegate')->firstOrFail();
        $order = TicketOrder::create([
            'event_id' => $vip->event_id,
            'buyer_name' => 'Cap Tester',
            'buyer_email' => 'multi@test.ke',
            'gateway' => 'Paystack',
            'reference' => 'ROI-TCK-' . strtoupper(bin2hex(random_bytes(16))),
            'amount' => 0,
            'currency' => 'KES',
            'status' => 'Completed',
        ]);
        TicketOrderItem::create([
            'ticket_order_id' => $order->id,
            'ticket_type_id' => $vip->id,
            'quantity' => 2,
            'unit_price' => 0,
        ]);
        TicketOrderItem::create([
            'ticket_order_id' => $order->id,
            'ticket_type_id' => $youth->id,
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $this->buy('multi@test.ke', 1, $vip)
            ->assertStatus(400)
            ->assertJsonPath('detail', 'This email has already reached the limit of 2 "VIP Patron" ticket(s) for this event.');

        $this->buy('multi@test.ke', 1, $youth)->assertStatus(201);
    }

    public function test_duplicate_lines_merge_before_the_cap_check(): void
    {
        $this->buy('merged@test.ke', 1)->assertStatus(201);

        $type = $this->freePass();
        $this->postJson('/api/tickets/checkout', [
            'event_id' => $type->event_id,
            'buyer_name' => 'Cap Tester',
            'buyer_email' => 'merged@test.ke',
            'gateway' => 'Paystack',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 1],
                ['ticket_type_id' => $type->id, 'quantity' => 1],
            ],
        ])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'This email has already reached the limit of 2 "Free Workshop Pass" ticket(s) for this event.');
    }

    public function test_cap_is_scoped_to_ticket_type_within_an_event(): void
    {
        $vip = TicketType::where('name', 'VIP Patron')->firstOrFail();
        $youth = TicketType::where('name', 'Youth Delegate')->firstOrFail();

        $this->buy('scoped@test.ke', 2, $vip)->assertStatus(201);
        $this->buy('scoped@test.ke', 1, $youth)->assertStatus(201);

        $this->buy('scoped@test.ke', 1, $vip)
            ->assertStatus(400)
            ->assertJsonPath('detail', 'This email has already reached the limit of 2 "VIP Patron" ticket(s) for this event.');
    }
}
