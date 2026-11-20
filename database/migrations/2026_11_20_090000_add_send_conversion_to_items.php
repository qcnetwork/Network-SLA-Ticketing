<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An item the main warehouse counts in one unit (a roll of dropcore) can reach a technician as another
        // item counted in a smaller unit (dropcore per meter): sending 1 roll gives the technician this many meters.
        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('send_as_item_id')->nullable()->after('min_stock')->constrained('items')->nullOnDelete();
            $table->unsignedInteger('send_ratio')->nullable()->after('send_as_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('send_as_item_id');
            $table->dropColumn('send_ratio');
        });
    }
};
