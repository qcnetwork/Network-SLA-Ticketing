<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hot queries: ticket/gamas timelines, the dashboard board,
 * the monthly report and the hourly auto-close check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['status', 'updated_at']);
            $table->index(['status', 'resolved_at']);
            $table->index(['technician_id', 'status']);
            $table->index('incident_id');
            $table->index('created_at');
        });

        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->index(['ticket_id', 'created_at']);
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->index('ticket_history_id');
            $table->index('incident_update_id');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->index('technician_id');
        });

        Schema::table('incident_updates', function (Blueprint $table) {
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('incident_updates', function (Blueprint $table) {
            $table->dropIndex(['incident_id', 'created_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['technician_id']);
        });

        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropIndex(['ticket_history_id']);
            $table->dropIndex(['incident_update_id']);
        });

        Schema::table('ticket_histories', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'created_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropIndex(['status', 'resolved_at']);
            $table->dropIndex(['technician_id', 'status']);
            $table->dropIndex(['incident_id']);
            $table->dropIndex(['created_at']);
        });
    }
};
