<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\StockMovementType;
use App\Enums\TicketAction;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock moves only through here, so every change in a warehouse has a matching movement row:
 * material comes into the main warehouse, goes out to a technician, comes back as a return,
 * or is used up on a ticket's repair once the warehouse approves the resume that reports it. Items that track serials (modems) move one scanned unit
 * at a time, so the warehouse always knows where each SN is.
 */
class InventoryService
{
    public function receive(Item $item, int $quantity, User $actor, ?string $notes = null): StockMovement
    {
        $this->ensureCounted($item, 'item');

        return DB::transaction(fn () => $this->move(StockMovementType::MASUK, $item, $quantity, null, Warehouse::main(), $actor, $notes));
    }

    public function send(Item $item, int $quantity, User $technician, User $actor, ?string $notes = null): StockMovement
    {
        $this->ensureCounted($item, 'item');

        return DB::transaction(fn () => $this->move(StockMovementType::KIRIM, $item, $quantity, Warehouse::main(), Warehouse::of($technician), $actor, $notes));
    }

    public function returnToMain(Item $item, int $quantity, Warehouse $from, User $actor, ?string $notes = null): StockMovement
    {
        $this->ensureCounted($item, 'item');
        if ($from->isMain()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Retur hanya dari gudang teknisi']);
        }

        return DB::transaction(function () use ($item, $quantity, $from, $actor, $notes) {
            // Stock a pending resume claims stays with the technician until the warehouse decides on it.
            $this->ensureAvailable($from, $item, $quantity, 'quantity');

            return $this->move(StockMovementType::RETUR, $item, $quantity, $from, Warehouse::main(), $actor, $notes);
        });
    }

    /**
     * Register newly arrived units, scanned one by one, into the main warehouse.
     *
     * @param  array<array{sn?: ?string, mac?: ?string}>  $scans
     */
    public function receiveSerials(Item $item, array $scans, User $actor, ?string $notes = null): int
    {
        if (! $item->tracks_serial) {
            throw ValidationException::withMessages(['item' => "{$item->code} tidak memakai SN, isi jumlahnya saja"]);
        }
        $units = collect($scans)
            ->map(fn ($scan) => ['sn' => SerialUnit::normalizeSerial($scan['sn'] ?? ''), 'mac_raw' => trim((string) ($scan['mac'] ?? ''))])
            ->filter(fn ($unit) => $unit['sn'] !== '')
            ->values();
        if ($units->isEmpty()) {
            throw ValidationException::withMessages(['serials' => 'Scan minimal satu SN']);
        }
        foreach ($units as $unit) {
            if ($unit['mac_raw'] !== '' && SerialUnit::normalizeMac($unit['mac_raw']) === null) {
                throw ValidationException::withMessages(['serials' => "MAC untuk SN {$unit['sn']} tidak valid: {$unit['mac_raw']}"]);
            }
        }
        if ($twice = $units->pluck('sn')->duplicates()->first()) {
            throw ValidationException::withMessages(['serials' => "SN {$twice} discan dua kali"]);
        }

        return DB::transaction(function () use ($item, $units, $actor, $notes) {
            $known = SerialUnit::query()->whereIn('serial_number', $units->pluck('sn'))->with('item')->first();
            if ($known) {
                throw ValidationException::withMessages(['serials' => "SN {$known->serial_number} sudah terdaftar ({$known->item->code})"]);
            }
            $main = Warehouse::main();
            foreach ($units as $unit) {
                $serial = SerialUnit::query()->create([
                    'item_id' => $item->id,
                    'serial_number' => $unit['sn'],
                    'mac' => SerialUnit::normalizeMac($unit['mac_raw']),
                    'warehouse_id' => $main->id,
                ]);
                $this->move(StockMovementType::MASUK, $item, 1, null, $main, $actor, $notes, serial: $serial);
            }

            return $units->count();
        });
    }

    /**
     * Hand scanned units from the main warehouse to a technician.
     *
     * @param  array<string>  $serials
     */
    public function sendSerials(array $serials, User $technician, User $actor, ?string $notes = null): int
    {
        return DB::transaction(fn () => $this->moveSerials(StockMovementType::KIRIM, $serials, Warehouse::main(), Warehouse::of($technician), $actor, $notes));
    }

    /**
     * Take scanned units back from a technician into the main warehouse.
     *
     * @param  array<string>  $serials
     */
    public function returnSerials(array $serials, Warehouse $from, User $actor, ?string $notes = null): int
    {
        if ($from->isMain()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Retur hanya dari gudang teknisi']);
        }

        return DB::transaction(fn () => $this->moveSerials(StockMovementType::RETUR, $serials, $from, Warehouse::main(), $actor, $notes));
    }

    /**
     * Turn the material listed in the repair resume into a request for the warehouse. Each line names the
     * crew member whose stock it comes from, so one resume can draw on several technicians. Nothing leaves
     * a warehouse yet: the lines only hold that stock (and a modem's SN) until the warehouse approves.
     * Runs inside the ticket transition's transaction, so a short stock cancels the whole report.
     *
     * @param  array<array{technician_id?: mixed, code?: ?string, quantity?: mixed, serial?: ?string, notes?: ?string}>  $lines
     * @return Collection<int, string> one line per material, for the ticket history
     */
    public function requestForTicket(Ticket $ticket, array $lines, User $actor): Collection
    {
        $lines = collect($lines)->filter(fn ($line) => trim((string) ($line['code'] ?? '')) !== '')->values();
        if ($lines->isEmpty()) {
            return collect();
        }

        return DB::transaction(function () use ($ticket, $lines, $actor) {
            $request = $ticket->materialRequests()->create(['requested_by' => $actor->id, 'status' => MaterialRequestStatus::PENDING]);

            return $lines->map(function (array $line) use ($ticket, $request) {
                $item = $this->findItem($line['code'], 'materials');
                $holder = $ticket->technicians->firstWhere('id', (int) ($line['technician_id'] ?? 0))
                    ?? throw ValidationException::withMessages(['materials' => "Pilih gudang teknisi untuk {$item->code}"]);
                $warehouse = Warehouse::of($holder);
                $notes = trim((string) ($line['notes'] ?? '')) ?: null;
                $serial = null;

                if ($item->tracks_serial) {
                    $serial = $this->unitIn(SerialUnit::normalizeSerial($line['serial'] ?? ''), $warehouse, 'materials', $item);
                    $this->ensureNotRequested($serial, 'materials');
                    $quantity = 1;
                } else {
                    $quantity = (int) ($line['quantity'] ?? 0);
                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['materials' => "Jumlah {$item->code} minimal 1"]);
                    }
                    $this->ensureAvailable($warehouse, $item, $quantity, 'materials');
                }

                return $request->lines()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $warehouse->id,
                    'serial_unit_id' => $serial?->id,
                    'quantity' => $quantity,
                    'notes' => $notes ? mb_substr($notes, 0, 255) : null,
                ])->setRelations(['item' => $item, 'warehouse' => $warehouse, 'serialUnit' => $serial])->describe();
            });
        });
    }

    /**
     * Send material again after the warehouse rejected the last request, whatever stage the ticket is at.
     *
     * @param  array<array{technician_id?: mixed, code?: ?string, quantity?: mixed, serial?: ?string, notes?: ?string}>  $lines
     */
    public function resubmitForTicket(Ticket $ticket, array $lines, User $actor): Collection
    {
        return DB::transaction(function () use ($ticket, $lines, $actor) {
            $ticket = Ticket::query()->lockForUpdate()->with(['technicians', 'materialRequests'])->findOrFail($ticket->id);
            if (! $ticket->awaitsMaterialResubmission()) {
                throw ValidationException::withMessages(['materials' => 'Material tiket ini tidak sedang ditolak Gudang']);
            }
            $materials = $this->requestForTicket($ticket, $lines, $actor);
            if ($materials->isEmpty()) {
                throw ValidationException::withMessages(['materials' => 'Isi minimal satu material']);
            }
            $ticket->histories()->create([
                'user_id' => $actor->id,
                'actor_name' => $actor->name,
                'action' => TicketAction::MATERIAL_DIAJUKAN_ULANG,
                'from_status' => $ticket->status,
                'to_status' => $ticket->status,
                'notes' => "Material diajukan ulang oleh {$actor->name} (menunggu ACC Gudang, stok belum berkurang):\n"
                    .$materials->map(fn (string $line) => "• {$line}")->implode("\n"),
            ]);

            return $materials;
        });
    }

    /**
     * The warehouse accepts a repair resume's material: each line now leaves its technician's warehouse,
     * and a modem's SN moves onto the ticket as installed.
     */
    public function approveRequest(MaterialRequest $request, User $actor, ?string $notes = null): MaterialRequest
    {
        return DB::transaction(function () use ($request, $actor, $notes) {
            $request = $this->lockPending($request);
            $ticket = $request->ticket;
            foreach ($request->lines as $line) {
                if ($line->serialUnit) {
                    $serial = $this->unitIn($line->serialUnit->serial_number, $line->warehouse, 'material_request', $line->item);
                    $this->move(StockMovementType::PAKAI, $line->item, 1, $line->warehouse, null, $actor, $line->notes, $ticket, $serial);
                    $serial->update(['warehouse_id' => null, 'ticket_id' => $ticket->id]);

                    continue;
                }
                try {
                    $this->move(StockMovementType::PAKAI, $line->item, $line->quantity, $line->warehouse, null, $actor, $line->notes, $ticket);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['material_request' => $e->errors()['quantity'] ?? $e->getMessage()]);
                }
            }

            return $this->review($request, MaterialRequestStatus::APPROVED, TicketAction::MATERIAL_DISETUJUI, $actor, $notes);
        });
    }

    /**
     * The warehouse turns the material down: the technician's stock stays as it was.
     */
    public function rejectRequest(MaterialRequest $request, User $actor, string $reason): MaterialRequest
    {
        return DB::transaction(fn () => $this->review($this->lockPending($request), MaterialRequestStatus::REJECTED, TicketAction::MATERIAL_DITOLAK, $actor, $reason));
    }

    /**
     * Look an item up by the code typed or picked in a form. A picked suggestion reads "CODE · NAME".
     */
    public function findItem(string $code, string $field = 'item'): Item
    {
        $code = strtoupper(trim(explode('·', $code)[0]));

        return Item::query()->where('code', $code)->first()
            ?? throw ValidationException::withMessages([$field => "Kode barang {$code} tidak ditemukan"]);
    }

    /**
     * Add or rename items from a warehouse code list (rows of [code, name]). Existing stock is untouched.
     * New MDM codes are modems, so they start out tracked by SN.
     *
     * @param  iterable<array{0: mixed, 1: mixed}>  $rows
     * @return array{created: int, updated: int}
     */
    public function importItems(iterable $rows): array
    {
        $created = $updated = 0;
        DB::transaction(function () use ($rows, &$created, &$updated) {
            foreach ($rows as [$code, $name]) {
                $code = strtoupper(trim((string) $code));
                $name = trim((string) $name);
                if ($code === '' || $name === '') {
                    continue;
                }
                $item = Item::query()->firstOrNew(['code' => $code]);
                if ($item->exists) {
                    $updated++;
                } else {
                    $created++;
                    $item->tracks_serial = str_starts_with($code, 'MDM');
                }
                $item->fill(['name' => mb_substr($name, 0, 150)])->save();
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    private function lockPending(MaterialRequest $request): MaterialRequest
    {
        $request = MaterialRequest::query()->lockForUpdate()->with(['ticket', 'lines.item', 'lines.warehouse', 'lines.serialUnit'])->findOrFail($request->id);
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['material_request' => "Pengajuan ini sudah {$request->status->label()}"]);
        }

        return $request;
    }

    private function review(MaterialRequest $request, MaterialRequestStatus $status, TicketAction $action, User $actor, ?string $notes): MaterialRequest
    {
        $notes = trim((string) $notes) ?: null;
        $request->update([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_notes' => $notes ? mb_substr($notes, 0, 255) : null,
        ]);
        $ticket = $request->ticket;
        $ticket->histories()->create([
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => $action,
            'from_status' => $ticket->status,
            'to_status' => $ticket->status,
            'notes' => ($status === MaterialRequestStatus::APPROVED
                    ? "Material disetujui oleh {$actor->name}, stok teknisi berkurang:\n"
                    : "Material ditolak oleh {$actor->name}, stok teknisi tidak berubah:\n")
                .$request->lines->map(fn (MaterialRequestLine $line) => "• {$line->describe()}")->implode("\n")
                .($notes ? "\nCatatan Gudang: {$notes}" : ''),
        ]);

        return $request;
    }

    /**
     * Stock in a warehouse that a pending resume has already claimed.
     */
    private function requested(Warehouse $warehouse, Item $item): int
    {
        return (int) MaterialRequestLine::query()->pending()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->sum('quantity');
    }

    private function ensureAvailable(Warehouse $warehouse, Item $item, int $quantity, string $field): void
    {
        $free = $this->stock($warehouse, $item)->quantity - $this->requested($warehouse, $item);
        if ($free < $quantity) {
            throw ValidationException::withMessages([
                $field => "Stok {$item->code} di {$warehouse->name} tidak cukup (sisa {$free} {$item->unit} yang belum diajukan)",
            ]);
        }
    }

    private function ensureNotRequested(SerialUnit $serial, string $field): void
    {
        $line = MaterialRequestLine::query()->pending()->where('serial_unit_id', $serial->id)->with('request.ticket')->first();
        if ($line) {
            throw ValidationException::withMessages([$field => "SN {$serial->serial_number} sudah diajukan di tiket {$line->request->ticket->ticket_number}, menunggu ACC Gudang"]);
        }
    }

    /**
     * @param  array<string>  $serials
     */
    private function moveSerials(StockMovementType $type, array $serials, Warehouse $from, Warehouse $to, User $actor, ?string $notes): int
    {
        $serials = collect($serials)->map(fn ($serial) => SerialUnit::normalizeSerial($serial))->filter()->unique()->values();
        if ($serials->isEmpty()) {
            throw ValidationException::withMessages(['serials' => 'Scan minimal satu SN']);
        }
        foreach ($serials as $number) {
            $serial = $this->unitIn($number, $from, 'serials');
            $this->ensureNotRequested($serial, 'serials');
            $this->move($type, $serial->item, 1, $from, $to, $actor, $notes, serial: $serial);
            $serial->update(['warehouse_id' => $to->id]);
        }

        return $serials->count();
    }

    /**
     * The unit with this SN, provided it is sitting in the given warehouse (and is of the given item).
     */
    private function unitIn(string $number, Warehouse $warehouse, string $field, ?Item $item = null): SerialUnit
    {
        if ($number === '') {
            throw ValidationException::withMessages([$field => 'Pilih SN '.($item?->code ?? 'barang')]);
        }
        $serial = SerialUnit::query()->with(['item', 'warehouse'])->lockForUpdate()->where('serial_number', $number)->first()
            ?? throw ValidationException::withMessages([$field => "SN {$number} tidak terdaftar"]);
        if ($serial->warehouse_id !== $warehouse->id) {
            throw ValidationException::withMessages([$field => "SN {$number} tidak ada di {$warehouse->name} (posisi: {$serial->location()})"]);
        }
        if ($item && $serial->item_id !== $item->id) {
            throw ValidationException::withMessages([$field => "SN {$number} adalah {$serial->item->code}, bukan {$item->code}"]);
        }

        return $serial;
    }

    private function ensureCounted(Item $item, string $field): void
    {
        if ($item->tracks_serial) {
            throw ValidationException::withMessages([$field => "{$item->code} memakai SN, gunakan form scan SN"]);
        }
    }

    private function move(StockMovementType $type, Item $item, int $quantity, ?Warehouse $from, ?Warehouse $to, User $actor, ?string $notes, ?Ticket $ticket = null, ?SerialUnit $serial = null): StockMovement
    {
        if ($from) {
            $stock = $this->stock($from, $item);
            if ($stock->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Stok {$item->code} di {$from->name} tidak cukup (sisa {$stock->quantity} {$item->unit})",
                ]);
            }
            $stock->decrement('quantity', $quantity);
        }
        if ($to) {
            $this->stock($to, $item)->increment('quantity', $quantity);
        }

        return StockMovement::query()->create([
            'type' => $type,
            'item_id' => $item->id,
            'serial_unit_id' => $serial?->id,
            'quantity' => $quantity,
            'from_warehouse_id' => $from?->id,
            'to_warehouse_id' => $to?->id,
            'ticket_id' => $ticket?->id,
            'user_id' => $actor->id,
            'notes' => $notes ? mb_substr($notes, 0, 255) : null,
        ]);
    }

    private function stock(Warehouse $warehouse, Item $item): Stock
    {
        return Stock::query()->lockForUpdate()->firstOrCreate(['warehouse_id' => $warehouse->id, 'item_id' => $item->id], ['quantity' => 0]);
    }
}
