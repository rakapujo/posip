# Audit menu — 01 Dashboard (Home)

> **Status:** patched (scope A)  
> **SSoT kode:** `syilex/app/Http/Controllers/Api/V1/DashboardController.php` · `syilex-frontend/src/views/Dashboard.vue` · `syilex-frontend/src/api/modules/dashboard.js` · `syilex/tests/Feature/Dashboard/DashboardApiTest.php`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan menu:** Home → Dashboard (`/app`) — pertama di `AppMenu.vue`.

## Scope A (applied)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| D-B1 | P0 | Omzet / `grand_total` / chart pembayaran: gate **`laporan.view` saja** (bukan `stok.view_hpp`) | FIXED |
| D-S1 | P0 | Semantik sama D-B1 — HPP tidak dipakai sebagai proxy “lihat uang” | FIXED |
| D-B2 | P1 | Low-stock KPI + list: `qty < minimum` **termasuk qty=0** (selaras halaman Stok) | FIXED |

## Temuan tersisa (open)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| D-B3 | P1 | Retur beli belum di `pending_approval` / `pending_items` | OPEN |
| D-B4 | P1 | Chart metode bayar settle piutang: deposit belum masuk | OPEN |
| D-B5 | P2 | KPI PO Pending overlap Pending Approval PO | OPEN |
| D-B6 | P2 | Tidak ada ringkasan AR / deposit customer (YAGNI) | OPEN |
| D-B7 | P2 | Top/chart sales hanya `completed` (tanpa net retur) | BY DESIGN |
| D-S2 | P2 | Endpoint tanpa permission route-level; widget gated | BY DESIGN |
| D-C1 | P2 | FE `notify.error` vs `notify.apiError` | OPEN |
| D-U1 | P2 | KPI/list belum drill-down (hanya pending) | OPEN |
| D-D1 | P1 | Fan-out COUNT/list pending per modul | OPEN |
| D-D3 | P2 | `whereDate` vs range datetime | OPEN |
| D-X2 | P1 | Low-stock vs Stok | FIXED (via D-B2) |

## Catatan permission (post-fix)

| Widget | Permission | Catatan |
|--------|------------|---------|
| `sales_today.omzet` / chart omzet / payment totals | `laporan.view` | Scope A: tanpa syarat `stok.view_hpp` |
| `stock.low_stock_*` | `stok.view` | Termasuk `qty = 0` di bawah minimum |
