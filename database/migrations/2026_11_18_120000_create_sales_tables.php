<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partners (mitra) that buy equipment from the office; a one-off buyer is typed on the sale itself.
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Stock sold out of the main warehouse. The buyer's name and phone are copied onto the sale, so the log
        // still reads right after a partner is renamed.
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number', 30)->unique();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buyer_name', 150);
            $table->string('buyer_phone', 30)->nullable();
            $table->date('sold_at')->index();
            $table->unsignedBigInteger('total')->default(0);
            $table->string('payment_status', 20)->default('BELUM_LUNAS')->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_notes', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
        });

        // A sold device leaves every warehouse; this says who bought it.
        Schema::table('serial_units', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('partners');
    }
};
