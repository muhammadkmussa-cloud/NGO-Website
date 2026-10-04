<?php

namespace Tests\Feature;

use App\Models\Pledge;
use App\Models\PledgePayment;
use App\Support\PledgeConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Step 1 — pledge schema guarantees (spec §1 unique-per-period obligation,
 * §11 configurable reminders, §19 Nairobi billing timezone).
 */
class PledgeSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makePledge(array $overrides = []): Pledge
    {
        return Pledge::create(array_merge([
            'email' => 'pledger@test.ke',
            'name' => 'Pledger',
            'phone' => '254712345678',
            'amount' => 1000,
            'currency' => 'KES',
            'method' => Pledge::METHOD_MPESA,
            'status' => Pledge::STATUS_ACTIVE,
            'start_date' => '2026-10-05',
            'next_payment_date' => '2026-11-05',
        ], $overrides));
    }

    public function test_only_one_obligation_row_per_pledge_and_billing_month(): void
    {
        $pledge = $this->makePledge();

        PledgePayment::create([
            'pledge_id' => $pledge->id,
            'billing_month' => '2026-11',
            'amount_due' => 1000,
            'currency' => 'KES',
            'due_date' => '2026-11-05',
            'status' => PledgePayment::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // A second PAID obligation for the same period must be structurally
        // impossible — not merely discouraged in application code.
        PledgePayment::create([
            'pledge_id' => $pledge->id,
            'billing_month' => '2026-11',
            'amount_due' => 1000,
            'currency' => 'KES',
            'due_date' => '2026-11-05',
            'status' => PledgePayment::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    public function test_payment_attempt_references_are_unique(): void
    {
        $pledge = $this->makePledge();
        $payment = PledgePayment::create([
            'pledge_id' => $pledge->id,
            'billing_month' => '2026-11',
            'amount_due' => 1000,
            'due_date' => '2026-11-05',
        ]);

        $attrs = [
            'pledge_payment_id' => $payment->id,
            'pledge_id' => $pledge->id,
            'reference' => 'ROI-PLE-DUPREF',
            'amount' => 1000,
        ];
        $payment->paymentAttempts()->create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        $payment->paymentAttempts()->create($attrs);
    }

    public function test_email_ledger_blocks_duplicate_sends_per_interval(): void
    {
        $pledge = $this->makePledge();
        $payment = PledgePayment::create([
            'pledge_id' => $pledge->id,
            'billing_month' => '2026-11',
            'amount_due' => 1000,
            'due_date' => '2026-11-05',
        ]);

        $attrs = [
            'pledge_id' => $pledge->id,
            'pledge_payment_id' => $payment->id,
            'kind' => 'reminder',
            'reminder_key' => '3',
            'status' => 'sent',
            'sent_at' => now(),
        ];
        $payment->emailAttempts()->create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        $payment->emailAttempts()->create($attrs);
    }

    public function test_collectable_scope_only_returns_due_active_mpesa_pledges(): void
    {
        $due = $this->makePledge(['email' => 'due@test.ke', 'next_payment_date' => '2026-11-05']);
        $this->makePledge(['email' => 'future@test.ke', 'next_payment_date' => '2026-12-05']);
        $this->makePledge(['email' => 'paused@test.ke', 'status' => Pledge::STATUS_PAUSED]);
        $this->makePledge(['email' => 'cancelled@test.ke', 'status' => Pledge::STATUS_CANCELLED]);
        $this->makePledge(['email' => 'card@test.ke', 'method' => Pledge::METHOD_CARD]);
        $this->makePledge(['email' => 'nodate@test.ke', 'next_payment_date' => null]);

        $ids = Pledge::collectable('2026-11-05')->pluck('id');

        $this->assertSame([$due->id], $ids->all());
        $this->assertTrue($due->isCollectableOn('2026-11-05'));
    }

    public function test_billing_month_is_calculated_in_nairobi_not_utc(): void
    {
        // 22:30 UTC on 31 Oct is already 01:30 on 1 Nov in Nairobi (+03).
        $this->assertSame('2026-11', PledgePayment::billingMonthFor('2026-10-31T22:30:00Z'));
        $this->assertSame('2026-10', PledgePayment::billingMonthFor('2026-10-31T20:30:00Z'));
        $this->assertSame('2026-11', PledgePayment::billingMonthFor('2026-11-01'));
        $this->assertNull(PledgePayment::billingMonthFor(null));
    }

    public function test_pledge_configuration_defaults_match_spec(): void
    {
        $this->assertSame([1, 3, 7], config('roi.pledge_reminder_days'));
        $this->assertSame(25, config('roi.pledge_email_batch_size'));
        $this->assertSame('Africa/Nairobi', config('roi.pledge_timezone'));
        $this->assertSame(168, config('roi.pledge_payment_link_ttl_hours'));
    }

    public function test_reminder_days_parsing_drops_non_positive_and_honours_empty(): void
    {
        $this->assertSame([1, 3, 7], PledgeConfig::parseReminderDays('1,3,7'));
        $this->assertSame([1, 3], PledgeConfig::parseReminderDays('0,1,-2,3'));
        $this->assertSame([], PledgeConfig::parseReminderDays(''));
        $this->assertSame([], PledgeConfig::parseReminderDays('0'));
        $this->assertSame([5, 2], PledgeConfig::parseReminderDays(' 5 , 0 ,2'));
        $this->assertSame([3, 3], PledgeConfig::parseReminderDays('3,3'));
    }

    public function test_config_hardening_floors_and_timezone_fallback(): void
    {
        $this->assertSame(1, PledgeConfig::parseBatchSize('0'));
        $this->assertSame(7, PledgeConfig::parseBatchSize('7'));
        $this->assertSame(1, PledgeConfig::parseLinkTtlHours('-5'));
        $this->assertSame(168, PledgeConfig::parseLinkTtlHours('168'));
        $this->assertSame('Africa/Nairobi', PledgeConfig::normalizeTimezone('Africa/Nairobi'));
        $this->assertSame('Africa/Nairobi', PledgeConfig::normalizeTimezone('Not/AZone'));
        $this->assertSame('Africa/Nairobi', PledgeConfig::normalizeTimezone(''));
    }

    public function test_empty_string_billing_month_is_null(): void
    {
        $this->assertNull(PledgePayment::billingMonthFor(''));
    }

    public function test_pledges_table_has_no_month_outcome_columns(): void
    {
        // Spec §1: month results belong on pledge_payments, never on pledges.
        foreach (['paid_at', 'attempts', 'reminder_count', 'billing_month'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('pledges', $column),
                "pledges must not carry month-level column: {$column}"
            );
        }
    }
}
