<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An SN is no longer only a device's: a roll of dropcore or a pole carries its own printed barcode.
        // Each item says whether its units also have a MAC label to scan after the SN (modems, routers).
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('scans_mac')->default(false)->after('tracks_serial');
        });
        // Every SN item so far is a device that was scanned with its MAC.
        DB::table('items')->where('tracks_serial', true)->update(['scans_mac' => true]);

        // A roll scanned out to a technician arrives there as meters of cable: the roll's SN leaves the
        // main warehouse and remembers whose warehouse it was opened into.
        Schema::table('serial_units', function (Blueprint $table) {
            $table->foreignId('converted_to_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_to_warehouse_id');
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('scans_mac');
        });
    }
};
