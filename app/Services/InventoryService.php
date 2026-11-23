<?php

namespace App\Services;

use App\Enums\MaterialRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\TicketAction;
use App\Enums\UsagePurpose;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialLog;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\MaterialUsage;
use App\Models\Partner;
use App\Models\Sale;
use App\Models\SerialUnit;
use App\Models\Stock;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InAppNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Stock moves only through here, so every change in a warehouse has a matching movement row:
 * material comes into the main warehouse, goes out to a technician, comes back as a return,
 * is used up on a ticket's repair once the warehouse approves the resume that reports it, or is sold to a partner. Items that track serials (modems) move one scanned unit
 * at a time, so the warehouse always knows where each SN is.
 */
class InventoryService
{
    public function __construct(private ItemClassifier $classifier = new ItemClassifier) {}

    public function receive(Item $item, int $quantity, User $actor, ?string $notes = null): StockMovement
    {
        $this->ensureCounted($item, 'item');

        return DB::transaction(fn () => $this->move(StockMovementType::MASUK, $item, $quantity, null, Warehouse::main(), $actor, $notes));
    }

    /**
     * Bring a counted item's main-warehouse stock to an exact figure (an opening balance or a stock take):
     * the difference comes in as MASUK or goes out as KOREKSI. Null when the stock already matches.
     */
    public function setMainStock(Item $item, int $quantity, User $actor, ?string $notes = null): ?StockMovement
    {
        $this->ensureCounted($item, 'item');

        return DB::transaction(function () use ($item, $quantity, $actor, $notes) {
            $main = Warehouse::main();
            $difference = max(0, $quantity) - $this->stock($main, $item)->quantity;

            return match (true) {
                $difference > 0 => $this->move(StockMovementType::MASUK, $item, $difference, null, $main, $actor, $notes),
                $difference < 0 => $this->move(StockMovementType::KOREKSI, $item, -$difference, $main, null, $actor, $notes),
                default => null,
            };
        });
    }

    /**
     * Hand counted stock to a technician. An item set to convert on send (a roll of dropcore) leaves the main
     * warehouse in its own unit and lands in the technician's warehouse as the other item (meters of dropcore),
     * recorded as two KIRIM rows, one per item, so each item's history shows the hand-over.
     */
    public function send(Item $item, int $quantity, User $technician, User $actor, ?string $notes = null): StockMovement
    {
        return DB::transaction(function () use ($item, $quantity, $technician, $actor, $notes) {
            $main = Warehouse::main();
            $own = Warehouse::of($technician);
            // An SN item sends by quantity only from the stock it had before it switched to SN.
            if ($item->tracks_serial) {
                $this->ensureLoose($main, $item, $quantity, 'item');
            }
            if (! $item->convertsOnSend()) {
                return $this->move(StockMovementType::KIRIM, $item, $quantity, $main, $own, $actor, $notes);
            }

            $target = $this->conversionTarget($item, 'item');
            $received = $quantity * $item->send_ratio;
            $with = fn (string $line) => $notes ? "{$line} · {$notes}" : $line;

            $movement = $this->move(StockMovementType::KIRIM, $item, $quantity, $main, $own, $actor,
                $with("Diterima teknisi sebagai {$received} {$target->unit} {$target->code}"), give: false);
            $this->move(StockMovementType::KIRIM, $target, $received, $main, $own, $actor,
                $with("Konversi dari {$quantity} {$item->unit} {$item->code}"), take: false);

            return $movement;
        });
    }

    public function returnToMain(Item $item, int $quantity, Warehouse $from, User $actor, ?string $notes = null): StockMovement
    {
        if ($from->isMain()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Retur hanya dari gudang teknisi']);
        }

        return DB::transaction(function () use ($item, $quantity, $from, $actor, $notes) {
            // Stock a pending resume claims stays with the technician until the warehouse decides on it.
            $item->tracks_serial
                ? $this->ensureLoose($from, $item, $quantity, 'quantity')
                : $this->ensureAvailable($from, $item, $quantity, 'quantity');

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
        return DB::transaction(fn () => $this->moveSerials(StockMovementType::KIRIM, $serials, Warehouse::main(), Warehouse::of($technician), $actor, $notes, convert: true));
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
                $holder = User::query()->whereIn('role', Role::stockHolders())->find((int) ($line['technician_id'] ?? 0))
                    ?? throw ValidationException::withMessages(['materials' => "Pilih gudang teknisi untuk {$item->code}"]);
                $warehouse = Warehouse::of($holder);
                $notes = trim((string) ($line['notes'] ?? '')) ?: null;
                $serial = null;

                $number = SerialUnit::normalizeSerial($line['serial'] ?? '');
                if ($item->tracks_serial && $number !== '') {
                    $serial = $this->unitIn($number, $warehouse, 'materials', $item);
                    $this->ensureNotRequested($serial, 'materials');
                    $quantity = 1;
                } else {
                    $quantity = (int) ($line['quantity'] ?? 0);
                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['materials' => "Jumlah {$item->code} minimal 1"]);
                    }
                    // A counted item (cable, connectors) may be reported beyond what the warehouse knows the technician
                    // holds: they often still have leftovers of their own that were never recorded. The warehouse is
                    // told when the approval takes the stock below zero and adjusts it after checking.
                    // Only something the technician has carried, though: an item never handed to them is a typo.
                    if ($item->tracks_serial) {
                        $this->ensureLoose($warehouse, $item, $quantity, 'materials');
                    } elseif (! Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->exists()) {
                        throw ValidationException::withMessages(['materials' => "{$item->code} tidak pernah dibawa {$holder->name}, pilih gudang teknisi yang memakainya"]);
                    }
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
            $short = [];
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
                    $serial->update(['warehouse_id' => null, 'ticket_id' => $ticket->id] + $this->installedBy($line->warehouse, $usage->location, $usage->purpose->label(), today()));

                    continue;
                }
                // A counted item is taken even past zero (see requestForTicket); an SN item's loose stock is not.
                $counted = ! $line->item->tracks_serial;
                try {
                    $this->move(StockMovementType::PAKAI, $line->item, $line->quantity, $line->warehouse, null, $actor, $line->notes, $ticket, usage: $usage, allowShort: $counted);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['material_request' => $e->errors()['quantity'] ?? $e->getMessage()]);
                }
                if ($counted && ($left = $this->stockQuantity($line->warehouse, $line->item)) < 0) {
                    $short[] = [$line, $left];
                }
            }

            // The ticket's customer now has this material (Gudang Pelanggan).
            app(CustomerMaterialService::class)->recordInstall($usage->refresh(), $actor, now());

            if ($short !== []) {
                $this->notifyMinus($ticket, $short);
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

                $serials = collect($line['serials'] ?? [])->map(fn ($sn) => SerialUnit::normalizeSerial($sn))->filter()->unique()->values();
                if ($item->tracks_serial && $serials->isNotEmpty()) {
                    foreach ($serials as $number) {
                        $serial = $this->unitIn($number, $main, 'lines', $item);
                        $this->move(StockMovementType::JUAL, $item, 1, $main, null, $actor, $movementNote, serial: $serial, sale: $record);
                        $serial->update(['warehouse_id' => null, 'sale_id' => $record->id, 'holder_name' => mb_substr($record->buyer_name, 0, 150), 'taken_at' => $soldAt, 'installed_to' => null, 'install_status' => 'TERJUAL', 'replaced_device' => null, 'condition' => null]);
                    }
                    $quantity = $serials->count();
                } else {
                    $quantity = (int) ($line['quantity'] ?? 0);
                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['lines' => $item->tracks_serial ? "Scan SN {$item->code} yang dijual" : "Jumlah {$item->code} minimal 1"]);
                    }
                    if ($item->tracks_serial) {
                        $this->ensureLoose($main, $item, $quantity, 'lines');
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
                'customer_code' => $text($data['customer_code'] ?? null, 50),
                'removed_device' => $text($data['removed_device'] ?? null, 255),
                'notes' => $text($data['notes'] ?? null, 255),
                'user_id' => $actor->id,
            ]);
            $movementNote = mb_substr("{$usage->purpose->label()} · {$usage->location}", 0, 255);

            foreach ($lines as $line) {
                $item = $this->findItem($line['item'], 'lines');
                $notes = $text($line['notes'] ?? null, 255);

                $serials = collect($line['serials'] ?? [])->map(fn ($sn) => SerialUnit::normalizeSerial($sn))->filter()->unique()->values();
                if ($item->tracks_serial && $serials->isNotEmpty()) {
                    foreach ($serials as $number) {
                        $serial = $this->unitIn($number, $source, 'lines', $item);
                        $this->ensureNotRequested($serial, 'lines');
                        $this->move(StockMovementType::PAKAI, $item, 1, $source, null, $actor, $movementNote, serial: $serial, usage: $usage);
                        $serial->update(['warehouse_id' => null, 'material_usage_id' => $usage->id]
                            + $this->installedBy($source, $usage->location, $usage->purpose->label(), $usage->used_at, $usage->removed_device));
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
                    throw ValidationException::withMessages(['lines' => $item->tracks_serial ? "Scan SN {$item->code} yang dipakai" : "Jumlah {$item->code} minimal 1"]);
                }
                // Stock a pending resume already claims is not free to use again.
                $item->tracks_serial
                    ? $this->ensureLoose($source, $item, $quantity, 'lines')
                    : $this->ensureAvailable($source, $item, $quantity, 'lines');
                $this->move(StockMovementType::PAKAI, $item, $quantity, $source, null, $actor, $movementNote, usage: $usage);
                $usage->lines()->create([
                    'item_id' => $item->id, 'item_name' => $item->name, 'warehouse_id' => $source->id,
                    'quantity' => $quantity, 'notes' => $notes,
                ]);
            }

            // Done for a customer ID (typed, or the ticket's): the material is now theirs in Gudang Pelanggan.
            app(CustomerMaterialService::class)->recordInstall($usage, $actor);

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
                    $line->serialUnit?->update(['warehouse_id' => $line->warehouse_id, 'material_usage_id' => null, 'installed_to' => null, 'install_status' => null, 'replaced_device' => null]);
                }
            }
            $usage->update(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => mb_substr($reason, 0, 255)]);
            app(CustomerMaterialService::class)->cancel($usage, $actor, $reason);

            return $usage;
        });
    }

    /**
     * Material comes off a customer (Gudang Pelanggan). Back to the office, it re-enters the main warehouse as
     * returned stock (an SN unit as an idle, used unit); otherwise (broken, lost, left behind) it is written off.
     */
    public function removeFromCustomer(CustomerMaterial $material, User $actor, string $reason, bool $backToOffice): CustomerMaterial
    {
        return DB::transaction(function () use ($material, $actor, $reason, $backToOffice) {
            $material = CustomerMaterial::query()->lockForUpdate()->with(['item', 'serialUnit'])->findOrFail($material->id);
            if (! $material->isInstalled()) {
                throw ValidationException::withMessages(['reason' => 'Material ini sudah dilepas dari pelanggan']);
            }
            $customers = app(CustomerMaterialService::class);
            $from = $customers->customerLabel($material->customer_code);
            $stamp = today()->format('d/m/Y');
            $serial = $material->serialUnit;

            if ($backToOffice && $material->item) {
                $main = Warehouse::main();
                $this->move(StockMovementType::RETUR, $material->item, $material->quantity, null, $main, $actor, mb_substr("Dilepas dari {$from}: {$reason}", 0, 255), serial: $serial);
                // Back in the main warehouse as a used unit: it remembers where it was, and when it came back.
                $serial?->update(['warehouse_id' => $main->id, 'ticket_id' => null, 'material_usage_id' => null,
                    'condition' => mb_substr("KEMBALI KE KANTOR {$stamp} · {$reason}", 0, 255)]);

                return $customers->markRemoved($material, CustomerMaterialLog::KEMBALI, $actor, $reason, $main->name);
            }

            // Written off: the unit stays out of stock, filed as returned / broken on the tracking page.
            $serial?->update(['ticket_id' => null, 'material_usage_id' => null, 'condition' => mb_substr("DILEPAS {$stamp} · {$reason}", 0, 255)]);

            return $customers->markRemoved($material, CustomerMaterialLog::LEPAS, $actor, $reason);
        });
    }

    /**
     * Mark a sale paid (or back to unpaid when it was ticked by mistake).
     */
    /**
     * A used device that came back from the field and was checked fit for use re-enters the main warehouse
     * (RETUR from no warehouse: "back from a customer"). An SN item comes back as its known unit, or is
     * registered when the SN is new to the system; a counted item comes back by quantity.
     */
    public function takeBackFromField(Item $item, int $quantity, ?string $serialNumber, ?string $mac, User $actor, string $notes, string $condition): ?SerialUnit
    {
        return DB::transaction(function () use ($item, $quantity, $serialNumber, $mac, $actor, $notes, $condition) {
            $main = Warehouse::main();
            if (! $item->tracks_serial) {
                $this->move(StockMovementType::RETUR, $item, max(1, $quantity), null, $main, $actor, $notes);

                return null;
            }

            $number = SerialUnit::normalizeSerial($serialNumber);
            if ($number === '') {
                throw ValidationException::withMessages(['serial_number' => "{$item->code} memakai SN, isi SN alat cabutan ini"]);
            }
            if (filled($mac) && SerialUnit::normalizeMac($mac) === null) {
                throw ValidationException::withMessages(['mac' => "MAC tidak valid: {$mac}"]);
            }
            $unit = SerialUnit::query()->lockForUpdate()->with('warehouse')->where('serial_number', $number)->first();
            if ($unit?->warehouse) {
                throw ValidationException::withMessages(['serial_number' => "SN {$number} tercatat masih ada di {$unit->warehouse->name}"]);
            }
            $unit ??= SerialUnit::query()->create(['item_id' => $item->id, 'serial_number' => $number]);
            $unit->update([
                'warehouse_id' => $main->id, 'ticket_id' => null, 'material_usage_id' => null,
                'mac' => SerialUnit::normalizeMac($mac) ?? $unit->mac,
                'condition' => mb_substr($condition, 0, 255),
            ]);
            $this->move(StockMovementType::RETUR, $unit->item, 1, null, $main, $actor, $notes, serial: $unit);

            return $unit;
        });
    }

    /**
     * Set a warehouse's stock of an item to the figure actually counted, e.g. a technician's stock gone minus
     * after more was entered than they held. The difference is a PENYESUAIAN movement, and the figure before,
     * after and the reason go to the adjustment log that only supervisors read. An SN item can only be set as
     * low as the units with an SN it holds: those move by SN, not by a count.
     */
    public function adjust(Warehouse $warehouse, Item $item, int $counted, User $actor, string $reason): StockAdjustment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Isi alasan adjust stok']);
        }
        if ($counted < 0) {
            throw ValidationException::withMessages(['quantity' => 'Stok hasil hitung tidak boleh minus']);
        }

        return DB::transaction(function () use ($warehouse, $item, $counted, $actor, $reason) {
            $stock = $this->stock($warehouse, $item);
            $before = (int) $stock->quantity;
            if ($counted === $before) {
                throw ValidationException::withMessages(['quantity' => "Stok {$item->code} di {$warehouse->name} sudah {$before} {$item->unit}, tidak ada yang diubah"]);
            }
            if ($item->tracks_serial) {
                $units = SerialUnit::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->count();
                if ($counted < $units) {
                    throw ValidationException::withMessages(['quantity' => "{$warehouse->name} masih memegang {$units} unit {$item->code} ber-SN; stok tidak bisa di bawah {$units}. Unit ber-SN dipindah lewat retur / pemakaian SN."]);
                }
            }
            if ($counted < ($claimed = $this->requested($warehouse, $item))) {
                throw ValidationException::withMessages(['quantity' => "{$claimed} {$item->unit} {$item->code} sedang diajukan di resume yang belum di-ACC; ACC / tolak dulu atau isi minimal {$claimed}"]);
            }

            $difference = $counted - $before;
            $notes = "Stok {$before} → {$counted}";
            $movement = $difference > 0
                ? $this->move(StockMovementType::PENYESUAIAN, $item, $difference, null, $warehouse, $actor, $notes)
                : $this->move(StockMovementType::PENYESUAIAN, $item, -$difference, $warehouse, null, $actor, $notes);

            return StockAdjustment::query()->create([
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'stock_movement_id' => $movement->id,
                'user_id' => $actor->id,
                'quantity_before' => $before,
                'quantity_after' => $counted,
                'difference' => $difference,
                'reason' => mb_substr($reason, 0, 255),
            ]);
        });
    }

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
        $code = Item::stripLooseMark(strtoupper(trim(explode('·', $code)[0])));

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
                    // Items guessed into an SN category are devices from the code list, scanned with their MAC.
                    $item->scans_mac = $item->tracks_serial;
                    // Jenis, merk and sifat read from the name, so the item lands in the stock tree.
                    $item->variant = $this->classifier->variant($category?->name, $name);
                    $item->brand = $this->classifier->brand($name);
                    $item->kind = $category?->kind ?? $this->classifier->kind($category?->name);
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

    /**
     * Stock of an SN item in a warehouse that has no SN: what it held before the item switched to SN (e.g. cable
     * already with technicians). It is counted, not scanned, and is what is left once the units with an SN and
     * the loose quantity a pending resume claims are taken out.
     */
    public function looseAvailable(Warehouse $warehouse, Item $item): int
    {
        if (! $item->tracks_serial) {
            return 0;
        }
        $units = SerialUnit::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->count();
        $claimed = (int) MaterialRequestLine::query()->pending()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)
            ->whereNull('serial_unit_id')->sum('quantity');

        return max(0, $this->stockQuantity($warehouse, $item) - $units - $claimed);
    }

    /**
     * Add a "(TANPA SN)" pick after each SN item in a stock picker whose warehouse still holds loose stock of it,
     * so that stock can be used by quantity. Rows are {code, serial, ...} with an optional `held`, which for the
     * SN row then counts only its units.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withLooseRows(array $rows, Warehouse $warehouse): array
    {
        $serialCodes = collect($rows)->where('serial', true)->pluck('code');
        if ($serialCodes->isEmpty()) {
            return $rows;
        }
        $items = Item::query()->whereIn('code', $serialCodes)->get()->keyBy('code');
        $out = [];
        foreach ($rows as $row) {
            $loose = ($row['serial'] ?? false) && isset($items[$row['code']]) ? $this->looseAvailable($warehouse, $items[$row['code']]) : 0;
            if ($loose > 0 && array_key_exists('held', $row)) {
                $row['held'] = max(0, (int) $row['held'] - $loose);
            }
            $out[] = $row;
            if ($loose > 0) {
                $out[] = ['code' => Item::looseCode($row['code']), 'serial' => false, 'mac' => false, 'loose' => true]
                    + (array_key_exists('held', $row) ? ['held' => $loose] : [])
                    + $row;
            }
        }

        return $out;
    }

    private function ensureLoose(Warehouse $warehouse, Item $item, int $quantity, string $field): void
    {
        $free = $this->looseAvailable($warehouse, $item);
        if ($free < $quantity) {
            throw ValidationException::withMessages([$field => $free === 0
                ? "{$item->code} memakai SN, pilih atau scan SN-nya"
                : "Stok {$item->code} tanpa SN di {$warehouse->name} tinggal {$free} {$item->unit}"]);
        }
    }

    private function stockQuantity(Warehouse $warehouse, Item $item): int
    {
        return (int) Stock::query()->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('quantity');
    }

    /**
     * What a unit leaving stock to be installed records, in the same fields the TRACKING ALAT sheet filled in:
     * who took it, when, where it went, why, and the device it replaced. So app installs list beside imported ones.
     *
     * @return array<string, mixed>
     */
    private function installedBy(Warehouse $from, ?string $place, string $purpose, mixed $date, ?string $replaced = null): array
    {
        return [
            'holder_name' => $this->holderOf($from),
            'taken_at' => $date,
            'installed_to' => $place ? mb_substr($place, 0, 255) : null,
            'install_status' => mb_substr(mb_strtoupper($purpose), 0, 100),
            'replaced_device' => $replaced ? mb_substr($replaced, 0, 150) : null,
            'condition' => null,
        ];
    }

    private function holderOf(Warehouse $warehouse): string
    {
        return mb_substr($warehouse->user?->name ?? $warehouse->name, 0, 150);
    }

    private function ensureNotRequested(SerialUnit $serial, string $field): void
    {
        $line = MaterialRequestLine::query()->pending()->where('serial_unit_id', $serial->id)->with('request.ticket')->first();
        if ($line) {
            throw ValidationException::withMessages([$field => "SN {$serial->serial_number} sudah diajukan di tiket {$line->request->ticket->ticket_number}, menunggu ACC Gudang"]);
        }
    }

    /**
     * With `convert` (a send to a technician), a unit whose item converts on send (a barcoded roll of dropcore)
     * is opened into the technician's warehouse as meters of the target item: the roll's SN leaves stock.
     *
     * @param  array<string>  $serials
     */
    private function moveSerials(StockMovementType $type, array $serials, Warehouse $from, Warehouse $to, User $actor, ?string $notes, bool $convert = false): int
    {
        $serials = collect($serials)->map(fn ($serial) => SerialUnit::normalizeSerial($serial))->filter()->unique()->values();
        if ($serials->isEmpty()) {
            throw ValidationException::withMessages(['serials' => 'Scan minimal satu SN']);
        }
        foreach ($serials as $number) {
            $serial = $this->unitIn($number, $from, 'serials');
            $this->ensureNotRequested($serial, 'serials');
            $item = $serial->item;
            if ($convert && $item->convertsOnSend()) {
                $target = $this->conversionTarget($item, 'serials');
                $with = fn (string $line) => $notes ? "{$line} · {$notes}" : $line;
                $this->move($type, $item, 1, $from, $to, $actor, $with("Dibuka teknisi jadi {$item->send_ratio} {$target->unit} {$target->code}"), serial: $serial, give: false);
                $this->move($type, $target, $item->send_ratio, $from, $to, $actor, $with("Konversi dari SN {$serial->serial_number} ({$item->code})"), take: false);
                $serial->update(['warehouse_id' => null, 'converted_to_warehouse_id' => $to->id]);

                continue;
            }
            $this->move($type, $item, 1, $from, $to, $actor, $notes, serial: $serial);
            $serial->update(['warehouse_id' => $to->id] + ($to->isMain() ? [] : ['holder_name' => $this->holderOf($to), 'taken_at' => today()]));
        }

        return $serials->count();
    }

    private function conversionTarget(Item $item, string $field): Item
    {
        $target = $item->sendAs;
        if (! $target || $target->tracks_serial) {
            throw ValidationException::withMessages([$field => "Konversi {$item->code} tidak valid, cek pengaturannya di Jenis Barang"]);
        }

        return $target;
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

    /**
     * Record a movement and apply it to stock. A converted send records both rows from main to the technician,
     * but only takes the source item from main ($give false) and only gives the target item to the technician ($take false).
     */
    private function move(StockMovementType $type, Item $item, int $quantity, ?Warehouse $from, ?Warehouse $to, User $actor, ?string $notes, ?Ticket $ticket = null, ?SerialUnit $serial = null, ?Sale $sale = null, ?MaterialUsage $usage = null, bool $take = true, bool $give = true, bool $allowShort = false): StockMovement
    {
        if ($from && $take) {
            $stock = $this->stock($from, $item);
            if (! $allowShort && $stock->quantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Stok {$item->code} di {$from->name} tidak cukup (sisa {$stock->quantity} {$item->unit})",
                ]);
            }
            $stock->decrement('quantity', $quantity);
        }
        if ($to && $give) {
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

    /**
     * Tell the warehouse team that an approved resume left a technician's stock below zero, one notice per item,
     * each opening Adjust Stok on that warehouse and item. Sent inside the approval's transaction, so a failed
     * approval leaves no notice behind.
     *
     * @param  list<array{0: MaterialRequestLine, 1: int}>  $short
     */
    private function notifyMinus(Ticket $ticket, array $short): void
    {
        $team = User::query()->where('role', Role::GUDANG)->where('active', true)->get();
        if ($team->isEmpty()) {
            return;
        }
        foreach ($short as [$line, $left]) {
            Notification::send($team, new InAppNotification(
                "Stok minus · {$line->warehouse->name}",
                "{$line->item->code} {$line->item->name} terpakai {$line->quantity} {$line->item->unit} di {$ticket->ticket_number}, stok jadi {$left} {$line->item->unit}. Cek sisa stok pribadi teknisi lalu adjust.",
                route('stock-adjustments.index', ['gudang' => $line->warehouse_id, 'barang' => $line->item->code], absolute: false),
                'alert-triangle',
            ));
        }
    }

    private function stock(Warehouse $warehouse, Item $item): Stock
    {
        return Stock::query()->lockForUpdate()->firstOrCreate(['warehouse_id' => $warehouse->id, 'item_id' => $item->id], ['quantity' => 0]);
    }
}
