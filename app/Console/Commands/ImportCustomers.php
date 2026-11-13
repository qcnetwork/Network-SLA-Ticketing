<?php

namespace App\Console\Commands;

use App\Services\CustomerImport;
use App\Support\GeoLocation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customers:import
    {files* : Path file export "Data Pelanggan" dari billing (.xls / .xlsx)}
    {--dry-run : Hanya tampilkan hasilnya tanpa menyimpan}
    {--resolve-links : Buka link maps.app.goo.gl untuk pelanggan yang lokasinya masih kosong (lambat, butuh internet)}')]
#[Description('Import data pelanggan dari export billing ke direktori pelanggan')]
class ImportCustomers extends Command
{
    public function handle(CustomerImport $import): int
    {
        $files = $this->argument('files');
        foreach ($files as $file) {
            if (! is_file($file)) {
                $this->error("File tidak ditemukan: {$file}");

                return self::FAILURE;
            }
        }
        ini_set('memory_limit', '1024M');

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Membaca '.implode(', ', array_map('basename', $files)).'...');
        $resolveLink = null;
        $linksFound = null;
        $bar = null;
        if ($this->option('resolve-links')) {
            $linksFound = function (int $count) use (&$bar) {
                $this->info("Membuka {$count} link lokasi...");
                $bar = $this->output->createProgressBar($count);
            };
            $resolveLink = function (string $url) use (&$bar) {
                $coordinates = GeoLocation::fromText($url);
                $bar?->advance();
                usleep(300_000); // a gentle pace, so Google does not start refusing

                return $coordinates;
            };
        }

        $report = $import->import($files, $dryRun, $resolveLink, $linksFound);
        if ($bar) {
            $bar->finish();
            $this->newLine(2);
        }

        $this->table(['Hasil', 'Jumlah'], [
            ['Baris di file', $report['rows']],
            ['Dilewati (tanpa ID / nama)', $report['skipped']],
            ['Pelanggan baru', $report['created']],
            ['Sudah ada, dilengkapi (HP/alamat/lokasi kosong)', $report['filled']],
            ['Sudah ada, tidak diubah', $report['unchanged']],
            ['  · tanpa nomor HP', $report['no_phone']],
            ['  · tanpa titik lokasi', $report['no_location']],
            ...($resolveLink ? [
                ['Link lokasi berhasil dibuka', $report['links_resolved']],
                ['Link lokasi gagal dibuka', $report['links_failed']],
            ] : []),
        ]);
        $this->info($dryRun ? 'Dry run selesai, tidak ada yang disimpan.' : 'Import selesai.');

        return self::SUCCESS;
    }
}
