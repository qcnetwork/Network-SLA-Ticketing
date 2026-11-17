<?php

namespace App\Http\Controllers;

use App\Models\ItemCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ItemCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateCategory($request);
        ItemCategory::query()->create($data + ['sort_order' => (int) ItemCategory::query()->max('sort_order') + 1]);

        return back()->with('success', "Kategori {$data['name']} ditambahkan");
    }

    /**
     * Turning on "wajib SN" makes every item in the category SN-tracked. An item that still has counted
     * stock cannot be switched, since those units have no SN, so the change is refused until it is used up or returned.
     */
    public function update(Request $request, ItemCategory $category): RedirectResponse
    {
        $data = $this->validateCategory($request, $category);

        DB::transaction(function () use ($category, $data) {
            if ($data['requires_serial'] && ! $category->requires_serial) {
                $counted = $category->items()->where('tracks_serial', false)
                    ->whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))->orderBy('code')->pluck('code');
                if ($counted->isNotEmpty()) {
                    throw ValidationException::withMessages(['category' => "Kategori belum bisa diwajibkan SN: {$counted->take(5)->implode(', ')}".($counted->count() > 5 ? ' dan lainnya' : '').' masih punya stok tanpa SN']);
                }
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
        ], [], ['name' => 'nama kategori', 'icon' => 'ikon']);

        return $data + ['requires_serial' => $request->boolean('requires_serial')];
    }
}
