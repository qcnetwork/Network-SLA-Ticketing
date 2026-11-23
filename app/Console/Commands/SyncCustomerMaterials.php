<?php

namespace App\Console\Commands;

use App\Models\MaterialUsage;
use App\Services\CustomerMaterialService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('gudang-pelanggan:sync')]
#[Description('Isi Gudang Pelanggan dari pemakaian alat lama yang punya ID pelanggan (langsung atau lewat tiketnya)')]
class SyncCustomerMaterials extends Command
{
    /**
     * Jobs recorded before Gudang Pelanggan existed. Lines already there are skipped, so it is safe to run again.
     */
    public function handle(CustomerMaterialService $customers): int
    {
        $usages = MaterialUsage::query()->whereNull('cancelled_at')
            ->where(fn (Builder $query) => $query->whereNotNull('customer_code')
                ->orWhereHas('ticket', fn (Builder $ticket) => $ticket->whereNotNull('customer_id')->where('customer_id', '!=', '')))
            ->orderBy('id');

        $jobs = 0;
        $lines = 0;
        $usages->each(function (MaterialUsage $usage) use ($customers, &$jobs, &$lines) {
            $added = $customers->recordInstall($usage, $usage->user);
            $jobs += $added > 0 ? 1 : 0;
            $lines += $added;
        });
        $this->info("{$lines} material dari {$jobs} pemakaian dicatat ke Gudang Pelanggan.");

        return self::SUCCESS;
    }
}
