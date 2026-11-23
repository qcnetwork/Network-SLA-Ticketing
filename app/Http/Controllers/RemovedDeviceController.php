<?php

namespace App\Http\Controllers;

use App\Enums\RemovedDeviceStatus;
use App\Enums\Role;
use App\Exports\RemovedDeviceExport;
use App\Models\Item;
use App\Models\RemovedDevice;
use App\Models\User;
use App\Services\RemovedDeviceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Alat Cabutan. The warehouse, helpdesk and supervisors see every technician's; a technician sees only
 * what they took off. Receiving and checking a device is for the warehouse team and supervisors.
 */
class RemovedDeviceController extends Controller
{
    /** Status filter values: the statuses plus "terlambat" (still with the technician past the deadline). */
    private const OVERDUE = 'terlambat';

    public function __construct(private RemovedDeviceService $devices) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $own = $this->visibleTo($user);

        return view('removed-devices.index', [
            'filters' => $filters,
            'devices' => $this->filtered($filters, $user)->with(['ticket', 'technician', 'receiver', 'checker', 'item'])
                ->orderByRaw("case status when 'DIBAWA' then 0 when 'MENUNGGU_CEK' then 1 else 2 end")->orderBy('due_at')->latest('id')
                ->paginate(25)->withQueryString(),
            'totals' => [
                'carried' => (clone $own)->where('status', RemovedDeviceStatus::DIBAWA)->count(),
                'overdue' => (clone $own)->overdue()->count(),
                'waiting' => (clone $own)->where('status', RemovedDeviceStatus::MENUNGGU_CEK)->count(),
                'fit' => (clone $own)->where('status', RemovedDeviceStatus::LAYAK)->whereBetween('created_at', [$filters['from'], $filters['to']])->count(),
                'broken' => (clone $own)->where('status', RemovedDeviceStatus::RUSAK)->whereBetween('created_at', [$filters['from'], $filters['to']])->count(),
            ],
            // Per technician: what they took off in the period and what they still owe the warehouse.
            'perTechnician' => $user->role === Role::TEKNISI ? collect() : $this->perTechnician($filters),
            'technicians' => $user->role === Role::TEKNISI ? collect() : User::query()->whereIn('id', RemovedDevice::query()->select('technician_id'))->orderBy('name')->get(['id', 'name']),
            'items' => $this->canHandle($user) ? Item::query()->where('active', true)->orderBy('code')->get(['code', 'name']) : collect(),
            'canHandle' => $this->canHandle($user),
            'isTechnician' => $user->role === Role::TEKNISI,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $devices = $this->filtered($this->filters($request), $request->user())
            ->with(['ticket', 'technician', 'receiver', 'checker', 'item'])->oldest('id')->get();

        return Excel::download(new RemovedDeviceExport($devices), 'alat-cabutan-'.now()->format('Ymd-His').'.xlsx');
    }

    public function receive(Request $request, RemovedDevice $removedDevice): RedirectResponse
    {
        $this->devices->receive($removedDevice, $request->user());

        return back()->with('success', "{$removedDevice->name} diterima dari ".($removedDevice->technician?->name ?? 'teknisi').', lanjut dicek kondisinya');
    }

    public function missing(Request $request, RemovedDevice $removedDevice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Tulis kenapa alat tidak kembali']);
        $this->devices->markMissing($removedDevice, $request->user(), $data['reason']);

        return back()->with('success', "{$removedDevice->name} ditandai tidak kembali");
    }

    public function check(Request $request, RemovedDevice $removedDevice): RedirectResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(['layak', 'rusak'])],
            'item' => ['nullable', 'string', 'max:200'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'mac' => ['nullable', 'string', 'max:30'],
            'check_notes' => ['nullable', 'string', 'max:255'],
        ]);
        $fit = $data['result'] === 'layak';
        $device = $this->devices->check($removedDevice, $request->user(), $fit, $data['item'] ?? null, $data['serial_number'] ?? null, $data['mac'] ?? null, $data['check_notes'] ?? null);

        return back()->with('success', $fit
            ? "{$device->name} layak pakai, masuk Gudang Utama sebagai {$device->item?->code} bekas"
            : "{$device->name} dicatat rusak, tidak masuk stok");
    }

    /**
     * @return array{status: string, technician: string, q: string, from: Carbon, to: Carbon}
     */
    private function filters(Request $request): array
    {
        $date = fn (string $key, Carbon $default) => rescue(fn () => $request->filled($key) ? Carbon::parse($request->query($key)) : $default, $default, false);

        return [
            'status' => (string) $request->query('status', ''),
            'technician' => (string) $request->query('teknisi', ''),
            'q' => trim((string) $request->query('q', '')),
            'from' => $date('dari', now()->startOfMonth())->startOfDay(),
            'to' => $date('sampai', today())->endOfDay(),
        ];
    }

    /**
     * Open devices (still carried or waiting to be checked) always show, whenever they were taken off, so nothing
     * owed drops out of view; closed ones follow the period.
     */
    private function filtered(array $filters, User $user): Builder
    {
        $status = RemovedDeviceStatus::tryFrom($filters['status']);

        return $this->visibleTo($user)
            ->when($filters['status'] === self::OVERDUE, fn ($q) => $q->overdue())
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when(! $status?->isOpen() && $filters['status'] !== self::OVERDUE, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('status', [RemovedDeviceStatus::DIBAWA, RemovedDeviceStatus::MENUNGGU_CEK])
                ->orWhereBetween('created_at', [$filters['from'], $filters['to']])))
            ->when(ctype_digit($filters['technician']), fn ($q) => $q->where('technician_id', (int) $filters['technician']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$filters['q']}%")->orWhere('serial_number', 'like', "%{$filters['q']}%")
                ->orWhere('customer_name', 'like', "%{$filters['q']}%")->orWhere('customer_code', 'like', "%{$filters['q']}%")
                ->orWhereHas('ticket', fn ($t) => $t->where('ticket_number', 'like', "%{$filters['q']}%"))));
    }

    private function visibleTo(User $user): Builder
    {
        return RemovedDevice::query()->when($user->role === Role::TEKNISI, fn ($q) => $q->where('technician_id', $user->id));
    }

    private function perTechnician(array $filters)
    {
        return RemovedDevice::query()->whereNotNull('technician_id')
            ->selectRaw('technician_id')
            ->selectRaw('sum(case when created_at between ? and ? then 1 else 0 end) as period_total', [$filters['from'], $filters['to']])
            ->selectRaw("sum(case when status = 'DIBAWA' then 1 else 0 end) as carried")
            ->selectRaw("sum(case when status = 'DIBAWA' and due_at < ? then 1 else 0 end) as overdue", [now()])
            ->selectRaw('min(case when status = ? then due_at end) as oldest_due', [RemovedDeviceStatus::DIBAWA->value])
            ->groupBy('technician_id')->with('technician:id,name')->get()
            ->filter(fn ($row) => $row->period_total > 0 || $row->carried > 0)
            ->sortByDesc(fn ($row) => [$row->overdue, $row->carried, $row->period_total])->values();
    }

    private function canHandle(User $user): bool
    {
        return $user->hasRole(Role::GUDANG, Role::SPV);
    }
}
