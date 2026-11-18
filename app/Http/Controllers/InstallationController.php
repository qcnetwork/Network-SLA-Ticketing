<?php

namespace App\Http\Controllers;

use App\Enums\TicketAction;
use App\Enums\TicketStatus;
use App\Models\ServicePackage;
use App\Models\Ticket;
use App\Services\DocumentationPhotoStore;
use App\Services\TicketWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * New installation orders (PSB, pasang sambung baru): a board of their own, apart from complaints.
 * Each order opens on the ticket page, which shows the installation flow for it.
 */
class InstallationController extends Controller
{
    public const COLUMN_LIMIT = 30;

    public function __construct(private TicketWorkflowService $workflow) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', Rule::in(['aktif', 'selesai', 'batal'])],
        ]);
        $tab = $filters['tab'] ?? 'aktif';
        $search = trim($filters['q'] ?? '');
        $query = fn () => Ticket::query()->installations()->visibleTo($user)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('ticket_number', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('customer_phone', 'like', "%{$search}%")
                ->orWhere('customer_address', 'like', "%{$search}%")
                ->orWhere('sales', 'like', "%{$search}%")))
            ->with(['technicians', 'creator']);

        $open = array_values(array_filter(TicketStatus::installation(), fn (TicketStatus $status) => ! $status->isFinal()));
        $counts = $query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('installations.index', [
            'tab' => $tab,
            'search' => $search,
            'counts' => $counts,
            'openStatuses' => $open,
            // Oldest first within a column: the order that has waited longest is served first.
            'columns' => $tab === 'aktif'
                ? collect($open)->mapWithKeys(fn (TicketStatus $status) => [
                    $status->value => $query()->where('status', $status)->oldest('updated_at')->limit(self::COLUMN_LIMIT)->get(),
                ])
                : collect(),
            'closed' => $tab === 'aktif' ? null : $query()
                ->where('status', $tab === 'selesai' ? TicketStatus::PSB_SELESAI : TicketStatus::PSB_BATAL)
                ->latest('closed_at')->latest('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('createInstallation', Ticket::class);

        return view('installations.create', ['packages' => ServicePackage::activeNames()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('createInstallation', Ticket::class);
        $packages = ServicePackage::activeNames();

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,}$/'],
            'customer_address' => ['required', 'string', 'max:255'],
            // Until a supervisor lists the packages, the order can be taken without one.
            'package' => [$packages ? 'required' : 'nullable', 'string', Rule::in($packages)],
            'sales' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...DocumentationPhotoStore::rules(),
            'photos' => ['required', 'array', 'min:1', 'max:5'],
        ], [
            ...DocumentationPhotoStore::MESSAGES,
            'photos.required' => 'Lampirkan foto depan rumah calon pelanggan',
            'customer_phone.regex' => 'Nomor WhatsApp tidak valid',
        ], [
            'customer_name' => 'nama sesuai KTP',
            'customer_phone' => 'nomor WhatsApp',
            'customer_address' => 'shareloc / alamat',
            'package' => 'paket layanan',
        ]);

        $ticket = $this->workflow->createInstallation($data, $request->user());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', "PSB {$ticket->ticket_number} berhasil dibuat dan menunggu cek lokasi oleh Helpdesk");
    }

    /**
     * The order's package, sales and ODP, which CS or Helpdesk fill in or correct later.
     */
    public function update(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('perform', [$ticket, TicketAction::PSB_UBAH_DATA]);

        $data = $request->validate([
            'package' => ['nullable', 'string', 'max:100'],
            'sales' => ['nullable', 'string', 'max:100'],
            'odp' => ['nullable', 'string', 'max:100'],
            'odp_location' => ['nullable', 'string', 'max:255'],
            'odp_distance' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        // A package retired since the order was taken may stay; a new pick must be on the list.
        if (($data['package'] ?? null) !== $ticket->package && filled($data['package'] ?? null)) {
            $request->validate(['package' => [Rule::in(ServicePackage::activeNames())]], [], ['package' => 'paket layanan']);
        }

        $this->workflow->updateInstallation($ticket, $data, $request->user());

        return redirect()->route('tickets.show', $ticket)->with('success', 'Data PSB berhasil diperbarui');
    }
}
