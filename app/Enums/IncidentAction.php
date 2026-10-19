<?php

namespace App\Enums;

enum IncidentAction: string
{
    case GAMAS_DIBUAT = 'GAMAS_DIBUAT';
    case TIKET_DITAUTKAN = 'TIKET_DITAUTKAN';
    case TIKET_DILEPAS = 'TIKET_DILEPAS';
    case TUGASKAN_TEKNISI = 'TUGASKAN_TEKNISI';
    case MULAI_PERBAIKAN = 'MULAI_PERBAIKAN';
    case UPDATE_PROGRES = 'UPDATE_PROGRES';
    case GAMAS_SELESAI = 'GAMAS_SELESAI';

    /**
     * Actions a user triggers from the gamas action panel (the rest are recorded by the system).
     *
     * @return array<self>
     */
    public static function performable(): array
    {
        return [self::TUGASKAN_TEKNISI, self::MULAI_PERBAIKAN, self::UPDATE_PROGRES, self::GAMAS_SELESAI];
    }

    /**
     * @return array<Role>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::GAMAS_DIBUAT, self::TUGASKAN_TEKNISI => [Role::HELPDESK, Role::SPV],
            self::TIKET_DITAUTKAN, self::TIKET_DILEPAS => [Role::CS, Role::HELPDESK, Role::SPV],
            self::MULAI_PERBAIKAN => [Role::TEKNISI, Role::SPV],
            self::UPDATE_PROGRES => [Role::CS, Role::HELPDESK, Role::TEKNISI, Role::SPV],
            self::GAMAS_SELESAI => [Role::HELPDESK, Role::TEKNISI, Role::SPV],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GAMAS_DIBUAT => 'Gamas Dibuat',
            self::TIKET_DITAUTKAN => 'Tiket Ditautkan',
            self::TIKET_DILEPAS => 'Tiket Dilepas',
            self::TUGASKAN_TEKNISI => 'Teknisi Ditugaskan',
            self::MULAI_PERBAIKAN => 'Mulai Perbaikan',
            self::UPDATE_PROGRES => 'Update Progres',
            self::GAMAS_SELESAI => 'Gamas Selesai',
        };
    }
}
