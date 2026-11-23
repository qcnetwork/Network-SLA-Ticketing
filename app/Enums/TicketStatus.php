<?php

namespace App\Enums;

enum TicketStatus: string
{
    case PROSES_CS = 'PROSES_CS';
    case ANTRIAN_HELPDESK = 'ANTRIAN_HELPDESK';
    case HELPDESK_CEK = 'HELPDESK_CEK';
    // The customer is still having trouble after a repair: back with Helpdesk. Kept beside Helpdesk Cek on the
    // board (case order is board order) so the helpdesk does not miss it at the far end.
    case CEK_ULANG = 'CEK_ULANG';
    // Needs a site visit, but no technician is free yet; waits here until Helpdesk assigns one.
    case ANTRIAN_TEKNISI = 'ANTRIAN_TEKNISI';
    case PERBAIKAN = 'PERBAIKAN';
    case GAMAS = 'GAMAS';
    case MENUNGGU_KONFIRMASI = 'MENUNGGU_KONFIRMASI';
    case TUTUP = 'TUTUP';

    // New installation (PSB). Helpdesk checks the location, a survey runs when coverage is unsure,
    // a crew installs, and CS confirms with the customer that the line is live.
    case PSB_CEK_LOKASI = 'PSB_CEK_LOKASI';
    case PSB_ANTRIAN_SURVEY = 'PSB_ANTRIAN_SURVEY';
    case PSB_SURVEY = 'PSB_SURVEY';
    case PSB_ANTRIAN_PASANG = 'PSB_ANTRIAN_PASANG';
    case PSB_PEMASANGAN = 'PSB_PEMASANGAN';
    case PSB_KONFIRMASI = 'PSB_KONFIRMASI';
    case PSB_SELESAI = 'PSB_SELESAI';
    case PSB_BATAL = 'PSB_BATAL';

    /**
     * The complaint workflow, in board order.
     *
     * @return list<self>
     */
    public static function complaint(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->kind() === TicketKind::GANGGUAN));
    }

    /**
     * The installation workflow, in board order.
     *
     * @return list<self>
     */
    public static function installation(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->kind() === TicketKind::PSB));
    }

    public function kind(): TicketKind
    {
        return str_starts_with($this->value, 'PSB_') ? TicketKind::PSB : TicketKind::GANGGUAN;
    }

    /**
     * A technician is on site: the assigned crew may open and act on the ticket.
     */
    public function isFieldWork(): bool
    {
        return in_array($this, [self::PERBAIKAN, self::PSB_SURVEY, self::PSB_PEMASANGAN], true);
    }

    /**
     * Nothing moves the ticket any more, so notes and edits are closed.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::TUTUP, self::PSB_SELESAI, self::PSB_BATAL], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PROSES_CS => 'Proses CS',
            self::ANTRIAN_HELPDESK => 'Antrian Helpdesk',
            self::HELPDESK_CEK => 'Helpdesk Cek',
            self::ANTRIAN_TEKNISI => 'Antrian Teknisi',
            self::PERBAIKAN => 'Perbaikan (Teknisi)',
            self::GAMAS => 'Gangguan Massal',
            self::MENUNGGU_KONFIRMASI => 'Menunggu Konfirmasi',
            self::CEK_ULANG => 'Cek Ulang',
            self::TUTUP => 'Tutup / Selesai',
            self::PSB_CEK_LOKASI => 'Cek Lokasi',
            self::PSB_ANTRIAN_SURVEY => 'Antrian Survey',
            self::PSB_SURVEY => 'Survey (Teknisi)',
            self::PSB_ANTRIAN_PASANG => 'Antrian Pasang',
            self::PSB_PEMASANGAN => 'Pemasangan (Teknisi)',
            self::PSB_KONFIRMASI => 'Konfirmasi Aktif',
            self::PSB_SELESAI => 'Selesai / Aktif',
            self::PSB_BATAL => 'Batal',
        };
    }

    public function telegramLabel(): string
    {
        return match ($this) {
            self::PROSES_CS => '🎧 PROSES CS',
            self::ANTRIAN_HELPDESK => '🟡 ANTRIAN HELPDESK',
            self::HELPDESK_CEK => '🔵 HELPDESK CEK',
            self::ANTRIAN_TEKNISI => '⏳ ANTRIAN TEKNISI',
            self::PERBAIKAN => '🔧 PERBAIKAN (TEKNISI)',
            self::GAMAS => '📡 GANGGUAN MASSAL (GAMAS)',
            self::MENUNGGU_KONFIRMASI => '🟣 MENUNGGU KONFIRMASI CS',
            self::CEK_ULANG => '🔄 CEK ULANG (TERKENDALA)',
            self::TUTUP => '✅ TIKET SELESAI & DITUTUP',
            self::PSB_CEK_LOKASI => '📍 PSB · CEK LOKASI',
            self::PSB_ANTRIAN_SURVEY => '⏳ PSB · ANTRIAN SURVEY',
            self::PSB_SURVEY => '🧭 PSB · SURVEY (TEKNISI)',
            self::PSB_ANTRIAN_PASANG => '⏳ PSB · ANTRIAN PASANG',
            self::PSB_PEMASANGAN => '🔧 PSB · PEMASANGAN (TEKNISI)',
            self::PSB_KONFIRMASI => '🟣 PSB · KONFIRMASI AKTIF',
            self::PSB_SELESAI => '✅ PSB · SELESAI, PELANGGAN AKTIF',
            self::PSB_BATAL => '⛔ PSB · DIBATALKAN',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::PROSES_CS => 'headset',
            self::ANTRIAN_HELPDESK => 'clock',
            self::HELPDESK_CEK => 'search',
            self::ANTRIAN_TEKNISI => 'hourglass',
            self::PERBAIKAN => 'wrench',
            self::GAMAS => 'radio-tower',
            self::MENUNGGU_KONFIRMASI => 'message-square',
            self::CEK_ULANG => 'refresh-cw',
            self::TUTUP => 'check-circle-2',
            self::PSB_CEK_LOKASI => 'map-pin',
            self::PSB_ANTRIAN_SURVEY => 'hourglass',
            self::PSB_SURVEY => 'compass',
            self::PSB_ANTRIAN_PASANG => 'hourglass',
            self::PSB_PEMASANGAN => 'wrench',
            self::PSB_KONFIRMASI => 'message-square',
            self::PSB_SELESAI => 'check-circle-2',
            self::PSB_BATAL => 'x-circle',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::PROSES_CS => 'bg-cyan-100 text-cyan-800 border-cyan-300',
            self::ANTRIAN_HELPDESK => 'bg-amber-100 text-amber-800 border-amber-300',
            self::HELPDESK_CEK => 'bg-blue-100 text-blue-800 border-blue-300',
            self::ANTRIAN_TEKNISI => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            self::PERBAIKAN => 'bg-orange-100 text-orange-800 border-orange-300',
            self::GAMAS => 'bg-red-100 text-red-800 border-red-300',
            self::MENUNGGU_KONFIRMASI => 'bg-purple-100 text-purple-800 border-purple-300',
            self::CEK_ULANG => 'bg-rose-100 text-rose-800 border-rose-300',
            self::TUTUP => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            self::PSB_CEK_LOKASI => 'bg-cyan-100 text-cyan-800 border-cyan-300',
            self::PSB_ANTRIAN_SURVEY => 'bg-amber-100 text-amber-800 border-amber-300',
            self::PSB_SURVEY => 'bg-blue-100 text-blue-800 border-blue-300',
            self::PSB_ANTRIAN_PASANG => 'bg-yellow-100 text-yellow-800 border-yellow-300',
            self::PSB_PEMASANGAN => 'bg-orange-100 text-orange-800 border-orange-300',
            self::PSB_KONFIRMASI => 'bg-purple-100 text-purple-800 border-purple-300',
            self::PSB_SELESAI => 'bg-emerald-100 text-emerald-800 border-emerald-300',
            self::PSB_BATAL => 'bg-slate-200 text-slate-700 border-slate-300',
        };
    }

    public function accentClasses(): string
    {
        return match ($this) {
            self::PROSES_CS => 'border-t-cyan-500',
            self::ANTRIAN_HELPDESK => 'border-t-amber-400',
            self::HELPDESK_CEK => 'border-t-blue-500',
            self::ANTRIAN_TEKNISI => 'border-t-yellow-500',
            self::PERBAIKAN => 'border-t-orange-500',
            self::GAMAS => 'border-t-red-600',
            self::MENUNGGU_KONFIRMASI => 'border-t-purple-500',
            self::CEK_ULANG => 'border-t-rose-500',
            self::TUTUP => 'border-t-emerald-500',
            self::PSB_CEK_LOKASI => 'border-t-cyan-500',
            self::PSB_ANTRIAN_SURVEY => 'border-t-amber-400',
            self::PSB_SURVEY => 'border-t-blue-500',
            self::PSB_ANTRIAN_PASANG => 'border-t-yellow-500',
            self::PSB_PEMASANGAN => 'border-t-orange-500',
            self::PSB_KONFIRMASI => 'border-t-purple-500',
            self::PSB_SELESAI => 'border-t-emerald-500',
            self::PSB_BATAL => 'border-t-slate-400',
        };
    }
}
