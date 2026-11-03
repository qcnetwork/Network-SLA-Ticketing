<?php

namespace App\Http\Controllers;

use App\Enums\IncidentAction;
use App\Enums\IncidentStatus;
use App\Enums\Role;
use App\Enums\TicketAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Customer;
use App\Models\Incident;
use App\Models\MaterialRequestLine;
use App\Models\ModemType;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketShare;
use App\Models\User;
use App\Services\DocumentationPhotoStore;
use App\Services\IncidentWorkflowService;
use App\Services\TicketWorkflowService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketController extends Controller
{
    public function __construct(private TicketWorkflowService $workflow) {}

    public function create(Request $request): View
    {
        $this->authorize('create', Ticket::class);
        // ?customer=ID, from the customer list: start the form filled with that customer.
        $customer = $request->filled('customer')
            ? Customer::query()->withCode((string) $request->query('customer'))->withCount('tickets')->first()
            : null;

        return view('tickets.create', [
            'customer' => $customer ? [
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'tickets' => $customer->tickets_count,
                'open_ticket' => null,
            ] : null,
            'categories' => TicketCategory::activeNames(),
            'priorities' => TicketPriority::cases(),
            'openIncidents' => Incident::query()->open()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,}$/'],
            'customer_id' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'category' => ['required', Rule::in(TicketCategory::activeNames())],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'complaint' => ['required', 'string', 'max:2000'],
            'incident_id' => ['nullable', Rule::exists('incidents', 'id')->whereIn('status', array_map(fn (IncidentStatus $status) => $status->value, IncidentStatus::open()))],
        ], [
            'incident_id.exists' => 'Gamas yang dipilih sudah selesai atau tidak ditemukan',
            'customer_phone.regex' => 'Nomor WhatsApp tidak valid',
        ], [
            'customer_name' => 'nama pelanggan',
            'customer_phone' => 'nomor WhatsApp',
            'complaint' => 'kendala',
        ]);

        $incident = isset($data['incident_id']) ? Incident::find($data['incident_id']) : null;
        $ticket = $this->workflow->create($data, $request->user(), $incident);

        return redirect()->route('tickets.show', $ticket)->with('success', $incident
            ? "Tiket {$ticket->ticket_number} berhasil dibuat dan ditautkan ke gamas {$incident->incident_number}"
            : "Tiket {$ticket->ticket_number} berhasil dibuat. Teruskan ke Helpdesk setelah koordinasi dengan pelanggan selesai");
    }

    public function show(Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $ticket->load(['creator', 'helpdesk', 'technicians', 'incident', 'histories.user', 'histories.attachments', 'materialRequests.lines.item', 'materialRequests.lines.warehouse', 'materialRequests.lines.serialUnit', 'materialRequests.requester', 'materialRequests.reviewer']);
        $user = request()->user();
        $isCrew = $ticket->status === TicketStatus::PERBAIKAN && $ticket->isAssignedTo($user);

        return view('tickets.show', [
            'shares' => $user->can('share', $ticket)
                ? $ticket->shares()->active()->with(['user', 'sharedBy'])->orderBy('expires_at')->get() : collect(),
            // Set when a technician opened the ticket through the archive rather than as its crew.
            'myShare' => $user->role === Role::TEKNISI && ! $isCrew
                ? $ticket->shares()->active()->where('user_id', $user->id)->first() : null,
            'shareDurations' => TicketShare::DURATIONS,
            'ticket' => $ticket,
            'technicians' => User::technicians()->get(),
            // What the repair crew carries, offered in the repair resume's material list.
            'crewStock' => $ticket->status === TicketStatus::PERBAIKAN ? $this->crewStock($ticket) : [],
            'flow' => [
                TicketStatus::PROSES_CS,
                TicketStatus::ANTRIAN_HELPDESK,
                TicketStatus::HELPDESK_CEK,
                TicketStatus::PERBAIKAN,
                TicketStatus::MENUNGGU_KONFIRMASI,
                TicketStatus::TUTUP,
            ],
            'urgencyLevels' => TicketWorkflowService::URGENCY_LEVELS,
            'modemSuggestions' => ModemType::activeNames(),
            'categories' => TicketCategory::activeNames(),
            'waTypes' => WhatsAppTemplateService::TYPES,
            'openIncidents' => Incident::query()->open()->latest()->get(),
            'canJoinIncident' => $ticket->incident_id === null
                && in_array($ticket->status, IncidentWorkflowService::LINKABLE_STATUSES, true)
                && request()->user()->hasRole(...IncidentAction::TIKET_DITAUTKAN->allowedRoles()),
        ]);
    }

    /**
     * What each crew member carries, keyed by their user id, with the SNs of every modem they hold.
     * Stock already claimed by a resume still waiting for the warehouse is left out.
     *
     * @return array<int, list<array{code: string, name: string, quantity: int, serial: bool, units: list<array{sn: string, mac: ?string}>}>>
     */
    private function crewStock(Ticket $ticket): array
    {
        $crew = fn ($q) => $q->whereIn('user_id', $ticket->technicians->modelKeys());
        $requested = MaterialRequestLine::query()->pending()->whereHas('warehouse', $crew)->get();
        $claimed = $requested->groupBy(fn (MaterialRequestLine $line) => "{$line->warehouse_id}:{$line->item_id}")->map->sum('quantity');
        $units = SerialUnit::query()->whereHas('warehouse', $crew)->whereNotIn('id', $requested->pluck('serial_unit_id')->filter())
            ->orderBy('serial_number')->get()->groupBy(fn (SerialUnit $unit) => "{$unit->warehouse_id}:{$unit->item_id}");

        return Stock::query()->with(['item', 'warehouse'])->where('quantity', '>', 0)->whereHas('warehouse', $crew)->get()
            ->each(fn (Stock $stock) => $stock->quantity -= $claimed["{$stock->warehouse_id}:{$stock->item_id}"] ?? 0)
            ->filter(fn (Stock $stock) => $stock->quantity > 0)
            ->sortBy('item.code')
            ->groupBy(fn (Stock $stock) => $stock->warehouse->user_id)
            ->map(fn ($stocks) => $stocks->map(fn (Stock $stock) => [
                'code' => $stock->item->code,
                'name' => $stock->item->name,
                'quantity' => $stock->quantity,
                'serial' => $stock->item->tracks_serial,
                'units' => ($units["{$stock->warehouse_id}:{$stock->item_id}"] ?? collect())
                    ->map(fn (SerialUnit $unit) => ['sn' => $unit->serial_number, 'mac' => $unit->formattedMac()])->values()->all(),
            ])->values()->all())
            ->all();
    }

    public function perform(Request $request, Ticket $ticket, string $action): RedirectResponse
    {
        $action = TicketAction::tryFrom($action);
        abort_if($action === null || ! $action->isPerformable(), 404);
        $this->authorize('perform', [$ticket, $action]);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'technician_ids' => ['nullable', 'array'],
            'technician_ids.*' => ['nullable', 'integer'],
            'modem' => ['nullable', 'string', 'max:100'],
            'urgency_level' => ['nullable', Rule::in(TicketWorkflowService::URGENCY_LEVELS)],
            'upgrade_priority' => ['nullable', 'boolean'],
            'category' => ['nullable', Rule::in(TicketCategory::activeNames())],
            'materials' => ['nullable', 'array', 'max:30'],
            'materials.*.technician_id' => ['nullable', 'integer'],
            'materials.*.code' => ['nullable', 'string', 'max:200'],
            'materials.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'materials.*.serial' => ['nullable', 'string', 'max:100'],
            'materials.*.notes' => ['nullable', 'string', 'max:255'],
            ...DocumentationPhotoStore::rules(),
        ], DocumentationPhotoStore::MESSAGES);

        $ticket = $this->workflow->transition($ticket, $action, $request->user(), $data);

        // Technicians lose access once the ticket leaves the repair stage.
        if ($request->user()->cannot('view', $ticket)) {
            return redirect()->route('dashboard')->with('success', "Tiket {$ticket->ticket_number}: {$action->label()} berhasil");
        }

        return redirect()->route('tickets.show', $ticket)->with('success', "{$action->label()} berhasil disimpan");
    }

    public function updateCustomer(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('perform', [$ticket, TicketAction::UBAH_DATA_PELANGGAN]);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{8,}$/'],
            'customer_id' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['nullable', 'string', 'max:255'],
        ], [
            'customer_phone.regex' => 'Nomor WhatsApp tidak valid',
        ], [
            'customer_name' => 'nama pelanggan',
            'customer_phone' => 'nomor WhatsApp',
        ]);

        $ticket = $this->workflow->updateCustomer($ticket, $data, $request->user());

        return redirect()->route('tickets.show', $ticket)->with('success', $ticket->hasLocation()
            ? 'Data pelanggan berhasil diperbarui. Titik lokasi tersimpan.'
            : 'Data pelanggan berhasil diperbarui');
    }

    public function updateHandling(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('perform', [$ticket, TicketAction::UBAH_PENANGANAN]);

        $data = $request->validate([
            'modem' => ['nullable', 'string', 'max:100'],
            'remote_resolution_notes' => ['nullable', 'string', 'max:2000'],
            'technician_notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'remote_resolution_notes' => 'solusi remote',
            'technician_notes' => 'laporan teknisi',
        ]);

        $ticket = $this->workflow->updateHandling($ticket, $data, $request->user());

        return redirect()->route('tickets.show', $ticket)->with('success', 'Data penanganan berhasil diperbarui');
    }

    public function attachment(Ticket $ticket, TicketAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $ticket);

        return $attachment->inlineResponse();
    }

    public function whatsapp(Request $request, Ticket $ticket, WhatsAppTemplateService $templates): RedirectResponse
    {
        $this->authorize('contactCustomer', $ticket);

        $type = $request->query('type', 'konfirmasi');
        abort_unless(array_key_exists($type, WhatsAppTemplateService::TYPES), 404);

        return redirect()->away($templates->build($ticket->loadMissing('technicians'), $type)['url']);
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        // History, attachment rows and technician assignments are removed by cascading foreign keys.
        $photoPaths = $ticket->attachments()->pluck('path')->all();
        $ticket->delete();
        Storage::disk(TicketAttachment::DISK)->delete($photoPaths);

        return redirect()->route('dashboard')->with('success', "Tiket {$ticket->ticket_number} berhasil dihapus");
    }
}
