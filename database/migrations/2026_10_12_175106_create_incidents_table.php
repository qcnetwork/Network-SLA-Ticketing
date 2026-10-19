<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_number')->unique();
            $table->string('title', 150);
            $table->string('area', 150);
            $table->text('cause')->nullable();
            $table->string('status', 20)->index();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('estimated_resolved_at')->nullable();
            $table->timestamp('repair_started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 40);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('technician_id')->constrained()->nullOnDelete();
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->change();
            $table->foreignId('incident_id')->nullable()->after('ticket_history_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incident_update_id')->nullable()->after('incident_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_update_id');
            $table->dropConstrainedForeignId('incident_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });

        Schema::dropIfExists('incident_updates');
        Schema::dropIfExists('incidents');
    }
};
