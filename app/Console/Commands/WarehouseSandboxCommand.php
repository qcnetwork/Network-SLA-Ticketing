<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\DatabaseBackup;
use App\Services\WarehouseSandbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('inventory:sandbox
    {action=status : on (simpan data asli lalu isi dummy), off (kembalikan data asli), atau status}
    {--force : Jangan tanya konfirmasi}')]
#[Description('Ganti sementara data gudang dengan data dummy untuk testing, lalu kembalikan data aslinya')]
class WarehouseSandboxCommand extends Command
{
    public function handle(WarehouseSandbox $sandbox, DatabaseBackup $backup): int
    {
        try {
            return match ($this->argument('action')) {
                'on' => $this->enable($sandbox, $backup),
                'off' => $this->restore($sandbox),
                'status' => $this->status($sandbox),
                default => $this->fail('Pilih salah satu: on, off, atau status'),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function enable(WarehouseSandbox $sandbox, DatabaseBackup $backup): int
    {
        if ($sandbox->active()) {
            $this->warn('Mode dummy sudah aktif.');

            return $this->status($sandbox);
        }
        $actor = User::query()->whereIn('role', [Role::GUDANG, Role::SPV])->where('active', true)->orderByRaw("role = 'GUDANG' desc")->orderBy('id')->first()
            ?? $this->fail('Butuh minimal satu user GUDANG atau SPV aktif untuk mencatat data dummy.');
        if (! $this->option('force') && ! $this->confirm('Data gudang asli akan disimpan lalu dikosongkan dan diganti data dummy. Lanjut?')) {
            return self::SUCCESS;
        }

        $this->info('Backup penuh dulu: '.$backup->create());
        $stashed = $sandbox->enable($actor);
        $this->table(['Tabel', 'Disimpan', 'Dummy sekarang'], collect($sandbox->liveCounts())->map(fn ($now, $t) => [$t, $stashed[$t], $now])->values()->all());
        $this->info("Mode dummy aktif (dicatat sebagai {$actor->name}). Data asli tersimpan di {$sandbox->stashPath()}");
        $this->line('Kembalikan data asli dengan: php artisan inventory:sandbox off');

        return self::SUCCESS;
    }

    private function restore(WarehouseSandbox $sandbox): int
    {
        if (! $this->option('force') && ! $this->confirm('Semua data gudang dummy (termasuk yang Anda input saat testing) akan dihapus dan data asli dikembalikan. Lanjut?')) {
            return self::SUCCESS;
        }
        $restored = $sandbox->restore();
        $this->table(['Tabel', 'Dikembalikan'], collect($restored)->map(fn ($n, $t) => [$t, $n])->values()->all());
        $this->info('Data gudang asli sudah kembali.');

        return self::SUCCESS;
    }

    private function status(WarehouseSandbox $sandbox): int
    {
        if (! $sandbox->active()) {
            $this->info('Mode dummy tidak aktif: data gudang yang terpakai adalah data asli.');

            return self::SUCCESS;
        }
        $stashed = $sandbox->stashCounts();
        $this->warn('Mode dummy AKTIF: data gudang yang tampil adalah data dummy.');
        $this->table(['Tabel', 'Data asli (tersimpan)', 'Dummy sekarang'], collect($sandbox->liveCounts())->map(fn ($now, $t) => [$t, $stashed[$t], $now])->values()->all());

        return self::SUCCESS;
    }
}
