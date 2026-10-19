<?php

namespace App\Services;

use App\Models\Ticket;

class WhatsAppTemplateService
{
    public const TYPES = [
        'tiket_dibuat' => 'Info Tiket Dibuat',
        'teknisi_menuju' => 'Teknisi Menuju Lokasi',
        'konfirmasi' => 'Minta Konfirmasi Hasil',
    ];

    /**
     * @return array{message: string, url: string}
     */
    public function build(Ticket $ticket, string $type = 'konfirmasi'): array
    {
        $name = $ticket->customer_name;
        $number = $ticket->ticket_number;

        $message = match ($type) {
            'tiket_dibuat' => "Halo Kak *{$name}*,\n\n"
                ."Laporan kendala Anda telah kami terima dengan nomor tiket:\n"
                ."🎫 *{$number}*\n"
                ."📝 Kendala: {$ticket->complaint}\n\n"
                .'Laporan sedang dalam antrian dan akan segera dianalisis oleh tim Helpdesk kami. Mohon ditunggu ya Kak. Terima kasih! 🙏',
            'teknisi_menuju' => "Halo Kak *{$name}*,\n\n"
                ."Update tiket *{$number}*:\n"
                .'Tim teknisi kami (*'.($ticket->technicianNames() ?: 'Teknisi Lapangan').'*) sedang ditugaskan dan akan segera menuju lokasi Anda untuk melakukan perbaikan. '
                .'Mohon pastikan ada orang di rumah/lokasi. Terima kasih! 🛠️',
            default => "Halo Kak *{$name}*,\n\n"
                ."Update pengerjaan untuk tiket *{$number}* telah selesai kami tangani:\n"
                .($ticket->technician_notes ? "🔧 Hasil Perbaikan: {$ticket->technician_notes}\n" : '')
                .($ticket->remote_resolution_notes ? "⚡ Tindakan: {$ticket->remote_resolution_notes}\n" : '')
                ."\nMohon bantuannya untuk mengecek kembali, apakah saat ini layanan/koneksi sudah *Normal* kembali atau *Masih Terkendala*? 🙏\n\n"
                .'Terima kasih.',
        };

        return [
            'message' => $message,
            'url' => 'https://wa.me/'.$ticket->customer_phone.'?text='.rawurlencode($message),
        ];
    }
}
