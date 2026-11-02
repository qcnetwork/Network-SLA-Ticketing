<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('unit', 20)->default('pcs');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // One main warehouse (user_id null) holding everything the office has, plus one per technician.
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('user_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'item_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        DB::table('warehouses')->insert(['name' => 'Gudang Utama', 'user_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('items');
    }
};
