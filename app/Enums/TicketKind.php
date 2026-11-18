<?php

namespace App\Enums;

/**
 * What a ticket is about. Complaints (gangguan) and new installations (PSB) share the ticket table, so
 * they share history, photos, technician assignments and warehouse material, but each has its own
 * statuses, menu and pages, and complaint reports never count installations.
 */
enum TicketKind: string
{
    case GANGGUAN = 'GANGGUAN';
    case PSB = 'PSB';

    public function label(): string
    {
        return match ($this) {
            self::GANGGUAN => 'Gangguan',
            self::PSB => 'Pasang Baru (PSB)',
        };
    }

    public function numberPrefix(): string
    {
        return match ($this) {
            self::GANGGUAN => 'TCK',
            self::PSB => 'PSB',
        };
    }
}
