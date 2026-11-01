<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // The categories that used to be hard-coded, followed by any other name already on a ticket.
        $names = collect([
            'LOS Merah / Kabel Putus',
            'Internet Mati Total (LOS Hijau tapi RTO)',
            'Internet Lambat / Ping Tinggi',
            'WiFi Sering Putus / Jangkauan Sinyal',
            'Router / Modem Rusak (Mati Total)',
            'Lainnya',
        ])->merge(DB::table('tickets')->distinct()->orderBy('category')->pluck('category'))->unique()->values();

        DB::table('ticket_categories')->insert($names->map(fn (string $name, int $i) => [
            'name' => $name,
            'sort_order' => ($i + 1) * 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_categories');
    }
};
