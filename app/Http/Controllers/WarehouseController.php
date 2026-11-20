<?php

namespace App\Http\Controllers;

use App\Enums\MaterialRequestStatus;
use App\Enums\Menu;
use App\Enums\Role;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    private const REQUEST_DETAILS = ['ticket', 'requester', 'lines.item', 'lines.warehouse', 'lines.serialUnit'];

    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        // A technician only has their own warehouse to look at.
        if ($user->role === Role::TEKNISI) {
            return redirect()->route('warehouses.show', Warehouse::of($user));
        }

        $main = Warehouse::main();

        return view('warehouses.index', [
            'main' => $main,
            'materialRequests' => MaterialRequest::query()->pending()->with(self::REQUEST_DETAILS)->oldest('id')->get(),
            ...$this->stockPage($request, $main),
            'technicianWarehouses' => Warehouse::query()->technicians()->with('user')
                ->withSum('stocks', 'quantity')->withCount(['stocks' => fn (Builder $q) => $q->where('quantity', '>', 0), 'serialUnits'])
                ->orderByDesc('stocks_sum_quantity')->orderBy('name')->get(),
            'technicians' => User::stockHolders()->get(),
            // Every active item with what the main warehouse holds, for the receive and send forms.
            'catalog' => $this->catalog(Item::query()->where('active', true)->addSelect(['held' => $this->heldIn($main)])->get(), $main),
            'lowStock' => Item::query()->where('active', true)->where('min_stock', '>', 0)->with('category')
                ->addSelect(['items.*', 'held' => $this->heldIn($main)])->orderBy('code')->get()
                ->filter(fn (Item $item) => $item->isLow((int) $item->held))->values(),
            // Searching an SN or MAC shows where that unit is now, wherever it is.
            'serialMatches' => $this->serialMatches($request),
            'canManage' => $user->canAccess(Menu::WAREHOUSE_MANAGE),
        ]);
    }

    public function show(Request $request, Warehouse $warehouse): View|RedirectResponse
    {
        $user = $request->user();
        abort_if($user->role === Role::TEKNISI && $warehouse->user_id !== $user->id, 403);
        if ($warehouse->isMain()) {
            return redirect()->route('warehouses.index');
        }

        return view('warehouses.show', [
            'warehouse' => $warehouse->load('user'),
            'materialRequests' => MaterialRequest::query()->pending()->whereHas('lines', fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->with(self::REQUEST_DETAILS)->oldest('id')->get(),
            // Tickets of this technician whose material the warehouse turned down, waiting to be sent again.
            'rejectedTickets' => $warehouse->user
                ? Ticket::query()->assignedTo($warehouse->user)
                    ->whereHas('materialRequests', fn ($q) => $q->where('status', MaterialRequestStatus::REJECTED))
                    ->with('materialRequests.reviewer')->latest('updated_at')->get()
                    ->filter->awaitsMaterialResubmission()->values()
                : collect(),
            ...$this->stockPage($request, $warehouse),
            // What the technician holds, for the return form.
            'catalog' => $this->catalog(Item::query()->whereHas('stocks', fn ($q) => $q->where('warehouse_id', $warehouse->id)->where('quantity', '>', 0))
                ->addSelect(['items.*', 'held' => $this->heldIn($warehouse)])->get(), $warehouse),
            'canManage' => $user->canAccess(Menu::WAREHOUSE_MANAGE),
        ]);
    }

    /**
     * One category's stock in a warehouse, a level at a time: jenis (ONT GPON / EPON / XPON), then merk, then the
     * models with their stock and SNs. A level nobody filled in is skipped; items without one sit under
     * "Belum ditentukan" (query value "-"). `semua` lists every model of the current level at once.
     */
    public function category(Request $request, Warehouse $warehouse, ItemCategory $category): View
    {
        $user = $request->user();
        abort_if($user->role === Role::TEKNISI && $warehouse->user_id !== $user->id, 403);
        $variant = $request->query('jenis');
        $brand = $request->query('merk');
        $matches = fn (?string $value, ?string $wanted) => $wanted === null || ($wanted === '-' ? $value === null : $value === $wanted);

        $stocks = $warehouse->stocks()->where('quantity', '>', 0)
            ->whereHas('item', fn ($q) => $q->where('item_category_id', $category->id))
            ->with(['item.category', 'item.sendAs'])->get()
            ->filter(fn (Stock $stock) => $matches($stock->item->variant, $variant) && $matches($stock->item->brand, $brand))
            ->sortBy('item.code')->values();

        $level = match (true) {
            $request->boolean('semua') => 'model',
            $variant === null && $stocks->contains(fn (Stock $stock) => $stock->item->variant !== null) => 'jenis',
            $brand === null && $stocks->contains(fn (Stock $stock) => $stock->item->brand !== null) => 'merk',
            default => 'model',
        };
        $groups = $level === 'model' ? collect() : $stocks
            ->groupBy(fn (Stock $stock) => ($level === 'jenis' ? $stock->item->variant : $stock->item->brand) ?? '-')
            ->map(fn ($rows, $key) => [
                'key' => (string) $key,
                'models' => $rows->count(),
                'units' => (int) $rows->sum('quantity'),
                'brands' => $rows->pluck('item.brand')->filter()->unique()->count(),
                'unit' => $rows->pluck('item.unit')->unique()->count() === 1 ? $rows->first()->item->unit : 'unit',
            ])
            // Named groups A-Z, "Belum ditentukan" last.
            ->sortBy(fn (array $group) => ($group['key'] === '-' ? '1' : '0').$group['key'])->values();

        return view('warehouses.category', [
            'warehouse' => $warehouse->load('user'),
            'category' => $category,
            'variant' => $variant,
            'brand' => $brand,
            'level' => $level,
            'groups' => $groups,
            'stocks' => $stocks,
            'total' => (int) $stocks->sum('quantity'),
            'units' => $level === 'model'
                ? $warehouse->serialUnits()->whereIn('item_id', $stocks->pluck('item_id'))->orderBy('serial_number')->get()->groupBy('item_id')
                : collect(),
            'canManage' => $user->canAccess(Menu::WAREHOUSE_MANAGE),
        ]);
    }

    public function receiveSerials(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'max:200'],
            'serials' => ['required', 'array', 'max:500'],
            'serials.*.sn' => ['nullable', 'string', 'max:100'],
            'serials.*.mac' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['serials.required' => 'Scan minimal satu SN'], ['item' => 'barang']);
        $item = $this->inventory->findItem($data['item'], 'item');
        $count = $this->inventory->receiveSerials($item, $data['serials'], $request->user(), $data['notes'] ?? null);

        return back()->with('success', "{$count} unit {$item->code} masuk Gudang Utama");
    }

    public function sendSerials(Request $request): RedirectResponse
    {
        $data = $this->validateSerials($request, [
            'technician_id' => ['required', Rule::exists('users', 'id')->whereIn('role', array_map(fn (Role $role) => $role->value, Role::stockHolders()))->where('active', true)],
        ]);
        $technician = User::query()->findOrFail($data['technician_id']);
        $count = $this->inventory->sendSerials(array_column($data['serials'], 'sn'), $technician, $request->user(), $data['notes'] ?? null);

        return back()->with('success', "{$count} unit dikirim ke {$technician->name}");
    }

    public function returnSerials(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $this->validateSerials($request);
        $count = $this->inventory->returnSerials(array_column($data['serials'], 'sn'), $warehouse, $request->user(), $data['notes'] ?? null);

        return back()->with('success', "{$count} unit dikembalikan ke Gudang Utama");
    }

    /**
     * Correct a unit's SN or MAC typed or scanned wrong (or a MAC never scanned), for any SN-tracked item.
     * The unit keeps its place and history; only its labels change. An SN or MAC another unit already has is refused.
     */
    public function updateSerial(Request $request, SerialUnit $serialUnit): RedirectResponse
    {
        $data = $request->validate([
            'serial_number' => ['required', 'string', 'max:100'],
            'mac' => ['nullable', 'string', 'max:30'],
        ], [], ['serial_number' => 'SN', 'mac' => 'MAC']);

        $number = SerialUnit::normalizeSerial($data['serial_number']);
        $macRaw = trim((string) ($data['mac'] ?? ''));
        $mac = SerialUnit::normalizeMac($macRaw);
        $fail = fn (string $message) => back()->withErrors(['serial_edit' => $message])->withInput();
        if ($number === '') {
            return $fail('SN tidak boleh kosong');
        }
        if ($macRaw !== '' && $mac === null) {
            return $fail("MAC {$macRaw} tidak valid, harus 12 digit hex (mis. 28:6D:DA:0C:D4:60)");
        }
        $taken = SerialUnit::query()->whereKeyNot($serialUnit->id)->with('item')
            ->where(fn ($q) => $q->where('serial_number', $number)->when($mac, fn ($q) => $q->orWhere('mac', $mac)))->first();
        if ($taken) {
            return $fail($taken->serial_number === $number
                ? "SN {$number} sudah dipakai unit lain ({$taken->item->code}, {$taken->location()})"
                : "MAC {$taken->formattedMac()} sudah dipakai SN {$taken->serial_number} ({$taken->item->code})");
        }

        $old = $serialUnit->serial_number;
        $serialUnit->update(['serial_number' => $number, 'mac' => $mac]);

        return back()->with('success', $old === $number ? "SN {$number} diperbarui" : "SN {$old} diubah menjadi {$number}");
    }

    /**
     * The SN units of one item sitting in a warehouse (the main one unless given), oldest first, so the
     * warehouse can tick which to send instead of scanning each. Units a pending resume claims are left out.
     */
    public function availableSerials(Request $request): JsonResponse
    {
        $item = $this->inventory->findItem((string) $request->query('item', ''));
        $warehouse = $request->filled('warehouse') ? Warehouse::query()->findOrFail((int) $request->query('warehouse')) : Warehouse::main();

        return response()->json($item->serialUnits()
            ->where('warehouse_id', $warehouse->id)
            ->whereNotIn('id', MaterialRequestLine::query()->pending()->whereNotNull('serial_unit_id')->select('serial_unit_id'))
            ->orderBy('id')->get(['serial_number', 'mac'])
            ->map(fn (SerialUnit $unit) => ['sn' => $unit->serial_number, 'mac' => $unit->formattedMac()]));
    }

    /**
     * Checks one scan as it happens, so a wrong unit is caught at the scanner rather than on save.
     * With `new`, the SN must not be registered yet; with `warehouse`, the unit must be sitting there.
     */
    public function lookupSerial(Request $request): JsonResponse
    {
        // Outside the warehouse team, a scan is only checked against one's own stock.
        if (! $request->user()->canAccess(Menu::WAREHOUSE_MANAGE)) {
            $own = Warehouse::query()->where('user_id', $request->user()->id)->value('id');
            abort_unless($own && (int) $request->query('warehouse') === $own && ! $request->boolean('new'), 403);
        }
        $number = SerialUnit::normalizeSerial($request->query('sn'));
        $serial = SerialUnit::query()->with(['item', 'warehouse', 'ticket'])->where('serial_number', $number)->first();

        if ($request->boolean('new')) {
            return $serial
                ? response()->json(['message' => "SN {$number} sudah terdaftar ({$serial->item->code}, {$serial->location()})"], 422)
                : response()->json(['sn' => $number]);
        }
        if (! $serial) {
            return response()->json(['message' => "SN {$number} tidak terdaftar"], 422);
        }
        if ($request->filled('warehouse') && $serial->warehouse_id !== (int) $request->query('warehouse')) {
            return response()->json(['message' => "SN {$number} tidak ada di gudang ini (posisi: {$serial->location()})"], 422);
        }

        return response()->json([
            'sn' => $serial->serial_number,
            'mac' => $serial->formattedMac(),
            'item' => $serial->item->label(),
        ]);
    }

    public function approveRequest(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:255']]);
        $this->inventory->approveRequest($materialRequest, $request->user(), $data['review_notes'] ?? null);

        return back()->with('success', "Material tiket {$materialRequest->ticket->ticket_number} disetujui, stok teknisi sudah dikurangi");
    }

    public function rejectRequest(Request $request, MaterialRequest $materialRequest): RedirectResponse
    {
        $data = $request->validate(['review_notes' => ['required', 'string', 'max:255']], ['review_notes.required' => 'Tulis alasan penolakan']);
        $this->inventory->rejectRequest($materialRequest, $request->user(), $data['review_notes']);

        return back()->with('success', "Material tiket {$materialRequest->ticket->ticket_number} ditolak, stok teknisi tidak berubah");
    }

    public function receive(Request $request): RedirectResponse
    {
        $data = $this->validateMovement($request);
        $item = $this->inventory->findItem($data['item'], 'item');
        $this->inventory->receive($item, $data['quantity'], $request->user(), $data['notes'] ?? null);

        return back()->with('success', "{$item->code} masuk Gudang Utama sebanyak {$data['quantity']} {$item->unit}");
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $this->validateMovement($request, [
            'technician_id' => ['required', Rule::exists('users', 'id')->whereIn('role', array_map(fn (Role $role) => $role->value, Role::stockHolders()))->where('active', true)],
        ]);
        $item = $this->inventory->findItem($data['item'], 'item');
        $technician = User::query()->findOrFail($data['technician_id']);
        $this->inventory->send($item, $data['quantity'], $technician, $request->user(), $data['notes'] ?? null);
        $converted = $item->convertsOnSend() ? " (diterima sebagai {$item->sendAs->code} × ".($data['quantity'] * $item->send_ratio)." {$item->sendAs->unit})" : '';

        return back()->with('success', "{$item->code} × {$data['quantity']} {$item->unit} dikirim ke {$technician->name}{$converted}");
    }

    public function returnStock(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $data = $this->validateMovement($request);
        $item = $this->inventory->findItem($data['item'], 'item');
        $this->inventory->returnToMain($item, $data['quantity'], $warehouse, $request->user(), $data['notes'] ?? null);

        return back()->with('success', "{$item->code} × {$data['quantity']} dikembalikan ke Gudang Utama");
    }

    /**
     * The warehouse's stock (searchable, filtered by category), how much of each category it holds, and its latest movements.
     */
    private function stockPage(Request $request, Warehouse $warehouse): array
    {
        $search = trim((string) $request->query('q', ''));
        $category = (string) $request->query('kategori', '');
        $byCategory = $warehouse->stocks()->where('quantity', '>', 0)->join('items', 'items.id', '=', 'stocks.item_id')
            ->selectRaw('items.item_category_id as category_id, count(*) as kinds, sum(stocks.quantity) as total')
            ->groupBy('items.item_category_id')->get()
            ->keyBy(fn ($row) => $row->category_id ?? 'none');

        return [
            'search' => $search,
            'categoryFilter' => $category,
            'categories' => ItemCategory::query()->ordered()->get(),
            'byCategory' => $byCategory,
            'summary' => [
                'kinds' => (int) $byCategory->sum('kinds'),
                'units' => (int) $byCategory->sum('total'),
                'serials' => $warehouse->serialUnits()->count(),
            ],
            'stocks' => $warehouse->stocks()->with('item.category')
                ->where('quantity', '>', 0)
                ->when($search !== '', fn ($q) => $q->whereHas('item', fn ($item) => $item->search($search)
                    ->orWhereHas('serialUnits', fn ($unit) => $unit->where('warehouse_id', $warehouse->id)->where('serial_number', 'like', '%'.SerialUnit::normalizeSerial($search).'%'))))
                ->join('items', 'items.id', '=', 'stocks.item_id')
                ->when($category === 'none', fn ($q) => $q->whereNull('items.item_category_id'))
                ->when(ctype_digit($category), fn ($q) => $q->where('items.item_category_id', (int) $category))
                ->orderBy('items.code')->select('stocks.*')
                ->paginate(20, pageName: 'stok')->withQueryString(),
            'units' => $warehouse->serialUnits()->with('item')->orderBy('item_id')->orderBy('serial_number')->get()->groupBy('item_id'),
            'movements' => StockMovement::query()
                ->with(['item', 'serialUnit', 'fromWarehouse', 'toWarehouse', 'ticket', 'sale', 'user'])
                ->where(fn ($q) => $q->where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id))
                ->latest('id')->limit(30)->get(),
        ];
    }

    /**
     * How many of an item a warehouse holds, as a subquery column.
     */
    private function heldIn(Warehouse $warehouse): Builder
    {
        return Stock::query()->select('quantity')->whereColumn('stocks.item_id', 'items.id')->where('stocks.warehouse_id', $warehouse->id)->limit(1);
    }

    /**
     * Items as the stock forms pick them: by category, then code.
     *
     * @param  Collection<int, Item>  $items
     */
    private function catalog(Collection $items, Warehouse $warehouse): array
    {
        $order = ItemCategory::query()->pluck('sort_order', 'id');

        // An SN item's stock kept from before it switched to SN moves by quantity, as its own "(TANPA SN)" pick.
        return $this->inventory->withLooseRows($items->load(['category', 'sendAs'])
            ->sortBy(fn (Item $item) => sprintf('%05d %s', $order[$item->item_category_id] ?? 99999, $item->code))
            ->map(fn (Item $item) => [
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                // Sending this item hands the technician `ratio` of the other item per unit.
                'converts' => $item->convertsOnSend() && $item->sendAs ? ['ratio' => $item->send_ratio, 'unit' => $item->sendAs->unit, 'code' => $item->sendAs->code] : null,
                'serial' => $item->tracks_serial,
                // Whether a scan asks for the MAC label too (modems yes, dropcore rolls and poles no).
                'mac' => $item->tracks_serial && $item->scans_mac,
                'category' => $item->category?->name ?? 'Tanpa kategori',
                'icon' => $item->category?->icon ?? 'package',
                'held' => (int) $item->held,
            ])->values()->all(), $warehouse);
    }

    private function serialMatches(Request $request)
    {
        $search = SerialUnit::normalizeSerial($request->query('q'));
        if (strlen($search) < 4) {
            return collect();
        }

        return SerialUnit::query()->with(['item', 'warehouse', 'ticket', 'sale'])
            ->where(fn ($q) => $q->where('serial_number', 'like', "%{$search}%")->orWhere('mac', 'like', '%'.str_replace([':', '-'], '', $search).'%'))
            ->limit(20)->get();
    }

    private function validateSerials(Request $request, array $extra = []): array
    {
        return $request->validate([
            'serials' => ['required', 'array', 'max:500'],
            'serials.*.sn' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
            ...$extra,
        ], ['serials.required' => 'Scan minimal satu SN'], ['technician_id' => 'teknisi']);
    }

    private function validateMovement(Request $request, array $extra = []): array
    {
        return $request->validate([
            'item' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:255'],
            ...$extra,
        ], [], ['item' => 'barang', 'quantity' => 'jumlah', 'technician_id' => 'teknisi']);
    }
}
