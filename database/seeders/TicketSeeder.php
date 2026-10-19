<?php

namespace Database\Seeders;

use App\Enums\TicketAction as A;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus as S;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Six sample tickets, one per workflow status (ported from the old seedInitialData).
 */
class TicketSeeder extends Seeder
{
    public function run(): void
    {
        // Demo tickets would pollute real data and reports.
        if (app()->isProduction() || Ticket::query()->exists()) {
            return;
        }

        $cs = User::query()->where('username', 'cs')->first();
        $hd = User::query()->where('username', 'helpdesk')->first();
        $tech = User::query()->where('username', 'teknisi')->first();
        $tech2 = User::query()->where('username', 'teknisi2')->first();
        $date = now()->format('Ymd');

        $samples = [
            [
                'ticket' => [
                    'customer_id' => 'CUST-1042', 'customer_name' => 'Bapak Rahmat Hidayat', 'customer_phone' => '6281234567891',
                    'customer_address' => 'Jl. Merak No. 14, RT 02/05, Kebayoran Baru', 'category' => 'LOS Merah / Kabel Putus',
                    'priority' => TicketPriority::TINGGI, 'status' => S::ANTRIAN_HELPDESK,
                    'complaint' => 'Lampu indikator modem LOS berkedip merah sejak sore tadi setelah hujan lebat.',
                ],
                'history' => [
                    [25, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Komplain masuk via WhatsApp. LOS merah indikasi kabel putus.'],
                ],
            ],
            [
                'ticket' => [
                    'customer_id' => 'CUST-2088', 'customer_name' => 'Ibu Ratna Dewi', 'customer_phone' => '6285712345678',
                    'customer_address' => 'Perum Gading Asri Blok C2 No. 8', 'category' => 'Internet Lambat / Ping Tinggi',
                    'priority' => TicketPriority::SEDANG, 'status' => S::HELPDESK_CEK, 'assigned_helpdesk_id' => $hd->id,
                    'complaint' => 'Koneksi lemot hanya dapat 2 Mbps dari paket 50 Mbps. Sering RTO saat zoom meeting.',
                ],
                'history' => [
                    [50, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Keluhan bandwidth drop dan latency tinggi.'],
                    [35, $hd, A::KLAIM_HELPDESK, S::ANTRIAN_HELPDESK, S::HELPDESK_CEK, 'Mengecek redaman OLT dan traffic interface pelanggan.'],
                ],
            ],
            [
                'ticket' => [
                    'customer_id' => 'CUST-3312', 'customer_name' => 'CV Mitra Sukses (Bp. Hendra)', 'customer_phone' => '6281987654321',
                    'customer_address' => 'Ruko Senopati Mas Kav. 5, Jl. Senopati', 'category' => 'Internet Mati Total (LOS Hijau tapi RTO)',
                    'priority' => TicketPriority::TINGGI, 'status' => S::PERBAIKAN, 'assigned_helpdesk_id' => $hd->id,
                    'complaint' => 'Internet putus-putus. Redaman di OLT -32 dBm (batas normal -24 dBm).',
                ],
                'technicians' => [$tech, $tech2],
                'history' => [
                    [120, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Pelanggan kantor komplain koneksi flapping.'],
                    [100, $hd, A::KLAIM_HELPDESK, S::ANTRIAN_HELPDESK, S::HELPDESK_CEK, 'Rx power di OLT -32.4 dBm.'],
                    [60, $hd, A::PERLU_TEKNISI, S::HELPDESK_CEK, S::PERBAIKAN, 'Redaman tinggi di ODP tiang 14. Perlu perbaikan konektor dan cek splicing.'],
                ],
            ],
            [
                'ticket' => [
                    'customer_id' => 'CUST-4105', 'customer_name' => 'Bapak Ilham Pratama', 'customer_phone' => '6281311223344',
                    'customer_address' => 'Jl. Cemara 3 No. 45, Bintaro Sektor 2', 'category' => 'Lainnya',
                    'priority' => TicketPriority::RENDAH, 'status' => S::MENUNGGU_KONFIRMASI, 'assigned_helpdesk_id' => $hd->id,
                    'complaint' => 'Tidak bisa akses portal kantor, web lain normal.',
                    'remote_resolution_notes' => 'Flush DNS cache di gateway dan reset IP lease MikroTik.', 'resolved_at' => now()->subMinutes(15),
                ],
                'history' => [
                    [90, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Keluhan akses ke IP server spesifik gagal.'],
                    [75, $hd, A::KLAIM_HELPDESK, S::ANTRIAN_HELPDESK, S::HELPDESK_CEK, 'Pengecekan routing table dan DNS cache.'],
                    [15, $hd, A::SELESAI_REMOTE, S::HELPDESK_CEK, S::MENUNGGU_KONFIRMASI, 'Flush DNS cache di gateway dan reset IP lease MikroTik.'],
                ],
            ],
            [
                'ticket' => [
                    'customer_id' => 'CUST-5220', 'customer_name' => 'Ibu Kartini Susilo', 'customer_phone' => '6281899887766',
                    'customer_address' => 'Apartemen Green Park Tower B Lt. 12 No. 06', 'category' => 'WiFi Sering Putus / Jangkauan Sinyal',
                    'priority' => TicketPriority::SEDANG, 'status' => S::CEK_ULANG, 'assigned_helpdesk_id' => $hd->id,
                    'complaint' => 'Sinyal 5GHz sering terputus di kamar tidur.',
                    'remote_resolution_notes' => 'Ubah channel Wi-Fi ke 36 dan transmit power 100%.',
                    'confirmation_notes' => 'Pelanggan tes kembali, frekuensi 5GHz masih drop.',
                ],
                'history' => [
                    [240, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Komplain sinyal drop.'],
                    [200, $hd, A::KLAIM_HELPDESK, S::ANTRIAN_HELPDESK, S::HELPDESK_CEK, null],
                    [180, $hd, A::SELESAI_REMOTE, S::HELPDESK_CEK, S::MENUNGGU_KONFIRMASI, 'Ubah channel Wi-Fi ke 36 dan transmit power 100%.'],
                    [10, $cs, A::KONFIRMASI_TERKENDALA, S::MENUNGGU_KONFIRMASI, S::CEK_ULANG, 'Di kamar tidur sinyal masih hilang timbul. Usul cek posisi atau AP tambahan.'],
                ],
            ],
            [
                'ticket' => [
                    'customer_id' => 'CUST-6119', 'customer_name' => 'Bapak Bambang Suryo', 'customer_phone' => '6281298761234',
                    'customer_address' => 'Jl. Dahlia Raya No. 21, Pondok Indah', 'category' => 'LOS Merah / Kabel Putus',
                    'priority' => TicketPriority::TINGGI, 'status' => S::TUTUP, 'assigned_helpdesk_id' => $hd->id,
                    'complaint' => 'Kabel FO putus tertimpa dahan pohon.',
                    'technician_notes' => 'Kabel FO ditarik ulang 45 meter, redaman OPM -18.2 dBm.',
                    'confirmation_notes' => 'Pelanggan konfirmasi koneksi sudah normal via WA.',
                    'repair_started_at' => now()->subMinutes(120), 'resolved_at' => now()->subMinutes(50), 'closed_at' => now()->subMinutes(45),
                ],
                'technicians' => [$tech2],
                'history' => [
                    [400, $cs, A::TIKET_DIBUAT, null, S::ANTRIAN_HELPDESK, 'Kabel putus tertimpa dahan.'],
                    [380, $hd, A::KLAIM_HELPDESK, S::ANTRIAN_HELPDESK, S::HELPDESK_CEK, null],
                    [360, $hd, A::PERLU_TEKNISI, S::HELPDESK_CEK, S::PERBAIKAN, 'Perlu penarikan kabel dropcore baru.'],
                    [120, $tech2, A::MULAI_PERBAIKAN, S::PERBAIKAN, S::PERBAIKAN, null],
                    [50, $tech2, A::LAPOR_PERBAIKAN_SELESAI, S::PERBAIKAN, S::MENUNGGU_KONFIRMASI, 'Kabel FO ditarik ulang 45 meter, redaman OPM -18.2 dBm.'],
                    [45, $cs, A::KONFIRMASI_NORMAL, S::MENUNGGU_KONFIRMASI, S::TUTUP, 'Pelanggan konfirmasi koneksi sudah normal via WA.'],
                ],
            ],
        ];

        foreach ($samples as $i => $sample) {
            $created = now()->subMinutes($sample['history'][0][0]);
            $updated = now()->subMinutes(end($sample['history'])[0]);

            $ticket = Ticket::create($sample['ticket'] + [
                'ticket_number' => sprintf('TCK-%s-%04d', $date, $i + 1),
                'created_by_id' => $cs->id,
                'created_at' => $created,
                'updated_at' => $updated,
            ]);
            $ticket->technicians()->attach(collect($sample['technicians'] ?? [])->pluck('id')->all());

            foreach ($sample['history'] as [$minutesAgo, $user, $action, $from, $to, $notes]) {
                $ticket->histories()->create([
                    'user_id' => $user->id,
                    'actor_name' => $user->name,
                    'action' => $action,
                    'from_status' => $from,
                    'to_status' => $to,
                    'notes' => $notes ?? $action->label(),
                    'created_at' => now()->subMinutes($minutesAgo),
                    'updated_at' => now()->subMinutes($minutesAgo),
                ]);
            }
        }
    }
}
