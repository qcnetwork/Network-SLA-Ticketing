<?php

use App\Models\ItemCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every kind of thing the warehouse keeps belongs to a category. A device category (modem, switch,
        // Mikrotik...) requires an SN for each unit, so its items are always tracked one unit at a time.
        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('icon', 40)->default('package');
            $table->boolean('requires_serial')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('item_category_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // Below this many units in the main warehouse the item shows as running low (0 = no warning).
            $table->unsignedInteger('min_stock')->default(0)->after('unit');
        });

        $now = now();
        foreach (ItemCategory::DEFAULTS as $order => [$name, $icon, $serial]) {
            DB::table('item_categories')->insert([
                'name' => $name, 'icon' => $icon, 'requires_serial' => $serial, 'sort_order' => $order + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Sort existing items by their code and name. An item guessed into a device category that is still
        // counted with stock on hand is left uncategorised: its units have no SN yet, so the warehouse decides.
        $categories = DB::table('item_categories')->get()->keyBy('name');
        $counted = DB::table('stocks')->where('quantity', '>', 0)->distinct()->pluck('item_id')->flip();
        foreach (DB::table('items')->get() as $item) {
            $guess = ItemCategory::guessName($item->code, $item->name);
            $category = $guess ? $categories[$guess] : null;
            if (! $category) {
                continue;
            }
            if ($category->requires_serial && ! $item->tracks_serial) {
                if ($counted->has($item->id)) {
                    continue;
                }
                DB::table('items')->where('id', $item->id)->update(['tracks_serial' => true]);
            }
            DB::table('items')->where('id', $item->id)->update(['item_category_id' => $category->id]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_category_id');
            $table->dropColumn('min_stock');
        });
        Schema::dropIfExists('item_categories');
    }
};
