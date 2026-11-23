<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Log Pengambilan: every hand-over from the main warehouse to a technician, with whose stock it went into and
 * who actually collected it (the owner, or a teammate collecting for them).
 */
class StockPickupController extends Controller
{
    public function index(Request $request): View
    {
        $date = fn (string $key, Carbon $default) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key)) : $default, $default, false);
        $filters = [
            'warehouse' => (string) $request->query('gudang', ''),
            'owner' => (string) $request->query('pemilik', ''),
            'collector' => (string) $request->query('pengambil', ''),
            'others' => $request->boolean('orang_lain'),
            'from' => $date('dari', now()->startOfMonth())->startOfDay(),
            'to' => $date('sampai', today())->endOfDay(),
        ];

        $query = StockMovement::query()->where('type', StockMovementType::KIRIM)->whereNotNull('to_warehouse_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when(ctype_digit($filters['warehouse']), fn ($q) => $q->where('to_warehouse_id', (int) $filters['warehouse']))
            ->when(ctype_digit($filters['owner']), fn ($q) => $q->whereHas('toWarehouse', fn ($w) => $w->where('user_id', (int) $filters['owner'])))
            // A collector is whoever is named, or the owner of the stock when nobody else is.
            ->when(ctype_digit($filters['collector']), fn ($q) => $q->where(fn ($q) => $q->where('taken_by', (int) $filters['collector'])
                ->orWhere(fn ($q) => $q->whereNull('taken_by')->whereHas('toWarehouse', fn ($w) => $w->where('user_id', (int) $filters['collector'])))))
            ->when($filters['others'], fn ($q) => $q->whereNotNull('taken_by'));

        return view('stock-pickups.index', [
            'filters' => $filters,
            'totals' => ['all' => (clone $query)->count(), 'others' => (clone $query)->whereNotNull('taken_by')->count()],
            'movements' => $query->with(['item', 'serialUnit', 'toWarehouse.user', 'takenBy', 'user'])->latest('id')->paginate(50)->withQueryString(),
            // Anyone who holds stock or ever collected some, for the two searchable filters.
            'people' => User::query()->whereIn('id', Warehouse::query()->technicians()->select('user_id'))->orWhereIn('id', StockMovement::query()->whereNotNull('taken_by')->select('taken_by'))->orderBy('name')->get(['id', 'name', 'role']),
            'filteredWarehouse' => ctype_digit($filters['warehouse']) ? Warehouse::query()->with('user')->find((int) $filters['warehouse']) : null,
        ]);
    }
}
