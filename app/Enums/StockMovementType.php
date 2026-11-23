<?php

namespace App\Enums;

enum StockMovementType: string
{
    case MASUK = 'MASUK';
    case KIRIM = 'KIRIM';
    case RETUR = 'RETUR';
    case PAKAI = 'PAKAI';
    case JUAL = 'JUAL';
    case KOREKSI = 'KOREKSI';
    case PENYESUAIAN = 'PENYESUAIAN';

    public function label(): string
    {
        return match ($this) {
            self::MASUK => 'Barang Masuk',
            self::KIRIM => 'Kirim ke Teknisi',
            self::RETUR => 'Retur ke Gudang',
            self::PAKAI => 'Dipakai di Tiket',
            self::JUAL => 'Dijual',
            self::KOREKSI => 'Batal Pemakaian',
            self::PENYESUAIAN => 'Adjust Stok',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::MASUK => 'download',
            self::KIRIM => 'truck',
            self::RETUR => 'undo-2',
            self::PAKAI => 'wrench',
            self::JUAL => 'shopping-cart',
            self::KOREKSI => 'rotate-ccw',
            self::PENYESUAIAN => 'sliders-horizontal',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::MASUK => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            self::KIRIM => 'border-indigo-300 bg-indigo-50 text-indigo-700',
            self::RETUR => 'border-amber-300 bg-amber-50 text-amber-700',
            self::PAKAI => 'border-rose-300 bg-rose-50 text-rose-700',
            self::JUAL => 'border-sky-300 bg-sky-50 text-sky-700',
            self::KOREKSI => 'border-slate-300 bg-slate-50 text-slate-700',
            self::PENYESUAIAN => 'border-violet-300 bg-violet-50 text-violet-700',
        };
    }
}
