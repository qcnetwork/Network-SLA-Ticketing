<?php

namespace App\Enums;

enum Role: string
{
    case SPV = 'SPV';
    case HELPDESK = 'HELPDESK';
    case CS = 'CS';
    case TEKNISI = 'TEKNISI';
    case GUDANG = 'GUDANG';

    public function label(): string
    {
        return match ($this) {
            self::SPV => 'Supervisor',
            self::HELPDESK => 'Helpdesk',
            self::CS => 'Customer Service',
            self::TEKNISI => 'Teknisi',
            self::GUDANG => 'Warehouse',
        };
    }
}
