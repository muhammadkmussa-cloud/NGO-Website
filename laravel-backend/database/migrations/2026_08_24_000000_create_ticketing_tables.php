<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->float('price')->default(0);
            $table->string('currency')->default('KES');
            $table->unsignedInteger('quantity')->nullable(); // null = unlimited
            $table->unsignedInteger('sold_count')->default(0);
            $table->timestamp('sales_start')->nullable();
            $table->timestamp('sales_end')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['event_id', 'is_active']);
        });

        Schema::create('ticket_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('buyer_name');
            $table->string('buyer_email')->index();
            $table->string('buyer_phone')->nullable();
            $table->string('gateway');
            $table->string('reference')->unique()->index();
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('merchant_request_id')->nullable()->index();
            $table->float('amount');
            $table->string('currency')->default('KES');
            $table->string('status')->default('Pending Payment');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
        });

        Schema::create('ticket_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_order_id')->constrained('ticket_orders')->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->float('unit_price');
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_order_id')->constrained('ticket_orders')->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained('ticket_types')->cascadeOnDelete();
            $table->string('code')->unique()->index();
            $table->string('attendee_name')->nullable();
            $table->string('attendee_email')->nullable();
            $table->string('status')->default('valid'); // valid | void | checked_in
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_order_items');
        Schema::dropIfExists('ticket_orders');
        Schema::dropIfExists('ticket_types');
    }
};
