# Testing (AI)

> **Status:** canonical  
> **SSoT kode:** `syilex/tests/`, `syilex-frontend/e2e/`, `syilex-frontend/tests/`  
> **Ledger:** [`../quality/menu-coverage.md`](../quality/menu-coverage.md)

## Backend (PHPUnit)

```bash
cd syilex
php artisan test
php artisan test --filter=Promo
```

- DB: MySQL `posip_db_test` (`phpunit.xml`)
- `RefreshDatabase` di feature tests
- Factory terbatas — sering `Model::create([...])`
- Skip MySQL-only bila driver bukan mysql

## Frontend unit

```bash
cd syilex-frontend
npm run test:unit
```

Detail: [`../quality/frontend-unit.md`](../quality/frontend-unit.md)

## Frontend E2E (Playwright)

Butuh backend + seed. Gate: lihat [`../quality/menu-coverage.md`](../quality/menu-coverage.md)  
Detail: [`../quality/frontend-e2e.md`](../quality/frontend-e2e.md)

## Sebelum klaim selesai

- Run suite relevan (filter Action/domain yang disentuh)
- Jangan claim “semua hijau” tanpa menjalankan perintah
