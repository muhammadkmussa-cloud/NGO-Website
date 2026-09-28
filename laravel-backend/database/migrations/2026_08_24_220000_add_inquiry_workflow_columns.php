<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_solution_inquiries', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->float('quoted_amount')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('digital_solution_inquiries', function (Blueprint $table) {
            $table->dropColumn(['notes', 'quoted_amount', 'follow_up_at', 'status_changed_at']);
        });
    }
};
