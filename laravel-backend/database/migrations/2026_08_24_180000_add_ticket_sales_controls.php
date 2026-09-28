<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('ticket_sales_enabled')->default(true);
            $table->unsignedInteger('capacity')->nullable();
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->unsignedInteger('max_per_order')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['ticket_sales_enabled', 'capacity']);
        });
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('max_per_order');
        });
    }
};
