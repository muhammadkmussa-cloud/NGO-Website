<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_solution_id')->nullable()->constrained('digital_solutions')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('client')->nullable();
            $table->string('location')->default('Harbor City, Kenya');
            $table->string('year')->nullable();
            $table->text('summary');
            $table->text('outcome')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_published')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_portfolio_items');
    }
};
