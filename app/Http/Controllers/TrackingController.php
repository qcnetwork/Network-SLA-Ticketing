<?php

namespace App\Http\Controllers;

use App\Exports\TrackingExport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\SerialUnit;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cari SN: every SN-tracked unit, idle (still in the main warehouse or held by a technician) or used ("kotor":
 * where it went, who took it and when, and why). Imported TRACKING ALAT history and installs recorded in the app
 * list side by side, since both fill the same fields.
 */
class TrackingController extends Controller
{
    /** Install reasons offered as a filter; a unit matches when its status mentions one. */
    public const STATUSES = ['PEMASANGAN BARU', 'MIGRASI', 'GANTI ALAT', 'PERBAIKAN', 'TERJUAL'];

    /** Idle or used; '' is both. */
    public const POSITIONS = ['idle' => 'Idle', 'terpakai' => 'Terpakai'];

    /** Where an idle unit sits. */
    public const HOLDS = ['utama' => 'Gudang Utama', 'teknisi' => 'Dipegang teknisi / HD'];

    private const RELATIONS = ['item.category', 'item.sendAs', 'ticket', 'usage', 'sale', 'convertedTo', 'warehouse.user'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $without = fn (string ...$keys) => array_merge($filters, array_fill_keys($keys, ''));

        return view('warehouses.tracking', [
            'filters' => $filters,
            'units' => $this->ordered($this->query($filters)->withIdleSince(), $filters)->with(self::RELATIONS)
                ->paginate(50)->withQueryString(),
            // How many units each tab holds under the other filters.
            'positionCounts' => collect(['' => null, ...self::POSITIONS])->mapWithKeys(fn ($label, $key) => [
                $key => $this->query(['posisi' => $key] + $without('tujuan', 'gudang'))->count(),
            ]),
            'counts' => collect(SerialUnit::DESTINATIONS)->mapWithKeys(fn ($label, $key) => [
                $key => $filters['posisi'] === 'terpakai' ? $this->query(['tujuan' => $key] + $filters)->count() : 0,
            ]),
            'holdCounts' => collect(self::HOLDS)->mapWithKeys(fn ($label, $key) => [
                $key => $filters['posisi'] === 'idle' ? $this->query(['gudang' => $key] + $filters)->count() : 0,
            ]),
            'categories' => ItemCategory::query()->ordered()->get(['id', 'name']),
            'models' => Item::query()->where('tracks_serial', true)->orderBy('code')->get(['code', 'name']),
            // Technicians holding units now, and whoever took the used ones.
            'holders' => SerialUnit::query()->used()->whereNotNull('holder_name')->distinct()->pluck('holder_name')
                ->merge(User::query()->whereIn('id', Warehouse::query()->technicians()->whereHas('serialUnits')->select('user_id'))->pluck('name'))
                ->unique(fn (string $name) => mb_strtoupper($name))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $units = $this->ordered($this->query($filters)->withIdleSince(), $filters)->with(self::RELATIONS)->limit(20000)->get();
        $name = match ($filters['posisi']) {
            'idle' => 'unit-idle',
            'terpakai' => 'unit-terpakai',
            default => 'unit-sn',
        };

        return Excel::download(new TrackingExport($units), "{$name}-".now()->format('Ymd-His').'.xlsx');
    }

    /**
     * One unit's whole story: what the imported sheet said about it, then every move recorded in the app.
     */
    public function show(SerialUnit $serialUnit): View
    {
        $unit = $serialUnit->load([...self::RELATIONS, 'ticket.technicians']);
        $moves = StockMovement::query()->where('serial_unit_id', $unit->id)
            ->with(['fromWarehouse', 'toWarehouse', 'ticket', 'sale', 'usage', 'user'])->orderBy('created_at')->orderBy('id')->get();

        // The sheet's dates come first, as events of the past, before anything the app recorded.
        $sheet = collect();
        if ($unit->imported_at) {
            if ($unit->received_at) {
                $sheet->push(['date' => $unit->received_at, 'icon' => 'download', 'title' => 'Masuk gudang', 'detail' => 'Paket masuk (data TRACKING ALAT)']);
            }
            if ($unit->taken_at) {
                $sheet->push(['date' => $unit->taken_at, 'icon' => 'user', 'title' => 'Diambil '.($unit->holder_name ?? 'teknisi'),
                    'detail' => collect([$unit->install_status, $unit->installed_to ? "dipasang di {$unit->installed_to}" : null,
                        $unit->replaced_device ? "mengganti {$unit->replaced_device}" : null])->filter()->implode(' · ') ?: null]);
            }
            if ($unit->condition) {
                $sheet->push(['date' => null, 'icon' => 'circle-alert', 'title' => $unit->condition, 'detail' => $unit->notes]);
            }
        }

        // An idle unit says how long it has sat where it is.
        $idle = $unit->warehouse_id ? SerialUnit::query()->withIdleSince()->find($unit->id) : null;

        $journey = $unit->journey(StockMovement::query()->where('serial_unit_id', $unit->id)
            ->with(['fromWarehouse.user', 'toWarehouse.user', 'usage', 'ticket', 'sale'])->orderBy('created_at')->orderBy('id')->get());

        return view('warehouses.unit', ['unit' => $unit, 'journey' => $journey, 'sheet' => $sheet, 'moves' => $moves, 'idleSince' => $idle?->idleSince(), 'idleDays' => $idle?->idleDays()]);
    }

    /**
     * @return array{q: string, posisi: string, tujuan: string, gudang: string, kategori: string, barang: string, status: string, teknisi: string, dari: ?string, sampai: ?string, urut: string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key))->toDateString() : null, null, false);
        $pick = fn (string $key, array $allowed) => in_array($request->query($key), $allowed, true) ? (string) $request->query($key) : '';
        $tujuan = $pick('tujuan', array_keys(SerialUnit::DESTINATIONS));
        $gudang = $pick('gudang', array_keys(self::HOLDS));
        // Picking where a unit went means it is used; picking where it sits means it is idle.
        $posisi = $tujuan !== '' ? 'terpakai' : ($gudang !== '' ? 'idle' : $pick('posisi', array_keys(self::POSITIONS)));

        return [
            'q' => trim((string) $request->query('q', '')),
            'posisi' => $posisi,
            'tujuan' => $tujuan,
            'gudang' => $gudang,
            'kategori' => ctype_digit((string) $request->query('kategori')) ? (string) $request->query('kategori') : '',
            'barang' => strtoupper(trim((string) $request->query('barang', ''))),
            'status' => $pick('status', self::STATUSES),
            'teknisi' => trim((string) $request->query('teknisi', '')),
            'dari' => $date('dari'),
            'sampai' => $date('sampai'),
            // Idle units list longest-waiting first by default, everything else newest first.
            'urut' => $pick('urut', ['terbaru', 'terlama']) ?: ($posisi === 'idle' ? 'terlama' : 'terbaru'),
        ];
    }

    /**
     * Units matching the filters; without `posisi`, the used ones (as the category page asks for them).
     * Searching takes an SN, a MAC (any separators), a customer, place, ticket, warehouse or holder.
     */
    public function query(array $filters): Builder
    {
        $q = $filters['q'] ?? '';
        $posisi = $filters['posisi'] ?? 'terpakai';
        $holder = $filters['teknisi'] ?? '';

        return SerialUnit::query()
            ->when($posisi === 'terpakai', fn (Builder $query) => $query->used())
            ->when($posisi === 'idle', fn (Builder $query) => $query->idle())
            ->when(($filters['tujuan'] ?? '') !== '', fn (Builder $query) => $query->used()->destination($filters['tujuan']))
            ->when(($filters['gudang'] ?? '') !== '', fn (Builder $query) => $query->idle()->whereHas('warehouse', fn ($warehouse) => $filters['gudang'] === 'utama'
                ? $warehouse->whereNull('user_id') : $warehouse->whereNotNull('user_id')))
            ->when(($filters['kategori'] ?? '') !== '', fn (Builder $query) => $query->whereHas('item', fn ($item) => $item->where('item_category_id', (int) $filters['kategori'])))
            ->when(($filters['barang'] ?? '') !== '', fn (Builder $query) => $query->whereHas('item', fn ($item) => $item->where('code', $filters['barang'])))
            ->when(isset($filters['items']), fn (Builder $query) => $query->whereIn('item_id', $filters['items']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $query) => $query->where('install_status', 'like', '%'.$filters['status'].'%'))
            // A used unit by who took it, an idle one by whose warehouse it sits in.
            ->when($holder !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where(fn (Builder $used) => $used->whereNull('warehouse_id')->where('holder_name', $holder))
                ->orWhereHas('warehouse.user', fn ($user) => $user->where('name', $holder))))
            ->when($filters['dari'] ?? null, fn (Builder $query, $from) => $query->whereDate('taken_at', '>=', $from))
            ->when($filters['sampai'] ?? null, fn (Builder $query, $to) => $query->whereDate('taken_at', '<=', $to))
            ->when($q !== '', function (Builder $query) use ($q) {
                $sn = SerialUnit::normalizeSerial($q);
                $mac = preg_replace('/[^0-9A-F]/', '', strtoupper($q));
                $query->where(fn (Builder $w) => $w->where('serial_number', 'like', "%{$sn}%")
                    ->when(strlen($mac) >= 4, fn (Builder $w) => $w->orWhere('mac', 'like', "%{$mac}%"))
                    ->orWhere('installed_to', 'like', "%{$q}%")
                    ->orWhere('holder_name', 'like', "%{$q}%")
                    ->orWhere('condition', 'like', "%{$q}%")
                    ->orWhereHas('warehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('ticket', fn ($t) => $t->where('customer_name', 'like', "%{$q}%")->orWhere('ticket_number', 'like', "%{$q}%")));
            });
    }

    /**
     * Idle units by when they arrived, used ones by when they were taken, a mix by when they were registered.
     */
    private function ordered(Builder $query, array $filters): Builder
    {
        $direction = $filters['urut'] === 'terlama' ? 'asc' : 'desc';
        $column = match ($filters['posisi']) {
            'idle' => 'idle_since',
            'terpakai' => 'taken_at',
            default => 'serial_units.id',
        };

        return $query->orderBy($column, $direction)->orderBy('serial_units.id', $direction);
    }
}
