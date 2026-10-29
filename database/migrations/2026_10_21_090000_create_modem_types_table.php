<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modem_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // The suggestions that used to be hard-coded, followed by any other modem already recorded on a ticket.
        $names = collect([
            'ZTE F609',
            'ZTE F660',
            'ZTE F670L',
            'Huawei HG8245H',
            'Huawei HG8245H5',
            'Huawei EG8145V5',
            'Fiberhome AN5506-04-F',
            'Fiberhome HG6243C',
        ])->merge(DB::table('tickets')->whereNotNull('modem')->distinct()->orderBy('modem')->pluck('modem'))
            ->map(fn (string $name) => trim($name))->filter()->unique()->values();

        DB::table('modem_types')->insert($names->map(fn (string $name, int $i) => [
            'name' => $name,
            'sort_order' => ($i + 1) * 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('modem_types');
    }
};
