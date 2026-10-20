<?php

namespace App\Http\Controllers;

use App\Enums\IncidentAction;
use App\Enums\IncidentStatus;
use App\Enums\TicketAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\TicketAttachment;
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

    public function create(): View
    {
        $this->authorize('create', Ticket::class);

        return view('tickets.create', [
            'categories' => Ticket::CATEGORIES,
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
            'category' => ['required', Rule::in(Ticket::CATEGORIES)],
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

        $ticket->load(['creator', 'helpdesk', 'technicians', 'incident', 'histories.user', 'histories.attachments']);

        return view('tickets.show', [
            'ticket' => $ticket,
            'technicians' => User::technicians()->get(),
            'flow' => [
                TicketStatus::PROSES_CS,
                TicketStatus::ANTRIAN_HELPDESK,
                TicketStatus::HELPDESK_CEK,
                TicketStatus::PERBAIKAN,
                TicketStatus::MENUNGGU_KONFIRMASI,
                TicketStatus::TUTUP,
            ],
            'urgencyLevels' => TicketWorkflowService::URGENCY_LEVELS,
            'modemSuggestions' => Ticket::MODEM_SUGGESTIONS,
            'waTypes' => WhatsAppTemplateService::TYPES,
            'openIncidents' => Incident::query()->open()->latest()->get(),
            'canJoinIncident' => $ticket->incident_id === null
                && in_array($ticket->status, IncidentWorkflowService::LINKABLE_STATUSES, true)
                && request()->user()->hasRole(...IncidentAction::TIKET_DITAUTKAN->allowedRoles()),
        ]);
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
            ...DocumentationPhotoStore::rules(),
        ], DocumentationPhotoStore::MESSAGES);

        $ticket = $this->workflow->transition($ticket, $action, $request->user(), $data);

        // Technicians lose access once the ticket leaves the repair stage.
        if ($request->user()->cannot('view', $ticket)) {
            return redirect()->route('dashboard')->with('success', "Tiket {$ticket->ticket_number}: {$action->label()} berhasil");
        }

        return redirect()->route('tickets.show', $ticket)->with('success', "{$action->label()} berhasil disimpan");
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
