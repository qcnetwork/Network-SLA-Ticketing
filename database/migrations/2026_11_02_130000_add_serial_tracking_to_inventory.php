<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('tracks_serial')->default(false)->after('unit');
        });
        // Modems are tracked one by one by SN and MAC; everything else is counted.
        DB::table('items')->where('code', 'like', 'MDM%')->update(['tracks_serial' => true]);

        // One physical unit: in a warehouse while warehouse_id is set, installed at a customer once ticket_id is.
        Schema::create('serial_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number', 100)->unique();
            $table->string('mac', 12)->nullable()->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('serial_unit_id')->nullable()->after('item_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_unit_id');
        });
        Schema::dropIfExists('serial_units');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tracks_serial');
        });
    }
};
