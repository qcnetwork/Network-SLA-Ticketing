<?php

namespace App\Enums;

/**
 * What sort of thing an item is. For now it labels and filters items; behaviour per kind comes later.
 */
enum ItemKind: string
{
    case PERANGKAT_AKTIF = 'PERANGKAT_AKTIF';
    case HABIS_PAKAI = 'HABIS_PAKAI';
    case KABEL = 'KABEL';
    case ASET = 'ASET';

    public function label(): string
    {
        return match ($this) {
            self::PERANGKAT_AKTIF => 'Perangkat Aktif',
            self::HABIS_PAKAI => 'Habis Pakai',
            self::KABEL => 'Kabel',
            self::ASET => 'Aset',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::PERANGKAT_AKTIF => 'Modem, Mikrotik, router, switch, server',
            self::HABIS_PAKAI => 'Konektor, klem, adaptor, dll yang terpakai habis',
            self::KABEL => 'Dropcore, UTP, kabel power',
            self::ASET => 'Tiang, alat kerja, aksesoris tidak habis pakai',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PERANGKAT_AKTIF => 'border-sky-300 bg-sky-50 text-sky-700',
            self::HABIS_PAKAI => 'border-slate-300 bg-slate-50 text-slate-600',
            self::KABEL => 'border-amber-300 bg-amber-50 text-amber-700',
            self::ASET => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        };
    }
}
