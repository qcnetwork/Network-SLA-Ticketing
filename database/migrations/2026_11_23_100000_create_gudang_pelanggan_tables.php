<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gudang Pelanggan: the material sitting at each customer, peered to the customer directory by its ID
 * (customer_code = customers.code, the same way tickets link), kept apart from the warehouse stock tables.
 * `gudang_pelanggan` is what a customer has now (and had, once removed); `gudang_pelanggan_logs` is every
 * install, removal and cancellation, in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gudang_pelanggan', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 50)->index();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name', 150);
            $table->string('unit', 20)->nullable();
            $table->foreignId('serial_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 100)->nullable()->index();
            $table->string('mac', 20)->nullable();
            $table->integer('quantity');
            // Whose stock it came from, and who put it in.
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('technician', 150)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_usage_id')->nullable()->constrained()->nullOnDelete();
            // One row per usage line, so recording the same job twice never doubles it.
            $table->foreignId('material_usage_line_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->date('installed_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('removed_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['customer_code', 'removed_at']);
        });

        Schema::create('gudang_pelanggan_logs', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code', 50)->index();
            $table->foreignId('gudang_pelanggan_id')->nullable()->constrained('gudang_pelanggan')->nullOnDelete();
            $table->string('action', 20);
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_name', 150);
            $table->string('serial_number', 100)->nullable();
            $table->integer('quantity');
            $table->string('unit', 20)->nullable();
            // Where it came from and went to, as names (a warehouse, a technician, the customer).
            $table->string('from_label', 191)->nullable();
            $table->string('to_label', 191)->nullable();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_usage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();
        });

        Schema::table('material_usages', function (Blueprint $table) {
            $table->string('customer_code', 50)->nullable()->index()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('material_usages', function (Blueprint $table) {
            $table->dropIndex(['customer_code']);
            $table->dropColumn('customer_code');
        });
        Schema::dropIfExists('gudang_pelanggan_logs');
        Schema::dropIfExists('gudang_pelanggan');
    }
};
