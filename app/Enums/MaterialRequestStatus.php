<?php

namespace App\Enums;

enum MaterialRequestStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Menunggu ACC Gudang',
            self::APPROVED => 'Disetujui Gudang',
            self::REJECTED => 'Ditolak Gudang',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PENDING => 'border-amber-300 bg-amber-50 text-amber-700',
            self::APPROVED => 'border-emerald-300 bg-emerald-50 text-emerald-700',
            self::REJECTED => 'border-rose-300 bg-rose-50 text-rose-700',
        };
    }
}
