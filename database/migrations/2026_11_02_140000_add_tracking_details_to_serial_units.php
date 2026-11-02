<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the warehouse's tracking sheet records per unit, kept so the history imported from it
     * (installs, swaps, returns) is not lost.
     */
    public function up(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->date('received_at')->nullable()->after('ticket_id');
            $table->date('taken_at')->nullable()->after('received_at');
            $table->string('holder_name', 150)->nullable()->after('taken_at');
            $table->string('installed_to', 255)->nullable()->after('holder_name');
            $table->string('install_status', 100)->nullable()->after('installed_to');
            $table->string('replaced_device', 150)->nullable()->after('install_status');
            $table->string('condition', 100)->nullable()->after('replaced_device');
            $table->string('notes', 255)->nullable()->after('condition');
            $table->timestamp('imported_at')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropColumn(['received_at', 'taken_at', 'holder_name', 'installed_to', 'install_status', 'replaced_device', 'condition', 'notes', 'imported_at']);
        });
    }
};
