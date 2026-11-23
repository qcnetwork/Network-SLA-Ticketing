<?php

namespace App\Http\Controllers;

use App\Enums\Menu;
use App\Enums\Role;
use App\Enums\UsagePurpose;
use App\Exports\MaterialUsageExport;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaterialUsage;
use App\Models\MaterialUsageLine;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pemakaian Alat: where each piece of material went, when, and what for. The warehouse records any job;
 * a technician records jobs from their own stock. Material approved from a ticket's resume shows up here too.
 */
class MaterialUsageController extends Controller
{
    public function __construct(private InventoryService $inventory) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $monthStart = now()->startOfMonth();
        $thisMonth = MaterialUsage::query()->visibleTo($user)->active()->where('used_at', '>=', $monthStart);

        return view('usages.index', [
            'filters' => $filters,
            'usages' => $this->filtered($filters, $user)
                ->with(['lines.warehouse', 'user', 'ticket'])
                ->latest('used_at')->latest('id')->paginate(25)->withQueryString(),
            'warehouses' => $this->canManage($user) ? Warehouse::query()->with('user')->orderByRaw('user_id is not null')->orderBy('name')->get() : collect(),
            'stats' => [
                'jobs' => (clone $thisMonth)->count(),
                'units' => (int) MaterialUsageLine::query()->whereIn('material_usage_id', (clone $thisMonth)->select('id'))->sum('quantity'),
                'devices' => MaterialUsageLine::query()->whereIn('material_usage_id', (clone $thisMonth)->select('id'))->whereNotNull('serial_number')->count(),
                'byPurpose' => (clone $thisMonth)->selectRaw('purpose, count(*) as total')->groupBy('purpose')->pluck('total', 'purpose'),
            ],
            'canRecord' => $this->canRecord($user),
            'canManage' => $this->canManage($user),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->canRecord($user), 403);
        $sources = $this->sources($user);

        return view('usages.create', [
            'sources' => $sources,
            'catalogs' => $sources->mapWithKeys(fn (Warehouse $warehouse) => [$warehouse->id => $this->catalog($warehouse)]),
            'technicians' => User::technicians()->pluck('name'),
            'projects' => MaterialUsage::query()->whereNotNull('project')->whereNull('ticket_id')->latest('id')->limit(300)->pluck('project')->unique()->take(30)->values(),
            'canManage' => $this->canManage($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canRecord($user), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'used_at' => ['required', 'date', 'before_or_equal:today'],
            'purpose' => ['required', Rule::enum(UsagePurpose::class)],
            'project' => ['nullable', 'string', 'max:100'],
            'location' => ['required', 'string', 'max:255'],
            'team' => ['nullable', 'string', 'max:150'],
            'ticket_number' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:50'],
            'removed_device' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:100'],
            'lines.*.item' => ['required', 'string', 'max:200'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.serials' => ['nullable', 'array', 'max:500'],
            'lines.*.serials.*' => ['string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ], ['lines.required' => 'Tambahkan minimal satu barang', 'location.required' => 'Isi lokasi pemasangan'], [
            'used_at' => 'tanggal pemakaian', 'purpose' => 'keperluan', 'warehouse_id' => 'stok dari',
        ]);

        // A technician only ever draws on their own stock.
        $source = $this->sources($user)->firstWhere('id', (int) $data['warehouse_id']);
        abort_unless($source, 403);
        $ticket = null;
        if (filled($data['ticket_number'] ?? null)) {
            $ticket = Ticket::query()->where('ticket_number', strtoupper(trim($data['ticket_number'])))->first()
                ?? throw ValidationException::withMessages(['ticket_number' => 'Nomor tiket tidak ditemukan']);
        }
        // A customer ID must be one in the customer directory, spelled as it is there.
        if (filled($data['customer_code'] ?? null)) {
            $data['customer_code'] = Customer::query()->withCode($data['customer_code'])->value('code')
                ?? throw ValidationException::withMessages(['customer_code' => "ID pelanggan {$data['customer_code']} tidak ada di data pelanggan"]);
        }

        $usage = $this->inventory->recordUsage([...$data, 'ticket_id' => $ticket?->id], $data['lines'], $source, $user);

        return redirect()->route('usages.show', $usage)
            ->with('success', "Pemakaian di {$usage->location} tercatat, stok {$source->name} sudah dikurangi");
    }

    public function show(Request $request, MaterialUsage $usage): View
    {
        abort_unless(MaterialUsage::query()->visibleTo($request->user())->whereKey($usage->id)->exists(), 403);

        return view('usages.show', [
            'usage' => $usage->load(['lines.item', 'lines.warehouse', 'user', 'ticket', 'canceller']),
            'canManage' => $this->canManage($request->user()),
        ]);
    }

    public function cancel(Request $request, MaterialUsage $usage): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:255']], ['cancel_reason.required' => 'Tulis alasan pembatalan']);
        $this->inventory->cancelUsage($usage, $request->user(), $data['cancel_reason']);

        return back()->with('success', $usage->isImported() ? 'Riwayat pemakaian ditandai batal' : 'Pemakaian dibatalkan, stok sudah dikembalikan ke gudang asalnya');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $usages = $this->filtered($this->filters($request), $request->user())
            ->with(['lines.warehouse', 'ticket'])->oldest('used_at')->oldest('id')->get();

        return Excel::download(new MaterialUsageExport($usages), 'pemakaian-alat-'.now()->format('Ymd-His').'.xlsx');
    }

    private function canManage(User $user): bool
    {
        return $user->canAccess(Menu::WAREHOUSE_MANAGE);
    }

    private function canRecord(User $user): bool
    {
        return $this->canManage($user) || $user->role === Role::TEKNISI;
    }

    /**
     * The warehouses this user may take stock from: every one for the warehouse, their own for a technician.
     *
     * @return Collection<int, Warehouse>
     */
    private function sources(User $user): Collection
    {
        if ($this->canManage($user)) {
            return Warehouse::query()->with('user')->orderByRaw('user_id is not null')->orderBy('name')->get()
                ->reject(fn (Warehouse $warehouse) => $warehouse->user && ! $warehouse->user->active)->values();
        }

        return collect([Warehouse::of($user)]);
    }

    private function catalog(Warehouse $warehouse): array
    {
        $order = ItemCategory::query()->pluck('sort_order', 'id');

        $rows = Item::query()->with('category')
            ->addSelect(['items.*', 'held' => Stock::query()->select('quantity')->whereColumn('stocks.item_id', 'items.id')->where('stocks.warehouse_id', $warehouse->id)->limit(1)])
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
            ])->values()->all();

        // Stock an SN item kept from before it switched to SN is used by quantity, as "(TANPA SN)".
        return $this->inventory->withLooseRows($rows, $warehouse);
    }

    /**
     * @return array{q: string, purpose: string, warehouse: string, from: ?string, to: ?string, status: string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key))->toDateString() : null, null, false);

        return [
            'q' => trim((string) $request->query('q', '')),
            'purpose' => UsagePurpose::tryFrom((string) $request->query('keperluan'))?->value ?? '',
            'warehouse' => ctype_digit((string) $request->query('stok')) ? (string) $request->query('stok') : '',
            'from' => $date('dari'),
            'to' => $date('sampai'),
            'status' => $request->query('status') === 'batal' ? 'batal' : 'aktif',
        ];
    }

    private function filtered(array $filters, User $user): Builder
    {
        $term = $filters['q'];

        return MaterialUsage::query()->visibleTo($user)
            ->when($filters['status'] === 'batal', fn (Builder $query) => $query->whereNotNull('cancelled_at'), fn (Builder $query) => $query->whereNull('cancelled_at'))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('location', 'like', "%{$term}%")
                ->orWhere('project', 'like', "%{$term}%")
                ->orWhere('team', 'like', "%{$term}%")
                ->orWhere('holder_name', 'like', "%{$term}%")
                ->orWhere('removed_device', 'like', "%{$term}%")
                ->orWhereHas('ticket', fn (Builder $ticket) => $ticket->where('ticket_number', 'like', "%{$term}%"))
                ->orWhereHas('lines', fn (Builder $line) => $line->where('item_name', 'like', "%{$term}%")
                    ->orWhere('serial_number', 'like', '%'.strtoupper($term).'%')
                    ->orWhere('mac', 'like', '%'.strtoupper(str_replace([':', '-'], '', $term)).'%'))))
            ->when($filters['purpose'] !== '', fn (Builder $query) => $query->where('purpose', $filters['purpose']))
            ->when($filters['warehouse'] !== '', fn (Builder $query) => $query->whereHas('lines', fn (Builder $line) => $line->where('warehouse_id', (int) $filters['warehouse'])))
            ->when($filters['from'], fn (Builder $query) => $query->whereDate('used_at', '>=', $filters['from']))
            ->when($filters['to'], fn (Builder $query) => $query->whereDate('used_at', '<=', $filters['to']));
    }
}
