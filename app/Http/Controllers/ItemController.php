<?php

namespace App\Http\Controllers;

use App\Enums\ItemKind;
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
        $kind = ItemKind::tryFrom((string) $request->query('sifat'))?->value ?? '';
        $categories = ItemCategory::query()->ordered()->withCount('items')->get();

        return view('warehouses.items', [
            'search' => $search,
            'category' => $category,
            'kind' => $kind,
            'categories' => $categories,
            // What a category hands a new item: its sifat, how its units are told apart, and the next code.
            'categoryMeta' => $categories->mapWithKeys(fn (ItemCategory $row) => [$row->id => [
                'requires_serial' => $row->requires_serial,
                'kind' => $row->kind->value,
                'identifier' => $row->requires_serial ? ($row->kind === ItemKind::PERANGKAT_AKTIF ? 'SN_MAC' : 'SN') : 'JUMLAH',
                'next_code' => $row->code_prefix ? Item::nextCode($row->code_prefix) : null,
            ]]),
            'variantsByCategory' => Item::query()->whereNotNull('variant')->whereNotNull('item_category_id')->distinct()->orderBy('variant')
                ->get(['item_category_id', 'variant'])->groupBy('item_category_id')->map->pluck('variant'),
            'brands' => Item::query()->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand'),
            'uncategorised' => Item::query()->whereNull('item_category_id')->count(),
            'icons' => ItemCategory::ICONS,
            // Counted items a roll-type item can be handed to technicians as (dropcore per meter).
            'conversionTargets' => Item::query()->where('tracks_serial', false)->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'unit']),
            'items' => Item::query()->with(['category', 'sendAs'])
                ->when($search !== '', fn ($query) => $query->search($search))
                ->when($category === 'none', fn ($query) => $query->whereNull('item_category_id'))
                ->when(ctype_digit($category), fn ($query) => $query->where('item_category_id', (int) $category))
                ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
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
        $tracksSerial = $this->tracksSerial($request, $data, $item);
        $item->fill($data + ['tracks_serial' => $tracksSerial, 'scans_mac' => $tracksSerial && $this->scansMac($request)])->save();

        return back()->with('success', "Barang {$item->code} · {$item->name} ditambahkan");
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $data = $this->validateItem($request, $item);
        $tracksSerial = $this->tracksSerial($request, $data, $item);
        // A MAC is only scanned after an SN, so it is off for a counted item.
        $item->update($data + [
            'active' => $request->boolean('active'),
            'tracks_serial' => $tracksSerial,
            'scans_mac' => $tracksSerial && $this->scansMac($request),
        ] + $this->conversion($request, $item));

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
     * A category that requires SN always tracks it. An item with counted stock may switch to SN: that stock stays
     * as "stok lama tanpa SN", still used by quantity, while new stock comes in by SN. Switching back to counted
     * is refused while units with an SN are still in a warehouse.
     */
    private function tracksSerial(Request $request, array $data, Item $item): bool
    {
        $category = ItemCategory::query()->find($data['item_category_id'] ?? null);
        $wanted = $category?->requires_serial || $this->identifier($request) !== 'JUMLAH';
        if (! $item->exists || $wanted === $item->tracks_serial) {
            return $wanted;
        }
        if (! $wanted && $item->serialUnits()->whereNotNull('warehouse_id')->exists()) {
            throw ValidationException::withMessages(['tracks_serial' => "{$item->code} masih punya unit ber-SN di gudang, tidak bisa diubah menjadi barang tanpa SN"]);
        }

        return $wanted;
    }

    /**
     * "Kirim ke teknisi sebagai": sending one unit of this item hands the technician `send_ratio` of another
     * counted item (1 roll of dropcore → 1000 meter). Leaving the target empty turns the conversion off.
     * A changed ratio only applies to later sends; what technicians already hold stays as it is.
     *
     * @return array{send_as_item_id: ?int, send_ratio: ?int}
     */
    private function conversion(Request $request, Item $item): array
    {
        $data = $request->validate([
            'send_as_item_id' => ['nullable', Rule::exists('items', 'id')->where('tracks_serial', false)],
            'send_ratio' => ['nullable', 'required_with:send_as_item_id', 'integer', 'min:1', 'max:1000000'],
        ], [
            'send_as_item_id.exists' => 'Barang hasil konversi harus barang tanpa SN',
            'send_ratio.required_with' => 'Isi berapa satuan yang diterima teknisi per 1 '.$item->unit,
        ], ['send_as_item_id' => 'barang hasil konversi', 'send_ratio' => 'rasio konversi']);

        $target = $data['send_as_item_id'] ?? null;
        if (! $target) {
            return ['send_as_item_id' => null, 'send_ratio' => null];
        }
        if ((int) $target === $item->id) {
            throw ValidationException::withMessages(['send_as_item_id' => 'Barang tidak bisa dikonversi menjadi dirinya sendiri']);
        }

        return ['send_as_item_id' => (int) $target, 'send_ratio' => (int) $data['send_ratio']];
    }

    /**
     * "Penanda unit" on the form: JUMLAH (counted), SN, or SN_MAC. Older callers send the tracks_serial and
     * scans_mac ticks instead.
     */
    private function identifier(Request $request): string
    {
        $identifier = (string) $request->input('identifier');
        if (in_array($identifier, ['JUMLAH', 'SN', 'SN_MAC'], true)) {
            return $identifier;
        }

        return ! $request->boolean('tracks_serial') ? 'JUMLAH' : ($request->boolean('scans_mac') ? 'SN_MAC' : 'SN');
    }

    private function scansMac(Request $request): bool
    {
        $identifier = $this->identifier($request);

        // A category that forces SN on a "jumlah" pick keeps the MAC tick as sent.
        return $identifier === 'SN_MAC' || ($identifier === 'JUMLAH' && $request->boolean('scans_mac'));
    }

    /**
     * A new item without a code takes the next one of its category's prefix (MDM → MDM083). Jenis and merk
     * reuse the spelling already in use, so "fiberhome" joins "Fiberhome" instead of starting a second group.
     */
    private function validateItem(Request $request, ?Item $item = null): array
    {
        $categoryId = $request->input('item_category_id') ?: null;
        $code = strtoupper(trim((string) $request->input('code')));
        if ($code === '' && ! $item) {
            $prefix = ItemCategory::query()->whereKey($categoryId)->value('code_prefix');
            $code = $prefix ? Item::nextCode($prefix) : '';
        }
        $same = fn (string $column, ?string $value) => ($value = trim((string) $value)) === ''
            ? null
            : (Item::query()->whereRaw("lower({$column}) = ?", [mb_strtolower($value)])->value($column) ?? $value);
        $request->merge([
            'code' => $code,
            'item_category_id' => $categoryId,
            'min_stock' => $request->input('min_stock') ?: 0,
            'variant' => $same('variant', $request->input('variant')),
            'brand' => $same('brand', $request->input('brand')),
            'kind' => $request->input('kind') ?: (ItemCategory::query()->find($categoryId)?->kind?->value ?? $item?->kind?->value ?? ItemKind::HABIS_PAKAI->value),
        ]);

        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('items', 'code')->ignore($item)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'item_category_id' => ['nullable', Rule::exists('item_categories', 'id')],
            'variant' => ['nullable', 'string', 'max:60'],
            'brand' => ['nullable', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(ItemKind::class)],
            'min_stock' => ['integer', 'min:0', 'max:1000000'],
        ], ['code.required' => 'Isi kode barang, atau atur awalan kode di kategorinya supaya terisi otomatis'], [
            'code' => 'kode', 'name' => 'nama barang', 'unit' => 'satuan', 'item_category_id' => 'kategori',
            'variant' => 'jenis', 'brand' => 'merk', 'kind' => 'sifat barang', 'min_stock' => 'stok minimum',
        ]);
    }
}
