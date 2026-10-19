<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tickets and gamas can be assigned to several technicians at once, so the single
 * technician_id column moves to a pivot table. Existing assignments are carried over.
 */
return new class extends Migration
{
    /** @var array<string, string> owner table => pivot table */
    private const PIVOTS = ['tickets' => 'ticket_technician', 'incidents' => 'incident_technician'];

    public function up(): void
    {
        foreach (self::PIVOTS as $owner => $pivot) {
            $ownerKey = str($owner)->singular().'_id';

            Schema::create($pivot, function (Blueprint $table) use ($owner, $ownerKey) {
                $table->foreignId($ownerKey)->constrained($owner)->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary([$ownerKey, 'user_id']);
                $table->index('user_id');
            });

            DB::table($pivot)->insertUsing(
                [$ownerKey, 'user_id', 'created_at', 'updated_at'],
                DB::table($owner)->whereNotNull('technician_id')->select('id', 'technician_id', 'updated_at', 'updated_at'),
            );
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['technician_id', 'status']);
        });
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['technician_id']);
        });

        foreach (array_keys(self::PIVOTS) as $owner) {
            Schema::table($owner, function (Blueprint $table) {
                $table->dropConstrainedForeignId('technician_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::PIVOTS as $owner => $pivot) {
            $ownerKey = str($owner)->singular().'_id';

            Schema::table($owner, function (Blueprint $table) {
                $table->foreignId('technician_id')->nullable()->after('created_by_id')->constrained('users')->nullOnDelete();
            });

            // Only one technician fits the old column; keep the first one assigned.
            DB::table($owner)->update([
                'technician_id' => DB::table($pivot)->select('user_id')->whereColumn("{$pivot}.{$ownerKey}", "{$owner}.id")
                    ->orderBy('created_at')->orderBy('user_id')->limit(1),
            ]);

            Schema::dropIfExists($pivot);
        }

        Schema::table('tickets', function (Blueprint $table) {
            $table->index(['technician_id', 'status']);
        });
        Schema::table('incidents', function (Blueprint $table) {
            $table->index('technician_id');
        });
    }
};
