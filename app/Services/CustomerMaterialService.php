<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMaterial;
use App\Models\CustomerMaterialLog;
use App\Models\MaterialUsage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Gudang Pelanggan: keeps what material each customer has, apart from the warehouse stock. Every job recorded
 * for a customer ID (a ticket's resume approved by the warehouse, or a Pemakaian Alat entry) adds its lines here
 * with a log entry; cancelling the job or removing the material is logged too.
 */
class CustomerMaterialService
{
    /**
     * The customer ID a job was for: the one typed on it, else its ticket's. Spelled as the directory has it
     * when the customer is there; null when the job names no customer.
     */
    public function customerCodeFor(MaterialUsage $usage): ?string
    {
        $code = trim((string) ($usage->customer_code ?: $usage->ticket?->customer_id));
        if ($code === '') {
            return null;
        }

        return Customer::query()->withCode($code)->value('code') ?? mb_substr($code, 0, 50);
    }

    /**
     * Put a job's material at its customer. Lines already recorded are skipped, so running it again (the
     * backfill) never doubles anything. Returns how many lines were added.
     */
    public function recordInstall(MaterialUsage $usage, ?User $actor, ?Carbon $loggedAt = null): int
    {
        $usage->loadMissing(['lines.item', 'lines.warehouse.user', 'ticket']);
        $code = $this->customerCodeFor($usage);
        if ($code === null || $usage->isCancelled()) {
            return 0;
        }
        if ($usage->customer_code !== $code) {
            $usage->update(['customer_code' => $code]);
        }
        $place = $this->customerLabel($code, $usage->location);
        $recorded = CustomerMaterial::query()->whereIn('material_usage_line_id', $usage->lines->pluck('id'))->pluck('material_usage_line_id')->all();

        $added = 0;
        foreach ($usage->lines as $line) {
            if (in_array($line->id, $recorded, true)) {
                continue;
            }
            $from = $line->warehouse?->name ?? $usage->holder_name;
            $technician = $line->warehouse?->user?->name ?? ($usage->team ?: $usage->holder_name);
            $material = CustomerMaterial::query()->create([
                'customer_code' => $code,
                'item_id' => $line->item_id,
                'item_name' => $line->item?->name ?? $line->item_name,
                'unit' => $line->item?->unit,
                'serial_unit_id' => $line->serial_unit_id,
                'serial_number' => $line->serial_number,
                'mac' => $line->mac,
                'quantity' => $line->quantity,
                'warehouse_id' => $line->warehouse_id,
                'technician' => $technician ? mb_substr($technician, 0, 150) : null,
                'ticket_id' => $usage->ticket_id,
                'material_usage_id' => $usage->id,
                'material_usage_line_id' => $line->id,
                'installed_at' => $usage->used_at,
            ]);
            $this->log($material, CustomerMaterialLog::PASANG, $actor, $from, $place,
                collect([$usage->purpose->label(), $usage->removed_device ? "mengganti {$usage->removed_device}" : null, $line->notes])->filter()->implode(' · '),
                $loggedAt ?? ($usage->used_at->isToday() ? now() : $usage->used_at));
            $added++;
        }

        return $added;
    }

    /**
     * A job entered by mistake was cancelled: its material never reached the customer.
     */
    public function cancel(MaterialUsage $usage, User $actor, string $reason): void
    {
        CustomerMaterial::query()->where('material_usage_id', $usage->id)->installed()->get()
            ->each(fn (CustomerMaterial $material) => $this->markRemoved($material, CustomerMaterialLog::BATAL, $actor, "Batal pemakaian: {$reason}"));
    }

    /**
     * Material leaves the customer: back to the office (the stock part is the inventory's job) or written off.
     */
    public function markRemoved(CustomerMaterial $material, string $action, User $actor, string $reason, ?string $to = null): CustomerMaterial
    {
        $material->update(['removed_at' => now(), 'removed_reason' => mb_substr($reason, 0, 255)]);
        $this->log($material, $action, $actor, $this->customerLabel($material->customer_code), $to, $reason);

        return $material;
    }

    /**
     * "Name (ID)" for the log, or the place the job named when the ID is not in the directory.
     */
    public function customerLabel(string $code, ?string $fallback = null): string
    {
        $name = Customer::query()->withCode($code)->value('name') ?? $fallback;

        return mb_substr($name ? "{$name} ({$code})" : $code, 0, 191);
    }

    private function log(CustomerMaterial $material, string $action, ?User $actor, ?string $from, ?string $to, ?string $notes, mixed $at = null): void
    {
        $material->logs()->create([
            'customer_code' => $material->customer_code,
            'action' => $action,
            'item_id' => $material->item_id,
            'item_name' => $material->item_name,
            'serial_number' => $material->serial_number,
            'quantity' => $material->quantity,
            'unit' => $material->unit,
            'from_label' => $from ? mb_substr($from, 0, 191) : null,
            'to_label' => $to ? mb_substr($to, 0, 191) : null,
            'ticket_id' => $material->ticket_id,
            'material_usage_id' => $material->material_usage_id,
            'user_id' => $actor?->id,
            'notes' => $notes ? mb_substr($notes, 0, 255) : null,
            'happened_at' => $at ? Carbon::parse($at) : now(),
        ]);
    }
}
