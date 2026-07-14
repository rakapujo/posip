# Deployment Guide — SIPOS

## Fresh Install (Server Baru)

```bash
# 1. Clone repo
git clone https://github.com/rakapujo/sipos-baru.git
cd sipos-baru

# 2. Install dependencies (production — NO dev packages)
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Setup environment — PAKAI .env.production.example sebagai template!
cp .env.production.example .env
# Edit .env dan pastikan:
#   - DB_DATABASE, DB_USERNAME, DB_PASSWORD (wajib diisi)
#   - APP_URL (https://...)
#   - APP_TIMEZONE
#   - SESSION_DOMAIN (.yourdomain.com)
#   - APP_DEBUG=false (WAJIB)
#   - LOG_LEVEL=warning
#   - SESSION_ENCRYPT=true
#   - QUEUE_CONNECTION=database (atau redis)

# 4. Generate app key (sekali saja di first deploy)
php artisan key:generate

# 5. Setup queue jobs table (jika pakai QUEUE_CONNECTION=database)
php artisan queue:table
php artisan queue:failed-table

# 6. Migrate + seed
php artisan migrate --force
php artisan db:seed --force

# 7. Symlink storage (WAJIB untuk upload image work)
php artisan storage:link

# 8. WAJIB: cache config/route/view (boost performance 2-3x)
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 9. Setup queue worker — lihat section Queue Worker di bawah

# 10. Set permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

## Update Deploy (Setiap Deploy Selanjutnya)

```bash
cd /path/ke/sipos
git pull origin main

# 1. Install dependencies production
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Migration (kalau ada yang baru — aman kalau tidak ada)
php artisan migrate --force

# 3. WAJIB: clear + rebuild cache
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Restart queue workers (supaya pakai code baru)
php artisan queue:restart

# 5. (Opsional) Deploy frontend
# Kalau ada perubahan FE:
cd ../sipos-frontend
npm install
npm run build
cp -r dist/* ../sipos/public/
```

**CATATAN PENTING:**
- `config:cache`, `route:cache`, `view:cache` **WAJIB** setiap deploy. Tanpa ini app lambat.
- `storage:link` sekali saja di first deploy. Cek dengan `ls -la public/storage`.
- `queue:restart` **WAJIB** kalau ada perubahan code di Jobs atau Service yang dipakai Job.

---

## Post-Deploy Checklist

Setiap selesai deploy, verify:

- [ ] `php artisan config:cache` sukses
- [ ] `php artisan route:cache` sukses
- [ ] `php artisan view:cache` sukses
- [ ] `public/storage` symlink sudah ada
- [ ] `.env` → `APP_DEBUG=false`, `LOG_LEVEL=warning`
- [ ] Queue worker jalan (`supervisorctl status sipos-worker:*`)
- [ ] Folder `storage/` writable oleh web server
- [ ] HTTPS aktif + SSL cert valid
- [ ] Frontend bundle ter-deploy ke `public/`
- [ ] Smoke test: login + buka dashboard + checkout 1 transaksi
- [ ] `curl /api/v1/health` return `200 {"status":"ok"}`
- [ ] `php artisan data:verify` return exit 0 (no mismatch)
- [ ] `php artisan schedule:list` tidak kosong (kalau pakai cron scheduler)

---

## Rollback / Troubleshooting

```bash
# Clear semua cache (kalau ada issue setelah deploy)
php artisan optimize:clear

# Cek log terakhir
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log

# Restore DB dari backup
mysql -u $DB_USER -p$DB_PASS $DB_NAME < /backup/sipos/db_YYYYMMDD.sql

# Rollback migration terakhir (CAREFUL)
php artisan migrate:rollback --force --step=1
```

---

## Queue Worker Setup (WAJIB Production)

Queue driver harus `database` atau `redis` di `.env` — **JANGAN `sync` untuk production** (proses berat block request).

### Supervisor Config (Linux)

File: `/etc/supervisor/conf.d/sipos-worker.conf`

```ini
[program:sipos-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/ke/sipos/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/ke/sipos/storage/logs/worker.log
stopwaitsecs=3600
```

Reload supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start sipos-worker:*
```

### Cron Scheduler

Tambah ke crontab `www-data` atau user app:
```
* * * * * cd /path/ke/sipos && php artisan schedule:run >> /dev/null 2>&1
```

---

## Backup Strategy

### Daily Automated Backup

File: `/etc/cron.d/sipos-backup`

```cron
# Backup DB tiap hari jam 2 pagi, retensi 30 hari
0 2 * * * www-data mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > /backup/sipos/db_$(date +\%Y\%m\%d).sql.gz
0 3 * * * www-data find /backup/sipos -name "db_*.sql.gz" -mtime +30 -delete
```

### Manual Backup via UI
Menu **Admin → Backup & Restore** di aplikasi, atau via API `POST /api/v1/backup/download`.

---

## Environment Variables Penting

| Variable | Contoh | Keterangan |
|----------|--------|------------|
| `APP_URL` | `https://domain.com` | URL aplikasi |
| `APP_TIMEZONE` | `Asia/Jakarta` | Timezone PHP (WIB/WITA/WIT) |
| `APP_ENV` | `production` | Harus `production` di server |
| `APP_DEBUG` | `false` | **WAJIB `false`** di production |
| `LOG_LEVEL` | `warning` | Gunakan `warning` atau `error` di prod |
| `DB_DATABASE` | `sipos` | Nama database MySQL |
| `DB_USERNAME` | `root` | User MySQL |
| `DB_PASSWORD` | `secret` | Password MySQL |
| `DB_TIMEZONE` | `+07:00` | Timezone MySQL session (default +07:00 WIB) |
| `CACHE_STORE` | `file` atau `redis` | `redis` untuk multi-server |
| `SESSION_DRIVER` | `file` atau `redis` | `redis` untuk multi-server |
| `SESSION_ENCRYPT` | `true` | **WAJIB `true`** di production |
| `QUEUE_CONNECTION` | `database` atau `redis` | **JANGAN `sync` di prod** |
| `SANCTUM_STATEFUL_DOMAINS` | `sipos.domain.com` | Domain FE |

---

## Catatan Timezone

Timezone bisa diubah dari **halaman Settings** di aplikasi (Pengaturan → Regional → Timezone). Perubahan langsung berlaku tanpa restart server.

`APP_TIMEZONE` di `.env` hanya dipakai sebagai fallback awal — setelah settings table ter-seed, nilai dari database yang dipakai.

---

## Running Tests

```bash
# Backend tests (PHPUnit, SQLite in-memory)
php artisan test

# Filter spesifik
php artisan test --filter=Promo

# Frontend E2E (Playwright, butuh Chromium)
cd sipos-frontend
npx playwright test e2e/
```

---

## Monitoring

- **Log location:** `storage/logs/laravel-YYYY-MM-DD.log` (daily rotation, 14 hari retention)
- **Queue failures:** `storage/logs/worker.log` + DB table `failed_jobs`
- **Error tracking:** Set `LOG_LEVEL=warning` di prod. Optional: integrate Sentry (tambah `sentry/sentry-laravel` package)
- **Frontend errors:** Dikirim ke `POST /api/v1/client-errors` → log ke `laravel.log` (tag `Frontend error`). Monitor: `tail -f storage/logs/laravel-*.log | grep "Frontend error"`

### Depth Health Check (JSON)

Endpoint untuk UptimeRobot / Grafana / load balancer:

```bash
curl https://yourdomain.com/api/v1/health
# 200 OK: { "status": "ok", "checks": { "db": {...}, "storage": {...}, "cache": {...} } }
# 503 Service Unavailable: { "status": "degraded", ... }
```

Cek DB ping + latency, storage write test, cache read/write. Lebih informatif dari `/up` (yang cuma render HTML basic).

### Data Invariant Verification

Jalankan berkala untuk catch drift antara derived aggregates vs stored snapshots:

```bash
# Manual check
php artisan data:verify

# Exit 1 kalau ada mismatch (pakai di CI/monitoring)
php artisan data:verify --fail-on-mismatch

# JSON output untuk pipe ke monitoring
php artisan data:verify --json
```

Check yang dilakukan:
- **Stock Consistency:** `SUM(stock_card.qty_in - qty_out)` per (product, warehouse) === `inventory_stock.qty`
- **Sales Payment Totals:** `SUM(doc_sales_payments.nominal) >= doc_sales.grand_total + total_biaya_pembayaran` untuk completed sales
- **Hutang Ledger:** `supplier_hutang.sisa_hutang === nominal_awal - SUM(pembayaran.nominal_dibayar)`

**Rekomendasi cron harian:**
```cron
0 4 * * * www-data cd /path/ke/sipos && php artisan data:verify --fail-on-mismatch || echo "Data invariant mismatch" | mail -s "SIPOS Alert" ops@yourcompany.com
```

### Scheduled Tasks (via Middleware, Bukan Cron)

Scheduler POSIP jalan via middleware saat ada request API — **tidak butuh cron** (ramah shared hosting):

- **Price Change auto-apply:** `ApplyScheduledPriceChanges` middleware (cooldown 5 min, atomic lock)
- **Activity log cleanup:** `CleanupActivityLog` middleware (cooldown 7 hari, hapus record >365 hari)

Config via database setting (`scheduler.*_enabled`, `scheduler.*_cooldown`).

---

## Backup Strategy (Updated)

### Manual Backup via UI

Menu **Admin → Backup & Restore**. Download sebagai **`.zip`** berisi:
- `database.sql` — mysqldump
- `uploads/` — copy dari `storage/app/public/` (logo, gambar produk, dll)

Restore: upload `.zip` (full) atau `.sql` (legacy, DB saja).

### Daily Automated Backup (Linux)

```cron
# DB backup (gzip) — tiap hari jam 2 pagi, retensi 30 hari
0 2 * * * www-data mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > /backup/sipos/db_$(date +\%Y\%m\%d).sql.gz

# Files backup (uploads) — tiap hari jam 2:30 pagi
30 2 * * * www-data tar -czf /backup/sipos/files_$(date +\%Y\%m\%d).tar.gz -C /path/ke/sipos storage/app/public/

# Cleanup retention 30 hari
0 3 * * * www-data find /backup/sipos -name "db_*.sql.gz" -o -name "files_*.tar.gz" -mtime +30 -delete
```

**Test restore bulanan** (drill): lihat [RESTORE_DRILL.md](RESTORE_DRILL.md).
