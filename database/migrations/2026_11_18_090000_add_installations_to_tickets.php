<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New installations (PSB) live in the tickets table beside complaints, told apart by kind, so they
 * reuse the history, photos, crew assignment and warehouse material. Existing tickets are complaints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('kind', 10)->default('GANGGUAN')->after('ticket_number');
            $table->string('package', 100)->nullable()->after('category');
            $table->string('sales', 100)->nullable()->after('package');
            $table->string('odp', 100)->nullable()->after('sales');
            $table->text('survey_notes')->nullable()->after('technician_notes');
            $table->index(['kind', 'status']);
        });

        // The service packages offered on a PSB order, managed by supervisors like complaint categories.
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_packages');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropColumn(['kind', 'package', 'sales', 'odp', 'survey_notes']);
        });
    }
};
