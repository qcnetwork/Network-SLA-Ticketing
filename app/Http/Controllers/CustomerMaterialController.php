<?php

namespace App\Http\Controllers;

use App\Enums\Menu;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialLog;
use App\Models\SerialUnit;
use App\Models\StockMovement;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Gudang Pelanggan: the material at each customer, by customer ID, with its own log. Material gets here when the
 * warehouse approves a ticket's resume or a Pemakaian Alat entry names the customer; it leaves when removed.
 */
class CustomerMaterialController extends Controller
{
    public function index(Request $request): View
    {
        abort_if($request->user()->role === Role::TEKNISI, 403);
        $search = trim((string) $request->query('q', ''));

        // One row per customer holding material that matches, newest install first.
        $customers = CustomerMaterial::query()->installed()
            ->when($search !== '', fn (Builder $query) => $this->matching($query, $search))
            ->select('customer_code')
            ->selectRaw('count(*) as items, sum(case when serial_unit_id is not null then 1 else 0 end) as units, max(installed_at) as last_installed')
            ->groupBy('customer_code')
            ->orderByDesc('last_installed')
            ->paginate(25)->withQueryString();
        $directory = Customer::query()->whereIn('code', $customers->pluck('customer_code'))->get()->keyBy(fn (Customer $customer) => mb_strtolower($customer->code));
        // Each listed customer's modems / devices, to show beside the name.
        $devices = CustomerMaterial::query()->installed()->whereNotNull('serial_number')->whereIn('customer_code', $customers->pluck('customer_code'))
            ->get(['customer_code', 'item_name', 'serial_number'])->groupBy(fn ($material) => mb_strtolower($material->customer_code));
        $logs = CustomerMaterialLog::query()->with(['user', 'ticket'])->latest('happened_at')->latest('id')->limit(30)->get();

        return view('customer-materials.index', [
            'search' => $search,
            'customers' => $customers,
            'directory' => $directory,
            'devices' => $devices,
            'logs' => $logs,
            'stats' => [
                'customers' => CustomerMaterial::query()->installed()->distinct()->count('customer_code'),
                'items' => CustomerMaterial::query()->installed()->count(),
                'units' => CustomerMaterial::query()->installed()->whereNotNull('serial_unit_id')->count(),
                'removed' => CustomerMaterial::query()->whereNotNull('removed_at')->where('removed_at', '>=', now()->startOfMonth())->count(),
            ],
            // Names for the log, which only keeps the ID.
            'names' => Customer::query()->whereIn('code', $logs->pluck('customer_code')->unique())
                ->pluck('name', 'code')->mapWithKeys(fn ($name, $code) => [mb_strtolower($code) => $name]),
        ]);
    }

    /**
     * One customer's material: what is installed now (each SN with the nodes it travelled), what was removed,
     * and the full log.
     */
    public function show(Request $request, string $code): View
    {
        abort_if($request->user()->role === Role::TEKNISI, 403);
        $customer = Customer::query()->withCode($code)->first();
        $materials = CustomerMaterial::query()->ofCustomer($code)
            ->with(['item.category', 'serialUnit', 'warehouse.user', 'ticket', 'usage'])
            ->orderByRaw('case when removed_at is null then 0 else 1 end')->orderByDesc('installed_at')->orderByDesc('id')->get();
        abort_if(! $customer && $materials->isEmpty(), 404);

        // Every SN's moves in one query, for the nodes.
        $moves = StockMovement::query()->whereIn('serial_unit_id', $materials->pluck('serial_unit_id')->filter())
            ->with(['fromWarehouse.user', 'toWarehouse.user', 'usage', 'ticket', 'sale'])->orderBy('created_at')->orderBy('id')->get()->groupBy('serial_unit_id');
        $journeys = $materials->mapWithKeys(fn (CustomerMaterial $material) => [$material->id => $material->serialUnit
            ? $material->serialUnit->journey($moves->get($material->serial_unit_id, collect()))
            : $this->countedJourney($material, $customer)]);

        return view('customer-materials.show', [
            'code' => $customer?->code ?? $materials->first()->customer_code,
            'customer' => $customer,
            'installed' => $materials->filter->isInstalled()->values(),
            'removed' => $materials->reject->isInstalled()->values(),
            'journeys' => $journeys,
            'logs' => CustomerMaterialLog::query()->whereRaw('lower(customer_code) = ?', [mb_strtolower($code)])
                ->with(['user', 'ticket', 'usage'])->latest('happened_at')->latest('id')->get(),
            'canManage' => $request->user()->canAccess(Menu::WAREHOUSE_MANAGE),
        ]);
    }

    /**
     * Take material off the customer: back to the office (into Gudang Utama) or written off.
     */
    public function remove(Request $request, CustomerMaterial $customerMaterial, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
            'back_to_office' => ['nullable', 'boolean'],
        ], ['reason.required' => 'Tulis alasan dilepas (mis. diganti modem baru, rusak, berhenti langganan)']);
        $back = $request->boolean('back_to_office');
        $inventory->removeFromCustomer($customerMaterial, $request->user(), $data['reason'], $back);
        $what = $customerMaterial->serial_number ? "{$customerMaterial->item_name} SN {$customerMaterial->serial_number}" : "{$customerMaterial->item_name} × {$customerMaterial->quantity}";

        return back()->with('success', $back ? "{$what} dilepas dan kembali ke Gudang Utama" : "{$what} dilepas dari pelanggan");
    }

    /**
     * Customers for the ID field of Pemakaian Alat: by ID, name or phone.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json(Customer::query()->search($term)
            ->orderByRaw('case when lower(code) = ? then 0 else 1 end', [mb_strtolower($term)])
            ->orderBy('name')->limit(8)->get(['code', 'name', 'address'])
            ->map(fn (Customer $customer) => ['code' => $customer->code, 'name' => $customer->name, 'address' => $customer->ticketAddress()]));
    }

    /**
     * Material counted by quantity has no moves of its own: it went from whose stock it was to the customer.
     */
    private function countedJourney(CustomerMaterial $material, ?Customer $customer): array
    {
        $nodes = [];
        if ($warehouse = $material->warehouse) {
            $nodes[] = $warehouse->isMain()
                ? ['kind' => 'gudang', 'label' => $warehouse->name, 'sub' => 'Stok', 'date' => null, 'url' => route('warehouses.index')]
                : ['kind' => 'teknisi', 'label' => $warehouse->user?->name ?? $warehouse->name, 'sub' => 'Stok teknisi', 'date' => null, 'url' => route('warehouses.show', $warehouse)];
        }
        $nodes[] = ['kind' => 'pelanggan', 'label' => $customer?->name ?? $material->customer_code, 'sub' => $material->usage?->purpose?->label() ?? 'Dipasang', 'date' => $material->installed_at, 'url' => null];
        if (! $material->isInstalled()) {
            $nodes[] = ['kind' => $material->logs()->latest('id')->value('action') === CustomerMaterialLog::KEMBALI ? 'gudang' : 'lepas',
                'label' => 'Dilepas', 'sub' => $material->removed_reason, 'date' => $material->removed_at, 'url' => null];
        }

        return $nodes;
    }

    /**
     * Material whose customer ID, customer name, item, SN or MAC matches.
     */
    private function matching(Builder $query, string $search): Builder
    {
        $sn = SerialUnit::normalizeSerial($search);
        $mac = preg_replace('/[^0-9A-F]/', '', strtoupper($search));
        $codes = Customer::query()->search($search)->limit(200)->pluck('code');

        return $query->where(fn (Builder $w) => $w->where('customer_code', 'like', "%{$search}%")
            ->orWhereIn(DB::raw('lower(customer_code)'), $codes->map(fn ($code) => mb_strtolower($code)))
            ->orWhere('item_name', 'like', "%{$search}%")
            ->orWhere('serial_number', 'like', "%{$sn}%")
            ->when(strlen($mac) >= 4, fn (Builder $w) => $w->orWhere('mac', 'like', "%{$mac}%")));
    }
}
