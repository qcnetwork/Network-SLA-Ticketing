<?php

namespace App\Enums;

/**
 * What material was used for, as the office's "PROJECT" column has always sorted it.
 */
enum UsagePurpose: string
{
    case PEMASANGAN_BARU = 'PEMASANGAN_BARU';
    case GANTI_ALAT = 'GANTI_ALAT';
    case FREE_PERBAIKAN = 'FREE_PERBAIKAN';
    case FREE_PENGEMBANGAN = 'FREE_PENGEMBANGAN';
    case AKTIF_KEMBALI = 'AKTIF_KEMBALI';
    case NAMBAH_CABANG = 'NAMBAH_CABANG';
    case BAYAR_BELI = 'BAYAR_BELI';
    case MIGRASI = 'MIGRASI';
    case GANTI_NAMA = 'GANTI_NAMA';
    case LAINNYA = 'LAINNYA';

    public function label(): string
    {
        return match ($this) {
            self::PEMASANGAN_BARU => 'Pemasangan Baru',
            self::GANTI_ALAT => 'Ganti Alat / Pinjami / Tukar',
            self::FREE_PERBAIKAN => 'Free Perbaikan',
            self::FREE_PENGEMBANGAN => 'Free Pengembangan',
            self::AKTIF_KEMBALI => 'Aktif Kembali',
            self::NAMBAH_CABANG => 'Nambah Cabang',
            self::BAYAR_BELI => 'Bayar / Beli',
            self::MIGRASI => 'Migrasi',
            self::GANTI_NAMA => 'Ganti Nama',
            self::LAINNYA => 'Lainnya',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PEMASANGAN_BARU => 'border-indigo-300 bg-indigo-50 text-indigo-700',
            self::GANTI_ALAT => 'border-violet-300 bg-violet-50 text-violet-700',
            self::FREE_PERBAIKAN => 'border-orange-300 bg-orange-50 text-orange-700',
            self::FREE_PENGEMBANGAN => 'border-teal-300 bg-teal-50 text-teal-700',
            self::AKTIF_KEMBALI => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            self::NAMBAH_CABANG => 'border-cyan-300 bg-cyan-50 text-cyan-700',
            self::BAYAR_BELI => 'border-sky-300 bg-sky-50 text-sky-700',
            default => 'border-slate-300 bg-slate-50 text-slate-700',
        };
    }

    /**
     * Read the purpose as the old sheet wrote it ("GANTI ALAT/PINJAMI/ TUKAR", "BAYAR / BELI").
     */
    public static function fromSheet(string $text): self
    {
        $text = strtoupper(preg_replace('/[^A-Za-z]+/', ' ', $text));

        return match (true) {
            str_contains($text, 'PEMASANGAN BARU') => self::PEMASANGAN_BARU,
            str_contains($text, 'GANTI ALAT'), str_contains($text, 'PINJAM'), str_contains($text, 'TUKAR') => self::GANTI_ALAT,
            str_contains($text, 'PERBAIKAN') => self::FREE_PERBAIKAN,
            str_contains($text, 'PENGEMBANGAN') => self::FREE_PENGEMBANGAN,
            str_contains($text, 'AKTIF KEMBALI') => self::AKTIF_KEMBALI,
            str_contains($text, 'CABANG') => self::NAMBAH_CABANG,
            str_contains($text, 'BAYAR'), str_contains($text, 'BELI') => self::BAYAR_BELI,
            str_contains($text, 'MIGRASI') => self::MIGRASI,
            str_contains($text, 'GANTI NAMA') => self::GANTI_NAMA,
            default => self::LAINNYA,
        };
    }
}
