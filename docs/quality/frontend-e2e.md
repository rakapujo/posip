> **Status:** canonical
> **SSoT kode:** syilex-frontend/e2e/
> **Jika konflik:** ikuti kode, lalu update dokumen ini.
# End-to-End Testing — POSIP Frontend

E2E tests menggunakan [Playwright](https://playwright.dev). Test cover flow critical: login, POS checkout, role-based access.

## Prerequisites

- Backend Laravel running (mis. `php artisan serve` atau Laragon → `http://127.0.0.1:8000`)
- Database sudah di-seed (minimal 1 admin user + produk aktif)
- Node.js 18+

## Setup

```bash
# Install dependencies (dari folder sipos-frontend)
npm install

# Install Playwright browsers (one-time)
npx playwright install chromium
# Atau semua browsers:
# npx playwright install
```

## Konfigurasi

Default base URL: Vite dev `http://127.0.0.1:5173` (Playwright otomatis `npm run dev` + proxy API ke Laragon).

```bash
# Override ke SPA ter-build (butuh asset path benar)
E2E_BASE_URL=http://POSIP.test/syilex/public npx playwright test
```

Vite proxy API default: `http://POSIP.test/syilex/public` (override `VITE_API_PROXY`).

Test credentials default (`e2e/helpers/auth.js`):
- Email: `admin@posip.com`
- Password: `password`

**Kalau password admin berbeda**, set env `E2E_ADMIN_EMAIL` / `E2E_ADMIN_PASSWORD`.

### Shared auth (login sekali)

Semua suite authenticated memakai `e2e/helpers/auth.js`:
- `getAuthData(request)` — login API sekali, cache memory + file `.auth/e2e-admin.json`
- `injectAuth(page)` — set localStorage sebelum SPA boot
- Token di-validasi via `/auth/me`; expired → login ulang otomatis

Ini **tidak** menonaktifkan throttle login di production. Middleware `throttle:login` (5/15 menit) tetap aktif. Cache hanya mengurangi hit `/auth/login` saat E2E lokal.

Kalau tetap kena 429 saat develop (mis. `auth.spec.js` masih login UI berkali-kali):

```bash
cd syilex
php artisan auth:clear-login-throttle --all
```

Command itu hanya clear RateLimiter cache lokal — **bukan** hapus middleware.

## Menjalankan Tests

```bash
# Semua test
npx playwright test

# Satu file
npx playwright test auth.spec.js

# Dengan UI mode (interactive)
npx playwright test --ui

# Debug single test
npx playwright test --debug

# Lihat HTML report
npx playwright show-report
```

## Test Yang Ada

### auth.spec.js
- Login page renders
- Valid credentials → redirect ke dashboard
- Wrong password → tetap di login
- Protected route tanpa auth → redirect login

### pos-checkout.spec.js
- POS page load + product search visible
- F1 focus product search
- Alt+1/2/3/4 tab switching
- Add product to cart + BAYAR enable
- F12 open payment dialog
- **Complete checkout flow** (cash payment → sales tercipta di DB)
- **Post-checkout success indicator**
- **Role-based access** (user tanpa permission POS → denied)

### reports.spec.js
- Smoke akses halaman laporan (permission-gated)

### penjualan-backoffice.spec.js
- Smoke list: sales / retur / piutang / pembayaran / deposit
- Create form shell
- Edit draft remaps `unit`/`qty`/`harga_satuan` ke field form
- Journey: draft → approve → muncul completed di list
  (skip jika permission `sales.create` / `sales.approve` belum di-seed — jalankan `php artisan db:seed --class=RolePermissionSeeder`)

### menu-shell.spec.js (@smoke)
- Parametrize semua `ALL_MENU_ROUTES` — route load + table/empty/shell
- Print barcode shell

### po-approve.spec.js
- Journey: buat PO draft via API → approve → terlihat approved di list UI

### serial-intake-pos.spec.js
- Journey: serial intake approve → scan SN di POS → checkout

### install-wizard.spec.js / docs-*.spec.js
- Install wizard + dokumentasi screenshot helpers (**bukan** gate CI)

**"Login #email not found / stuck on preloader"**
- SPA shell load tapi JS Vite tidak resolve (path `/assets/...` vs `/syilex/public/assets/...`).
- Pastikan frontend sudah di-build & di-deploy ke `syilex/public` (`npm run build` + copy), ATAU jalankan Vite dev + override `E2E_BASE_URL` ke origin yang benar.
- Cek DevTools Network: `index-*.js` harus 200, bukan 404.

**"Cannot connect to http://sipos.test"**
- Pastikan Laravel running: `php artisan serve` atau Laragon
- Pastikan hosts entry: `127.0.0.1 sipos.test` / `POSIP.test`

**"Authentication failed" / login 429**
- Run seeder: `php artisan db:seed --class=UserSeeder`
- Reset password admin kalau lupa
- Clear throttle lokal (bukan production config): `php artisan auth:clear-login-throttle --all`
- Pastikan suite memakai shared helper (`getAuthData`) — jangan login ulang per test

**"No products in POS"**
- Run: `php artisan db:seed --class=MasterSeeder`
- Pastikan terminal aktif (test otomatis create terminal E2E_001)

**Test lambat / timeout**
- Backend mungkin slow — cek log Laravel
- Increase timeout di `playwright.config.js`

## Gate CI (recommended)

**Gate** (regresi operasional) — jalankan ini, bukan `docs-screenshots*`:

```bash
# Backend
cd syilex && php artisan test

# Frontend E2E smoke + journeys
cd syilex-frontend
npx playwright test menu-shell.spec.js po-approve.spec.js penjualan-backoffice.spec.js serial-intake-pos.spec.js pos-checkout.spec.js auth.spec.js
```

`docs-screenshots.spec.js` / `docs-crud-screenshots.spec.js` tetap **manual/optional** (capture dokumentasi).

Contoh GitHub Actions (belum ada di repo — pakai pola ini bila ditambahkan):
```yaml
# .github/workflows/e2e.yml (example)
name: E2E Tests
on: [pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: npm ci
        working-directory: syilex-frontend
      - run: npx playwright install --with-deps chromium
        working-directory: syilex-frontend
      - run: npx playwright test menu-shell.spec.js po-approve.spec.js penjualan-backoffice.spec.js serial-intake-pos.spec.js pos-checkout.spec.js auth.spec.js
        working-directory: syilex-frontend
        env:
          VITE_API_PROXY: http://localhost:8000
```

## Notes

- `fullyParallel: false` di config — tests share terminal/shift state, sequential lebih aman
- `workers: 1` — same reason
- Tests pakai `request` API client untuk setup (bypass UI bisa lebih cepat)
- Post-test cleanup tidak agresif — DB test isolated via RefreshDatabase kalau pakai Laravel test env
