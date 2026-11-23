<?php

namespace App\Console\Commands;

use App\Models\RemovedDevice;
use App\Services\RemovedDeviceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('removed-devices:remind')]
#[Description('Ingatkan Gudang (dan teknisinya) tentang alat cabutan yang belum diserahkan lewat batas hari')]
class RemindRemovedDevices extends Command
{
    public function handle(RemovedDeviceService $devices): int
    {
        $count = $devices->remindOverdue();
        $this->info($count
            ? "{$count} alat cabutan lewat ".RemovedDevice::DAYS_TO_RETURN.' hari, Gudang sudah diingatkan.'
            : 'Tidak ada alat cabutan yang perlu diingatkan.');

        return self::SUCCESS;
    }
}
