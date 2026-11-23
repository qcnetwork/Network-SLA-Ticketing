<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One SN-tracked unit: a modem, a roll of dropcore, a pole... It sits in a warehouse until a repair resume
 * installs it at a customer (moving it onto that ticket), it is sold, or (a roll set to convert on send) it is
 * opened into a technician's warehouse as meters of cable.
 */
class SerialUnit extends Model
{
    protected $guarded = ['id'];

    /**
     * Where a unit that left stock went, as the tracking page groups it: installed at a customer, handed to a
     * partner (mitra) or sold, returned / broken, or anything else (a roll opened into meters, no note).
     */
    public const DESTINATIONS = [
        'pelanggan' => 'Terpasang di pelanggan',
        'mitra' => 'Mitra / terjual',
        'retur' => 'Retur / eror',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'date', 'taken_at' => 'date', 'imported_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'converted_to_warehouse_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function usage(): BelongsTo
    {
        return $this->belongsTo(MaterialUsage::class, 'material_usage_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * Units no longer in any warehouse: installed, handed out, sold or written off ("kotor").
     */
    public function scopeUsed(Builder $query): Builder
    {
        return $query->whereNull('warehouse_id');
    }

    /**
     * Units still sitting in a warehouse, the main one or a technician's: not installed, sold or written off yet.
     */
    public function scopeIdle(Builder $query): Builder
    {
        return $query->whereNotNull('warehouse_id');
    }

    /**
     * Adds `idle_since`: when the unit last arrived in the warehouse it sits in (received, sent or returned there),
     * falling back to the sheet's received date or when it was registered.
     */
    public function scopeWithIdleSince(Builder $query): Builder
    {
        $arrived = StockMovement::query()->select('created_at')
            ->whereColumn('stock_movements.serial_unit_id', 'serial_units.id')
            ->whereColumn('stock_movements.to_warehouse_id', 'serial_units.warehouse_id')
            ->orderByDesc('id')->limit(1);

        return $query->select('serial_units.*')
            ->selectRaw("coalesce(({$arrived->toSql()}), serial_units.received_at, serial_units.created_at) as idle_since");
    }

    /**
     * When an idle unit arrived where it sits (needs withIdleSince), or null for a used one.
     */
    public function idleSince(): ?Carbon
    {
        if ($this->warehouse_id === null) {
            return null;
        }

        return Carbon::parse($this->getAttribute('idle_since') ?? $this->received_at ?? $this->created_at);
    }

    /**
     * Whole days an idle unit has been sitting where it is.
     */
    public function idleDays(): ?int
    {
        $since = $this->idleSince();

        return $since ? (int) $since->startOfDay()->diffInDays(today()) : null;
    }

    /**
     * Narrow used units to one of DESTINATIONS, in the same order location() reads a unit.
     */
    public function scopeDestination(Builder $query, string $destination): Builder
    {
        $toPartner = fn (Builder $q) => $q->where('condition', 'like', 'Mitra %')->orWhere('condition', 'like', 'Partner %');
        $notInApp = fn (Builder $q) => $q->whereNull('ticket_id')->whereNull('material_usage_id')->whereNull('sale_id')->whereNull('converted_to_warehouse_id');

        return match ($destination) {
            'pelanggan' => $query->where(fn (Builder $q) => $q->whereNotNull('ticket_id')->orWhereNotNull('material_usage_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->whereNull('condition')->whereNotNull('installed_to'))),
            'mitra' => $query->where(fn (Builder $q) => $q->whereNotNull('sale_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->where($toPartner))),
            'retur' => $query->where(fn (Builder $q) => $notInApp($q)->whereNotNull('condition')->whereNot($toPartner)),
            default => $query->where(fn (Builder $q) => $q->whereNotNull('converted_to_warehouse_id')
                ->orWhere(fn (Builder $q) => $notInApp($q)->whereNull('condition')->whereNull('installed_to'))),
        };
    }

    /**
     * Which of DESTINATIONS a used unit belongs to (null while it sits in a warehouse).
     */
    public function destination(): ?string
    {
        $partner = $this->condition !== null && (str_starts_with($this->condition, 'Mitra ') || str_starts_with($this->condition, 'Partner '));

        return match (true) {
            $this->warehouse_id !== null => null,
            $this->ticket_id !== null || $this->material_usage_id !== null => 'pelanggan',
            $this->sale_id !== null => 'mitra',
            $this->converted_to_warehouse_id !== null => 'lainnya',
            $this->condition !== null => $partner ? 'mitra' : 'retur',
            $this->installed_to !== null => 'pelanggan',
            default => 'lainnya',
        };
    }

    /**
     * Where it is now, as a short place name (customer, partner, buyer...) without the "Terpasang ·" prefix.
     */
    public function placeName(): string
    {
        return match (true) {
            $this->warehouse !== null => $this->warehouse->name,
            $this->ticket !== null => trim("{$this->ticket->customer_name} · {$this->ticket->ticket_number}", ' ·'),
            $this->usage !== null => $this->usage->location,
            $this->sale !== null => "{$this->sale->buyer_name} ({$this->sale->sale_number})",
            $this->convertedTo !== null => "Dibuka jadi meter · {$this->convertedTo->name}",
            $this->condition !== null && $this->destination() === 'mitra' => preg_replace('/^(Mitra|Partner jual beli) /', '', $this->condition),
            $this->installed_to !== null => $this->installed_to,
            default => $this->condition ?? '-',
        };
    }

    /**
     * The places this unit has been, in order, as nodes: Gudang Utama → a technician → a customer → back to the
     * office... Consecutive moves within one place make one node. The TRACKING ALAT sheet's story comes first for
     * imported units, then every move recorded in the app.
     *
     * @param  Collection<int, StockMovement>|null  $moves  oldest first, with fromWarehouse.user, toWarehouse.user, usage, ticket and sale
     * @return array<int, array{kind: string, label: string, sub: ?string, date: ?Carbon, url: ?string}>
     */
    public function journey($moves = null): array
    {
        $moves ??= StockMovement::query()->where('serial_unit_id', $this->id)
            ->with(['fromWarehouse.user', 'toWarehouse.user', 'usage', 'ticket', 'sale'])->orderBy('created_at')->orderBy('id')->get();
        $nodes = [];
        $push = function (string $kind, string $label, ?string $sub, mixed $date, ?string $url = null) use (&$nodes) {
            $last = end($nodes);
            if ($last && $last['kind'] === $kind && $last['label'] === $label) {
                return;
            }
            $nodes[] = ['kind' => $kind, 'label' => $label, 'sub' => $sub, 'date' => $date ? Carbon::parse($date) : null, 'url' => $url];
        };
        $warehouse = function (?Warehouse $place, string $sub, mixed $date) use ($push) {
            if ($place) {
                $place->isMain()
                    ? $push('gudang', $place->name, $sub, $date, route('warehouses.index'))
                    : $push('teknisi', $place->user?->name ?? $place->name, $sub, $date, route('warehouses.show', $place));
            }
        };
        $customer = fn (?string $code) => $code ? route('customer-materials.show', $code) : null;

        if ($this->imported_at) {
            if ($this->received_at || $this->holder_name) {
                $push('gudang', 'Gudang Utama', 'Paket masuk', $this->received_at);
            }
            if ($this->holder_name) {
                $push('teknisi', $this->holder_name, 'Diambil', $this->taken_at);
            }
            if ($this->installed_to) {
                $push('pelanggan', $this->installed_to, $this->install_status ?? 'Dipasang', $this->taken_at);
            }
            if ($this->condition && $moves->isEmpty()) {
                match (true) {
                    (bool) preg_match('/^(KEMBALI|RETUR KANTOR|WAREHO)/i', $this->condition) => $push('gudang', 'Gudang Utama', $this->condition, null),
                    $this->destination() === 'mitra' => $push('mitra', $this->placeName(), $this->condition, null),
                    default => $push('lepas', $this->condition, $this->notes, null),
                };
            }
        }

        foreach ($moves as $move) {
            $date = $move->created_at;
            match ($move->type) {
                StockMovementType::MASUK => $warehouse($move->toWarehouse, 'Barang masuk', $date),
                StockMovementType::KIRIM => [$nodes === [] ? $warehouse($move->fromWarehouse, 'Stok', null) : null, $warehouse($move->toWarehouse, 'Dikirim', $date)],
                StockMovementType::RETUR => $warehouse($move->toWarehouse, $move->fromWarehouse ? 'Retur' : 'Kembali dari pelanggan', $date),
                StockMovementType::KOREKSI => $warehouse($move->toWarehouse, 'Batal pemakaian', $date),
                StockMovementType::JUAL => $push('mitra', $move->sale?->buyer_name ?? 'Pembeli', 'Dijual', $date),
                StockMovementType::PAKAI => [
                    $nodes === [] ? $warehouse($move->fromWarehouse, 'Stok', null) : null,
                    $push('pelanggan',
                        Customer::query()->withCode((string) ($move->usage?->customer_code ?: $move->ticket?->customer_id))->value('name')
                            ?? $move->ticket?->customer_name ?? $move->usage?->location ?? 'Pelanggan',
                        collect([$move->usage?->customer_code ?: $move->ticket?->customer_id, $move->usage?->purpose?->label()])->filter()->implode(' · ') ?: 'Dipasang',
                        $date, $customer($move->usage?->customer_code ?: $move->ticket?->customer_id)),
                ],
            };
        }

        // Taken off a customer and written off in the app: the unit ends there.
        if ($this->warehouse_id === null && $this->condition && str_starts_with($this->condition, 'DILEPAS')) {
            $push('lepas', 'Dilepas', $this->condition, null);
        }

        return $nodes;
    }

    /**
     * A scanned SN as stored: upper case without spaces.
     */
    public static function normalizeSerial(?string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $serial));
    }

    /**
     * A scanned MAC as stored: twelve hex digits without separators, or null when it is not one.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        $mac = preg_replace('/[^0-9A-F]/', '', strtoupper((string) $mac));

        return strlen($mac) === 12 ? $mac : null;
    }

    public function formattedMac(): ?string
    {
        return $this->mac ? implode(':', str_split($this->mac, 2)) : null;
    }

    public function location(): string
    {
        return $this->warehouse?->name
            ?? match (true) {
                $this->ticket !== null => "Terpasang · {$this->ticket->ticket_number}",
                $this->usage !== null => "Terpakai · {$this->usage->location}",
                $this->sale !== null => "Terjual · {$this->sale->buyer_name} ({$this->sale->sale_number})",
                $this->convertedTo !== null => "Dibuka jadi {$this->item->sendAs?->unit} · {$this->convertedTo->name}",
                $this->condition !== null => "Keluar stok · {$this->condition}",
                $this->installed_to !== null => "Terpasang · {$this->installed_to}",
                default => '-',
            };
    }
}
