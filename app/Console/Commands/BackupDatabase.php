<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('db:backup {--keep=14 : Jumlah backup terbaru yang disimpan}')]
#[Description('Simpan salinan database ke storage/app/private/backups dan hapus backup lama')]
class BackupDatabase extends Command
{
    public function handle(DatabaseBackup $backup): int
    {
        $path = $backup->create();
        $pruned = $backup->prune(max(1, (int) $this->option('keep')));

        $this->info("Backup tersimpan: {$path}".($pruned ? " ({$pruned} backup lama dihapus)" : ''));

        return self::SUCCESS;
    }
}
