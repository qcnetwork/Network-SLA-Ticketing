<?php

namespace App\Enums;

enum TicketStatus: string
{
    case ANTRIAN_HELPDESK = 'ANTRIAN_HELPDESK';
    case HELPDESK_CEK = 'HELPDESK_CEK';
    case PERBAIKAN = 'PERBAIKAN';
    case GAMAS = 'GAMAS';
    case MENUNGGU_KONFIRMASI = 'MENUNGGU_KONFIRMASI';
    case CEK_ULANG = 'CEK_ULANG';
    case TUTUP = 'TUTUP';

    public function label(): string
    {
        return match ($this) {
            self::ANTRIAN_HELPDESK => 'Antrian Helpdesk',
            self::HELPDESK_CEK => 'Helpdesk Cek',
            self::PERBAIKAN => 'Perbaikan (Teknisi)',
            self::GAMAS => 'Gangguan Massal',
            self::MENUNGGU_KONFIRMASI => 'Menunggu Konfirmasi',
            self::CEK_ULANG => 'Cek Ulang',
            self::TUTUP => 'Tutup / Selesai',
        };
    }

    public function telegramLabel(): string
    {
        return match ($this) {
            self::ANTRIAN_HELPDESK => '🟡 ANTRIAN HELPDESK',
            self::HELPDESK_CEK => '🔵 HELPDESK CEK',
            self::PERBAIKAN => '🔧 PERBAIKAN (TEKNISI)',
            self::GAMAS => '📡 GANGGUAN MASSAL (GAMAS)',
            self::MENUNGGU_KONFIRMASI => '🟣 MENUNGGU KONFIRMASI CS',
            self::CEK_ULANG => '🔄 CEK ULANG (TERKENDALA)',
            self::TUTUP => '✅ TIKET SELESAI & DITUTUP',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ANTRIAN_HELPDESK => 'clock',
            self::HELPDESK_CEK => 'search',
            self::PERBAIKAN => 'wrench',
            self::GAMAS => 'radio-tower',
            self::MENUNGGU_KONFIRMASI => 'message-square',
            self::CEK_ULANG => 'refresh-cw',
            self::TUTUP => 'check-circle-2',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::ANTRIAN_HELPDESK => 'bg-amber-100 text-amber-800 border-amber-300',
            self::HELPDESK_CEK => 'bg-blue-100 text-blue-800 border-blue-300',
            self::PERBAIKAN => 'bg-orange-100 text-orange-800 border-orange-300',
            self::GAMAS => 'bg-red-100 text-red-800 border-red-300',
            self::MENUNGGU_KONFIRMASI => 'bg-purple-100 text-purple-800 border-purple-300',
            self::CEK_ULANG => 'bg-rose-100 text-rose-800 border-rose-300',
            self::TUTUP => 'bg-emerald-100 text-emerald-800 border-emerald-300',
        };
    }

    public function accentClasses(): string
    {
        return match ($this) {
            self::ANTRIAN_HELPDESK => 'border-t-amber-400',
            self::HELPDESK_CEK => 'border-t-blue-500',
            self::PERBAIKAN => 'border-t-orange-500',
            self::GAMAS => 'border-t-red-600',
            self::MENUNGGU_KONFIRMASI => 'border-t-purple-500',
            self::CEK_ULANG => 'border-t-rose-500',
            self::TUTUP => 'border-t-emerald-500',
        };
    }
}
