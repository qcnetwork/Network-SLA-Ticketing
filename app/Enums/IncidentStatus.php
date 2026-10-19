<?php

namespace App\Enums;

enum IncidentStatus: string
{
    case AKTIF = 'AKTIF';
    case PERBAIKAN = 'PERBAIKAN';
    case SELESAI = 'SELESAI';

    /**
     * @return array<self>
     */
    public static function open(): array
    {
        return [self::AKTIF, self::PERBAIKAN];
    }

    public function label(): string
    {
        return match ($this) {
            self::AKTIF => 'Aktif (Investigasi)',
            self::PERBAIKAN => 'Perbaikan',
            self::SELESAI => 'Selesai',
        };
    }

    public function telegramLabel(): string
    {
        return match ($this) {
            self::AKTIF => '🔴 AKTIF',
            self::PERBAIKAN => '🔧 PERBAIKAN',
            self::SELESAI => '✅ SELESAI',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::AKTIF => 'bg-red-100 text-red-800 border-red-300',
            self::PERBAIKAN => 'bg-orange-100 text-orange-800 border-orange-300',
            self::SELESAI => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        };
    }
}
