<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Manual stock corrections, for the warehouse team and supervisors: set a warehouse's stock of an item to what
 * was actually counted. Stock gone minus is listed first. The log of every correction (before, after, who, why)
 * is shown to supervisors only.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $warehouses = Warehouse::query()->with('user')->get()
            ->sortBy(fn (Warehouse $w) => [$w->isMain() ? 0 : 1, $w->name])->values();
        $warehouse = $warehouses->firstWhere('id', (int) $request->query('gudang')) ?? $warehouses->first(fn (Warehouse $w) => $w->isMain());
        $isSupervisor = $request->user()->hasRole(Role::SPV);

        return view('stock-adjustments.index', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'catalog' => $warehouse ? $this->catalog($warehouse) : [],
            'selected' => (string) $request->query('barang', ''),
            'negatives' => Stock::query()->where('quantity', '<', 0)->with(['item', 'warehouse'])->orderBy('quantity')->get(),
            'isSupervisor' => $isSupervisor,
            'log' => $isSupervisor
                ? StockAdjustment::query()->with(['item', 'warehouse', 'user'])
                    ->when($request->filled('log_gudang'), fn ($q) => $q->where('warehouse_id', (int) $request->query('log_gudang')))
                    ->latest('id')->paginate(25, pageName: 'log')->withQueryString()
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'item' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'item.required' => 'Pilih barang yang mau di-adjust',
            'quantity.required' => 'Isi stok hasil hitung',
            'quantity.min' => 'Stok hasil hitung tidak boleh minus',
            'reason.required' => 'Isi alasan adjust stok',
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $item = $this->inventory->findItem($data['item'], 'item');
        $adjustment = $this->inventory->adjust($warehouse, $item, (int) $data['quantity'], $request->user(), $data['reason']);

        $sign = $adjustment->difference > 0 ? '+' : '−';

        return redirect()->route('stock-adjustments.index', ['gudang' => $warehouse->id])
            ->with('success', "Stok {$item->code} di {$warehouse->name} jadi {$adjustment->quantity_after} {$item->unit} ({$sign}".abs($adjustment->difference).')');
    }

    /**
     * Every active item with what this warehouse holds now (minus included), plus items it holds that are no
     * longer active, so stock gone wrong on an old item can still be fixed.
     */
    private function catalog(Warehouse $warehouse): array
    {
        $order = ItemCategory::query()->pluck('sort_order', 'id');
        $held = Stock::query()->where('warehouse_id', $warehouse->id)->pluck('quantity', 'item_id');
        $units = SerialUnit::query()->where('warehouse_id', $warehouse->id)->selectRaw('item_id, count(*) as n')->groupBy('item_id')->pluck('n', 'item_id');

        return Item::query()->with('category')
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $held->keys()))
            ->get()
            ->sortBy(fn (Item $item) => sprintf('%05d %s', $order[$item->item_category_id] ?? 99999, $item->code))
            ->map(fn (Item $item) => [
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'serial' => $item->tracks_serial,
                'units' => (int) ($units[$item->id] ?? 0),
                'category' => $item->category?->name ?? 'Tanpa kategori',
                'icon' => $item->category?->icon ?? 'package',
                'held' => (int) ($held[$item->id] ?? 0),
            ])->values()->all();
    }
}
