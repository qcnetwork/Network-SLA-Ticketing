<?php

namespace App\Enums;

enum TicketPriority: string
{
    case TINGGI = 'TINGGI';
    case SEDANG = 'SEDANG';
    case RENDAH = 'RENDAH';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    public function telegramLabel(): string
    {
        return match ($this) {
            self::TINGGI => '🔴 TINGGI',
            self::SEDANG => '🟡 SEDANG',
            self::RENDAH => '🟢 RENDAH',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::TINGGI => 'bg-red-100 text-red-700 border-red-300',
            self::SEDANG => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            self::RENDAH => 'bg-green-100 text-green-700 border-green-300',
        };
    }
}
