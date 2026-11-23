<?php

namespace App\Services;

use App\Enums\RemovedDeviceStatus;
use App\Enums\Role;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialLog;
use App\Models\RemovedDevice;
use App\Models\SerialUnit;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Alat cabutan: devices a technician takes off a customer. The repair resume reports them (typed by hand,
 * since most customers have no record of what they hold yet), the technician carries them until the warehouse
 * receives them (within RemovedDevice::DAYS_TO_RETURN days, or the warehouse is reminded), and the warehouse then
 * checks each: fit for use goes back into the main warehouse as a used unit, broken stays out of stock.
 */
class RemovedDeviceService
{
    public function __construct(private InventoryService $inventory, private CustomerMaterialService $customers) {}

    /**
     * @param  array<array{name?: ?string, serial?: ?string, mac?: ?string, quantity?: mixed, condition?: ?string, notes?: ?string}>  $lines
     * @return Collection<int, string> one line per device, for the ticket history
     */
    public function recordFromResume(Ticket $ticket, array $lines, User $actor): Collection
    {
        $lines = collect($lines)->filter(fn ($line) => trim((string) ($line['name'] ?? '')) !== '')->values();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['removed_devices' => 'Isi alat yang dicabut, atau pilih "Tidak ada"']);
        }
        // Whoever reports, the device is in the hands of a technician on site.
        $holder = $actor->role === Role::TEKNISI ? $actor : ($ticket->technicians()->orderBy('name')->first() ?? $actor);

        return DB::transaction(fn () => $lines->map(function (array $line) use ($ticket, $actor, $holder) {
            $serial = SerialUnit::normalizeSerial($line['serial'] ?? '') ?: null;
            $macRaw = trim((string) ($line['mac'] ?? ''));
            $mac = SerialUnit::normalizeMac($macRaw);
            if ($macRaw !== '' && $mac === null) {
                throw ValidationException::withMessages(['removed_devices' => "MAC alat cabutan tidak valid: {$macRaw}"]);
            }
            $condition = array_key_exists($line['condition'] ?? '', RemovedDevice::FIELD_CONDITIONS) ? $line['condition'] : 'TIDAK_TAHU';

            $device = RemovedDevice::query()->create([
                'ticket_id' => $ticket->id,
                'customer_code' => $ticket->customer_id ? mb_substr($ticket->customer_id, 0, 50) : null,
                'customer_name' => mb_substr((string) $ticket->customer_name, 0, 150) ?: null,
                'technician_id' => $holder->id,
                'reported_by' => $actor->id,
                'name' => mb_substr(trim($line['name']), 0, 150),
                'serial_number' => $serial ? mb_substr($serial, 0, 100) : null,
                'mac' => $mac,
                'quantity' => max(1, min(1000, (int) ($line['quantity'] ?? 1))),
                'field_condition' => $condition,
                'notes' => ($notes = trim((string) ($line['notes'] ?? ''))) !== '' ? mb_substr($notes, 0, 255) : null,
                'status' => RemovedDeviceStatus::DIBAWA,
                'due_at' => now()->addDays(RemovedDevice::DAYS_TO_RETURN),
                'serial_unit_id' => $serial ? SerialUnit::query()->where('serial_number', $serial)->value('id') : null,
            ]);
            $this->leaveCustomer($device, $ticket, $actor, $holder);

            return $device->describe();
        }));
    }

    /**
     * The technician handed the device in: it waits at the warehouse to be checked.
     */
    public function receive(RemovedDevice $device, User $actor): RemovedDevice
    {
        return DB::transaction(function () use ($device, $actor) {
            $device = $this->lock($device, RemovedDeviceStatus::DIBAWA, 'Alat ini sudah diterima atau sudah diputuskan');
            $device->update(['status' => RemovedDeviceStatus::MENUNGGU_CEK, 'received_at' => now(), 'received_by' => $actor->id]);

            return $device;
        });
    }

    /**
     * The device will not come back (lost, left with the customer, thrown away): closed with the reason.
     */
    public function markMissing(RemovedDevice $device, User $actor, string $reason): RemovedDevice
    {
        return DB::transaction(function () use ($device, $actor, $reason) {
            $device = $this->lock($device, RemovedDeviceStatus::DIBAWA, 'Hanya alat yang masih dibawa teknisi yang bisa ditandai tidak kembali');
            $device->update(['status' => RemovedDeviceStatus::HILANG, 'checked_at' => now(), 'checked_by' => $actor->id, 'check_notes' => mb_substr($reason, 0, 255)]);
            $this->fileUnit($device, 'TIDAK KEMBALI', $reason);

            return $device;
        });
    }

    /**
     * The warehouse's check. Fit for use: back into the main warehouse as the chosen item (with its SN for a
     * device), so it can be sent out again. Broken: recorded, and stays out of stock.
     */
    public function check(RemovedDevice $device, User $actor, bool $fit, ?string $itemCode, ?string $serial, ?string $mac, ?string $notes): RemovedDevice
    {
        return DB::transaction(function () use ($device, $actor, $fit, $itemCode, $serial, $mac, $notes) {
            $device = $this->lock($device, RemovedDeviceStatus::MENUNGGU_CEK, 'Terima alatnya dulu sebelum dicek');
            $notes = trim((string) $notes) ?: null;
            $stamp = today()->format('d/m/Y');
            $done = ['checked_at' => now(), 'checked_by' => $actor->id, 'check_notes' => $notes ? mb_substr($notes, 0, 255) : null];

            if (! $fit) {
                $device->update($done + ['status' => RemovedDeviceStatus::RUSAK]);
                $this->fileUnit($device, 'RUSAK', $notes);

                return $device;
            }

            if (blank($itemCode)) {
                throw ValidationException::withMessages(['item' => 'Pilih barang di Master Barang untuk alat yang layak pakai']);
            }
            $item = $this->inventory->findItem($itemCode, 'item');
            $from = $device->ticket?->ticket_number ?? 'tanpa tiket';
            $unit = $this->inventory->takeBackFromField(
                $item, $device->quantity, $serial ?? $device->serial_number, $mac ?? $device->mac, $actor,
                mb_substr("Alat cabutan {$device->customer_name} ({$from}), cek layak pakai".($notes ? ": {$notes}" : ''), 0, 255),
                "BEKAS · CABUTAN {$stamp}".($notes ? " · {$notes}" : ''),
            );
            $device->update($done + ['status' => RemovedDeviceStatus::LAYAK, 'item_id' => $item->id, 'serial_unit_id' => $unit?->id ?? $device->serial_unit_id]);

            return $device;
        });
    }

    /**
     * Remind about devices still with technicians past the deadline: the warehouse team gets the full list,
     * each technician their own. Repeats once a day until every one is handed in or closed.
     *
     * @return int devices overdue
     */
    public function remindOverdue(): int
    {
        $overdue = RemovedDevice::query()->overdue()
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subHours(23)))
            ->with(['technician', 'ticket'])->orderBy('due_at')->get();
        if ($overdue->isEmpty()) {
            return 0;
        }
        $line = fn (RemovedDevice $device) => "{$device->name}".($device->serial_number ? " ({$device->serial_number})" : '')
            .' · '.($device->technician?->name ?? '-').' · '.$device->due_at->diffForHumans(now(), true).' lewat batas';
        $list = fn (Collection $devices) => $devices->take(5)->map($line)->implode('; ').($devices->count() > 5 ? '; dan '.($devices->count() - 5).' lainnya' : '');

        $team = User::query()->where('role', Role::GUDANG)->where('active', true)->get();
        if ($team->isNotEmpty()) {
            Notification::send($team, new InAppNotification(
                "{$overdue->count()} alat cabutan belum kembali ke gudang",
                'Lewat '.RemovedDevice::DAYS_TO_RETURN." hari: {$list($overdue)}.",
                route('removed-devices.index', ['status' => 'terlambat'], absolute: false),
                'alert-triangle',
            ));
        }
        foreach ($overdue->whereNotNull('technician_id')->groupBy('technician_id') as $devices) {
            $technician = $devices->first()->technician;
            if ($technician?->active) {
                $technician->notify(new InAppNotification(
                    'Serahkan alat cabutan ke gudang',
                    "{$devices->count()} alat cabutan sudah lewat ".RemovedDevice::DAYS_TO_RETURN.' hari: '.$devices->map(fn ($d) => $d->name.($d->ticket ? " ({$d->ticket->ticket_number})" : ''))->implode('; ').'.',
                    route('removed-devices.index', absolute: false),
                    'alert-triangle',
                ));
            }
        }
        RemovedDevice::query()->whereKey($overdue->modelKeys())->update(['reminded_at' => now()]);

        return $overdue->count();
    }

    /**
     * A device the system knew to be at this customer (Gudang Pelanggan, matched by SN) is no longer there.
     */
    private function leaveCustomer(RemovedDevice $device, Ticket $ticket, User $actor, User $holder): void
    {
        if (! $device->serial_number) {
            return;
        }
        $installed = CustomerMaterial::query()->installed()->where('serial_number', $device->serial_number)
            ->orderByRaw('customer_code = ? desc', [(string) $ticket->customer_id])->first();
        if ($installed) {
            $this->customers->markRemoved($installed, CustomerMaterialLog::LEPAS, $actor, "Dicabut {$holder->name} ({$ticket->ticket_number}), dibawa ke gudang");
            $device->update(['customer_material_id' => $installed->id]);
        }
    }

    /**
     * A known unit that will not return to stock is filed with why, so Cari SN shows what became of it.
     */
    private function fileUnit(RemovedDevice $device, string $what, ?string $notes): void
    {
        $unit = $device->serialUnit;
        if ($unit && $unit->warehouse_id === null) {
            $unit->update(['ticket_id' => null, 'material_usage_id' => null,
                'condition' => mb_substr("{$what} · CABUTAN ".today()->format('d/m/Y').($notes ? " · {$notes}" : ''), 0, 255)]);
        }
    }

    private function lock(RemovedDevice $device, RemovedDeviceStatus $expected, string $message): RemovedDevice
    {
        $device = RemovedDevice::query()->lockForUpdate()->with(['ticket', 'serialUnit'])->findOrFail($device->id);
        if ($device->status !== $expected) {
            throw ValidationException::withMessages(['removed_device' => $message]);
        }

        return $device;
    }
}
