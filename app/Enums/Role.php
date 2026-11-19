<?php

namespace App\Enums;

enum Role: string
{
    case SPV = 'SPV';
    case HELPDESK = 'HELPDESK';
    case CS = 'CS';
    case TEKNISI = 'TEKNISI';
    case GUDANG = 'GUDANG';

    /**
     * Roles that carry their own warehouse stock: technicians in the field, and helpdesk, who swap or
     * install devices from the office or remotely.
     *
     * @return list<self>
     */
    public static function stockHolders(): array
    {
        return [self::TEKNISI, self::HELPDESK];
    }

    public function holdsStock(): bool
    {
        return in_array($this, self::stockHolders(), true);
    }

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
