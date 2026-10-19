# NetTicket (Laravel)

Sistem ticketing komplain pelanggan untuk alur **CS → Helpdesk → Teknisi → CS**.
Ini versi Laravel dari aplikasi Node di `D:\Project\Ticketing`.

## Alur Tiket

```
Pelanggan komplain via WhatsApp
        │
        ▼
CS buat tiket ──► 🟡 ANTRIAN HELPDESK (idle, menunggu tim HD)
                        │  HD klaim
                        ▼
                  🔵 HELPDESK CEK ◄───────────────┐
                   │            │                 │
          selesai remote   perlu teknisi          │
                   │            ▼                 │
                   │      🔧 PERBAIKAN            │
                   │       (mulai → lapor selesai)│
                   ▼            │                 │
             🟣 MENUNGGU KONFIRMASI (CS hubungi pelanggan via WA)
                   │                 │
                 Normal          Terkendala
                   ▼                 ▼
               ✅ TUTUP         🔄 CEK ULANG ─────┘ (kembali ke HD)
```

Setiap perubahan status tercatat di riwayat tiket dan dikirim ke grup Telegram.

## Peran

| Peran | Hak akses |
|---|---|
| **SPV** | Semua aksi, kelola pengguna, pengaturan Telegram, laporan |
| **HELPDESK** | Klaim tiket, selesai remote, tugaskan teknisi, laporan, feed Telegram |
| **CS** | Buat tiket, konfirmasi ke pelanggan (Normal/Terkendala), catatan urgensi, laporan |
| **TEKNISI** | Hanya melihat tiket Perbaikan yang ditugaskan ke dirinya: mulai dan lapor selesai |

## Kebutuhan

- PHP 8.3+ (sudah ada di Laragon), Composer
- Node.js + npm (untuk build CSS/JS)
- Database: SQLite, file `database/database.sqlite`

## Menjalankan

Instalasi pertama kali:

```powershell
cd D:\Project\ticketing-laravel
composer install
npm install
copy .env.example .env      # lewati jika .env sudah ada
php artisan key:generate    # lewati jika APP_KEY sudah terisi
php artisan migrate --seed  # buat tabel + akun default + 6 contoh tiket
npm run build
```

Jalankan aplikasi (untuk dipakai banyak user bersamaan):

1. Buka **Laragon** → klik **Start All** (Apache). Situs sudah dikonfigurasi di
   `D:\laragon\etc\apache2\sites-enabled\netticket.conf` (port 8000).
2. Buka **http://localhost:8000**, atau `http://<IP-komputer-ini>:8000` dari komputer lain.

Apache melayani banyak request sekaligus. Sebaliknya, `php artisan serve` hanya memproses **satu request
dalam satu waktu** (user lain menunggu), jadi pakai itu hanya untuk coba-coba sendiri. Jangan jalankan
keduanya bersamaan karena sama-sama memakai port 8000.

**Setiap kali `.env` diubah**, perbarui cache konfigurasi untuk Apache:

```powershell
$env:APP_CONFIG_CACHE="bootstrap/cache/config-web.php"; php artisan config:cache
```

(Cache ini memakai file tersendiri supaya `php artisan test` tidak ikut membacanya dan menyentuh database asli.)

Untuk development dengan hot reload CSS/JS, jalankan `npm run dev` di terminal lain.

Kalau tidak bisa dibuka dari komputer lain, izinkan port 8000 (Apache / `httpd.exe`) di Windows Firewall.

## Akun Default (hanya untuk development)

| Username | Password | Peran |
|---|---|---|
| spv | spv123 | Supervisor |
| helpdesk | hd123 | Helpdesk |
| cs | cs123 | Customer Service |
| teknisi | tek123 | Teknisi (Agus) |
| teknisi2 | tek123 | Teknisi (Rian) |

⚠️ **Ganti semua password ini sebelum dipakai sungguhan.** Setiap user bisa mengganti password sendiri di menu Profil. SPV bisa mengubah password user lain di menu Pengguna.

Akun `spv` adalah supervisor utama. Akun ini tidak bisa dihapus, dinonaktifkan, atau diturunkan perannya.

## Telegram

Masuk sebagai SPV, buka **Pengaturan**, lalu isi Bot Token dan Group Chat ID. Selama keduanya kosong, aplikasi berjalan dalam **mode simulator**: pesan hanya muncul di menu **Feed Telegram** dan tidak dikirim ke Telegram.

Pesan dikirim setelah halaman selesai dimuat, jadi request pengguna tidak ikut lambat dan tidak perlu menjalankan queue worker.

## Perintah Berguna

```powershell
php artisan test               # jalankan semua test
php artisan migrate:fresh --seed  # reset database ke data contoh (MENGHAPUS SEMUA DATA)
vendor\bin\pint                # rapikan format kode PHP
```

## Struktur Kode

| Lokasi | Isi |
|---|---|
| `app/Enums` | `Role`, `TicketStatus`, `TicketPriority`, `TicketAction` (termasuk matriks peran per aksi) |
| `app/Services/TicketWorkflowService.php` | Aturan transisi status tiket |
| `app/Services/TelegramService.php` | Format pesan dan pengiriman ke Telegram |
| `app/Services/MonthlyReportService.php` + `app/Exports` | Laporan bulanan dan ekspor Excel |
| `app/Policies/TicketPolicy.php` | Siapa boleh melihat tiket dan menjalankan aksi |
| `resources/views` | Tampilan Blade + Tailwind + Alpine.js |
| `tests/Feature` | Test login, hak akses, dan alur tiket |

## Backup

Semua data ada di satu file: `database/database.sqlite`. Salin file ini secara berkala untuk backup.
