<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the ODP a new installation hangs from stands (its shareloc and pin), and how much cable the
 * crew expects to pull from it to the house.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('odp_location')->nullable()->after('odp');
            $table->decimal('odp_latitude', 10, 7)->nullable()->after('odp_location');
            $table->decimal('odp_longitude', 10, 7)->nullable()->after('odp_latitude');
            $table->unsignedInteger('odp_distance')->nullable()->after('odp_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['odp_location', 'odp_latitude', 'odp_longitude', 'odp_distance']);
        });
    }
};
