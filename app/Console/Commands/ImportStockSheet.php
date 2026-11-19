<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\StockSheetImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:import-stock
    {file : Path file Excel Admin WAREHOUSE (sheet "Sisa Stok")}
    {--user= : Username yang dicatat sebagai pembuat mutasi (default: SPV/Gudang pertama)}
    {--dry-run : Hanya tampilkan hasilnya tanpa menyimpan}')]
#[Description('Import stok Gudang Utama untuk barang tanpa SN dari sheet "Sisa Stok"')]
class ImportStockSheet extends Command
{
    public function handle(StockSheetImport $import): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }
        $actor = $this->option('user')
            ? User::query()->where('username', $this->option('user'))->first()
            : User::query()->where('active', true)->whereIn('role', [Role::GUDANG, Role::SPV])->orderByRaw('role = ? desc', [Role::GUDANG->value])->orderBy('id')->first();
        if (! $actor) {
            $this->error('User pencatat tidak ditemukan. Pakai --user=<username>');

            return self::FAILURE;
        }
        ini_set('memory_limit', '2048M');

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Membaca sheet "'.StockSheetImport::SHEET.'" dari '.basename($file).'...');
        $report = $import->import($file, $actor, $dryRun);

        $this->table(['Hasil', 'Jumlah'], [
            ['Barang di sheet', $report['rows']],
            ['Barang baru di master', count($report['created'])],
            ['Barang tanpa SN dengan stok', $report['stocked']],
            ['  · total stok', $report['quantity']],
            ['Barang tanpa SN, stok 0', $report['empty']],
            ['Tidak berubah (stok sudah sama)', $report['unchanged']],
            ['Perangkat ber-SN dilewati (stok 0, scan SN manual)', count($report['devices'])],
        ]);
        if ($report['negative']) {
            $this->warn('Stok minus di sheet, diisi 0: '.implode('; ', $report['negative']));
        }
        if ($report['devices']) {
            $this->line('Perangkat yang perlu discan SN-nya lewat Barang Masuk:');
            foreach ($report['devices'] as $line) {
                $this->line("  · {$line}");
            }
        }
        $this->line("Mutasi dicatat atas nama {$actor->name}.");
        $this->info($dryRun ? 'Dry run selesai, tidak ada yang disimpan.' : 'Import selesai.');

        return self::SUCCESS;
    }
}
