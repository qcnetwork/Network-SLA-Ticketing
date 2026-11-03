<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Material reported in a repair resume waits here until the warehouse approves it; only then does stock move.
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_notes', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('material_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('serial_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity');
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_request_lines');
        Schema::dropIfExists('material_requests');
    }
};
