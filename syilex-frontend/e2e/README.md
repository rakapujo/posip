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

Default base URL: `http://127.0.0.1:8000` atau Laragon virtual host. Override:

```bash
E2E_BASE_URL=http://127.0.0.1:8000 npx playwright test
```

Test credentials default (fixtures/auth.js):
- Email: `admin@posip.com`
- Password: `password`

**Kalau password admin berbeda**, edit `fixtures/auth.js` atau set env `E2E_ADMIN_EMAIL` dan `E2E_ADMIN_PASSWORD`.

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

### install-wizard.spec.js / docs-*.spec.js
- Install wizard + dokumentasi screenshot helpers (opsional CI)

**"Login #email not found / stuck on preloader"**
- SPA shell load tapi JS Vite tidak resolve (path `/assets/...` vs `/syilex/public/assets/...`).
- Pastikan frontend sudah di-build & di-deploy ke `syilex/public` (`npm run build` + copy), ATAU jalankan Vite dev + override `E2E_BASE_URL` ke origin yang benar.
- Cek DevTools Network: `index-*.js` harus 200, bukan 404.

**"Cannot connect to http://sipos.test"**
- Pastikan Laravel running: `php artisan serve` atau Laragon
- Pastikan hosts entry: `127.0.0.1 sipos.test` / `POSIP.test`

**"Authentication failed"**
- Run seeder: `php artisan db:seed --class=UserSeeder`
- Reset password admin kalau lupa

**"No products in POS"**
- Run: `php artisan db:seed --class=MasterSeeder`
- Pastikan terminal aktif (test otomatis create terminal E2E_001)

**Test lambat / timeout**
- Backend mungkin slow — cek log Laravel
- Increase timeout di `playwright.config.js`

## CI/CD Integration (Future)

Tests bisa di-integrate ke GitHub Actions:
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
      - run: npx playwright install --with-deps chromium
      - run: npx playwright test
        env:
          E2E_BASE_URL: http://localhost:8000
```

## Notes

- `fullyParallel: false` di config — tests share terminal/shift state, sequential lebih aman
- `workers: 1` — same reason
- Tests pakai `request` API client untuk setup (bypass UI bisa lebih cepat)
- Post-test cleanup tidak agresif — DB test isolated via RefreshDatabase kalau pakai Laravel test env
