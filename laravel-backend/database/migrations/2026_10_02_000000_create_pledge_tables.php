<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The recurring commitment itself (spec §1: ACTIVE / PAUSED / CANCELLED /
        // COMPLETED — month-level outcomes live on pledge_payments, never here).
        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 191)->nullable();
            $table->string('email', 191)->index();
            $table->string('phone', 32)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('method', 32)->default('mpesa');
            $table->string('channel', 64)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->date('start_date');
            $table->date('next_payment_date')->nullable();
            $table->date('last_successful_payment_date')->nullable();
            $table->timestamps();

            // Scheduler lookup: ACTIVE pledges with an obligation due.
            $table->index(['status', 'next_payment_date']);
        });

        // One obligation row per pledge per billing month. The composite unique
        // key is the structural guarantee that two PAID records can never exist
        // for the same pledge + period (spec §1).
        Schema::create('pledge_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained('pledges')->cascadeOnDelete();
            $table->string('billing_month', 7);
            $table->decimal('amount_due', 18, 2);
            $table->string('currency', 3)->default('KES');
            $table->date('due_date');
            $table->string('status', 16)->default('DUE');
            $table->string('method', 32)->default('mpesa');
            $table->string('channel', 64)->nullable();
            $table->string('paystack_reference', 191)->nullable();
            $table->string('paystack_transaction_id', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);
            $table->timestamp('last_reminder_at')->nullable();
            $table->timestamps();

            $table->unique(['pledge_id', 'billing_month']);
            $table->index(['status', 'due_date']);
        });

        // Individual transaction attempts (spec §8: multiple tries per month,
        // still ONE amount due — the obligation row above).
        Schema::create('pledge_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_payment_id')->constrained('pledge_payments')->cascadeOnDelete();
            $table->foreignId('pledge_id')->constrained('pledges')->cascadeOnDelete();
            $table->string('reference', 191)->unique();
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('method', 32)->default('mpesa');
            $table->string('channel', 64)->nullable();
            $table->string('status', 32)->default('initiated');
            $table->string('paystack_transaction_id', 64)->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('failure_reason', 191)->nullable();
            $table->text('meta')->nullable();
            $table->timestamps();

            $table->index(['pledge_id', 'created_at']);
        });

        // Outbound email ledger — the anti-spam guard: at most one send per
        // (payment, kind, interval) no matter how often the cron fires.
        Schema::create('pledge_email_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pledge_id')->constrained('pledges')->cascadeOnDelete();
            $table->foreignId('pledge_payment_id')->constrained('pledge_payments')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('reminder_key', 16)->default('');
            $table->string('status', 16);
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 191)->nullable();
            $table->timestamps();

            $table->unique(['pledge_payment_id', 'kind', 'reminder_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pledge_email_attempts');
        Schema::dropIfExists('pledge_payment_attempts');
        Schema::dropIfExists('pledge_payments');
        Schema::dropIfExists('pledges');
    }
};
