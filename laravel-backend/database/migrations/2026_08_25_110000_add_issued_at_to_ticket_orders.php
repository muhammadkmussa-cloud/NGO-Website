<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Email delivery was removed; tickets are now issued client-side as a
     * downloadable PNG. We add an `issued_at` flag (replacing the legacy
     * emailed_at/delivery_error columns, which remain as unused vestiges to
     * avoid a doctrine/dbal dependency for renameColumn on SQLite).
     */
    public function up(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_orders', function (Blueprint $table) {
            $table->dropColumn('issued_at');
        });
    }
};
