<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the movement reached the Google Sheet; null means it still has to be sent.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->timestamp('sheet_synced_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['sheet_synced_at']);
            $table->dropColumn('sheet_synced_at');
        });
    }
};
