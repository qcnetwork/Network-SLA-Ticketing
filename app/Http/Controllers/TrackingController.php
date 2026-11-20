<?php

namespace App\Http\Controllers;

use App\Exports\TrackingExport;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\SerialUnit;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Units that left stock ("kotor"): where each SN / MAC went, who took it and when, and why. Imported TRACKING ALAT
 * history and installs recorded in the app list side by side, since both fill the same fields.
 */
class TrackingController extends Controller
{
    /** Install reasons offered as a filter; a unit matches when its status mentions one. */
    public const STATUSES = ['PEMASANGAN BARU', 'MIGRASI', 'GANTI ALAT', 'PERBAIKAN', 'TERJUAL'];

    private const RELATIONS = ['item.category', 'item.sendAs', 'ticket', 'usage', 'sale', 'convertedTo', 'warehouse'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('warehouses.tracking', [
            'filters' => $filters,
            'units' => $this->query($filters)->with(self::RELATIONS)
                ->orderByDesc('taken_at')->orderByDesc('id')
                ->paginate(50)->withQueryString(),
            // How many units each destination holds under the other filters, for the tabs.
            'counts' => collect(SerialUnit::DESTINATIONS)->mapWithKeys(fn ($label, $key) => [
                $key => $this->query(['tujuan' => $key] + $filters)->count(),
            ]),
            'total' => $this->query(['tujuan' => ''] + $filters)->count(),
            'categories' => ItemCategory::query()->ordered()->get(['id', 'name']),
            'models' => Item::query()->where('tracks_serial', true)->orderBy('code')->get(['code', 'name']),
            'holders' => SerialUnit::query()->used()->whereNotNull('holder_name')->distinct()->orderBy('holder_name')->pluck('holder_name'),
            'statuses' => self::STATUSES,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $filters = $this->filters($request);
        $units = $this->query($filters)->with(self::RELATIONS)->orderByDesc('taken_at')->orderByDesc('id')->limit(20000)->get();

        return Excel::download(new TrackingExport($units), 'tracking-unit-terpakai-'.now()->format('Ymd-His').'.xlsx');
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

        return view('warehouses.unit', ['unit' => $unit, 'sheet' => $sheet, 'moves' => $moves]);
    }

    /**
     * @return array{q: string, tujuan: string, kategori: string, barang: string, status: string, teknisi: string, dari: ?string, sampai: ?string}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key))->toDateString() : null, null, false);
        $tujuan = (string) $request->query('tujuan', '');

        return [
            'q' => trim((string) $request->query('q', '')),
            'tujuan' => array_key_exists($tujuan, SerialUnit::DESTINATIONS) ? $tujuan : '',
            'kategori' => ctype_digit((string) $request->query('kategori')) ? (string) $request->query('kategori') : '',
            'barang' => strtoupper(trim((string) $request->query('barang', ''))),
            'status' => in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '',
            'teknisi' => trim((string) $request->query('teknisi', '')),
            'dari' => $date('dari'),
            'sampai' => $date('sampai'),
        ];
    }

    /**
     * Used units matching the filters. Searching takes an SN, a MAC (any separators), a customer or place, or a holder.
     */
    public function query(array $filters): Builder
    {
        $q = $filters['q'] ?? '';

        return SerialUnit::query()->used()
            ->when(($filters['tujuan'] ?? '') !== '', fn (Builder $query) => $query->destination($filters['tujuan']))
            ->when(($filters['kategori'] ?? '') !== '', fn (Builder $query) => $query->whereHas('item', fn ($item) => $item->where('item_category_id', (int) $filters['kategori'])))
            ->when(($filters['barang'] ?? '') !== '', fn (Builder $query) => $query->whereHas('item', fn ($item) => $item->where('code', $filters['barang'])))
            ->when(isset($filters['items']), fn (Builder $query) => $query->whereIn('item_id', $filters['items']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $query) => $query->where('install_status', 'like', '%'.$filters['status'].'%'))
            ->when(($filters['teknisi'] ?? '') !== '', fn (Builder $query) => $query->where('holder_name', $filters['teknisi']))
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
                    ->orWhereHas('ticket', fn ($t) => $t->where('customer_name', 'like', "%{$q}%")->orWhere('ticket_number', 'like', "%{$q}%")));
            });
    }
}
