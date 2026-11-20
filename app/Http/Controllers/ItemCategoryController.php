<?php

namespace App\Http\Controllers;

use App\Enums\ItemKind;
use App\Models\ItemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ItemCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCategory($request);
        ItemCategory::query()->create($data + ['sort_order' => (int) ItemCategory::query()->max('sort_order') + 1]);

        return back()->with('success', "Kategori {$data['name']} ditambahkan");
    }

    /**
     * Turning on "wajib SN" makes every item in the category SN-tracked. Counted stock they already have stays
     * as "stok lama tanpa SN", used by quantity, while new stock comes in by SN.
     */
    public function update(Request $request, ItemCategory $category): RedirectResponse
    {
        $data = $this->validateCategory($request, $category);

        DB::transaction(function () use ($category, $data) {
            if ($data['requires_serial'] && ! $category->requires_serial) {
                $category->items()->update(['tracks_serial' => true]);
            }
            $category->update($data);
        });

        return back()->with('success', "Kategori {$category->name} diperbarui");
    }

    public function destroy(ItemCategory $category): RedirectResponse
    {
        if ($category->items()->exists()) {
            return back()->withErrors(['category' => "Kategori {$category->name} masih berisi barang. Pindahkan barangnya dulu"]);
        }
        $category->delete();

        return back()->with('success', "Kategori {$category->name} dihapus");
    }

    private function validateCategory(Request $request, ?ItemCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('item_categories', 'name')->ignore($category)],
            'icon' => ['required', Rule::in(array_keys(ItemCategory::ICONS))],
            'code_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]+$/'],
            'kind' => ['nullable', Rule::enum(ItemKind::class)],
        ], ['code_prefix.regex' => 'Awalan kode hanya huruf, mis. MDM'], ['name' => 'nama kategori', 'icon' => 'ikon', 'code_prefix' => 'awalan kode']);

        return [
            'name' => $data['name'],
            'icon' => $data['icon'],
            'code_prefix' => filled($data['code_prefix'] ?? null) ? strtoupper($data['code_prefix']) : null,
            'kind' => $data['kind'] ?? $category?->kind?->value ?? ItemKind::HABIS_PAKAI->value,
            'requires_serial' => $request->boolean('requires_serial'),
        ];
    }
}
