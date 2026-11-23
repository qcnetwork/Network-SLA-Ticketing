<?php

namespace App\Enums;

/**
 * Where a removed device is: with the technician, at the warehouse waiting to be checked, or done with
 * (fit for use and back in stock, broken, or never handed in).
 */
enum RemovedDeviceStatus: string
{
    case DIBAWA = 'DIBAWA';
    case MENUNGGU_CEK = 'MENUNGGU_CEK';
    case LAYAK = 'LAYAK';
    case RUSAK = 'RUSAK';
    case HILANG = 'HILANG';

    public function label(): string
    {
        return match ($this) {
            self::DIBAWA => 'Dibawa teknisi',
            self::MENUNGGU_CEK => 'Menunggu cek',
            self::LAYAK => 'Layak pakai',
            self::RUSAK => 'Rusak',
            self::HILANG => 'Tidak kembali',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::DIBAWA => 'border-amber-300 bg-amber-50 text-amber-700',
            self::MENUNGGU_CEK => 'border-sky-300 bg-sky-50 text-sky-700',
            self::LAYAK => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            self::RUSAK => 'border-rose-300 bg-rose-50 text-rose-700',
            self::HILANG => 'border-slate-400 bg-slate-100 text-slate-700',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::DIBAWA, self::MENUNGGU_CEK], true);
    }
}
