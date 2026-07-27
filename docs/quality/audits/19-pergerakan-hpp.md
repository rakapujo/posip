# Audit menu — 19 Inventory → Pergerakan HPP

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/HppMovementPage.vue` · `api/modules/stockCards.js`  
> - Twin: `StockCardPage.vue` (Kartu Stok — ~clone; audit [18-kartu-stok.md](18-kartu-stok.md) ID `KS-*` / `SC-*`)  
> - BE: `syilex/app/Http/Controllers/Api/V1/StockCardController.php` (`index` + `hppSummary` + `export`) · `Exports/StockCardExport.php` · `Models/StockCard.php`  
> - Routes FE: `inventory-pergerakan-hpp` (`stok.view_hpp`) · API: `GET /inventory/stock-cards` (+ `hpp-summary`, `export`)  
> - Menu: `AppMenu.vue` Inventory → Pergerakan HPP  
> - Tes: **tidak ada** dedicated `hpp_changed_only`; partial `hpp-summary` di `StockCardApiHppResetTest` + `InventoryAccessCoverageTest`  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Kartu Stok di `AppMenu.vue`.

## Scope

View-only ledger **hanya baris yang mengubah HPP** (`hpp_changed_only=true` → `whereColumn avg_cost_before != avg_cost_after`). Ringkasan via `hpp-summary` (HPP awal/akhir global + total nilai masuk/keluar). Tidak CRUD; tulis lewat transaksi + `StockCard::record` / koreksi HPP / serial / reset.

| Endpoint | Permission (kode) | Dipakai halaman |
|----------|-------------------|-----------------|
| `index` (+ `hpp_changed_only`) | **`stok.view`** (+ strip HPP tanpa `view_hpp`) | List |
| `export` (+ `hpp_changed_only`) | **`stok.view`** | Excel |
| `hpp-summary` | **`stok.view_hpp` saja** | Summary |
| FE route / AppMenu | **`stok.view_hpp` saja** | Gate UI |

---

## Shared vs Kartu Stok (DRY plan)

| Twin ID (KS/SC) | HM | Catatan |
|-----------------|-----|---------|
| KS-S1 | HM-S1 | `hpp-summary` tanpa wajib `stok.view` |
| KS-S2 / KS-C4 | HM-S2 / HM-C4 | product/warehouse numeric id; export `?int` |
| KS-S3 | HM-S3 | export sync tanpa throttle |
| KS-C1 | HM-C1 | sort whitelist ≠ kolom DB (FE HPP saat ini hanya sort `tanggal`) |
| KS-C7 | HM-C5 | `SettingService` unused di controller |
| KS-X4 / SC-02 | HM-U1 | clone page — fix FE harus sekali (composable) |
| SC-01 | HM-U2 | AutoComplete tanpa `forceSelection` |
| SC-03 | HM-U3 | deep-link mount double/triple fetch |
| SC-04 | HM-U4 | tidak watch `route.query` |
| SC-06 | HM-U5 | badge filter selalu ≥2 (tanggal default) |
| SC-07 | HM-U6 | DatePicker Clear tidak reload |
| SC-08 | HM-U7 | params filter di-build 3× |
| SC-09 | HM-U8 | error tidak clear table stale |
| KS-D1 | HM-D1 | index global HPP kurang ideal `(product, tanggal)` |

**HM-only** (tidak di Kartu Stok): HM-B1…B5, HM-S0, HM-X*, HM-U9…, HM-T1.

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HM-B1 | P0 | **`hpp_changed_only` pada `hpp-summary` membunuh Total Nilai Keluar (retail):** FE selalu kirim flag (`HppMovementPage` 242). SALES/TRANSFER/ADJUSTMENT_OUT/PURCHASE_RETURN **tidak** ubah HPP (rules) → terfilter keluar sum. Retail: `total_nilai_keluar` ≈ **0** selamanya meski ada penjualan. Tes BE tanpa flag masih expect nilai SALES (`StockCardApiHppResetTest` 432–440) — **bertentangan dengan perilaku FE**. | Controller 467–480; FE 239–256; business-rules B | **Jangan** apply `hpp_changed_only` ke sum nilai; flag hanya untuk list/export baris. Atau relabel + hitung nilai dari semua tipe dalam periode. |
| HM-B2 | P0 | **Filter gudang menyembunyikan `HPP_CORRECTION`:** baris koreksi (retail + serial + biaya transfer) `warehouse_id=null`. `byWarehouse` = exact match → hilang dari list/export/nilai saat gudang dipilih. Banner bilang filter gudang “hanya nilai” — **salah**: list juga terfilter (`HppMovementPage` 194–197, 245–247). | `ApproveHppCorrectionAction` 60–62; `ApproveSerialHppCorrectionAction` 92–94; `StockCard::scopeByWarehouse` 166–168; banner FE 448–454 | Saat `hpp_changed_only`: `(warehouse_id = X OR warehouse_id IS NULL)` **atau** disable filter gudang di FE HPP + jangan kirim `warehouse_id` ke list. |
| HM-B3 | P1 | **Inkonsistensi HPP_RESET vs HPP_CORRECTION:** RESET menulis `warehouse_id` = WH pemicu (`MasterProduk::checkAndResetHppIfStockEmpty` 297–299); CORRECTION null. Filter gudang menampilkan RESET gudang itu saja padahal HPP global. | Produk 286–310 vs HppCorrection 58–62 | Samakan: keduanya null **atau** dokumentasikan + selalu include null-WH di filter HPP. |
| HM-B4 | P1 | **HPP Awal/Akhir ≠ rekonsiliasi baris terfilter:** `avg_cost_awal/akhir` dihitung dari **semua** stock_card (abaikan `hpp_changed_only` + tipe), sementara list/nilai (salah) terfilter. User lihat rantai HPP di tabel tidak “menjelaskan” lompatan awal→akhir bila filter tipe/gudang aktif. | Controller 434–452, 482–498 vs 467–470 | Dokumentasikan di UI; atau akhir = last **changed** row in period (semantik beda — pilih satu). |
| HM-B5 | P1 | **Dropdown tipe transaksi menyesatkan:** opsi penuh (SALES, TRANSFER, …) padahal halaman `hpp_changed_only`. Retail filter “Penjualan” → kosong hampir selalu; serial kadang ada (Metode A recalc). | FE filter + `getTransactionTypeOptions` | Filter tipe hanya tipe yang *bisa* ubah HPP, atau hint. |
| HM-B6 | P2 | **Nilai masuk/keluar tidak merepresentasikan koreksi HPP:** `TYPES_NO_QTY` → `total_cost=0` (`StockCard::record` 261–263). Delta nilai inventory dari koreksi hanya terlihat di kolom HPP sebelum/sesudah, bukan di summary “nilai”. | record 249–263; summary sum total_cost | **FIXED (A+B + B+C+A penjelasan):** label mutasi + `nilai_stok`/`qty_stok`; panel penjelasan = Δ HPP unit + selisih vs sejarah mutasi + count/last no koreksi. Rumus historical `total_nilai_*` tidak diubah. |
| HM-B7 | P2 | Serial SALES **bisa** `before≠after` (recompute) → masuk list + nilai keluar; retail SALES tidak. Perilaku halaman beda mode produk tanpa penjelasan UI (hanya chip SERIAL). | `PostsSalesInventory` 148–160 | Tooltip / empty-state copy. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HM-S0 | P0 | **Permission FE ≠ BE untuk list/export:** menu+route = `stok.view_hpp` (`AppMenu` 88, router 209). `index`/`export` = `stok.view` (Controller 25–26, 325–326). Role custom `view_hpp` tanpa `view` → buka halaman, summary OK, **list+export 403**. Seeded admin/manager kebetulan punya keduanya; Kasir/Gudang **tanpa** `view_hpp`. | RolePermissionSeeder 41, 90 vs 117, 125 | BE: `index`/`export` izinkan `(stok.view && …)` **atau** untuk `hpp_changed_only` require `stok.view_hpp`; FE gate `view_hpp && view` **atau** selaraskan ke satu kontrak. |
| HM-S1 | P1 | = **KS-S1** — `hpp-summary` cukup `view_hpp` tanpa `stok.view`. | Controller 395–397; KS audit | Require keduanya **atau** dokumentasikan orthogonal (lalu fix HM-S0 konsisten). |
| HM-S2 | P2 | = **KS-S2** — terima `product_id` numerik. | Controller 412–419 | ULID-first bertahap. |
| HM-S3 | P2 | = **KS-S3** — export sync no throttle. | routes api 384–389 | Throttle shared. |
| HM-S4 | P2 | User `stok.view` tanpa `view_hpp` bisa `?hpp_changed_only=1` → lihat **dokumen mana** yang mengubah HPP (tipe/no), meski kolom cost di-strip. | Controller 95–97, 154–160 | Require `view_hpp` bila `hpp_changed_only`, atau 403. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HM-C1 | P1 | = **KS-C1** sort whitelist `tipe_transaksi`/`qty_masuk`/… → 500. | Controller 100–107 | Map alias (shared fix). |
| HM-C2 | P2 | **`hpp_changed_only` tidak diuji sama sekali** di PHPUnit (`rg` 0 hit di `tests/`). Tes summary yang ada **tanpa** flag — false confidence vs FE. | tests/ | Feature: list filter; summary dengan/tanpa flag; warehouse + null WH correction. |
| HM-C3 | P2 | Export sheet “Pergerakan HPP” masih kolom Kartu Stok (Qty/Saldo), tanpa Selisih HPP; filename server `stock_card_*` vs FE `pergerakan_hpp_*`. | StockCardExport 87–120; Controller 355; FE 302 | Kolom delta opsional; filename server selaras. |
| HM-C4 | P2 | = **KS-C4** export `warehouseId` `?int`. | Export ctor | Resolve ULID. |
| HM-C5 | P3 | = **KS-C7** unused `SettingService` import. | Controller 11 | Hapus. |
| HM-C6 | P3 | `getHppSummary` JSDoc di `stockCards.js` tidak dokumentasikan `hpp_changed_only`. | stockCards.js 36–46 | Update. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| HM-X1 | P0 | Writer HPP_CORRECTION (`ApproveHppCorrection`, `ApproveSerialHppCorrection`, transfer biaya) → null WH → **hilang** di Pergerakan HPP saat filter gudang (HM-B2). |
| HM-X2 | P1 | `HPP_RESET` (auto stock kosong) vs Koreksi HPP menu vs Koreksi HPP Serial vs kartu stok: satu halaman konsumsi; tidak ada deep-link ke dokumen koreksi (beda Kartu Stok yang punya `source_doc` PBS). |
| HM-X3 | P1 | Margin/Gross Profit pakai `stok.view_hpp` orthogonal `laporan.keuangan` — konsisten permission nama, **tidak** share endpoint; risiko user lihat margin tanpa pernah buka Pergerakan HPP (OK) atau sebaliknya. |
| HM-X4 | P2 | = **KS-X4** twin FE — bug filter/fetch dobel di dua file. |
| HM-X5 | P2 | Void sales tipe `SALES_RETURN` (= **KS-B4**) — bila serial void ubah avg, muncul sebagai “Retur” di Pergerakan HPP. |

**Siapa menulis `avg_cost_before` / `avg_cost_after`:**

| Sumber | before/after | Muncul di HM (`!=`) |
|--------|--------------|---------------------|
| `StockCard::record` default | produk.avg → sama | Tidak |
| `InventoryStockObserver` | global sama | Tidak |
| PO / Serial intake / ADJ_IN / REPACK_IN | recalc weighted | Ya |
| SALES retail | sama | Tidak |
| SALES/void/retur **serial** (Metode A) | recompute avg unit tersedia | Sering ya |
| TRANSFER qty | sama | Tidak |
| Transfer **biaya** → `HPP_CORRECTION` | old→new | Ya |
| PURCHASE_RETURN / ADJ_OUT / REPACK_OUT | tipikal sama* | Tidak* |
| `ApproveHppCorrection` / `ApproveSerialHppCorrection` | eksplisit | Ya (kecuali noop) |
| `checkAndResetHppIfStockEmpty` → `HPP_RESET` | >0 → 0 | Ya |
| Stock opname audit line | sama | Tidak |

\*Cek retur beli: `LockPurchaseReturnAction` mengirim `currentHpp`/`newHpp` — verifikasi kasus serial vs retail saat patch.

### Tampilan / DRY / FE

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| HM-U1 | P1 | = **SC-02** clone ~600 LOC vs `StockCardPage` | Extract shell saat fix P0/P1 FE |
| HM-U2 | P1 | = **SC-01** AutoComplete no `forceSelection` | Shared |
| HM-U3 | P1 | = **SC-03** mount + watch double fetch | Shared |
| HM-U4–U8 | P2 | = SC-04,06,07,08,09 | Shared |
| HM-U9 | P2 | **Severity drift:** HPP page masukkan `HPP_CORRECTION` di `systemTypes` (347); Kartu Stok + unit test **tidak** → CORRECTION = `info` di KS, `warn` di HM. Unit test copy KS saja. | Satu helper + update test |
| HM-U10 | P2 | Tidak ada link `source_doc` / buka Koreksi HPP / Serial HPP / PBS (KS punya PBS). | Map tipe→route |
| HM-U11 | P2 | `goBack` hardcode `inventory-stok` meski deep-link dari Produk (`ProdukPage` 1258–1263). | `router.back()` / query `from` |
| HM-U12 | P3 | Total Cost: class merah jika `qty_in` tidak >0 — termasuk baris koreksi qty 0 → merah palsu. | Neutral jika qty in=out=0 |
| HM-U13 | P3 | Tidak pakai `canViewHpp` di page (route sudah gate) — OK; beda pola KS yang strip kolom. | — |
| HM-U14 | P3 | E2E: shell smoke saja (`docs-helpers` route); tidak assert list/summary/export. | Optional e2e happy path |

**OK:** `ListFiltersSheet`, `MoneySummaryPanel`; deep-link Produk gated `canViewHpp`; banner “HPP global” (sebagian benar).

### DB

| ID | Sev | Temuan |
|----|-----|--------|
| HM-D1 | P2 | = **KS-D1** — query HPP global/order by tanggal: index `(product, warehouse, tanggal)` kurang optimal tanpa warehouse; `(product_id, tanggal)` membantu list HM. |
| HM-D2 | P3 | `whereColumn` before≠after OK untuk decimal; tidak ada generated column / index partial “hpp changed”. |

---

## Keputusan produk (default plan)

1. **HM-B1 (P0):** `hpp_changed_only` **hanya** list + export; **hapus** dari payload `getHppSummary` (atau BE ignore flag untuk sum nilai). Align tes FE/BE.  
2. **HM-B2 + HM-S0 (P0):** filter gudang di mode HPP include `warehouse_id IS NULL`; permission kontrak tunggal (`view_hpp`(+`view`?)) untuk index/export saat `hpp_changed_only`.  
3. **HM-C2:** tes wajib sebelum merge fix.  
4. Twin extract: **minimal** `buildFilterParams` + hydrate product bersama KS; full shell hanya jika diff tetap kecil setelah patch.

## Antrian fix

Gabung plan Kartu Stok (`18`) + Stok (`17`) — shared controller/export/FE shell dulu, lalu HM-only (B1/B2/S0).
