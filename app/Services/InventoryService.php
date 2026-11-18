<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketAction;
use App\Enums\UsagePurpose;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\MaterialUsage;
use App\Models\Partner;
use App\Models\Sale;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock moves only through here, so every change in a warehouse has a matching movement row:
 * material comes into the main warehouse, goes out to a technician, comes back as a return,
 * is used up on a ticket's repair once the warehouse approves the resume that reports it, or is sold to a partner. Items that track serials (modems) move one scanned unit
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
     * technician whose stock it comes from (not only the ticket's crew), so one resume can draw on several. Nothing leaves
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

            return $lines->map(function (array $line) use ($request) {
                $item = $this->findItem($line['code'], 'materials');
                $holder = User::query()->where('role', Role::TEKNISI)->find((int) ($line['technician_id'] ?? 0))
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
                    $this->ensureAvailable($warehouse, $item, $quantity, 'materials', showLeft: false);
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
            // The approved material also lands in the usage log, beside what the warehouse records by hand.
            $usage = MaterialUsage::query()->create([
                'used_at' => today(),
                'purpose' => $ticket->isInstallation() ? UsagePurpose::PEMASANGAN_BARU : UsagePurpose::FREE_PERBAIKAN,
                'project' => $ticket->ticket_number,
                'location' => mb_substr(collect([$ticket->customer_name, $ticket->customer_address])->filter()->implode(' · '), 0, 255) ?: $ticket->ticket_number,
                'team' => mb_substr($ticket->technicians()->pluck('name')->implode(', '), 0, 150) ?: null,
                'ticket_id' => $ticket->id,
                'material_request_id' => $request->id,
                'user_id' => $actor->id,
            ]);
            foreach ($request->lines as $line) {
                $usage->lines()->create([
                    'item_id' => $line->item_id,
                    'item_name' => $line->item->name,
                    'warehouse_id' => $line->warehouse_id,
                    'serial_unit_id' => $line->serialUnit?->id,
                    'serial_number' => $line->serialUnit?->serial_number,
                    'mac' => $line->serialUnit?->mac,
                    'quantity' => $line->quantity,
                    'notes' => $line->notes,
                ]);
                if ($line->serialUnit) {
                    $serial = $this->unitIn($line->serialUnit->serial_number, $line->warehouse, 'material_request', $line->item);
                    $this->move(StockMovementType::PAKAI, $line->item, 1, $line->warehouse, null, $actor, $line->notes, $ticket, $serial, usage: $usage);
                    $serial->update(['warehouse_id' => null, 'ticket_id' => $ticket->id]);

                    continue;
                }
                try {
                    $this->move(StockMovementType::PAKAI, $line->item, $line->quantity, $line->warehouse, null, $actor, $line->notes, $ticket, usage: $usage);
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
     * Sell stock out of the main warehouse. The buyer is a partner (by id) or someone typed in; each line is an
     * item with its unit price and either a quantity or, for a device, the scanned SNs.
     *
     * @param  array{partner_id?: mixed, buyer_name?: ?string, buyer_phone?: ?string, sold_at?: ?string, paid?: mixed, notes?: ?string}  $sale
     * @param  array<array{item?: ?string, quantity?: mixed, price?: mixed, serials?: array<string>}>  $lines
     */
    public function sell(array $sale, array $lines, User $actor): Sale
    {
        $lines = collect($lines)->filter(fn ($line) => trim((string) ($line['item'] ?? '')) !== '')->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Tambahkan minimal satu barang']);
        }

        return DB::transaction(function () use ($sale, $lines, $actor) {
            $partner = filled($sale['partner_id'] ?? null) ? Partner::query()->active()->find((int) $sale['partner_id']) : null;
            if (filled($sale['partner_id'] ?? null) && ! $partner) {
                throw ValidationException::withMessages(['partner_id' => 'Mitra tidak ditemukan atau sudah nonaktif']);
            }
            $buyer = $partner?->name ?? trim((string) ($sale['buyer_name'] ?? ''));
            if ($buyer === '') {
                throw ValidationException::withMessages(['buyer_name' => 'Pilih mitra atau isi nama pembeli']);
            }
            $soldAt = Carbon::parse($sale['sold_at'] ?? today())->startOfDay();
            $paid = filter_var($sale['paid'] ?? false, FILTER_VALIDATE_BOOL);
            $notes = trim((string) ($sale['notes'] ?? '')) ?: null;

            $record = Sale::query()->create([
                'sale_number' => Sale::nextNumber($soldAt),
                'partner_id' => $partner?->id,
                'buyer_name' => mb_substr($buyer, 0, 150),
                'buyer_phone' => $partner ? $partner->phone : (trim((string) ($sale['buyer_phone'] ?? '')) ?: null),
                'sold_at' => $soldAt,
                'payment_status' => $paid ? PaymentStatus::LUNAS : PaymentStatus::BELUM_LUNAS,
                'paid_at' => $paid ? now() : null,
                'paid_by' => $paid ? $actor->id : null,
                'notes' => $notes ? mb_substr($notes, 0, 255) : null,
                'user_id' => $actor->id,
            ]);
            $main = Warehouse::main();
            $movementNote = "Dijual ke {$record->buyer_name} ({$record->sale_number})";

            $total = 0;
            foreach ($lines as $line) {
                $item = $this->findItem($line['item'], 'lines');
                $price = (int) ($line['price'] ?? -1);
                if ($price < 0) {
                    throw ValidationException::withMessages(['lines' => "Isi harga jual {$item->code}"]);
                }

                if ($item->tracks_serial) {
                    $serials = collect($line['serials'] ?? [])->map(fn ($sn) => SerialUnit::normalizeSerial($sn))->filter()->unique()->values();
                    if ($serials->isEmpty()) {
                        throw ValidationException::withMessages(['lines' => "Scan SN {$item->code} yang dijual"]);
                    }
                    foreach ($serials as $number) {
                        $serial = $this->unitIn($number, $main, 'lines', $item);
                        $this->move(StockMovementType::JUAL, $item, 1, $main, null, $actor, $movementNote, serial: $serial, sale: $record);
                        $serial->update(['warehouse_id' => null, 'sale_id' => $record->id]);
                    }
                    $quantity = $serials->count();
                } else {
                    $quantity = (int) ($line['quantity'] ?? 0);
                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['lines' => "Jumlah {$item->code} minimal 1"]);
                    }
                    try {
                        $this->move(StockMovementType::JUAL, $item, $quantity, $main, null, $actor, $movementNote, sale: $record);
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(['lines' => $e->errors()['quantity'] ?? $e->getMessage()]);
                    }
                }

                $record->lines()->create(['item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => $price, 'subtotal' => $price * $quantity]);
                $total += $price * $quantity;
            }
            $record->update(['total' => $total]);

            return $record;
        });
    }

    /**
     * Record material used on a job outside a ticket's resume (or beside it): new installs, swaps, network
     * work. Everything is taken from one warehouse, the main one or a technician's, and leaves stock at once.
     *
     * @param  array{used_at?: ?string, purpose: string, project?: ?string, location: string, team?: ?string, ticket_id?: ?int, removed_device?: ?string, notes?: ?string}  $data
     * @param  array<array{item?: ?string, quantity?: mixed, serials?: array<string>, notes?: ?string}>  $lines
     */
    public function recordUsage(array $data, array $lines, Warehouse $source, User $actor): MaterialUsage
    {
        $lines = collect($lines)->filter(fn ($line) => trim((string) ($line['item'] ?? '')) !== '')->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Tambahkan minimal satu barang']);
        }
        $text = fn (?string $value, int $max) => ($value = trim((string) $value)) === '' ? null : mb_substr($value, 0, $max);

        return DB::transaction(function () use ($data, $lines, $source, $actor, $text) {
            $usage = MaterialUsage::query()->create([
                'used_at' => Carbon::parse($data['used_at'] ?? today())->startOfDay(),
                'purpose' => UsagePurpose::from($data['purpose']),
                'project' => $text($data['project'] ?? null, 100),
                'location' => $text($data['location'] ?? null, 255) ?? throw ValidationException::withMessages(['location' => 'Isi lokasi pemasangan']),
                'team' => $text($data['team'] ?? null, 150),
                'ticket_id' => $data['ticket_id'] ?? null,
                'removed_device' => $text($data['removed_device'] ?? null, 255),
                'notes' => $text($data['notes'] ?? null, 255),
                'user_id' => $actor->id,
            ]);
            $movementNote = mb_substr("{$usage->purpose->label()} · {$usage->location}", 0, 255);

            foreach ($lines as $line) {
                $item = $this->findItem($line['item'], 'lines');
                $notes = $text($line['notes'] ?? null, 255);

                if ($item->tracks_serial) {
                    $serials = collect($line['serials'] ?? [])->map(fn ($sn) => SerialUnit::normalizeSerial($sn))->filter()->unique()->values();
                    if ($serials->isEmpty()) {
                        throw ValidationException::withMessages(['lines' => "Scan SN {$item->code} yang dipakai"]);
                    }
                    foreach ($serials as $number) {
                        $serial = $this->unitIn($number, $source, 'lines', $item);
                        $this->ensureNotRequested($serial, 'lines');
                        $this->move(StockMovementType::PAKAI, $item, 1, $source, null, $actor, $movementNote, serial: $serial, usage: $usage);
                        $serial->update(['warehouse_id' => null, 'material_usage_id' => $usage->id]);
                        $usage->lines()->create([
                            'item_id' => $item->id, 'item_name' => $item->name, 'warehouse_id' => $source->id,
                            'serial_unit_id' => $serial->id, 'serial_number' => $serial->serial_number, 'mac' => $serial->mac,
                            'quantity' => 1, 'notes' => $notes,
                        ]);
                    }

                    continue;
                }

                $quantity = (int) ($line['quantity'] ?? 0);
                if ($quantity < 1) {
                    throw ValidationException::withMessages(['lines' => "Jumlah {$item->code} minimal 1"]);
                }
                // Stock a pending resume already claims is not free to use again.
                $this->ensureAvailable($source, $item, $quantity, 'lines');
                $this->move(StockMovementType::PAKAI, $item, $quantity, $source, null, $actor, $movementNote, usage: $usage);
                $usage->lines()->create([
                    'item_id' => $item->id, 'item_name' => $item->name, 'warehouse_id' => $source->id,
                    'quantity' => $quantity, 'notes' => $notes,
                ]);
            }

            return $usage;
        });
    }

    /**
     * Undo a usage entered by mistake: the stock (and each device) goes back to the warehouse it came from,
     * and the usage stays in the log marked cancelled. Imported history never moved stock, so it is only marked.
     */
    public function cancelUsage(MaterialUsage $usage, User $actor, string $reason): MaterialUsage
    {
        return DB::transaction(function () use ($usage, $actor, $reason) {
            $usage = MaterialUsage::query()->lockForUpdate()->with(['lines.item', 'lines.warehouse', 'lines.serialUnit'])->findOrFail($usage->id);
            if ($usage->isCancelled()) {
                throw ValidationException::withMessages(['cancel_reason' => 'Pemakaian ini sudah dibatalkan']);
            }
            if ($usage->material_request_id) {
                throw ValidationException::withMessages(['cancel_reason' => 'Material dari tiket sudah di-ACC Gudang; koreksi lewat retur ke gudang']);
            }
            if (! $usage->isImported()) {
                foreach ($usage->lines as $line) {
                    if (! $line->item || ! $line->warehouse) {
                        continue;
                    }
                    $this->move(StockMovementType::KOREKSI, $line->item, $line->quantity, null, $line->warehouse, $actor, "Batal pemakaian: {$reason}", serial: $line->serialUnit, usage: $usage);
                    $line->serialUnit?->update(['warehouse_id' => $line->warehouse_id, 'material_usage_id' => null]);
                }
            }
            $usage->update(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);

            return $usage;
        });
    }

    /**
     * Mark a sale paid (or back to unpaid when it was ticked by mistake).
     */
    public function setPayment(Sale $sale, PaymentStatus $status, User $actor, ?string $notes = null): Sale
    {
        $notes = trim((string) $notes) ?: null;
        $sale->update([
            'payment_status' => $status,
            'paid_at' => $status === PaymentStatus::LUNAS ? now() : null,
            'paid_by' => $status === PaymentStatus::LUNAS ? $actor->id : null,
            'payment_notes' => $notes ? mb_substr($notes, 0, 255) : $sale->payment_notes,
        ]);

        return $sale;
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
     * A new item goes into the category its code and name suggest; one in a device category (or an MDM
     * code) starts out tracked by SN.
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
                    $category = ItemCategory::guess($code, $name);
                    $item->item_category_id = $category?->id;
                    $item->tracks_serial = str_starts_with($code, 'MDM') || (bool) $category?->requires_serial;
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

    /**
     * The repair resume keeps stock counts from technicians, so its message leaves out what is left.
     */
    private function ensureAvailable(Warehouse $warehouse, Item $item, int $quantity, string $field, bool $showLeft = true): void
    {
        $free = $this->stock($warehouse, $item)->quantity - $this->requested($warehouse, $item);
        if ($free < $quantity) {
            throw ValidationException::withMessages([
                $field => "Stok {$item->code} di {$warehouse->name} tidak cukup".($showLeft ? " (sisa {$free} {$item->unit} yang belum diajukan)" : ''),
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

    private function move(StockMovementType $type, Item $item, int $quantity, ?Warehouse $from, ?Warehouse $to, User $actor, ?string $notes, ?Ticket $ticket = null, ?SerialUnit $serial = null, ?Sale $sale = null, ?MaterialUsage $usage = null): StockMovement
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
            'sale_id' => $sale?->id,
            'material_usage_id' => $usage?->id,
            'user_id' => $actor->id,
            'notes' => $notes ? mb_substr($notes, 0, 255) : null,
        ]);
    }

    private function stock(Warehouse $warehouse, Item $item): Stock
    {
        return Stock::query()->lockForUpdate()->firstOrCreate(['warehouse_id' => $warehouse->id, 'item_id' => $item->id], ['quantity' => 0]);
    }
}
