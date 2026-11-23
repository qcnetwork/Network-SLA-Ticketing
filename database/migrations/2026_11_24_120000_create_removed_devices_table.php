<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devices a technician takes off a customer (a swapped modem, a dead router). Reported on the repair resume,
 * carried by the technician until handed to the warehouse (within three days), then checked: fit for use goes
 * back into stock, broken stays out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('removed_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code', 50)->nullable()->index();
            $table->string('customer_name', 150)->nullable();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('serial_number', 100)->nullable()->index();
            $table->string('mac', 12)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('field_condition', 20);
            $table->string('notes', 255)->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('due_at');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('check_notes', 255)->nullable();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('serial_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_material_id')->nullable()->constrained('gudang_pelanggan')->nullOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('removed_devices');
    }
};
