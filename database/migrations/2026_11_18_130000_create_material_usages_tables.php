<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where material went: the office's old "Laporan Pemasangan" sheet. One usage is one job (a date, a crew,
        // a purpose, a place); its lines are the items, each taken from a warehouse.
        Schema::create('material_usages', function (Blueprint $table) {
            $table->id();
            $table->date('used_at')->index();
            $table->string('purpose', 30)->index();
            $table->string('project', 100)->nullable();
            $table->string('location', 255);
            $table->string('team', 150)->nullable();
            // The stock holder as the old sheet named it; rows made in the app carry a warehouse on each line instead.
            $table->string('holder_name', 100)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('removed_device', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            // Imported history never touched stock; the key keeps a second import from doubling it.
            $table->timestamp('imported_at')->nullable();
            $table->string('import_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('material_usage_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_usage_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name', 150);
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('serial_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 100)->nullable()->index();
            $table->string('mac', 20)->nullable();
            $table->integer('quantity');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('material_usage_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('serial_units', function (Blueprint $table) {
            $table->foreignId('material_usage_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_usage_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_usage_id');
        });
        Schema::dropIfExists('material_usage_lines');
        Schema::dropIfExists('material_usages');
    }
};
