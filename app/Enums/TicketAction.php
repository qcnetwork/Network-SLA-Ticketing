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
    case ANTRI_TEKNISI = 'ANTRI_TEKNISI';
    case UBAH_TEKNISI = 'UBAH_TEKNISI';
    case MULAI_PERBAIKAN = 'MULAI_PERBAIKAN';
    case LAPOR_PERBAIKAN_SELESAI = 'LAPOR_PERBAIKAN_SELESAI';
    case KONFIRMASI_NORMAL = 'KONFIRMASI_NORMAL';
    case KONFIRMASI_TERKENDALA = 'KONFIRMASI_TERKENDALA';
    case CATATAN_URGENSI_CS = 'CATATAN_URGENSI_CS';
    case CATATAN_INFO_HELPDESK = 'CATATAN_INFO_HELPDESK';
    case CATATAN_DOKUMENTASI = 'CATATAN_DOKUMENTASI';
    case UBAH_KATEGORI = 'UBAH_KATEGORI';
    case UBAH_DATA_PELANGGAN = 'UBAH_DATA_PELANGGAN';
    case UBAH_PENANGANAN = 'UBAH_PENANGANAN';
    case GABUNG_GAMAS = 'GABUNG_GAMAS';
    case LEPAS_GAMAS = 'LEPAS_GAMAS';
    case GAMAS_SELESAI = 'GAMAS_SELESAI';
    case TUTUP_OTOMATIS = 'TUTUP_OTOMATIS';
    case MATERIAL_DISETUJUI = 'MATERIAL_DISETUJUI';
    case MATERIAL_DITOLAK = 'MATERIAL_DITOLAK';
    case MATERIAL_DIAJUKAN_ULANG = 'MATERIAL_DIAJUKAN_ULANG';

    // New installation (PSB).
    case PSB_DIBUAT = 'PSB_DIBUAT';
    case PSB_LANGSUNG_PASANG = 'PSB_LANGSUNG_PASANG';
    case PSB_PERLU_SURVEY = 'PSB_PERLU_SURVEY';
    case PSB_TUGAS_SURVEY = 'PSB_TUGAS_SURVEY';
    case PSB_SURVEY_LAYAK = 'PSB_SURVEY_LAYAK';
    case PSB_SURVEY_TIDAK_LAYAK = 'PSB_SURVEY_TIDAK_LAYAK';
    case PSB_TUGAS_PASANG = 'PSB_TUGAS_PASANG';
    case PSB_MULAI_PASANG = 'PSB_MULAI_PASANG';
    case PSB_PASANG_SELESAI = 'PSB_PASANG_SELESAI';
    case PSB_AKTIF = 'PSB_AKTIF';
    case PSB_BELUM_AKTIF = 'PSB_BELUM_AKTIF';
    case PSB_KEMBALI_ANTRIAN = 'PSB_KEMBALI_ANTRIAN';
    case PSB_BATAL = 'PSB_BATAL';
    case PSB_UBAH_DATA = 'PSB_UBAH_DATA';

    /**
     * Steps of the installation workflow; the notes and Ubah Teknisi are shared with complaints.
     */
    public function isInstallation(): bool
    {
        return str_starts_with($this->value, 'PSB_');
    }

    /**
     * Whether the action is triggered from the ticket action panel. Ticket creation and
     * gamas-driven changes are recorded by their own flows instead.
     */
    public function isPerformable(): bool
    {
        return ! in_array($this, [self::TIKET_DIBUAT, self::UBAH_DATA_PELANGGAN, self::UBAH_PENANGANAN, self::GABUNG_GAMAS, self::LEPAS_GAMAS, self::GAMAS_SELESAI, self::TUTUP_OTOMATIS, self::MATERIAL_DISETUJUI, self::MATERIAL_DITOLAK, self::MATERIAL_DIAJUKAN_ULANG, self::PSB_DIBUAT, self::PSB_UBAH_DATA], true);
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
            self::KLAIM_HELPDESK, self::SELESAI_REMOTE, self::PERLU_TEKNISI, self::ANTRI_TEKNISI, self::UBAH_TEKNISI => [Role::HELPDESK, Role::SPV],
            // Helpdesk may record repair progress on the technician's behalf while technicians still report via Telegram.
            self::MULAI_PERBAIKAN, self::LAPOR_PERBAIKAN_SELESAI => [Role::TEKNISI, Role::HELPDESK, Role::SPV],
            self::SELESAI_EDUKASI_CS, self::TERUSKAN_HELPDESK, self::KONFIRMASI_NORMAL, self::KONFIRMASI_TERKENDALA, self::CATATAN_URGENSI_CS => [Role::CS, Role::SPV],
            // Helpdesk's counterpart of the CS urgency note, sent to whoever holds the ticket's current step.
            self::CATATAN_INFO_HELPDESK => [Role::HELPDESK, Role::SPV],
            self::CATATAN_DOKUMENTASI => [Role::CS, Role::HELPDESK, Role::TEKNISI, Role::SPV],
            // CS may fix a mis-picked category; the real fault often only shows up once Helpdesk or the technician checks the line.
            self::UBAH_KATEGORI => [Role::CS, Role::HELPDESK, Role::TEKNISI, Role::SPV],
            // Whoever talks to the customer can complete their details, e.g. a shareloc sent after the ticket was made.
            self::UBAH_DATA_PELANGGAN => [Role::CS, Role::HELPDESK, Role::SPV],
            // Helpdesk fills in what was missed when handling, e.g. the modem type, even after the ticket is closed.
            self::UBAH_PENANGANAN => [Role::HELPDESK, Role::SPV],
            self::GABUNG_GAMAS, self::LEPAS_GAMAS => [Role::CS, Role::HELPDESK, Role::SPV],
            self::GAMAS_SELESAI => [Role::HELPDESK, Role::TEKNISI, Role::SPV],
            self::TUTUP_OTOMATIS => [],
            // The warehouse signs off the material a repair resume reports before it leaves the technician's stock.
            self::MATERIAL_DISETUJUI, self::MATERIAL_DITOLAK => [Role::GUDANG, Role::SPV],
            // After a rejection the crew sends corrected material, even once the ticket has moved on.
            self::MATERIAL_DIAJUKAN_ULANG => [Role::TEKNISI, Role::HELPDESK, Role::SPV],
            // CS takes the order; Helpdesk checks coverage and plans the visits; the crew reports from site.
            self::PSB_DIBUAT, self::PSB_BATAL, self::PSB_UBAH_DATA => [Role::CS, Role::HELPDESK, Role::SPV],
            self::PSB_LANGSUNG_PASANG, self::PSB_PERLU_SURVEY, self::PSB_TUGAS_SURVEY, self::PSB_TUGAS_PASANG, self::PSB_KEMBALI_ANTRIAN => [Role::HELPDESK, Role::SPV],
            self::PSB_SURVEY_LAYAK, self::PSB_SURVEY_TIDAK_LAYAK, self::PSB_MULAI_PASANG, self::PSB_PASANG_SELESAI => [Role::TEKNISI, Role::HELPDESK, Role::SPV],
            self::PSB_AKTIF, self::PSB_BELUM_AKTIF => [Role::CS, Role::SPV],
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
            self::ANTRI_TEKNISI => 'Masuk Antrian Teknisi',
            self::UBAH_TEKNISI => 'Ubah Teknisi',
            self::MULAI_PERBAIKAN => 'Mulai Perbaikan',
            self::LAPOR_PERBAIKAN_SELESAI => 'Perbaikan Selesai',
            self::KONFIRMASI_NORMAL => 'Konfirmasi Normal',
            self::KONFIRMASI_TERKENDALA => 'Konfirmasi Terkendala',
            self::CATATAN_URGENSI_CS => 'Catatan Urgensi CS',
            self::CATATAN_INFO_HELPDESK => 'Info Helpdesk',
            self::CATATAN_DOKUMENTASI => 'Catatan Dokumentasi',
            self::UBAH_KATEGORI => 'Ubah Kategori Kendala',
            self::UBAH_DATA_PELANGGAN => 'Ubah Data Pelanggan',
            self::UBAH_PENANGANAN => 'Ubah Data Penanganan',
            self::GABUNG_GAMAS => 'Masuk Gamas',
            self::LEPAS_GAMAS => 'Dilepas dari Gamas',
            self::GAMAS_SELESAI => 'Gamas Selesai',
            self::TUTUP_OTOMATIS => 'Ditutup Otomatis',
            self::MATERIAL_DISETUJUI => 'Material Disetujui Gudang',
            self::MATERIAL_DITOLAK => 'Material Ditolak Gudang',
            self::MATERIAL_DIAJUKAN_ULANG => 'Material Diajukan Ulang',
            self::PSB_DIBUAT => 'PSB Dibuat',
            self::PSB_LANGSUNG_PASANG => 'Lokasi OK, Langsung Pasang',
            self::PSB_PERLU_SURVEY => 'Perlu Survey',
            self::PSB_TUGAS_SURVEY => 'Teknisi Survey Ditugaskan',
            self::PSB_SURVEY_LAYAK => 'Survey: Bisa Dipasang',
            self::PSB_SURVEY_TIDAK_LAYAK => 'Survey: Tidak Bisa Dipasang',
            self::PSB_TUGAS_PASANG => 'Teknisi Pasang Ditugaskan',
            self::PSB_MULAI_PASANG => 'Mulai Pemasangan',
            self::PSB_PASANG_SELESAI => 'Pemasangan Selesai',
            self::PSB_AKTIF => 'Pelanggan Aktif',
            self::PSB_BELUM_AKTIF => 'Belum Aktif',
            self::PSB_KEMBALI_ANTRIAN => 'Kembali ke Antrian',
            self::PSB_BATAL => 'PSB Dibatalkan',
            self::PSB_UBAH_DATA => 'Ubah Data PSB',
        };
    }
}
