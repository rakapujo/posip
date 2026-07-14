# SIPOS — Point of Sale System

POS + Inventory management system, backend Laravel 12 + frontend Vue 3 + PrimeVue.

## Tech Stack

**Backend:**
- Laravel 12 (PHP 8.2+)
- MySQL 8+ (production & PHPUnit — lihat `phpunit.xml` → `posip_db_test`)
- Sanctum (API auth)
- Spatie Permission (role/permission)

**Frontend:**
- Vue 3 + Composition API
- Vite 5
- Pinia (state)
- PrimeVue 4.5 (UI components)
- Tailwind CSS

## Quick Start (Development)

```bash
# 1. Backend setup
git clone https://github.com/rakapujo/sipos-baru.git sipos
cd sipos

composer install
cp .env.example .env
php artisan key:generate

# Buat database (contoh: MySQL "sipos" lokal)
# Edit .env: DB_DATABASE, DB_USERNAME, DB_PASSWORD

php artisan migrate --seed
php artisan storage:link
php artisan serve

# 2. Frontend setup (terminal terpisah)
cd ../sipos-frontend
npm install
npm run dev
```

Akses: `http://localhost:5173` (FE proxy ke `http://sipos.test` atau `http://localhost:8000`).

## Sample Data

Seed promo contoh untuk testing:
```bash
php artisan db:seed --class=PromoSampleSeeder
```

Menghasilkan 14 promo sample (global, kategori customer, Happy Hour, dll).

## Architecture

```
sipos/
├── app/
│   ├── Actions/              # Business logic per domain (Sales, Adjustment, ...)
│   ├── Constants/            # Business rule constants (PromoConstants, ...)
│   ├── Exceptions/           # Custom business exceptions
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   └── Middleware/       # IdempotencyKey, SecurityHeaders, ...
│   ├── Models/               # Eloquent models
│   └── Services/             # Reusable services (PromoService, SettingService, ...)
├── database/migrations/
├── database/seeders/
├── routes/api.php            # All API routes (prefix /api/v1)
├── tests/                    # 333 tests (PHPUnit + SQLite in-memory)
├── API_DOCS.md               # API documentation
└── DEPLOY.md                 # Production deployment guide

sipos-frontend/
└── src/
    ├── api/modules/          # API client per resource
    ├── components/common/    # Shared components (DetailItem, DetailDialog, DataTableHeader, dll)
    ├── composables/          # useFormatters, useNotification, useTransactionList
    ├── layout/               # AppLayout, AppMenu
    ├── stores/               # Pinia stores
    └── views/                # Pages
```

## Key Patterns

### Anti-fraud POS Checkout
Frontend HANYA kirim `items[]` + `diskon_5_*` (manual kasir). Backend rebuild `diskon_1..4` dari DB promo (tidak trust FE untuk promo diskon). Detail: `app/Actions/Sales/CheckoutSalesAction.php`

### Idempotency
POS checkout endpoint support header `Idempotency-Key: {uuid}` untuk cegah double-submit. Response di-cache 10 menit per key+user+route.

### API Response Format
Konsisten via `BaseApiController::success()` dan `::error()`:
```json
{ "success": true, "data": { ... }, "message": "..." }
```

### Permission Check
Pakai Spatie Permission. Check di Controller level:
```php
if (!auth()->user()->can('promo.create')) return $this->forbidden();
```

### Custom Business Exceptions
Throw dari Action/Service, auto-rendered ke HTTP 422 via `bootstrap/app.php::withExceptions`:
```php
throw StockInsufficientException::forProduct($id, $name, $required, $available);
```

## Testing

**Backend (PHPUnit)** — database `posip_db_test` (`phpunit.xml`):

```bash
# Buat DB kosong dulu, lalu:
php artisan migrate --env=testing
php artisan test
php artisan test --filter=Promo
```

**Frontend** (`../syilex-frontend/`):

```bash
npm run test:unit    # 87 unit tests (print thermal + policy + isolation)
npm run build
npx playwright test  # E2E — butuh backend + seed (lihat syilex-frontend/tests/README.md)
```

**Cetak thermal:** [`../docs/print-support-matrix.md`](../docs/print-support-matrix.md)

> Jika `php artisan test` gagal massal dengan `BadMethodCallException::askQuestion`, periksa migrasi/DB test — bukan regression print frontend.

## Documentation

- **[CLAUDE.md](CLAUDE.md)** — Guide untuk AI coding assistants (konvensi, gotcha, business rules)
- **[ARCHITECTURE.md](ARCHITECTURE.md)** — Flow diagrams + layered architecture
- **[ONBOARDING.md](ONBOARDING.md)** — Step-by-step setup untuk dev baru
- **[API_DOCS.md](API_DOCS.md)** — Endpoint reference
- **[DEPLOY.md](DEPLOY.md)** — Production deployment guide
- **[RESTORE_DRILL.md](RESTORE_DRILL.md)** — Disaster recovery procedure
- **[INSTALL-SHARED-HOSTING.md](INSTALL-SHARED-HOSTING.md)** — Shared hosting install

## Monitoring & Health

```bash
# Health check (untuk UptimeRobot, Grafana)
curl http://sipos.test/api/v1/health

# Data integrity verification
php artisan data:verify
php artisan data:verify --json
php artisan data:verify --fail-on-mismatch  # exit 1 kalau mismatch
```

## License

Proprietary — © POSIP.
