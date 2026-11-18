<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Exports\SalesExport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Partner;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Selling equipment from the main warehouse to partners (mitra) or other buyers, and the log of those sales.
 */
class SaleController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $monthStart = now()->startOfMonth();

        return view('sales.index', [
            'filters' => $filters,
            'partners' => Partner::query()->orderBy('name')->get(),
            'sales' => $this->filtered($filters)->with(['partner', 'user'])->withSum('lines', 'quantity')
                ->latest('sold_at')->latest('id')->paginate(25)->withQueryString(),
            'stats' => [
                'monthTotal' => (int) Sale::query()->where('sold_at', '>=', $monthStart)->sum('total'),
                'monthCount' => Sale::query()->where('sold_at', '>=', $monthStart)->count(),
                'unpaidTotal' => (int) Sale::query()->unpaid()->sum('total'),
                'unpaidCount' => Sale::query()->unpaid()->count(),
                'filteredTotal' => (int) $this->filtered($filters)->sum('total'),
            ],
        ]);
    }

    public function create(): View
    {
        $main = Warehouse::main();
        $order = ItemCategory::query()->pluck('sort_order', 'id');
        // The price each item last sold for, so a repeat sale starts from it.
        $lastPrices = SaleLine::query()->whereIn('id', SaleLine::query()->selectRaw('max(id)')->groupBy('item_id'))->pluck('unit_price', 'item_id');

        $catalog = Item::query()->where('active', true)->with('category')
            ->addSelect(['items.*', 'held' => Stock::query()->select('quantity')->whereColumn('stocks.item_id', 'items.id')->where('stocks.warehouse_id', $main->id)->limit(1)])
            ->get()
            ->filter(fn (Item $item) => (int) $item->held > 0)
            ->sortBy(fn (Item $item) => sprintf('%05d %s', $order[$item->item_category_id] ?? 99999, $item->code))
            ->map(fn (Item $item) => [
                'code' => $item->code,
                'name' => $item->name,
                'unit' => $item->unit,
                'serial' => $item->tracks_serial,
                'category' => $item->category?->name ?? 'Tanpa kategori',
                'held' => (int) $item->held,
                'price' => $lastPrices[$item->id] ?? null,
            ])->values();

        return view('sales.create', [
            'main' => $main,
            'catalog' => $catalog,
            'partners' => Partner::query()->active()->orderBy('name')->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'buyer_type' => ['required', Rule::in(['mitra', 'lain'])],
            'partner_id' => ['nullable', 'required_if:buyer_type,mitra', 'integer'],
            'buyer_name' => ['nullable', 'required_if:buyer_type,lain', 'string', 'max:150'],
            'buyer_phone' => ['nullable', 'string', 'max:30'],
            'sold_at' => ['required', 'date', 'before_or_equal:today'],
            'paid' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.item' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'lines.*.serials' => ['nullable', 'array', 'max:500'],
            'lines.*.serials.*' => ['string', 'max:100'],
        ], [
            'lines.required' => 'Tambahkan minimal satu barang',
            'partner_id.required_if' => 'Pilih mitra',
            'buyer_name.required_if' => 'Isi nama pembeli',
            'lines.*.price.required' => 'Isi harga jual tiap barang',
        ], ['sold_at' => 'tanggal jual', 'buyer_name' => 'nama pembeli']);

        $sale = $this->inventory->sell([
            'partner_id' => $data['buyer_type'] === 'mitra' ? $data['partner_id'] : null,
            'buyer_name' => $data['buyer_type'] === 'lain' ? $data['buyer_name'] : null,
            'buyer_phone' => $data['buyer_type'] === 'lain' ? ($data['buyer_phone'] ?? null) : null,
            'sold_at' => $data['sold_at'],
            'paid' => $data['paid'],
            'notes' => $data['notes'] ?? null,
        ], $data['lines'], $request->user());

        return redirect()->route('sales.show', $sale)
            ->with('success', "Penjualan {$sale->sale_number} ke {$sale->buyer_name} tersimpan, stok Gudang Utama sudah dikurangi");
    }

    public function show(Sale $sale): View
    {
        return view('sales.show', [
            'sale' => $sale->load(['partner', 'user', 'payer', 'lines.item', 'serialUnits']),
        ]);
    }

    public function payment(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::enum(PaymentStatus::class)],
            'payment_notes' => ['nullable', 'string', 'max:255'],
        ]);
        $status = PaymentStatus::from($data['payment_status']);
        $this->inventory->setPayment($sale, $status, $request->user(), $data['payment_notes'] ?? null);

        return back()->with('success', "{$sale->sale_number} ditandai {$status->label()}");
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $sales = $this->filtered($filters)->with(['lines.item', 'serialUnits', 'user'])->oldest('sold_at')->oldest('id')->get();

        return Excel::download(new SalesExport($sales), 'penjualan-gudang-'.now()->format('Ymd-His').'.xlsx');
    }

    /**
     * @return array{q: string, partner: string, status: string, from: ?string, to: ?string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key))->toDateString() : null, null, false);

        return [
            'q' => trim((string) $request->query('q', '')),
            'partner' => (string) $request->query('mitra', ''),
            'status' => PaymentStatus::tryFrom((string) $request->query('status'))?->value ?? '',
            'from' => $date('dari'),
            'to' => $date('sampai'),
        ];
    }

    private function filtered(array $filters): Builder
    {
        return Sale::query()
            ->when($filters['q'] !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('sale_number', 'like', "%{$filters['q']}%")
                ->orWhere('buyer_name', 'like', "%{$filters['q']}%")
                ->orWhereHas('serialUnits', fn (Builder $unit) => $unit->where('serial_number', 'like', '%'.strtoupper($filters['q']).'%'))
                ->orWhereHas('lines.item', fn (Builder $item) => $item->search($filters['q']))))
            ->when($filters['partner'] === 'lain', fn (Builder $query) => $query->whereNull('partner_id'))
            ->when(ctype_digit($filters['partner']), fn (Builder $query) => $query->where('partner_id', (int) $filters['partner']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('payment_status', $filters['status']))
            ->when($filters['from'], fn (Builder $query) => $query->whereDate('sold_at', '>=', $filters['from']))
            ->when($filters['to'], fn (Builder $query) => $query->whereDate('sold_at', '<=', $filters['to']));
    }
}
