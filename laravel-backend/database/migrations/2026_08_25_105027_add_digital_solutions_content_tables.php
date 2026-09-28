<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_solution_faqs', function (Blueprint $table) {
            $table->id();
            $table->text('question');
            $table->text('answer');
            $table->string('group')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('digital_solution_industries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('globe');
            $table->text('summary')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('digital_solution_tech', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('code');
            $table->text('description')->nullable();
            $table->string('group')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::table('digital_solutions', function (Blueprint $table) {
            $table->string('service_category')->nullable()->after('category');
            $table->json('features')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_solution_faqs');
        Schema::dropIfExists('digital_solution_industries');
        Schema::dropIfExists('digital_solution_tech');
        Schema::table('digital_solutions', function (Blueprint $table) {
            $table->dropColumn(['service_category', 'features']);
        });
    }
};