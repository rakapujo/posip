# Audit menu — 17 Inventory → Stok

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/StockPage.vue` · `api/modules/inventoryStocks.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/InventoryStockController.php` · `Models/InventoryStock.php` · `Exports/InventoryStockExport.php` · `Observers/InventoryStockObserver.php`  
> - Routes: `syilex/routes/api.php` `GET /inventory/stocks*` · Router FE `inventory-stok` (`stok.view`)  
> - Tes: `StokLowStockFilterTest` · `StokValuationTest` · `InventoryAccessCoverageTest` (stock) · `StockCardTest`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan menu:** Inventory → Stok (`/app/inventory/stok`) — item pertama grup Inventory di `AppMenu.vue`.

## Scope yang diaudit

| Lapisan | Cakupan |
|---------|---------|
| FE | List lazy, expand per gudang, filter (gudang/status/menipis), search, sort, paginate, summary strip, valuation panel, detail dialog, export Excel, deep-link `?warehouse_id=`, link → Kartu Stok |
| API | `index`, `summary`, `valuation-by-warehouse`, `export`, `by-product/{ulid}` |
| Model / observer | `InventoryStock` scopes, `initializeFor*`, `adjustStock`, observer create/update, sync HPP vs `MasterProduk` |
| Auth | `stok.view` / `stok.view_hpp` (seeder kasir: view tanpa HPP) |
| Cross | Dashboard low-stock, POS product search HPP, Kartu Stok, Warehouse → Stok, mutasi stok (PO/Adj/Sales/Transfer) |
| DB | unique `(product_id, warehouse_id)`, indexes, valuation SQL, summary full-load |

**Bukan CRUD dokumen** — menu view-only (stok berubah lewat modul transaksi).

---

## Peta API ↔ permission

| Endpoint | `stok.view` | `stok.view_hpp` | Catatan |
|----------|-------------|-----------------|---------|
| `GET /inventory/stocks` | wajib | strip `avg_cost` / `total_value` | Index expose `warehouse_id` numerik |
| `GET .../summary` | wajib | strip nilai | Full `->get()` ke PHP |
| `GET .../valuation-by-warehouse` | wajib | **wajib** (403) | Pakai `p.avg_cost` (SSoT produk) |
| `GET .../export` | wajib | strip kolom HPP | Search via `scopeSearch` |
| `GET .../by-product/{ulid}` | wajib | strip HPP | Detail tidak kirim `warehouse_id` |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-B1 | P1 | Filter status **“Semua Status”** (`null`) di FE tidak dikirim; BE tanpa `status` **memaksa `active`**. Label menipu; inactive tidak pernah terlihat via “Semua”. | `StockPage.vue` ~104–108, 167–169; `InventoryStockController` 74–80 | Kirim `status=all` eksplisit, atau ubah default BE + label FE “Aktif (default)”. |
| ST-B2 | P1 | Filter **`low_stock` + `warehouse_id`**: `whereHas` low-stock **tidak di-scope gudang terpilih**. Produk bisa lolos karena gudang lain menipis, sementara expand hanya menampilkan gudang filter (qty cukup). | Controller 43–56 vs 83–86; tes lintas gudang hanya global (`StokLowStockFilterTest` 172–194) | Di cabang `warehouseId`, tambahkan `where('warehouse_id', $warehouseId)` ke `whereHas` low_stock. |
| ST-B3 | P1 | **Summary** vs list tidak selaras: summary hanya terima `warehouse_id`; abaikan search/status/low_stock. `total_products` = `MasterProduk::active()->count()` global, bukan produk yang tampil. | Controller 282–316; FE `loadSummary` ~194–208 | Aggregate SQL scoped mirror filter list; atau label “Semua produk aktif (global)”. |
| ST-B4 | P1 | **Index** menampilkan stok gudang **inactive**; summary/export/valuation pakai `activeWarehouse()`. Dashboard low-stock juga filter WH aktif → angka KPI vs halaman Stok bisa beda. | Controller 57–61 vs 284–286, Export 43; `DashboardController` 61–66 | Filter `activeWarehouse` di index (atau flag “sertakan nonaktif”). |
| ST-B5 | P2 | Valuation memakai **`master_produk.avg_cost`**; list/export/summary value sering **`inventory_stock.avg_cost`**. Drift jika caller lupa `syncAvgCostToInventoryStocks` setelah `recalculateAvgCost`. | Controller 168–170 vs 347; `MasterProduk` 251–263 | Satu sumber: selalu `p.avg_cost` di tampilan, atau sync wajib di satu helper. |
| ST-B6 | P2 | `initializeFor*` set `avg_cost => 0` meski produk sudah punya HPP (gudang baru setelah HPP ada). | `InventoryStock` 174–179, 201–206 | Init dari `product->avg_cost`. |
| ST-B7 | P2 | Semantik **low stock = qty &lt; minimum per baris gudang** (bukan total global). By design untuk expand; dokumentasikan di UI (“menipis di ≥1 gudang”). | Controller 161, 174–176 | Copy bantuan filter. |

### Logika keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-S1 | P0 | **HPP leak di POS** (cross-modul): `PosController` product search + barcode **select + return `avg_cost`**; `MasterProduk::$hidden` tidak menyembunyikan HPP. Kasir punya `stok.view` tanpa `stok.view_hpp`. Menu Stok sendiri sudah strip HPP — celah di POS. | `PosController` 152–159, 217–222, 196–198, 239–241; `MasterProduk` 68–76; seeder kasir | Unset/`makeHidden('avg_cost')` di response POS (sama pola ProdukController). |
| ST-S2 | P2 | Index expose **`warehouse_id` numerik** (plus ULID) — menyimpang konvensi ULID-first; permukaan IDOR kecil. | Controller 141–146 | Cukup ULID ke FE; kartu stok query pakai ULID bila memungkinkan. |
| ST-S3 | P2 | Model `$appends = ['total_value']` selalu hitung dari `avg_cost`. Aman selama controller map manual; berbahaya jika endpoint serialisasi model mentah. | `InventoryStock` 53–56, 92–95 | Jangan append cost; hitung hanya di controller saat `view_hpp`. |
| ST-S4 | P3 | Export FE tanpa `v-if` permission — OK karena route sudah `stok.view`; konsistensi eksplisit opsional. | `StockPage` ~406 | `canExport = can('stok.view')`. |

### Logika kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-C1 | P2 | `InventoryStockObserver::updated` pakai **`isDirty('qty')`**. Idiom Eloquent di event `updated` = **`wasChanged`**. `StockCardTest` saat ini **lulus** — residual risk saat upgrade framework / path update lain. | Observer 35–36; `StockCardTest` 308–329 | Ganti `wasChanged('qty')` + `getOriginal`/`getChanges`. |
| ST-C2 | P1 | `adjustStock` set `StockCard::$skipObserver` **tanpa `try/finally`** — exception meninggalkan flag true → mutasi berikutnya skip observer diam-diam. | `InventoryStock` 254–280 | `try/finally` seperti `ApprovePurchaseOrderAction`. |
| ST-C3 | P2 | `MasterProdukObserver::updated` pakai `isDirty('status')` untuk re-init stok saat reaktifasi — pola sama, risiko skip `initializeForProduct`. | `MasterProdukObserver` ~29 | `wasChanged('status')`. |
| ST-C4 | P1 | Export `scopeSearch`: `whereHas` dengan `orWhere` **tanpa grouping** → precedence SQL longgar (barcode/nama produk lain bisa lolos). Index search sudah di-group benar. | `InventoryStock` 113–119 vs Controller 65–71 | Wrap `orWhere` dalam closure. |
| ST-C5 | P3 | Trait `HasInventoryStock` hampir tak dipakai bermakna; `CreateTransferAction` import tanpa call. | trait + Transfer | Hapus atau pakai. |
| ST-C6 | P3 | FE: `dt` ref mati; `valuation.loading` tidak di template; product `avg_cost` API tidak ditampilkan di header detail. | `StockPage` | Bersihkan. |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-X1 | P0 | Sama **ST-S1** — POS vs gate HPP Stok. | PosController | Fix di POS (bukan di halaman Stok). |
| ST-X2 | P1 | Dashboard low-stock (WH+produk aktif) ≠ filter Stok (bisa hitung WH inactive / tanpa scope WH di low_stock). | Dashboard 61–66; Stok index | Samakan definisi + ST-B2/B4. |
| ST-X3 | P2 | Dashboard `orderByRaw('(qty / minimum_stok)')` — risiko div/0 jika `minimum_stok = 0`. | Dashboard ~253 | Guard `NULLIF(minimum_stok,0)`. |
| ST-X4 | P2 | Deep-link Warehouse→Stok OK on mount; **tidak ada `watch` query** jika sudah di halaman. Detail Stok **tidak** link Kartu Stok (stocks detail tanpa `warehouse_id`). | `StockPage` 118–123, 335–343; Controller show 215–226 | Watch query; expose id/ulid WH di detail. |
| ST-X5 | P3 | Tidak ada link Stok → Produk master / Warehouse master. | FE | Opsional deep-link. |

### Tampilan (PrimeVue / Sakai / reusable)

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-U1 | P2 | Detail pakai raw `Dialog` + spinner manual — peer inventory lain mulai pakai `DetailDialog`. | `StockPage` 544–614 | Ganti `DetailDialog` (jangan komponen baru). |
| ST-U2 | P2 | Tag **RETAIL** muncul untuk semua baris saat modul serial off (`v-else`). | ~431–432 | `v-else-if="serialEnabled"`. |
| ST-U3 | P2 | Valuation: tidak ada empty/loading UI; tidak refresh saat filter. | loadValuation mount-only | Skeleton / “tidak ada data”; refresh opsional. |
| ST-U4 | P3 | Kolom “Gudang” pakai `stocks.length` padahal field `total_warehouses` (beda makna saat filter WH). | ~473–475 | Satu sumber kebenaran + label. |
| ST-U5 | P3 | Toolbar `#start` kosong; summary chips tidak clickable (filter menipis/negatif). | MoneySummaryPanel | Opsional UX. |

**OK:** `DataTableHeader`, `ListFiltersSheet`, `RowActionButtons`, `MoneySummaryPanel`, `CollapsibleSection`, `DetailItem`/`DetailTable` sudah dipakai — tidak perlu komponen baru.

### DRY & reusable

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| ST-D1 | P2 | Hierarki satuan / breakdown stok diduplikasi Export vs `useFormatters` FE | Shared helper BE atau mirror formatters |
| ST-D2 | P3 | Manual `can()` di controller (bukan route middleware) — pola peer | Pertahankan; pastikan endpoint baru tidak lupa |

### Optimize database

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| ST-Q1 | P1 | `summary()` **`->get()` seluruh baris** lalu sum/filter di PHP + load product untuk low-stock. | Controller 296–305 | `SUM`/`COUNT` SQL + conditional aggregate |
| ST-Q2 | P2 | Index tanpa filter WH: eager **semua** `inventoryStocks` per produk di halaman — berat katalog besar. | Controller 59–61 | Select kolom minimal; atau lazy load expand |
| ST-Q3 | P2 | `initializeAll` O(produk×gudang) + `exists` per pasangan. | Model 217–241 | Bulk insert missing keys |
| ST-Q4 | P3 | Schema: unique + index `(product_id, qty)` cukup; low-stock `whereColumn` tetap scan. | migration create inventory_stock | OK YAGNI index ekstra sampai profil |

---

## Tes — gap coverage

| Ada | Gap |
|-----|-----|
| Low-stock filter global + flag per WH | `low_stock` + `warehouse_id` scoped (ST-B2) |
| Valuation HPP gate + angka | Export search SQL; export HPP strip assert |
| Access: index/summary/valuation 403 | `by-product` HPP strip; index `avg_cost` null tanpa view_hpp |
| Observer create/update (test lulus) | `adjustStock` finally; reaktifasi produk init |

---

## Keputusan produk (default audit — belum dikunci user)

1. Low-stock + filter gudang = hanya menipis **di gudang itu** (ST-B2).  
2. Index default tetap produk aktif; “Semua status” harus benar-benar all atau dihilangkan (ST-B1).  
3. POS tidak boleh kirim `avg_cost` tanpa `stok.view_hpp` (ST-S1) — selaras gate menu Stok.

---

## Antrian fix (bila user minta execute)

1. **P0:** strip `avg_cost` di POS products / barcode.  
2. **P1:** low_stock scope warehouse; export search group; `adjustStock` finally; summary SQL; status filter semantics; index active WH.  
3. **P2+:** observer `wasChanged`, DetailDialog, RETAIL tag, sync/init HPP, valuation UI.

**Di luar scope / YAGNI:** edit stok dari halaman ini; klik-summary → filter (nice-to-have); komponen UI baru.
