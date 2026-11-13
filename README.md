# QCNet Ticketing (Laravel)

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

## API Data Pelanggan

Aplikasi lain bisa **membaca** data pelanggan (tidak bisa mengubah). Isi token di `.env`, satu token per aplikasi
dipisah koma, minimal 32 karakter. Buat token dengan `openssl rand -hex 32`:

```
CUSTOMER_API_TOKENS=token_aplikasi_a,token_aplikasi_b
```

Lalu `php artisan config:clear` (atau `config:cache` jika server memakai cache konfigurasi). Setiap request mengirim
header `Authorization: Bearer <token>`. Batasnya 120 request per menit.

| Endpoint | Isi |
|---|---|
| `GET /api/v1/customers` | Daftar pelanggan per halaman. Parameter: `q` (cari ID/nama/WA), `updated_since` (tanggal/ISO 8601, hanya yang berubah setelahnya), `has_location` (`1`/`0`), `per_page` (1–500, default 100), `page` |
| `GET /api/v1/customers/{id}` | Satu pelanggan berdasarkan ID Pelanggan |

Setiap pelanggan berisi `id`, `name`, `phone`, `address`, `latitude`, `longitude`, `maps_url`,
`network` (`olt`, `odc`, `odp`, `odp_port`, `updated_at`), `created_at`, `updated_at`. Daftar pelanggan juga bisa
difilter dengan `olt` dan `odp`.

### Data jaringan dari aplikasi Mapping FTTH

Token di `CUSTOMER_API_WRITE_TOKENS` boleh membaca **dan** mengisi posisi pelanggan di jaringan. Selain OLT, ODC,
ODP, dan port, tidak ada data lain yang bisa diubah lewat API: nama, WA, alamat, dan tikor tetap dikelola ticketing.

| Endpoint | Isi |
|---|---|
| `PATCH /api/v1/customers/{id}/network` | Body JSON `{"olt": "OLT 17", "odc": "ODC-3", "odp": "ODP-12", "odp_port": 4}`. Hanya field yang dikirim yang berubah; `null` mengosongkan. |
| `PATCH /api/v1/customers/network` | Banyak sekaligus (maks. 500): `{"customers": [{"id": "...", "odp": "...", ...}]}`. Balasan: `updated` dan `not_found` (ID yang tidak ada). |

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
