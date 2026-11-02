<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Facades\Excel;

class ItemController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        return view('warehouses.items', [
            'search' => $search,
            'items' => Item::query()
                ->when($search !== '', fn ($query) => $query->search($search))
                ->withSum('stocks', 'quantity')
                ->orderBy('code')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateItem($request);
        Item::query()->create($data + ['tracks_serial' => $request->boolean('tracks_serial')]);

        return back()->with('success', "Barang {$data['code']} ditambahkan");
    }

    public function update(Request $request, Item $item): RedirectResponse
    {
        $item->update($this->validateItem($request, $item) + ['active' => $request->boolean('active'), 'tracks_serial' => $request->boolean('tracks_serial')]);

        return back()->with('success', "Barang {$item->code} diperbarui");
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

    private function validateItem(Request $request, ?Item $item = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('items', 'code')->ignore($item)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
        ], [], ['code' => 'kode', 'name' => 'nama barang', 'unit' => 'satuan']);
    }
}
