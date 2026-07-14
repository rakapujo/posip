# Instalasi POSIP di Shared Hosting (cPanel)

Panduan ini untuk instalasi di shared hosting **tanpa SSH**, menggunakan cPanel File Manager.

Paket siap upload dibuat dengan script rebuild:

```powershell
# Windows / Laragon
powershell -ExecutionPolicy Bypass -File syilex\scripts\build-shared-hosting.ps1
```

```bash
# Git Bash / Linux
cd syilex && bash scripts/build-shared-hosting.sh
```

Output: `installer/posip-installer.zip` (berisi `INSTALL.md`, `INSTALL.txt`, dan folder `posip/`).

## Persyaratan Minimal

- PHP >= 8.2
- MySQL 5.7+ atau MariaDB 10.3+
- Extensions: pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, gd
- cPanel dengan File Manager

## Langkah 1: Download / Ambil Paket

Ambil file `posip-installer.zip` dari folder `installer/` (hasil rebuild) atau dari [halaman releases](https://github.com/rakapujo/sipos-baru/releases).

File ini sudah berisi:
- Semua kode POSIP + SPA frontend (`public/`)
- Dependencies (`vendor/`) — tidak perlu Composer di hosting
- `.env` dengan APP_KEY — tidak perlu CLI `key:generate`
- Tutorial `INSTALL.md` / `INSTALL.txt`

## Langkah 2: Upload ke Hosting

1. Login ke **cPanel**
2. Buka **File Manager**
3. Navigasi ke `/home/username/`
4. Klik **Upload** → pilih `posip-installer.zip`
5. Setelah upload, **klik kanan** file zip → **Extract**
6. Akan muncul `INSTALL.md`, `INSTALL.txt`, dan folder `posip/`

## Langkah 3: Setup File

### Opsi A — Domain Utama

1. Buka folder `posip/`
2. **Select All** → **Move** ke `public_html/`
3. Pastikan file `.htaccess` ikut terpindah (file hidden — aktifkan "Show Hidden Files" di Settings)

URL: `http://domain-anda.com/install`

### Opsi B — Subdomain

1. Di cPanel, buka **Subdomains**
2. Buat subdomain (misal: `pos.domain.com`)
3. Arahkan **Document Root** ke `/home/username/posip/public`

URL: `http://pos.domain.com/install`

> **Catatan path aset:** paket ini memakai base URL absolute `/assets/...` (cocok domain/subdomain root). Jangan taruh aplikasi di subdirectory URL (mis. `/syilex/public`) tanpa rebuild frontend dengan `base` yang sesuai.

## Langkah 4: Buat Database

1. Di cPanel, buka **MySQL Databases**
2. **Create New Database** → isi nama (misal: `user_posip`)
3. **Create New User** → isi username & password
4. **Add User to Database** → pilih user & database → centang **ALL PRIVILEGES** → **Make Changes**
5. **Catat**: nama database, username, dan password

## Langkah 5: Set Permissions

Di File Manager:
1. Klik kanan folder `storage/` → **Change Permissions** → set ke `775`
2. Klik kanan folder `bootstrap/cache/` → **Change Permissions** → set ke `775`

## Langkah 6: Jalankan Wizard

1. Buka browser: `http://domain-anda.com/install`
2. **Step 1**: Cek server (pastikan semua hijau)
3. **Step 2**: Masukkan kredensial database dari Langkah 4
4. **Step 3–7**: Isi informasi toko, regional, pajak, promo, akun admin
5. **Step 5**: Aktif/nonaktifkan modul Elektronik (serial) sesuai kebutuhan toko
6. **Step 8**: Pilih **Mulai Kosong** (production) atau **Data Demo** (belajar/uji coba)
7. Opsional: centang **Buat POS Terminal** agar bisa langsung buka kasir
8. Klik **Mulai Instalasi** → tunggu progress selesai
9. Klik **Masuk ke Aplikasi** → login!

### Mode Instalasi

| Mode | Cocok untuk | User yang dibuat |
|------|-------------|------------------|
| **Mulai Kosong** | Production / toko nyata | Hanya akun admin dari Step 7 |
| **Data Demo** | Uji coba / training | Admin Step 7 + akun demo (`manager@`, `kasir@`, `gudang@` — password `password`) |

> Untuk production, **selalu pilih Mulai Kosong** dan gunakan password kuat di Step 7.

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| Error 500 / Blank page | Cek permissions `storage/` dan `bootstrap/cache/` harus 775 |
| Wizard tidak muncul | Pastikan `.htaccess` ter-upload (file hidden di cPanel) |
| "PHP version too low" | Di cPanel → **Select PHP Version** → pilih PHP 8.2+ |
| Database connection failed | Pastikan username sudah di-add ke database dengan ALL PRIVILEGES |
| Page not found (404) | Pastikan `mod_rewrite` aktif di hosting |
| Login SPA gagal setelah install | Pastikan domain di browser sama dengan `APP_URL`; wizard otomatis set `SANCTUM_STATEFUL_DOMAINS` |

## Setelah Instalasi

- Wizard otomatis terkunci (file `storage/installed`)
- Login di: `http://domain-anda.com`
- `.env` production: `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning`, `QUEUE_CONNECTION=database`
- Untuk queue worker (price change scheduler): setup cron atau supervisor `php artisan queue:work`
- Untuk reinstall: hapus file `storage/installed` via File Manager
