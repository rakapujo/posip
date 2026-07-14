# Onboarding — Dev Baru POSIP

Step-by-step buat dev baru yang mau setup POSIP di mesin lokal (Windows, asumsi Laragon + XAMPP).

**Estimasi waktu: 30-45 menit** (belum termasuk download).

> **Production / shared hosting tanpa SSH?** Gunakan wizard `/install` — lihat [INSTALL-SHARED-HOSTING.md](INSTALL-SHARED-HOSTING.md).

---

## 1. Tools yang Dibutuhkan

### Wajib
| Tool | Versi | Download |
|------|-------|----------|
| Laragon (PHP 8.2+, MySQL 8, Composer) | Full | https://laragon.org/download/ |
| Node.js | 20 LTS+ | https://nodejs.org |
| Git | latest | https://git-scm.com |
| VSCode | latest | https://code.visualstudio.com |

### Rekomendasi
- **VSCode Extensions:** PHP Intelephense, Laravel Snippets, Vue - Official, Tailwind CSS IntelliSense, ESLint
- **MySQL GUI:** HeidiSQL (bundled di Laragon) atau DBeaver
- **API client:** Insomnia / Bruno / Postman

---

## 2. Clone & Setup Backend

```bash
# Pastikan berada di www root Laragon
cd C:/laragon/www

# Clone
git clone <repo-url> POSIP
cd POSIP/syilex

# Install dependencies PHP
composer install

# Setup environment
cp .env.example .env
```

Edit `.env`:
```env
APP_NAME=POSIP
APP_ENV=local
APP_DEBUG=true
APP_URL=http://posip.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=posip_db
DB_USERNAME=root
DB_PASSWORD=

# Database khusus PHPUnit (lihat phpunit.xml)
DB_DATABASE_TEST=posip_db_test
```

Generate key:
```bash
php artisan key:generate
```

Buat database `sipos` di HeidiSQL / phpMyAdmin.

Migrate & seed:
```bash
php artisan migrate --seed
php artisan storage:link
```

**Catatan:** Laragon otomatis bikin virtual host dari nama folder — `C:/laragon/www/sipos` → `http://sipos.test`. Kalau tidak, klik kanan Laragon tray → Menu → Apache/Nginx → Reload.

Verify backend:
```bash
curl http://sipos.test/api/v1/health
# Harus return { "status": "ok", ... }
```

---

## 3. Setup Frontend

```bash
cd C:/xampp/htdocs/POSIP/frontend
# (atau direktori terpisah kalau frontend disimpan di repo lain)

npm install
cp .env.example .env
```

Edit `.env` frontend:
```env
VITE_API_URL=http://sipos.test/api/v1
```

Run dev server:
```bash
npm run dev
```

Akses: http://localhost:5173

---

## 4. Login Default

Setelah seed berhasil, ada 3 akun default:

| Email | Password | Role |
|-------|----------|------|
| super-admin@sipos.com | password | super-admin (all permissions) |
| admin@sipos.com | password | admin |
| kasir@sipos.com | password | kasir |

Super-admin bisa akses semua menu. Kasir hanya POS + shift.

---

## 5. Sanity Check

Setelah login sebagai super-admin:

### Backend
- ✅ Dashboard muncul, chart kosong (belum ada transaksi)
- ✅ Master data → Produk: seeder ada beberapa produk dummy
- ✅ POS → pilih terminal → input setor awal → coba checkout 1 transaksi
- ✅ Laporan → Penjualan: transaksi yang baru dibuat muncul

### Tes otomatis
```bash
cd C:/laragon/www/sipos
php artisan test
# Expected: ~399 passed, 1 skipped, 0 failed, ~20s
```

### Kalau gagal: Troubleshooting Umum
Lihat section 8 di bawah.

---

## 6. Struktur Direktori (Overview)

Lihat detail di [ARCHITECTURE.md](ARCHITECTURE.md). Ringkasan cepat:

```
sipos/                                 # Backend
├── app/
│   ├── Actions/{Domain}/              # Transactional writes (CheckoutSalesAction, ...)
│   ├── Http/Controllers/Api/V1/       # Thin controllers, delegate ke Action
│   ├── Http/Middleware/               # Idempotency, scheduler triggers
│   ├── Models/                        # Eloquent
│   ├── Services/                      # Business services (PromoService, SettingService)
│   ├── Exceptions/                    # Custom business exceptions
│   └── Console/Commands/              # Artisan: data:verify, backfill, dll
├── database/migrations/
├── database/seeders/
├── tests/Feature/{Domain}/            # ~399 test
├── routes/api.php                     # Semua route API (/api/v1)
├── CLAUDE.md                          # Guide untuk AI agents
├── DEPLOY.md                          # Production deploy
├── API_DOCS.md                        # Endpoint reference
└── RESTORE_DRILL.md                   # DR procedure

frontend/                              # Frontend (Vue 3)
└── src/
    ├── api/modules/                   # Axios wrapper per resource
    ├── components/                    # Shared components
    ├── composables/                   # useNotification, usePosCart, dll
    ├── stores/                        # Pinia (auth, preferences, settings)
    ├── router/                        # Vue Router + guards
    └── views/{domain}/                # Pages (master, pos, pembelian, laporan, ...)
```

---

## 7. First Tasks untuk Familiarisasi

Coba ini dulu sebelum tugas production:

### Task 1 (~30 min): Trace Flow Checkout
1. Buka [PosKasirPage.vue](../frontend/src/views/pos/PosKasirPage.vue), cari function `onPayment()` (sekitar baris 500-600)
2. Follow network call ke backend: `posApi.checkout(payload)`
3. Backend masuk ke route `/pos/checkout` → `PosController::checkout` → `CheckoutSalesAction::execute`
4. Baca step-by-step di CheckoutSalesAction:
   - Validate stock
   - Rebuild promo discount (anti-fraud)
   - Calculate totals
   - Create doc_sales + details + payments
   - Decrement inventory_stock + write stock_card
   - Auto-void kalau ada error di tengah

### Task 2 (~30 min): Tambah Field ke Master Produk
1. Buat migration `add_field_to_master_produk.php`
2. Update `MasterProduk::$fillable`
3. Update `ProdukController::store/update` validation
4. Update `ProdukPage.vue` form
5. Test: `php artisan test --filter=Produk`

### Task 3 (~45 min): Baca Test Penting
1. [tests/Feature/Sales/CheckoutSalesActionTest.php](tests/Feature/Sales/CheckoutSalesActionTest.php) — 14 scenario checkout
2. [tests/Feature/Sales/ProcessSalesReturnActionTest.php](tests/Feature/Sales/ProcessSalesReturnActionTest.php) — return flow
3. [tests/Feature/Promo/PromoCheckoutIntegrationTest.php](tests/Feature/Promo/PromoCheckoutIntegrationTest.php) — anti-fraud
4. [tests/Feature/Api/BackupControllerTest.php](tests/Feature/Api/BackupControllerTest.php) — backup (recent)

---

## 8. Troubleshooting

| Gejala | Fix |
|--------|-----|
| `SQLSTATE[HY000] [1045] Access denied` | Cek DB_USERNAME/PASSWORD di `.env`. Default Laragon `root` tanpa password. |
| `Route [login] not defined` saat akses API | Pastikan `/api/v1/` prefix + header `Accept: application/json` |
| Frontend dev "Network Error" | Cek `VITE_API_URL` sesuai backend URL. Cek CORS di backend `config/cors.php` |
| `composer install` stuck | Hapus `vendor/` + `composer.lock`, rerun. Atau `composer clear-cache` |
| `npm install` conflict peer dep (zod) | Pakai `npm install --legacy-peer-deps` |
| `storage:link` error di Windows | Jalankan terminal as Administrator, atau manually buat symlink |
| Test gagal: "SQLSTATE: no such column" | Pastikan migration terbaru sudah run: `php artisan migrate:fresh --seed` di test env |
| Vite build "Illegal '/' in tags" | Ada kesalahan HTML attribute di `.vue`. Baca error line, biasanya duplicate attribute |
| `php artisan test` error di SQLite-specific | Pastikan pakai `DB::connection()->getDriverName()` check untuk MySQL-only SQL |
| Setelah `git pull`, frontend tidak update | `npm install` (dep baru) + restart `npm run dev` |
| Permission denied pas test `can('xxx')` | Update `UserSeeder.php` permission list + re-seed |

---

## 9. Resources Belajar (Level Up)

### Laravel (backend baru ke PHP/Laravel)
- [Laravel Docs](https://laravel.com/docs/12.x) — mulai dari "Routing", "Eloquent", "Sanctum"
- [Laracasts](https://laracasts.com) — series "Laravel From Scratch"

### Vue 3 + PrimeVue
- [Vue.js Guide](https://vuejs.org/guide/) — Composition API section
- [PrimeVue Docs](https://primevue.org) — reference component yang dipakai (DataTable, Dialog, InputNumber, dll)

### Domain POS
- Baca [CLAUDE.md](CLAUDE.md) untuk business rules kritis
- Baca [ARCHITECTURE.md](ARCHITECTURE.md) untuk flow diagrams

---

## 10. Workflow Development

### Saat Mulai Task
```bash
git pull origin main
composer install   # kalau composer.json berubah
npm install        # kalau package.json berubah (frontend)
php artisan migrate # kalau ada migration baru
```

### Selama Development
- Run test before commit: `php artisan test`
- Untuk perubahan frontend: verify build `npm run build`
- Commit kecil, message jelas (conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`)

### Sebelum PR
- [ ] `php artisan test` pass
- [ ] `npm run build` sukses (frontend)
- [ ] `php artisan data:verify` zero mismatch (kalau touch data model)
- [ ] Tambah/update test untuk logic baru
- [ ] Update API_DOCS.md kalau ada endpoint baru
- [ ] Self-review diff di editor sebelum push

### Code Review Checklist
- [ ] Actions wrapped in `DB::transaction`?
- [ ] Stock mutation ada matching `stock_card` entry?
- [ ] Permission check di controller?
- [ ] Response pakai `BaseApiController` method?
- [ ] Field sensitive di `$hidden`?
- [ ] Test coverage minimal happy path + 1 edge case?

---

## 11. Kontak & Dukungan

- Pertanyaan kode: cek [CLAUDE.md](CLAUDE.md) dulu, lalu tanya tim via Slack/WA
- Bug di production: ikuti [RESTORE_DRILL.md](RESTORE_DRILL.md) incident runbook
- Pertanyaan arsitektur: baca [ARCHITECTURE.md](ARCHITECTURE.md)

Welcome aboard! 🚀
