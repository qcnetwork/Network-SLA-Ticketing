<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('kategori', '');

        return view('warehouses.items', [
            'search' => $search,
            'category' => $category,
            'categories' => ItemCategory::query()->ordered()->withCount('items')->get(),
            'uncategorised' => Item::query()->whereNull('item_category_id')->count(),
            'icons' => ItemCategory::ICONS,
            'items' => Item::query()->with('category')
                ->when($search !== '', fn ($query) => $query->search($search))
                ->when($category === 'none', fn ($query) => $query->whereNull('item_category_id'))
                ->when(ctype_digit($category), fn ($query) => $query->where('item_category_id', (int) $category))
                ->withSum('stocks', 'quantity')
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateItem($request);
        $item = new Item(['active' => true]);
        $item->fill($data + ['tracks_serial' => $this->tracksSerial($request, $data, $item)])->save();

        return back()->with('success', "Barang {$data['code']} ditambahkan");
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validateItem($request, $item);
        $item->update($data + ['active' => $request->boolean('active'), 'tracks_serial' => $this->tracksSerial($request, $data, $item)]);

        return back()->with('success', "Barang {$item->code} diperbarui");
    }

    /**
     * Remove an item entered by mistake. Stock, movements, SNs, material requests, sales and usage lines all
     * point at the item (most cascade on delete), so an item with any of that history is only deactivated.
     */
    public function destroy(Item $item): RedirectResponse
    {
        $used = $item->stocks()->where('quantity', '>', 0)->exists()
            || $item->serialUnits()->exists()
            || collect(['stock_movements', 'material_request_lines', 'sale_lines', 'material_usage_lines'])
                ->contains(fn (string $table) => DB::table($table)->where('item_id', $item->id)->exists());
        if ($used) {
            return back()->withErrors(['item' => "{$item->code} sudah punya stok atau riwayat transaksi, jadi tidak bisa dihapus. Nonaktifkan saja supaya tidak muncul di pilihan"]);
        }
        $item->delete();

        return back()->with('success', "Barang {$item->code} dihapus");
    }

    /**
     * Read a warehouse code list (e.g. "KODE BARANG GUDANG.xlsx"): the first row holding a column whose
     * header starts with "KODE" and one starting with "NAMA" is the header, every row below is an item.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']], [], ['file' => 'file']);

        $rows = collect(Excel::toArray(new class implements Import {}, $request->file('file'))[0] ?? []);
        $headerIndex = $rows->search(fn (array $row) => $this->column($row, 'KODE') !== null && $this->column($row, 'NAMA') !== null);
        if ($headerIndex === false) {
            return back()->withErrors(['file' => 'Kolom KODE dan NAMA tidak ditemukan di sheet pertama']);
        }
        $codeColumn = $this->column($rows[$headerIndex], 'KODE');
        $nameColumn = $this->column($rows[$headerIndex], 'NAMA');

        $result = $this->inventory->importItems(
            $rows->slice($headerIndex + 1)->map(fn (array $row) => [$row[$codeColumn] ?? null, $row[$nameColumn] ?? null])
        );

        return back()->with('success', "Impor selesai: {$result['created']} barang baru, {$result['updated']} diperbarui");
    }

    private function column(array $row, string $prefix): ?int
    {
        foreach ($row as $index => $value) {
            if (str_starts_with(strtoupper(trim((string) $value)), $prefix)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A device category always tracks SN. Switching an item between counted and SN-tracked is only allowed
     * while no stock would be left without (or with) an SN it no longer matches.
     */
    private function tracksSerial(Request $request, array $data, Item $item): bool
    {
        $category = ItemCategory::query()->find($data['item_category_id'] ?? null);
        $wanted = $category?->requires_serial || $request->boolean('tracks_serial');
        if (! $item->exists || $wanted === $item->tracks_serial) {
            return $wanted;
        }
        if ($wanted && $item->stocks()->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['tracks_serial' => "{$item->code} masih punya stok tanpa SN. Habiskan atau retur dulu stoknya sebelum diubah menjadi barang ber-SN".($category?->requires_serial ? " (kategori {$category->name} wajib SN)" : '')]);
        }
        if (! $wanted && $item->serialUnits()->whereNotNull('warehouse_id')->exists()) {
            throw ValidationException::withMessages(['tracks_serial' => "{$item->code} masih punya unit ber-SN di gudang, tidak bisa diubah menjadi barang tanpa SN"]);
        }

        return $wanted;
    }

    private function validateItem(Request $request, ?Item $item = null): array
    {
        $request->merge([
            'code' => strtoupper(trim((string) $request->input('code'))),
            'item_category_id' => $request->input('item_category_id') ?: null,
            'min_stock' => $request->input('min_stock') ?: 0,
        ]);

        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('items', 'code')->ignore($item)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'item_category_id' => ['nullable', Rule::exists('item_categories', 'id')],
            'min_stock' => ['integer', 'min:0', 'max:1000000'],
        ], [], ['code' => 'kode', 'name' => 'nama barang', 'unit' => 'satuan', 'item_category_id' => 'kategori', 'min_stock' => 'stok minimum']);
    }
}
