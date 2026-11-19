<?php

namespace App\Console\Commands;

use App\Services\SheetSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('inventory:sync-sheet {--full : Tulis ulang seluruh tab Stok dari stok saat ini}')]
#[Description('Kirim mutasi stok yang belum terkirim ke Google Spreadsheet gudang')]
class SyncWarehouseSheet extends Command
{
    public function handle(SheetSync $sync): int
    {
        if (! $sync->enabled()) {
            $this->warn('Sinkron Google Spreadsheet belum diatur (Pengaturan → Google Spreadsheet Gudang).');

            return self::SUCCESS;
        }
        try {
            if ($this->option('full')) {
                $this->info("Tab Stok ditulis ulang: {$sync->snapshot()} baris.");
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sent = $sync->push();
        $left = $sync->pendingCount();
        $this->info("{$sent} mutasi terkirim".($left ? ", {$left} belum (dicoba lagi nanti, lihat log)" : '').'.');

        return $left ? self::FAILURE : self::SUCCESS;
    }
}
