<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case BELUM_LUNAS = 'BELUM_LUNAS';
    case LUNAS = 'LUNAS';

    public function label(): string
    {
        return match ($this) {
            self::BELUM_LUNAS => 'Belum Lunas',
            self::LUNAS => 'Lunas',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::BELUM_LUNAS => 'border-amber-300 bg-amber-50 text-amber-700',
            self::LUNAS => 'border-emerald-300 bg-emerald-50 text-emerald-700',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::BELUM_LUNAS => 'hourglass',
            self::LUNAS => 'badge-check',
        };
    }
}
