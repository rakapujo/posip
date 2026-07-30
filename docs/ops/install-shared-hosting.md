> **Status:** canonical
> **SSoT kode:** installer/ · public/install
> **Jika konflik:** ikuti kode, lalu update dokumen ini.
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

Download **`posip-installer.zip`** dari [GitHub Releases](https://github.com/rakapujo/posip/releases) (Assets), atau bangun ulang lokal ke `installer/posip-installer.zip` dengan script di atas.

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

### Opsi A — Domain Utama (DocumentRoot = isi aplikasi)

1. Buka folder `posip/`
2. **Select All** → **Move** ke `public_html/`
3. Rename `htaccess.root-shared-hosting` → `.htaccess` (aktifkan "Show Hidden Files" di Settings setelah rename)

URL: `http://domain-anda.com/install`

### Opsi B — Subdomain / Hestia (DocumentRoot = `…/public`)

1. Di cPanel/Hestia, buat subdomain (misal: `pos.domain.com`)
2. Arahkan **Document Root** ke `/home/username/posip/public` (atau `…/public_html/public`)
3. **Jangan** rename / pasang `htaccess.root-shared-hosting` sebagai `.htaccess` di parent — file itu untuk Opsi A saja; di Hestia bisa memicu Apache 500 (`IfModule not allowed`)

URL: `http://pos.domain.com/install`

> **Catatan path aset:** paket ini memakai base URL absolute `/assets/...` (cocok domain/subdomain root). Jangan taruh aplikasi di subdirectory URL (mis. `/syilex/public`) tanpa rebuild frontend dengan `base` yang sesuai.

## Langkah 4: Buat Database

1. Di cPanel, buka **MySQL Databases**
2. **Create New Database** → isi nama (misal: `user_posip`)
3. **Create New User** → isi username & password
4. **Add User to Database** → pilih user & database → centang **ALL PRIVILEGES** → **Make Changes**
5. **Catat**: nama database, username, dan password

## Langkah 5: Set Permissions (pre-flight — sebelum buka `/install`)

Di File Manager:
1. Klik kanan folder `storage/` → **Change Permissions** → set ke `775`
2. Klik kanan folder `bootstrap/cache/` → **Change Permissions** → set ke `775`

Via SSH (disarankan setelah extract zip dari Windows — zip sering hilangkan bit execute folder `vendor/`):

```bash
cd /path/ke/posip   # atau public_html
find . -type d -exec chmod u+rx {} \;
chmod -R ug+rwx storage bootstrap/cache
```

Tanpa langkah ini, PHP bisa gagal load `vendor/autoload.php` (Permission denied) sebelum wizard jalan.

## Langkah 6: Jalankan Wizard

1. Buka browser: `http://domain-anda.com/install`
2. **Step 1**: Cek server (pastikan semua hijau)
3. **Step 2**: Masukkan kredensial database dari Langkah 4
4. **Step 3**: Informasi toko (+ URL terdeteksi, footer struk)
5. **Step 4**: Regional & mata uang (+ desimal persen, mode huruf besar)
6. **Step 5**: Pajak, pembulatan jual/beli, stok negatif (`block`/`allow`), retur bebas, modul elektronik, mode harga
7. **Step 6–7**: Promo, akun admin
8. **Step 8**: Pilih **Mulai Kosong** (production) atau **Data Demo** (belajar/uji coba)
9. Opsional: centang **Buat POS Terminal** agar bisa langsung buka kasir
10. Klik **Mulai Instalasi** → tunggu progress selesai
11. Klik **Masuk ke Aplikasi** → login!

### Mode Instalasi

| Mode | Cocok untuk | User yang dibuat |
|------|-------------|------------------|
| **Mulai Kosong** | Production / toko nyata | Hanya akun admin dari Step 7 |
| **Data Demo** | Uji coba / training | Admin Step 7 + akun demo (`manager@`, `kasir@`, `gudang@` — password `password`) |

> Untuk production, **selalu pilih Mulai Kosong** dan gunakan password kuat di Step 7.

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| Error 500 / blank — log Apache `IfModule not allowed` | Hapus `.htaccess` di **parent** DocumentRoot; Opsi B/Hestia: jangan aktifkan `htaccess.root-shared-hosting` |
| Error 500 — log PHP `Permission denied` di `vendor/...` | Pre-flight: `find . -type d -exec chmod u+rx {} \;` |
| Wizard tidak muncul (Opsi A) | Rename `htaccess.root-shared-hosting` → `.htaccess`; pastikan `mod_rewrite` aktif |
| "PHP version too low" | Di cPanel → **Select PHP Version** → pilih PHP 8.2+ |
| Database connection failed | Pastikan username sudah di-add ke database dengan ALL PRIVILEGES |
| Page not found (404) | Pastikan `mod_rewrite` aktif; Opsi A butuh root rewrite `.htaccess` |
| Login Server Error / `Access denied` MySQL setelah wizard “sukses” | Cek `.env` `DB_*` cocok wizard; `php artisan config:clear` (installer baru menulis `.env` sebelum optimize) |
| Login SPA gagal (bukan 500) | Domain browser = `APP_URL`; cek `SANCTUM_STATEFUL_DOMAINS` |

## Setelah Instalasi

- Wizard otomatis terkunci (file `storage/installed`)
- Login di: `http://domain-anda.com`
- `.env` production: `SESSION_ENCRYPT=true`, `LOG_LEVEL=warning`, `QUEUE_CONNECTION=database`
- Untuk price change scheduler: setup cron `* * * * * php artisan schedule:run` (bukan `queue:work`)
- Untuk reinstall: hapus file `storage/installed` via File Manager
