<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B (real monthly pledges): link donation rows to Paystack subscriptions.
 * Raw-SQL twin for production (no artisan there):
 * database/production/001_donations_subscription_columns.sql — keep in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            // m-5: column widths mirror the production ALTER twin exactly
            // (database/production/001_donations_subscription_columns.sql) —
            // VARCHAR(191)/VARCHAR(64) keep utf8mb4 indexes InnoDB-safe, and
            // the manage URL is TEXT because hosted JWT links can exceed 191.
            $table->string('subscription_code', 191)->nullable()->index();
            $table->string('subscription_token', 191)->nullable();
            $table->string('subscription_status', 191)->nullable();
            $table->text('subscription_manage_url')->nullable();
            $table->timestamp('manage_link_expires_at')->nullable();
            $table->string('next_payment_date', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('donations', function (Blueprint $table) {
            $table->dropIndex(['subscription_code']);
            $table->dropColumn([
                'subscription_code', 'subscription_token', 'subscription_status',
                'subscription_manage_url', 'manage_link_expires_at', 'next_payment_date',
            ]);
        });
    }
};
