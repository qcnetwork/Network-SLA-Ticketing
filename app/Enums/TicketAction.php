<?php

namespace App\Enums;

enum TicketAction: string
{
    case TIKET_DIBUAT = 'TIKET_DIBUAT';
    case SELESAI_EDUKASI_CS = 'SELESAI_EDUKASI_CS';
    case TERUSKAN_HELPDESK = 'TERUSKAN_HELPDESK';
    case KLAIM_HELPDESK = 'KLAIM_HELPDESK';
    case SELESAI_REMOTE = 'SELESAI_REMOTE';
    case PERLU_TEKNISI = 'PERLU_TEKNISI';
    case UBAH_TEKNISI = 'UBAH_TEKNISI';
    case MULAI_PERBAIKAN = 'MULAI_PERBAIKAN';
    case LAPOR_PERBAIKAN_SELESAI = 'LAPOR_PERBAIKAN_SELESAI';
    case KONFIRMASI_NORMAL = 'KONFIRMASI_NORMAL';
    case KONFIRMASI_TERKENDALA = 'KONFIRMASI_TERKENDALA';
    case CATATAN_URGENSI_CS = 'CATATAN_URGENSI_CS';
    case CATATAN_DOKUMENTASI = 'CATATAN_DOKUMENTASI';
    case UBAH_KATEGORI = 'UBAH_KATEGORI';
    case UBAH_DATA_PELANGGAN = 'UBAH_DATA_PELANGGAN';
    case GABUNG_GAMAS = 'GABUNG_GAMAS';
    case LEPAS_GAMAS = 'LEPAS_GAMAS';
    case GAMAS_SELESAI = 'GAMAS_SELESAI';
    case TUTUP_OTOMATIS = 'TUTUP_OTOMATIS';

    /**
     * Whether the action is triggered from the ticket action panel. Ticket creation and
     * gamas-driven changes are recorded by their own flows instead.
     */
    public function isPerformable(): bool
    {
        return ! in_array($this, [self::TIKET_DIBUAT, self::UBAH_DATA_PELANGGAN, self::GABUNG_GAMAS, self::LEPAS_GAMAS, self::GAMAS_SELESAI, self::TUTUP_OTOMATIS], true);
    }

    /**
     * Roles allowed to perform this action (mirrors actionRoles in the old server.js).
     *
     * @return array<Role>
     */
    public function allowedRoles(): array
    {
        return match ($this) {
            self::TIKET_DIBUAT => [Role::CS, Role::HELPDESK, Role::SPV],
            self::KLAIM_HELPDESK, self::SELESAI_REMOTE, self::PERLU_TEKNISI, self::UBAH_TEKNISI => [Role::HELPDESK, Role::SPV],
            // Helpdesk may record repair progress on the technician's behalf while technicians still report via Telegram.
            self::MULAI_PERBAIKAN, self::LAPOR_PERBAIKAN_SELESAI => [Role::TEKNISI, Role::HELPDESK, Role::SPV],
            self::SELESAI_EDUKASI_CS, self::TERUSKAN_HELPDESK, self::KONFIRMASI_NORMAL, self::KONFIRMASI_TERKENDALA, self::CATATAN_URGENSI_CS => [Role::CS, Role::SPV],
            self::CATATAN_DOKUMENTASI => [Role::CS, Role::HELPDESK, Role::TEKNISI, Role::SPV],
            // The real fault often only shows up once Helpdesk or the technician checks the line.
            self::UBAH_KATEGORI => [Role::HELPDESK, Role::TEKNISI, Role::SPV],
            // Whoever talks to the customer can complete their details, e.g. a shareloc sent after the ticket was made.
            self::UBAH_DATA_PELANGGAN => [Role::CS, Role::HELPDESK, Role::SPV],
            self::GABUNG_GAMAS, self::LEPAS_GAMAS => [Role::CS, Role::HELPDESK, Role::SPV],
            self::GAMAS_SELESAI => [Role::HELPDESK, Role::TEKNISI, Role::SPV],
            self::TUTUP_OTOMATIS => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TIKET_DIBUAT => 'Tiket Dibuat',
            self::SELESAI_EDUKASI_CS => 'Selesai Edukasi CS',
            self::TERUSKAN_HELPDESK => 'Teruskan ke Helpdesk',
            self::KLAIM_HELPDESK => 'Klaim Helpdesk',
            self::SELESAI_REMOTE => 'Selesai Remote',
            self::PERLU_TEKNISI => 'Eskalasi ke Teknisi',
            self::UBAH_TEKNISI => 'Ubah Teknisi',
            self::MULAI_PERBAIKAN => 'Mulai Perbaikan',
            self::LAPOR_PERBAIKAN_SELESAI => 'Perbaikan Selesai',
            self::KONFIRMASI_NORMAL => 'Konfirmasi Normal',
            self::KONFIRMASI_TERKENDALA => 'Konfirmasi Terkendala',
            self::CATATAN_URGENSI_CS => 'Catatan Urgensi CS',
            self::CATATAN_DOKUMENTASI => 'Catatan Dokumentasi',
            self::UBAH_KATEGORI => 'Ubah Kategori Kendala',
            self::UBAH_DATA_PELANGGAN => 'Ubah Data Pelanggan',
            self::GABUNG_GAMAS => 'Masuk Gamas',
            self::LEPAS_GAMAS => 'Dilepas dari Gamas',
            self::GAMAS_SELESAI => 'Gamas Selesai',
            self::TUTUP_OTOMATIS => 'Ditutup Otomatis',
        };
    }
}
