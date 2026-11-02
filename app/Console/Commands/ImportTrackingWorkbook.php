<?php

namespace App\Console\Commands;

use App\Services\TrackingImport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inventory:import-tracking
    {file : Path file Excel TRACKING ALAT}
    {--dry-run : Hanya tampilkan hasilnya tanpa menyimpan}')]
#[Description('Import unit modem per SN dari workbook TRACKING ALAT gudang')]
class ImportTrackingWorkbook extends Command
{
    public function handle(TrackingImport $import): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File tidak ditemukan: {$file}");

            return self::FAILURE;
        }
        // The workbook holds tens of thousands of cells across forty-odd sheets.
        ini_set('memory_limit', '1024M');

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '').'Membaca '.basename($file).'...');
        $report = $import->import($file, $dryRun);

        $this->table(['Hasil', 'Jumlah'], [
            ['Baris unit di file', $report['rows']],
            ['SN tidak valid (dilewati)', $report['invalid']],
            ['SN dobel (diambil baris terbaru)', $report['duplicates']],
            ['SN yang sudah discan di aplikasi (tidak diubah)', $report['kept']],
            ['Unit diimport', $report['units']],
            ...collect($report['places'])->sortDesc()->map(fn ($count, $place) => ["  · {$place}", $count])->values(),
        ]);
        $this->line('Barang baru ('.count($report['items']).'): '.(implode(', ', $report['items']) ?: '-'));
        $this->line('Akun teknisi baru, nonaktif ('.count($report['technicians']).'): '.(implode(', ', $report['technicians']) ?: '-'));
        $this->info($dryRun ? 'Dry run selesai, tidak ada yang disimpan.' : 'Import selesai.');

        return self::SUCCESS;
    }
}
