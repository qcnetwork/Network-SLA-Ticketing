<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user exceptions to the role's menus, as {"reports": false, "archive": true}. Null means the
     * user has exactly their role's menus, so existing accounts keep the access they have today.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('menu_access')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('menu_access');
        });
    }
};
