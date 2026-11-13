<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each customer hangs in the fibre network (OLT, ODC, ODP and the ODP port). The FTTH mapping
 * application keeps these up to date through the API; the ticketing app only shows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('olt', 100)->nullable()->after('longitude')->index();
            $table->string('odc', 100)->nullable()->after('olt');
            $table->string('odp', 100)->nullable()->after('odc')->index();
            $table->unsignedSmallInteger('odp_port')->nullable()->after('odp');
            $table->timestamp('network_updated_at')->nullable()->after('odp_port');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['olt']);
            $table->dropIndex(['odp']);
            $table->dropColumn(['olt', 'odc', 'odp', 'odp_port', 'network_updated_at']);
        });
    }
};
