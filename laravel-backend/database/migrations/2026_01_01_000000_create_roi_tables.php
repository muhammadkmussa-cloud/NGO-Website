<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique()->index();
            $table->string('password_hash');
            $table->timestamp('last_login')->nullable();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->index();
            $table->string('slug')->unique()->index();
            $table->text('summary');
            $table->text('content');
            $table->string('category')->default('Social Impact')->index();
            $table->string('author')->default('DEMO Communications');
            $table->string('image_url')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->string('youtube_id')->unique()->index();
            $table->string('title');
            $table->string('category')->index();
            $table->text('summary')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('duration')->default('5:30');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->useCurrent();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('date'); // Free-text date string, e.g. "August 14-16, 2026"
            $table->string('time')->default('09:00 AM EAT');
            $table->string('location')->default('Harbor City, Kenya');
            $table->text('description');
            $table->string('category')->default('Conference');
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('volunteers', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->index();
            $table->string('phone');
            $table->string('primary_skill')->index();
            $table->string('availability');
            $table->text('motivation')->nullable();
            $table->string('status')->default('Pending Review');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->string('donor_name')->default('Anonymous');
            $table->string('email')->nullable();
            $table->float('amount');
            $table->string('currency')->default('KES'); // KES, USD, EUR, GBP
            $table->string('gateway');
            $table->string('frequency')->default('one-time');
            $table->string('reference')->unique()->index();
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('merchant_request_id')->nullable()->index();
            $table->string('status')->default('Completed');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status')->default('New');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('admin_email')->index();
            $table->string('action')->index();
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('leaders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role');
            $table->text('bio');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaders');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('inquiries');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('volunteers');
        Schema::dropIfExists('events');
        Schema::dropIfExists('media_items');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('admin_users');
    }
};
