<?php

namespace App\Http\Controllers;

use App\Enums\IncidentAction;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\DocumentationPhotoStore;
use App\Services\IncidentWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IncidentController extends Controller
{
    public function __construct(private IncidentWorkflowService $workflow) {}

    public function index(Request $request): View
    {
        $query = fn () => Incident::query()->visibleTo($request->user())->with('technicians')->withCount('tickets');

        return view('incidents.index', [
            'openIncidents' => $query()->open()->latest()->get(),
            'closedIncidents' => $query()->where('status', IncidentStatus::SELESAI)->latest('resolved_at')->limit(20)->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Incident::class);

        return view('incidents.create', [
            'linkableTickets' => $this->linkableTickets(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Incident::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'area' => ['required', 'string', 'max:150'],
            'cause' => ['nullable', 'string', 'max:2000'],
            'estimated_resolved_at' => ['nullable', 'date'],
            'ticket_ids' => ['nullable', 'array'],
            'ticket_ids.*' => ['integer'],
        ], [], [
            'title' => 'judul gangguan',
            'area' => 'area terdampak',
        ]);

        $incident = $this->workflow->create($data, $request->user(), $data['ticket_ids'] ?? []);

        return redirect()->route('incidents.show', $incident)
            ->with('success', "Gamas {$incident->incident_number} berhasil dibuat");
    }

    public function show(Incident $incident): View
    {
        $this->authorize('view', $incident);

        $incident->load(['creator', 'technicians', 'tickets', 'updates.user', 'updates.attachments']);

        return view('incidents.show', [
            'incident' => $incident,
            'technicians' => User::technicians()->get(),
            'linkableTickets' => $incident->isOpen() ? $this->linkableTickets() : new Collection,
        ]);
    }

    public function perform(Request $request, Incident $incident, string $action): RedirectResponse
    {
        $action = IncidentAction::tryFrom($action);
        abort_unless($action && in_array($action, IncidentAction::performable(), true), 404);
        $this->authorize('perform', [$incident, $action]);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'technician_ids' => ['nullable', 'array'],
            'technician_ids.*' => ['nullable', 'integer'],
            'estimated_resolved_at' => ['nullable', 'date'],
            ...DocumentationPhotoStore::rules(),
        ], DocumentationPhotoStore::MESSAGES);

        $incident = $this->workflow->perform($incident, $action, $request->user(), $data);

        return redirect()->route('incidents.show', $incident)->with('success', "{$action->label()} berhasil disimpan");
    }

    public function linkTickets(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('perform', [$incident, IncidentAction::TIKET_DITAUTKAN]);

        $data = $request->validate([
            'ticket_ids' => ['required', 'array'],
            'ticket_ids.*' => ['integer'],
        ], ['ticket_ids.required' => 'Pilih minimal satu tiket untuk ditautkan']);

        $count = $this->workflow->linkTickets($incident, $data['ticket_ids'], $request->user());

        if ($count === 0) {
            return back()->withErrors(['ticket_ids' => 'Tidak ada tiket yang bisa ditautkan (sudah masuk gamas lain atau sudah diselesaikan)']);
        }

        return back()->with('success', "{$count} tiket ditautkan ke gamas {$incident->incident_number}");
    }

    public function detachTicket(Request $request, Incident $incident, Ticket $ticket): RedirectResponse
    {
        $this->authorize('perform', [$incident, IncidentAction::TIKET_DILEPAS]);

        $this->workflow->detachTicket($incident, $ticket, $request->user());

        return back()->with('success', "Tiket {$ticket->ticket_number} dilepas dari gamas dan kembali ke Antrian Helpdesk");
    }

    public function attachment(Incident $incident, TicketAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('view', $incident);

        return $attachment->inlineResponse();
    }

    /**
     * Open customer tickets that are not part of any gamas yet.
     *
     * @return Collection<int, Ticket>
     */
    private function linkableTickets(): Collection
    {
        return Ticket::query()
            ->whereNull('incident_id')
            ->whereIn('status', IncidentWorkflowService::LINKABLE_STATUSES)
            ->latest()
            ->get();
    }
}
