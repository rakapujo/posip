# Audit menu — 18 Inventory → Kartu Stok

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/StockCardPage.vue` · `api/modules/stockCards.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/StockCardController.php` · `Models/StockCard.php` · `Exports/StockCardExport.php`  
> - Twin UI: `HppMovementPage.vue` (Pergerakan HPP — hampir clone)  
> - Routes: `GET /api/v1/inventory/stock-cards` (+ summary, hpp-summary, export) · FE `inventory-kartu-stok` (`stok.view`)  
> - Tes: `StockCardTest` · `StockCardApiHppResetTest` · `InventoryAccessCoverageTest` (stock-cards)  
> **Jika konflik:** ikuti kode.  
> **Urutan:** Inventory → Kartu Stok (`/app/inventory/kartu-stok`) — setelah Stok di `AppMenu.vue`.

## Scope

View-only ledger per produk (wajib pilih produk). Filter: gudang, rentang tanggal, tipe transaksi, (BE) search `transaction_no`, `hpp_changed_only`. Tidak ada CRUD baris; tulis lewat modul transaksi + `StockCard::record` / observer.

| Endpoint | Permission |
|----------|------------|
| index / summary / export | `stok.view` (+ strip HPP tanpa `stok.view_hpp`) |
| hpp-summary | **`stok.view_hpp` saja** (asimetris) |

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| KS-B1 | P0 | **Saldo buka/akhir summary salah setelah `HPP_CORRECTION`/`HPP_RESET`:** baris HPP-only simpan `qty_balance=0` + `warehouse_id=null`. Tanpa filter gudang, `orderBy tanggal,id` bisa ambil baris itu → saldo “0” padahal stok ada. | `StockCard::record` 249–254; `summary` 252–294; HPP correction writers | Saat hitung opening/ending qty: **abaikan** `TYPES_NO_QTY` / `warehouse_id IS NULL`; fallback `inventory_stock` sum. |
| KS-B2 | P1 | Multi-gudang tanpa filter: kolom `qty_balance` **per-WH** digabung kronologis → “Saldo” meloncat antar gudang (bukan running global). | index query + FE kolom Saldo | Wajibkan filter gudang untuk kolom saldo bermakna, **atau** label “Saldo gudang baris”; default FE: jika multi-WH tampilkan per-baris saja + summary minta pilih gudang. |
| KS-B3 | P1 | Filter `transaction_type`: in/out terfilter, opening/ending **tidak** → `opening+in−out ≠ ending`. | summary 269–275 vs 280–294 | Label FE “Masuk/Keluar (filter tipe)” vs “Saldo (semua tipe)”; atau hitung ending dari opening+filtered (ubah semantik — default: **label saja**). |
| KS-B4 | P1 | Void sales menulis tipe **`SALES_RETURN`** (sama retur nyata). Filter “Retur” mencampur void; beda hanya di `notes`. | `VoidSalesAction` | BY DESIGN docs + FE tooltip; tipe `VOID` = YAGNI sampai laporan minta. |
| KS-B5 | P2 | `STOCK_OPNAME` qty 0/0 audit line — mudah disalahbaca. | ApproveStockOpname | Copy / Tag “audit”. |
| KS-B6 | P2 | `source_doc` link hanya PURCHASE serial → PBS. PO/sales/adj/transfer tidak. | Controller 116–168 | Perluas map tipe→route (bertahap). |
| KS-B7 | P2 | Cascade delete produk hapus history kartu. | migration FK | Dokumentasikan; soft-delete produk sudah ada di master. |

### Keamanan

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| KS-S1 | P1 | `hpp-summary` cukup `stok.view_hpp` tanpa `stok.view` | Require **keduanya** |
| KS-S2 | P2 | Expose numeric `warehouse_id` / terima product id numerik | Selaras Stok: ULID-first bertahap |
| KS-S3 | P2 | Export sync tanpa throttle | Throttle route (shared pattern) |
| KS-S4 | OK | Index/export strip HPP tanpa `view_hpp` — solid | — |

### Kode

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| KS-C1 | P1 | Sort whitelist **`tipe_transaksi`/`qty_masuk`/`qty_keluar`/`saldo`** ≠ kolom DB → **500** jika FE sort | Map ke `transaction_type`,`qty_in`,`qty_out`,`qty_balance` |
| KS-C2 | P1 | `adjustStock` skipObserver tanpa `finally` (sama ST-C2) | Fix sekali di model (shared #17) |
| KS-C3 | P2 | Observer `isDirty` vs `wasChanged` | P2 |
| KS-C4 | P2 | Export `warehouseId` int — ULID string → TypeError | Cast/resolve ULID→id |
| KS-C5 | P2 | `getLastBalance` tanpa lock — race concurrent record | lockForUpdate di path tulis |
| KS-C6 | P3 | Export tanpa `search` (index punya) | Parity param |
| KS-C7 | P3 | Import `SettingService` unused | Hapus |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| KS-X1 | P0 | = KS-B1 (modul HPP correction meracuni summary qty) |
| KS-X2 | P1 | Void vs retur tipe sama |
| KS-X3 | P2 | `data:verify` tidak validasi rantai `qty_balance` |
| KS-X4 | P2 | Twin FE `HppMovementPage` — bug FE akan dobel |

### Tampilan / DRY / FE

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| SC-01 | P1 | AutoComplete tanpa `forceSelection` — string truthy buka UI rusak | `forceSelection` + gate `?.ulid` |
| SC-02 | P1 | Clone `HppMovementPage` | Composable shell bersama (ponytail: extract **saat** fix FE, bukan abstraksi spekulatif penuh) |
| SC-03 | P1 | Deep-link mount double/triple fetch | Satu `Promise.all` setelah hydrate |
| SC-04 | P2 | Tidak `watch` `route.query` | Watch / `onBeforeRouteUpdate` |
| SC-05 | P2 | BE `search` tidak dipakai FE | `DataTableHeader` + param |
| SC-06 | P2 | Badge filter selalu ≥2 (tanggal default) | Hitung non-default saja |
| SC-07 | P2 | DatePicker Clear tidak reload | `@update:model-value` |
| SC-08 | P2 | Params filter di-build 3× | `buildFilterParams()` |
| SC-09 | P2 | Error tidak clear table stale | Reset di catch |
| SC-10–21 | P2–P3 | Query sync, source_doc, HPP_CORRECTION tag, goBack, dll. | Lihat audit FE agent |

**OK:** `ListFiltersSheet`, `MoneySummaryPanel`; HPP kolom `v-if canViewHpp`; tidak perlu komponen baru untuk ledger.

### DB

| ID | Sev | Temuan |
|----|-----|--------|
| KS-D1 | P2 | Index `(product, warehouse, tanggal)` OK; query global HPP kurang ideal `(product, tanggal)` |
| KS-D2 | P3 | Search `LIKE %x%` tidak pakai index `transaction_no` |

---

## Keputusan produk (default plan)

1. **KS-B1:** opening/ending qty skip baris `TYPES_NO_QTY` / null warehouse.  
2. **KS-B2:** FE: jika tidak pilih gudang, sembunyikan/relabel kolom Saldo aggregat; summary saldo minta pilih gudang atau tampilkan sum inventory.  
3. **KS-B3:** tetap opening/ending all-types; label FE klarifikasi.  
4. **KS-B4:** docs BY DESIGN (bukan tipe VOID baru).  
5. **KS-C1 + SC-01/03:** wajib di P1 execute.  
6. Extract twin page: **minimal** shared `buildFilterParams` / hydrate product — full shell extract hanya jika diff kecil.

## Antrian fix (gabung plan #17+#18)

Lihat plan hidup: `fix_inventory_stok_01972f4e.plan.md`.
