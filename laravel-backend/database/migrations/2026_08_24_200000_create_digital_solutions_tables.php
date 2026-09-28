<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_solutions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category')->default('Platform');
            $table->text('summary');
            $table->text('description');
            $table->string('price_label')->nullable();
            $table->string('icon')->default('globe');
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('digital_solution_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_solution_id')->nullable()->constrained('digital_solutions')->nullOnDelete();
            $table->string('name');
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->string('organization')->nullable();
            $table->text('message');
            $table->string('status')->default('New');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_solution_inquiries');
        Schema::dropIfExists('digital_solutions');
    }
};
