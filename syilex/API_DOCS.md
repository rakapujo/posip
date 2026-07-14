# SIPOS API Documentation

**Version:** v1
**Base URL:** `https://yourdomain.com/api/v1`
**Authentication:** Sanctum Bearer Token

---

## Table of Contents
- [Authentication](#authentication)
- [Response Format](#response-format)
- [Error Handling](#error-handling)
- [Rate Limits](#rate-limits)
- [Endpoint Index](#endpoint-index)

---

## Public Endpoints (No Auth)

### Health Check
```
GET /api/v1/health
```

Depth check untuk monitoring tools (UptimeRobot, Grafana, load balancer). Rate limit 60 req/menit.

**Response 200 OK** (semua sehat):
```json
{
    "status": "ok",
    "app": "SIPOS",
    "env": "production",
    "timestamp": "2026-04-16T14:30:00+07:00",
    "checks": {
        "db": { "ok": true, "latency_ms": 2.45 },
        "storage": { "ok": true, "writable": true },
        "cache": { "ok": true, "driver": "redis" }
    }
}
```

**Response 503 Service Unavailable** (ada yang degraded):
```json
{
    "status": "degraded",
    "checks": {
        "db": { "ok": false, "error": "Connection refused" },
        ...
    }
}
```

### Settings (Public)
```
GET /api/v1/settings/public
```
Return setting yang dipakai frontend sebelum login (app name, logo, timezone).

### Public Receipt
```
GET /api/v1/public/receipt/{ulid}
```
Struk HTML untuk dibagikan via link. Rate limit 30 req/menit.

---

## Authentication

### Login
```
POST /api/v1/auth/login
Content-Type: application/json

{
    "email": "admin@sipos.com",
    "password": "secret"
}
```

**Response 200:**
```json
{
    "success": true,
    "data": {
        "token": "1|abc...",
        "user": {
            "ulid": "01HX...",
            "name": "Admin",
            "email": "admin@sipos.com",
            "roles": ["super-admin"],
            "permissions": ["produk.view", "promo.create", ...]
        }
    }
}
```

**Response 422** (validation) / **429** (rate limit) / **401** (wrong credentials)

### Authenticated Requests
Sertakan header:
```
Authorization: Bearer {token}
Accept: application/json
```

### Current User
```
GET /api/v1/auth/me
```

### Logout
```
POST /api/v1/auth/logout
```

---

## Response Format

Semua response API konsisten lewat `BaseApiController`:

### Success
```json
{
    "success": true,
    "message": "Optional message",
    "data": { ... }
}
```

### Created (201)
```json
{
    "success": true,
    "message": "Resource berhasil dibuat",
    "data": { ... }
}
```

### Paginated List
```json
{
    "success": true,
    "data": {
        "items": [ ... ],
        "pagination": {
            "current_page": 1,
            "last_page": 10,
            "per_page": 15,
            "total": 150
        }
    }
}
```

---

## Error Handling

### Validation Error (422)
```json
{
    "success": false,
    "message": "Validasi gagal",
    "errors": {
        "field_name": ["Error message"]
    }
}
```

### Unauthorized (401)
```json
{
    "success": false,
    "message": "Unauthenticated"
}
```

### Forbidden (403)
```json
{
    "success": false,
    "message": "Unauthorized"
}
```

### Not Found (404)
```json
{
    "success": false,
    "message": "Resource tidak ditemukan"
}
```

### Rate Limit (429)
```json
{
    "message": "Too Many Attempts."
}
```

---

## Rate Limits

| Endpoint | Limit |
|---|---|
| `POST /auth/login` | 5 req / 15 menit |
| `GET /health` | 60 req / menit |
| `GET /public/receipt/{ulid}` | 30 req / menit |
| `PUT /settings/*` | 20 req / menit |
| `POST /import/{entity}` | 10 req / menit |
| `POST /backup/*` | 10 req / menit |
| `POST /pos/checkout` | 60 req / menit |
| `POST /client-errors` | 30 req / menit |
| `/reset/*` | 30 req / menit |

---

## Endpoint Index

### Master Data
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/produk` | `produk.view` |
| POST | `/produk` | `produk.create` |
| GET | `/produk/{ulid}` | `produk.view` |
| PUT | `/produk/{ulid}` | `produk.update` |
| DELETE | `/produk/{ulid}` | `produk.delete` |
| GET | `/brand`, `/tipe`, `/kategori`, `/grup` | `{entity}.view` |
| GET | `/customer`, `/supplier` | `{entity}.view` |
| GET | `/metode-pembayaran` | `metode-pembayaran.view` |
| GET | `/warehouse`, `/pos-terminal` | `{entity}.view` |

Setiap master data punya pola: `index`, `show`, `store`, `update`, `destroy`, `list` (dropdown), `export` (excel).

### Promo
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/promos` | `promo.view` |
| POST | `/promos` | `promo.create` |
| GET | `/promos/{ulid}` | `promo.view` |
| PUT | `/promos/{ulid}` | `promo.update` (draft only) |
| DELETE | `/promos/{ulid}` | `promo.delete` (draft only) |
| POST | `/promos/{ulid}/approve` | `promo.approve` |
| POST | `/promos/{ulid}/cancel` | `promo.approve` (approved → draft) |
| POST | `/promos/{ulid}/deactivate` | `promo.toggle` |
| POST | `/promos/{ulid}/reactivate` | `promo.toggle` |

**Payload create/update:**
```json
{
    "nama_promo": "Diskon Lebaran",
    "deskripsi": "Optional",
    "customer_type_id": null,
    "customer_category_id": null,
    "terminal_id": null,
    "tanggal_mulai": "2026-04-01",
    "tanggal_selesai": "2026-04-30",
    "jam_mulai": "08:00",
    "jam_selesai": "12:00",
    "details": [
        {
            "target_type": "semua",
            "target_id": null,
            "min_qty": 1,
            "diskon_1_tipe": "percent",
            "diskon_1_nilai": 10,
            ...
        }
    ]
}
```

### POS (Point of Sale)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/pos/active-terminal` | `pos.access` |
| GET | `/pos/active-promos` | `pos.access` |
| POST | `/pos/checkout` | `pos.checkout` |
| GET | `/pos/sales` | `pos.sales-history` |
| GET | `/pos/sales/{ulid}` | `pos.sales-history` |
| POST | `/pos/sales/{ulid}/void` | `pos.void` |
| GET | `/public/receipt/{ulid}` | (public) |

### Inventory Operations
| Type | Base Endpoint |
|---|---|
| Adjustment | `/adjustments` |
| Transfer | `/transfers` |
| Repack | `/repacks` |
| Stock Opname | `/opnames` |
| HPP Correction | `/hpp-corrections` |

Pola: CRUD + `approve`. Semua modul memvalidasi **warehouse aktif** + **produk aktif** saat create/update/approve (defense-in-depth via `InventoryMasterRules`).

### Purchase
| Type | Base Endpoint |
|---|---|
| Purchase Order | `/purchase-orders` |
| Purchase Return | `/purchase-returns` |
| Pembayaran Hutang | `/pembayaran-hutang` |
| Supplier Hutang | `/supplier-hutangs` |

### Reports — Penjualan & Pembelian

**Permissions (granular):**
| Permission | Scope |
|---|---|
| `laporan.penjualan` | View laporan penjualan (per nota, per barang, financial) |
| `laporan.pembelian` | View laporan pembelian |
| `laporan.export` | Export Excel semua laporan penjualan/pembelian |
| `stok.view_hpp` | Kolom HPP/margin di laporan penjualan per barang |
| `po.view_harga` | Kolom finansial laporan pembelian; **wajib** untuk laporan Diskon Pembelian |

**Query params umum:** `date_from`, `date_to`, `page`, `per_page`, `search`, `sort_field`, `sort_order`. Laporan pembelian tambahan: `source=all|po|serial`.

#### Penjualan — Per Nota (`/sales-report`)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/sales-report` | `laporan.penjualan` |
| GET | `/sales-report/{ulid}` | `laporan.penjualan` |
| GET | `/sales-report/dropdowns` | `laporan.penjualan` |
| GET | `/sales-report/export` | `laporan.export` |

Filter index: `terminal_id`, `user_id`, `metode_bayar_id`, `status` (`completed`, `voided`, `retur_partial`, `retur_full`).

#### Penjualan — Per Barang (`/sales-product-report`)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/sales-product-report` | `laporan.penjualan` (+ HPP jika `stok.view_hpp`) |
| GET | `/sales-product-report/{productUlid}` | `laporan.penjualan` |
| GET | `/sales-product-report/dropdowns` | `laporan.penjualan` |
| GET | `/sales-product-report/export` | `laporan.export` |

Filter: `terminal_id`, `brand_id`, `kategori_id`.

#### Penjualan — Financial (`/sales-financial-report`)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/sales-financial-report/pembulatan` | `laporan.penjualan` |
| GET | `/sales-financial-report/disc-line` | `laporan.penjualan` |
| GET | `/sales-financial-report/disc-line/{salesUlid}` | `laporan.penjualan` |
| GET | `/sales-financial-report/disc-nota` | `laporan.penjualan` |
| GET | `/sales-financial-report/biaya` | `laporan.penjualan` |
| GET | `/sales-financial-report/dropdowns` | `laporan.penjualan` |
| GET | `/sales-financial-report/{type}/export` | `laporan.export` |

`{type}` = `pembulatan`, `disc-line`, `disc-nota`, `biaya`.

#### Pembelian (`/purchase-report`)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/purchase-report/dropdowns` | `laporan.pembelian` |
| GET | `/purchase-report/per-dokumen` | `laporan.pembelian` |
| GET | `/purchase-report/per-dokumen/{ulid}` | `laporan.pembelian` |
| GET | `/purchase-report/per-barang` | `laporan.pembelian` |
| GET | `/purchase-report/per-barang/{productUlid}` | `laporan.pembelian` |
| GET | `/purchase-report/per-supplier` | `laporan.pembelian` |
| GET | `/purchase-report/per-supplier/{supplierId}` | `laporan.pembelian` |
| GET | `/purchase-report/diskon` | `laporan.pembelian` + **`po.view_harga`** |
| GET | `/purchase-report/harga-terakhir` | `laporan.pembelian` |
| GET | `/purchase-report/{type}/export` | `laporan.export` (+ `po.view_harga` untuk diskon) |

`{type}` = `per-dokumen`, `per-barang`, `per-supplier`, `diskon`, `harga-terakhir`. Sumber data: PO approved + Pembelian Serial approved (UNION).

#### Laporan Keuangan / Analitik (Sprint 1–3)

**Permissions (granular — `laporan.view` hanya untuk widget dashboard, BUKAN halaman laporan):**

| Permission | Scope |
|---|---|
| `laporan.keuangan` | Gross Profit, Margin per Barang, Arus Kas |
| `laporan.performa` | Performa Kasir, Metode Pembayaran, Top Customer |
| `laporan.promo` | Promo Usage, Product Promo, Customer Promo |
| `laporan.inventory` | Pola Retur, Dead Stock |
| `stok.view_hpp` | **Wajib** Gross Profit + Margin; **opsional** Dead Stock (masking kolom nilai) |

**Query params umum:** `date_from`, `date_to` (format `Y-m-d`). Filter tambahan per endpoint (terminal_id, kategori_id, limit, dll.).

#### Keuangan — Gross Profit (`/reports/gross-profit`)
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/reports/gross-profit/summary` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/daily` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/by-kategori` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/top-products` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/daily/export` | `laporan.export` + `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/by-kategori/export` | `laporan.export` + `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/gross-profit/top-products/export` | `laporan.export` + `laporan.keuangan` + `stok.view_hpp` |

#### Keuangan — Margin & Arus Kas
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/reports/margin-per-barang/summary` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/margin-per-barang` | `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/margin-per-barang/export` | `laporan.export` + `laporan.keuangan` + `stok.view_hpp` |
| GET | `/reports/cash-flow/summary` | `laporan.keuangan` |
| GET | `/reports/cash-flow/daily` | `laporan.keuangan` |
| GET | `/reports/cash-flow/daily/export` | `laporan.export` + `laporan.keuangan` |

#### Performa
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/reports/kasir-performance` | `laporan.performa` |
| GET | `/reports/kasir-performance/export` | `laporan.export` + `laporan.performa` |
| GET | `/reports/payment-method/breakdown` | `laporan.performa` |
| GET | `/reports/payment-method/breakdown/export` | `laporan.export` + `laporan.performa` |
| GET | `/reports/customer/top` | `laporan.performa` |
| GET | `/reports/customer/top/export` | `laporan.export` + `laporan.performa` |

#### Promo
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/reports/promo-usage/summary` | `laporan.promo` |
| GET | `/reports/promo-usage` | `laporan.promo` |
| GET | `/reports/promo-usage/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/promo-usage/{promoUlid}` | `laporan.promo` |
| GET | `/reports/product-promo/by-product` | `laporan.promo` |
| GET | `/reports/product-promo/by-product/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/product-promo/by-promo` | `laporan.promo` |
| GET | `/reports/product-promo/by-promo/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/customer-promo/summary` | `laporan.promo` |
| GET | `/reports/customer-promo/summary/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/customer-promo/by-tipe` | `laporan.promo` |
| GET | `/reports/customer-promo/by-tipe/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/customer-promo/by-kategori` | `laporan.promo` |
| GET | `/reports/customer-promo/by-kategori/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/customer-promo/by-customer` | `laporan.promo` |
| GET | `/reports/customer-promo/by-customer/export` | `laporan.export` + `laporan.promo` |
| GET | `/reports/customer-promo/customer/{customerUlid}` | `laporan.promo` |

#### Inventory / Operasional
| Method | Endpoint | Permission |
|---|---|---|
| GET | `/reports/retur/pattern` | `laporan.inventory` |
| GET | `/reports/retur/pattern/export` | `laporan.export` + `laporan.inventory` |
| GET | `/reports/inventory/dead-stock` | `laporan.inventory` (+ masking HPP tanpa `stok.view_hpp`) |
| GET | `/reports/inventory/dead-stock/export` | `laporan.export` + `laporan.inventory` (+ kolom HPP tanpa `stok.view_hpp`) |

### Admin / Tools
| Endpoint | Permission |
|---|---|
| `/settings/*` | `setting.*` |
| `/users` | `user.*` |
| `/roles` | `role.*` |
| `/import/{entity}` | `import.*` |
| `/backup/*` | `settings.reset` |
| `/reset/*` | `settings.reset` |
| `/client-errors` | (authenticated, any role) |

### Client Error Logging
Frontend global error handler mengirim ke:
```
POST /api/v1/client-errors
Content-Type: application/json

{
    "message": "Cannot read property 'nama' of null",
    "source": "vue" | "promise" | "window",
    "stack": "Error: ... at ...",
    "component": "PosKasirPage",
    "url": "/pos/kasir"
}
```
Log ke `storage/logs/laravel-*.log` dengan tag `"Frontend error"`. Rate limit 30/menit.

### Backup Download (Updated — ZIP Format)
```
POST /api/v1/backup/download
Content-Type: application/json

{ "password": "user-password-for-confirmation" }
```

**Response:** binary `.zip` (attachment) berisi `database.sql` + `uploads/` (dari `storage/app/public/`).

Filename: `posip_backup_YYYY-MM-DD_HH-ii-ss.zip`.

### Backup Restore
```
POST /api/v1/backup/restore
Content-Type: multipart/form-data

file=backup.zip
password=user-password
```

Menerima `.zip` (full) atau `.sql` (legacy, DB saja). Auto-detect by extension.

### Backup Info
```
GET /api/v1/backup/info
```
Return:
```json
{
    "success": true,
    "data": {
        "database": "sipos",
        "tables": 68,
        "uploads_size_bytes": 15482368
    }
}
```

### POS Terminal Shift

**Start Shift:**
```
POST /api/v1/pos-terminals/{ulid}/start-shift
```

**End Shift** (updated dengan reconciliation fields):
```
POST /api/v1/pos-terminals/{ulid}/end-shift
Content-Type: application/json

{
    "saldo_fisik": 250000,           // opsional: uang tunai fisik yang dihitung kasir
    "closing_notes": "Lebih 2rb"     // opsional: catatan kasir
}
```

**Response:**
```json
{
    "success": true,
    "message": "Shift berhasil diakhiri",
    "data": {
        "terminal": { ... },
        "shift_ulid": "01HX...",
        "saldo_system": 248000,       // dihitung otomatis dari transaksi
        "saldo_fisik": 250000,
        "selisih": 2000               // saldo_fisik - saldo_system
    }
}
```

Hanya user yang sedang aktif di terminal (`active_user_id === auth()->id()`) yang bisa end shift-nya sendiri.

**Force Release** (admin/supervisor, optional reconciliation):
```
POST /api/v1/pos-terminals/{ulid}/force-release
Content-Type: application/json

{
    "saldo_fisik": 480000,                                   // opsional
    "closing_notes": "Kasir lapor via WA, kas fisik 480rb"   // opsional
}
```

Butuh permission `terminal.force-release`. Selalu snapshot `saldo_system` untuk audit trail walau `saldo_fisik` tidak diberi.

---

## Pagination

Standard query params:
```
?page=1&per_page=15&search=keyword&status=active
```

- `per_page`: **default 10-20, max 100** (lihat `BaseApiController::getPerPage`)
- `sort_field`: whitelist per controller (misal `created_at`, `nama_promo`)
- `sort_order`: `asc` | `desc`

---

## Export

Setiap master data punya `GET /{entity}/export` yang return Excel file (MIME `.xlsx`).

Support query param filter (mirror `index`).

---

## Promo Auto-Apply di POS Checkout

**Anti-fraud pattern:**

Frontend HANYA mengirim `items[]` dengan harga & qty. **JANGAN kirim diskon_1-4 dari FE** (akan di-override server-side).

```
POST /api/v1/pos/checkout
{
    "customer_id": 123,
    "terminal_id": 1,
    "items": [
        {
            "product_id": 5,
            "qty": 3,
            "harga": 10000,
            "diskon_5_tipe": "nominal",  // ← hanya diskon_5 (manual kasir) yang di-trust dari FE
            "diskon_5_nilai": 500
        }
    ],
    "payments": [ ... ]
}
```

Server akan:
1. Fetch active promos (filter by terminal, customer type, category, jam)
2. Pick best promo per item (`PromoService::findBestPromo`)
3. Override `diskon_1..4` dari DB (slot 1-4 promo)
4. Preserve `diskon_5_*` dari FE (manual)
5. Calculate total via `SalesCalculationService`

Detail di: `app/Actions/Sales/CheckoutSalesAction.php`

---

## Development

- **Base URL dev:** `http://sipos.test/api/v1`
- **Test user:** Lihat `database/seeders/UserSeeder.php`
- **Test data:** Jalankan `php artisan db:seed --class=PromoSampleSeeder` untuk sample promo

### Running Tests
```bash
php artisan test
php artisan test --filter=Promo
```

### Database
- Primary: MySQL 8+ (production)
- Testing: SQLite in-memory
