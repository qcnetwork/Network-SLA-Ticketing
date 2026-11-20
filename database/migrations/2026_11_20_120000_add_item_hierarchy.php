<?php

use App\Services\ItemClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock is browsed as category → jenis (ONT GPON / EPON / XPON) → merk (Fiberhome, ZTE...) → model, where
        // each model is an item with its own code. Every item also says what sort of thing it is (sifat).
        Schema::table('items', function (Blueprint $table) {
            $table->string('variant', 60)->nullable()->after('item_category_id');
            $table->string('brand', 60)->nullable()->after('variant');
            $table->string('kind', 20)->default('HABIS_PAKAI')->after('brand');
        });
        // A category hands new items its code prefix (MDM → MDM083) and the sifat they start with.
        Schema::table('item_categories', function (Blueprint $table) {
            $table->string('code_prefix', 10)->nullable()->after('name');
            $table->string('kind', 20)->default('HABIS_PAKAI')->after('code_prefix');
        });

        $classifier = new ItemClassifier;
        $categories = DB::table('item_categories')->get()->keyBy('id');
        foreach ($categories as $category) {
            // The prefix most of its codes already use (MDM for MDM001, MDM019AZN...).
            $prefix = DB::table('items')->where('item_category_id', $category->id)->pluck('code')
                ->map(fn (string $code) => preg_match('/^([A-Z]+)/', strtoupper($code), $m) ? $m[1] : null)
                ->filter()->countBy()->sortDesc()->keys()->first();
            DB::table('item_categories')->where('id', $category->id)->update([
                'code_prefix' => $prefix ? substr($prefix, 0, 10) : null,
                'kind' => $classifier->kind($category->name)->value,
            ]);
        }
        foreach (DB::table('items')->get(['id', 'name', 'item_category_id']) as $item) {
            $categoryName = $categories[$item->item_category_id]->name ?? null;
            DB::table('items')->where('id', $item->id)->update([
                'variant' => $classifier->variant($categoryName, $item->name),
                'brand' => $classifier->brand($item->name),
                'kind' => $classifier->kind($categoryName)->value,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['variant', 'brand', 'kind']);
        });
        Schema::table('item_categories', function (Blueprint $table) {
            $table->dropColumn(['code_prefix', 'kind']);
        });
    }
};
