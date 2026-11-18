<?php

namespace App\Http\Controllers;

use App\Enums\Menu;
use App\Enums\Role;
use App\Enums\TicketKind;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\MaterialRequest;
use App\Models\Sale;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The landing page: a summary of complaints, new installations and gamas, what waits on the user's
 * role, and the way into each menu. The complaint board itself lives under Tiket Komplain.
 */
class DashboardController extends Controller
{
    /**
     * The nodes each role acts on, listed under "Perlu Ditangani". Supervisors see both desks'.
     */
    private const ATTENTION = [
        'CS' => ['PROSES_CS', 'MENUNGGU_KONFIRMASI', 'PSB_KONFIRMASI'],
        'HELPDESK' => ['ANTRIAN_HELPDESK', 'CEK_ULANG', 'ANTRIAN_TEKNISI', 'PSB_CEK_LOKASI', 'PSB_ANTRIAN_SURVEY', 'PSB_ANTRIAN_PASANG'],
        'TEKNISI' => ['PERBAIKAN', 'PSB_SURVEY', 'PSB_PEMASANGAN'],
        'SPV' => ['PROSES_CS', 'ANTRIAN_HELPDESK', 'CEK_ULANG', 'ANTRIAN_TEKNISI', 'MENUNGGU_KONFIRMASI', 'PSB_CEK_LOKASI', 'PSB_ANTRIAN_SURVEY', 'PSB_ANTRIAN_PASANG', 'PSB_KONFIRMASI'],
    ];

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $isTechnician = $user->role === Role::TEKNISI;
        $seesInstallations = $user->canAccess(Menu::PSB);

        // One grouped count per kind; every figure below is read from these.
        $counts = Ticket::query()->visibleTo($user)
            ->when(! $seesInstallations, fn ($query) => $query->complaints())
            ->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status');
        $sum = fn (iterable $statuses) => collect($statuses)->sum(fn (TicketStatus $status) => $counts[$status->value] ?? 0);
        $open = fn (TicketKind $kind) => array_filter(TicketStatus::cases(), fn (TicketStatus $status) => $status->kind() === $kind && ! $status->isFinal());

        $complaints = fn () => Ticket::query()->complaints()->visibleTo($user);
        $installations = fn () => Ticket::query()->installations()->visibleTo($user);

        return view('dashboard', [
            'isTechnician' => $isTechnician,
            'seesInstallations' => $seesInstallations,
            'openIncidents' => Incident::query()->open()->visibleTo($user)->withCount('tickets')->latest()->get(),
            'stats' => [
                'complaintsOpen' => $sum($open(TicketKind::GANGGUAN)),
                'complaintsToday' => $complaints()->whereDate('created_at', today())->count(),
                'complaintsClosedToday' => $complaints()->where('status', TicketStatus::TUTUP)->whereDate('closed_at', today())->count(),
                'installationsOpen' => $sum($open(TicketKind::PSB)),
                'installationsActiveThisMonth' => $seesInstallations
                    ? $installations()->where('status', TicketStatus::PSB_SELESAI)->where('closed_at', '>=', now()->startOfMonth())->count() : 0,
            ],
            'attention' => $this->attention($user, $counts, $seesInstallations),
            // A technician's visits, newest assignment first, so the day's work is one tap away.
            'myVisits' => $isTechnician
                ? Ticket::query()->visibleTo($user)->with('technicians')->latest('updated_at')->limit(10)->get()
                : collect(),
            'pendingMaterial' => $user->canAccess(Menu::WAREHOUSE_MANAGE) ? MaterialRequest::query()->pending()->count() : 0,
            'unpaidSales' => $user->canAccess(Menu::WAREHOUSE_MANAGE) ? Sale::query()->unpaid()->count() : 0,
            'menus' => $this->menus($user, $counts, $sum, $open),
        ]);
    }

    /**
     * @return Collection<int, array{status: TicketStatus, count: int, url: string}>
     */
    private function attention(User $user, Collection $counts, bool $seesInstallations): Collection
    {
        return collect(self::ATTENTION[$user->role->value] ?? [])
            ->map(fn (string $value) => TicketStatus::from($value))
            ->reject(fn (TicketStatus $status) => $status->kind() === TicketKind::PSB && ! $seesInstallations)
            ->map(fn (TicketStatus $status) => [
                'status' => $status,
                'count' => (int) ($counts[$status->value] ?? 0),
                'url' => $status->kind() === TicketKind::PSB
                    ? route('installations.index')
                    : route('complaints.index', $user->role === Role::TEKNISI ? [] : ['status' => $status->value]),
            ])
            ->values();
    }

    /**
     * The menus this user may open, as tiles with a live figure.
     *
     * @return list<array{route: string, label: string, icon: string, description: string, figure: ?string}>
     */
    private function menus(User $user, Collection $counts, callable $sum, callable $open): array
    {
        $incidents = Incident::query()->open()->visibleTo($user)->count();

        return array_values(array_filter([
            ['route' => 'complaints.index', 'label' => 'Tiket Komplain', 'icon' => 'ticket', 'description' => 'Board & tabel tiket gangguan pelanggan',
                'figure' => $sum($open(TicketKind::GANGGUAN)).' terbuka'],
            $user->canAccess(Menu::PSB) ? ['route' => 'installations.index', 'label' => 'Pasang Baru (PSB)', 'icon' => 'house-plus', 'description' => 'Waiting list cek lokasi, survey, dan pemasangan',
                'figure' => $sum($open(TicketKind::PSB)).' berjalan'] : null,
            ['route' => 'incidents.index', 'label' => 'Gamas', 'icon' => 'radio-tower', 'description' => 'Gangguan massal per area',
                'figure' => $incidents ? "{$incidents} aktif" : 'Tidak ada'],
            $user->canAccess(Menu::WAREHOUSE) ? ['route' => 'warehouses.index', 'label' => $user->role === Role::TEKNISI ? 'Stok Saya' : 'Gudang', 'icon' => 'warehouse', 'description' => 'Stok barang, ACC material, dan penjualan ke mitra', 'figure' => null] : null,
            $user->canAccess(Menu::CUSTOMERS) ? ['route' => 'customers.index', 'label' => 'Pelanggan', 'icon' => 'user', 'description' => 'Direktori pelanggan', 'figure' => null] : null,
            $user->canAccess(Menu::REPORTS) ? ['route' => 'reports.index', 'label' => 'Laporan', 'icon' => 'bar-chart-3', 'description' => 'SLA bulanan, export Excel, peta area', 'figure' => null] : null,
            $user->canAccess(Menu::ARCHIVE) ? ['route' => 'archive.index', 'label' => 'Arsip', 'icon' => 'archive', 'description' => 'Cari tiket lama yang sudah ditutup', 'figure' => null] : null,
        ]));
    }
}
