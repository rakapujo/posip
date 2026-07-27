# Audit menu — 27 Pembelian → Purchase Order

> **Status:** audit complete (belum patch; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/pembelian/PurchaseOrderPage.vue` · `PurchaseOrderFormPage.vue` · `api/modules/purchaseOrders.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/PurchaseOrderController.php` · `Actions/PurchaseOrder/{Create,Update,Approve}PurchaseOrderAction.php` · `Services/PurchaseOrderCalculationService.php` · `PurchaseMasterRules.php` · `Models/DocPurchaseOrder*` · `HistoryHargaBeli` · `SupplierHutang` · trait `SettlesCashPayment`  
> - Turunan approve: `InventoryStock` + `StockCard` (`PURCHASE`) + `master_produk.avg_cost` + hutang (+ optional `DocPembayaranHutang` cash)  
> - Cross: Retur Beli (`ValidatesPoHeaderMatch`) · Laporan Pembelian (`PurchaseReportSource`) · menu twin **Pembelian Serial** (`SerialIntake`, bukan submenu PO)  
> - Routes FE: `pembelian-po*` · API: `/purchase-orders*` (`routes/api.php` 458–470)  
> - Menu: `AppMenu.vue` Pembelian → Purchase Order (pertama di grup)  
> - Tes: `tests/Feature/PurchaseOrder/{Create,Approve}PurchaseOrderActionTest.php` · `PurchaseOrderDateFilterTest.php` · partial `PembelianAccessCoverageTest` · `PurchaseMoneyStripTest`  
> - Domain: `docs/ai/business-rules.md` §status + §B HPP masuk  
> **Jika konflik:** ikuti kode.  
> **Urutan:** pertama di grup Pembelian (sebelum Pembelian Serial / Hutang / …).

## Scope

Dokumen pembelian retail (non-serial): **draft → approved (terminal)**. Approve = **terima barang** (stok + HPP weighted + kartu `PURCHASE` + hutang + history harga; cash → settle hutang). Produk `is_serial` diblok di PO standar (pakai menu Pembelian Serial). **Tidak ada** lock / void / cancel di API atau enum DB.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /purchase-orders` | `po.view` (+ strip uang tanpa `po.view_harga`) | List |
| `GET /purchase-orders/list` | `po.view` | Dropdown retur (approved, limit 100) |
| `GET /{ulid}` | `po.view` (+ strip harga) | Detail + form hydrate |
| `POST /` | `po.create` | Draft create |
| `PUT /{ulid}` | `po.edit` | Draft update |
| `DELETE /{ulid}` | `po.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `po.approve` + throttle 30/1 | Posting stok/hutang |
| `GET /products` | `po.create` **saja** | Autocomplete (+ **`avg_cost`**) |
| `GET /last-price` | `po.create` **saja** | Prefill harga (leak tanpa `view_harga`) |
| `GET /price-history` | `po.view_harga` | **API hidup, FE tidak pakai** |
| `GET /tax-settings` | **tanpa `can()`** | Label pajak form |
| `POST /calculate` | `po.create` **saja** | Ringkasan form |
| FE route list | `po.view` | |
| FE create / edit | `po.create` / `po.edit` | |
| AppMenu | `po.view` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search/supplier/WH/status/date); detail dialog; PDF client-side. **Tidak:** Excel, cancel/void/lock, edit approved, terima parsial, serial line.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor via `SettingService` `purchase_order` | Create 27–31 |
| Status DB: `draft` \| `approved` saja | migration `2026_01_25_100001_*` :68 |
| Docs klaim `cancelled` | `business-rules.md` :49 — **tidak ada di DB/API** |
| Duplikat `product_id`+`unit_used` | Controller `hasDuplicateProducts` HTTP only |
| Qty base = `qty_in_unit * unit_konversi` (dari client) | CalculationService 270–273 |
| Approve qty stok = `(int) qty_in_base` | Approve 87 |
| HPP: `cost_per_unit` = subtotal item + alokasi biaya/pajak(HPP)/pembulatan — **bukan** diskon header | `allocateCosts` 209–213; cost 368–374 |
| Hutang = `grand_total` (setelah diskon header) | Approve 146–155 |
| Serial produk | `PurchaseMasterRules::poDetailProductErrors` blockSerial |
| SoftDeletes | Tidak — hard delete draft |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-B1 | P0 | **Docs vs kode: status `cancelled`.** Aturan bisnis menulis draft/approved/**cancelled**; enum + model + routes **tanpa** cancel/void. Salah approve hanya dilawan retur/adj. | `business-rules.md` :49 vs migration :68; `api.php` 458–470 | Putuskan: (a) hapus `cancelled` dari docs, atau (b) implementasi cancel/void reverse stok+hutang+history. |
| PO-B2 | P0 | **Race update/destroy vs approve.** `UpdatePurchaseOrderAction` cek `isDraft()` **di luar** TX, **tanpa** `lockForUpdate` / re-check di dalam. Paralel: approve posting stok/hutang lalu update masih menulis ulang header+detail PO yang sudah approved → dokumen/retur/history tidak lagi cocok stok. Destroy draft check sama pola (lebih ringan). | Update 27–33 vs 57+; Approve sudah lock 46–49; destroy Controller 422–426 | `lockForUpdate` + re-assert draft di dalam TX update/destroy (mirror Approve). |
| PO-B3 | P1 | **Diskon header tidak masuk HPP / `cost_per_unit`.** Hutang turun oleh diskon nota; valuasi stok memakai subtotal item + biaya/pajak/pembulatan saja → HPP **lebih tinggi** dari cash out. Pola sama serial intake (sudah disebut di tes serial). | CalculationService `allocateCosts` 209–213; cost dari `subtotal` item 370–373 | Keputusan produk: alokasi proporsional −diskon header ke cost **atau** dokumentasikan BY DESIGN di business-rules + UI hint. |
| PO-B4 | P1 | **`unit_konversi` / `unit_used` dipercaya client.** Tidak diverifikasi ke `master_produk.unit_*/konversi_*`. Forgery konversi → `qty_in_base` / stok / HPP salah. | Controller rules 62–63; CalculationService 272–273; `PurchaseMasterRules` hanya aktif+serial | Resolve konversi dari master by `unit_used` di BE; tolak mismatch. |
| PO-B5 | P1 | **`(int) qty_in_base` saat approve.** Validasi `qty_in_unit` `numeric`; pecahan × konversi bisa terpotong → under-receive vs dokumen. | Approve 87; rules 64 | Cast konsisten (int qty policy) atau simpan/pakai decimal sesuai kolom. |
| PO-B6 | P2 | Duplikat product+unit: HTTP tolak; Action/approve test boleh multi-line same product beda path — kontrak inkonsisten. | Controller 108–114 vs `ApprovePurchaseOrderActionTest` multi-line | Samakan: unique DB atau izinkan multi-line sadar. |
| PO-B7 | P2 | Tidak ada terima parsial / sisa qty — approve = full receive. Retur terpisah. | Approve loop semua detail | OK bila disengaja; dokumentasikan. |
| PO-B8 | P2 | `%` diskon header/item: BE `min:0` tanpa max 100 — 150% lolos (item FE clamp 100; header FE tidak). | Controller 45–50; Form header InputNumber | `max:100` percent di BE + FE header. |
| PO-B9 | P3 | Tanggal PO masa depan: FE/BE tidak ketat `before_or_equal:today` (opsional kebijakan). | rules `date` only | Keputusan. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-S1 | P1 | **`GET /last-price` = `po.create` saja** — harga beli tanpa `po.view_harga`. | Controller 534–536 | Require `po.view_harga` (atau AND create). |
| PO-S2 | P1 | **`GET /products` mengembalikan `avg_cost`** tanpa `stok.view_hpp` / `po.view_harga`. | Controller 482, 519–520 | Strip `avg_cost` kecuali permission HPP. |
| PO-S3 | P1 | **Form FE tidak gate `po.view_harga`.** List/detail/PDF strip; form tetap tampilkan harga/disc/ringkasan. Role gudang: create/edit tanpa `view_harga`. | Form vs Page 20; Seeder 130 vs 96 | Sembunyikan/disable field harga di form; last-price skip. |
| PO-S4 | P1 | **`calculate` / `products` / `last-price` hanya `po.create`.** User `po.edit` tanpa create → form edit 403 helper. | Controller 469, 534, 600; router edit `po.edit` | Gate `create \|\| edit`. |
| PO-S5 | P2 | **`GET /tax-settings` tanpa `can()`.** | Controller 621–626 | Minimal `po.view` / create\|edit. |
| PO-S6 | P2 | Throttle hanya approve; store/update/delete tanpa. | `api.php` 461–470 | Throttle write. |
| PO-S7 | P3 | SoD gudang tanpa approve — benar; cash settle masih butuh approve di role lain. | Seeder 130 | OK. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-C1 | P0 | = **PO-B2** TOCTOU update/destroy. | Update Action | Lock + re-check. |
| PO-C2 | P1 | Nested TX cash settle di dalam approve TX — savepoint / failure opacity. | `SettlesCashPayment` + CreatePembayaranHutang | Dokumentasikan atau flatten. |
| PO-C3 | P2 | Approve notes lazy `$po->supplier` (1 query). | Approve 123 | Eager load sebelum loop. |
| PO-C4 | P2 | `pembulatan` fillable tanpa cast di model. | DocPurchaseOrder casts vs fillable | Cast decimal. |
| PO-C5 | P2 | Coverage: create/approve kuat; **UpdateAction**, destroy HTTP, race update×approve, unit forgery, harga strip HTTP, last-price leak — tipis/hilang. | `tests/Feature/PurchaseOrder/*` | Tambah suite. |
| PO-C6 | P3 | Sum-mode item discount hitung variabel unused. | CalculationService 39–40 | Bersihkan. |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-X1 | P1 | Setelah corrupt update (PO-B2), **Retur** (`po_detail_id`) / laporan / hutang bisa tidak selaras stok. | Retur validate header; hutang `po_id` | Fix race dulu. |
| PO-X2 | — | Serial: menu terpisah; PO exclude `is_serial`; history serial `po_id` null — OK. | getProducts 480; SerialIntake | Dokumentasikan di UI (banner sudah ada). |
| PO-X3 | — | Cash approve → hutang + pembayaran complete — OK. | SettlesCashPayment | — |
| PO-X4 | P2 | Retur punya **lock**; PO tidak — asimetris kontrol dokumen. | api retur lock vs PO | Keputusan produk. |
| PO-X5 | P2 | `list` approved limit 100 — retur dropdown bisa miss PO lama. | Controller list 227–253 | Paginate/search atau naikkan limit sadar. |
| PO-X6 | P3 | Laporan pembelian union PO + serial — pastikan diskon header semantik sama di report. | PurchaseReportSource | Audit saat menu laporan. |

### Tampilan / DRY / FE

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-U1 | P1 | = **PO-S3** form tanpa `view_harga`. | Form | Gate field. |
| PO-U2 | P1 | **`getPriceHistory` dead** — API + `purchaseOrders.js` ada; UI tidak. | api module; Form hanya last-price | Dialog history (PrimeVue Dialog) atau hapus API dari client. |
| PO-U3 | P1 | Subtotal baris client (`getItemSubtotal`) ≠ round BE → drift vs ringkasan `calculate`. | Form ~629–672 vs CalculationService round 2 | Pakai angka dari `/calculate` per line atau mirror round. |
| PO-U4 | P1 | Cash maxlength FE 100/100/50 > BE 50/50/30 → 422. | Form ~889–898; Controller 40–42 | Samakan maxlength. |
| PO-U5 | P2 | **`activeFilterCount` pakai `additionalFilters.value`** padahal object reactive bukan ref → badge supplier/WH undercount. | Page 31–38 | `additionalFilters.supplier_id` (tanpa `.value`). |
| PO-U6 | P2 | DatePicker Clear/Today tanpa refetch (`@date-select` only). | Page filters | `@update:modelValue="onFilter"`. |
| PO-U7 | P2 | Detail dialog disc ringkas vs PDF disc 1–5 — inkonsisten. | Page detail vs PDF helpers | Samakan tampilan. |
| PO-U8 | P2 | Ringkasan form hand-rolled; bisa `MoneySummaryPanel` (sudah di common). | Form 1136+ | Reuse panel. |
| PO-U9 | P2 | DRY besar dengan `PurchaseReturnFormPage` (disc dialog, tipeOptions, calculate debounce). | kedua form | Extract shared purchase-doc helpers. |
| PO-U10 | P3 | Unit `Select` tanpa `filter` (aturan frontend). | Form unit Select | `filter`. |
| PO-U11 | P3 | Tidak Excel list; PDF tanpa gate export laporan. | Page | Optional. |
| PO-U12 | P3 | E2E `po-approve.spec.js` API-heavy; form/disc/cash/permission tidak diuji. | e2e | Happy-path form optional. |

### DB / query

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PO-D1 | P1 | Enum status **tidak bisa** simpan `cancelled` (docs dust). | migration :68 | Align docs atau migrasi enum. |
| PO-D2 | P2 | `supplier_hutang.po_id` indexed **tidak UNIQUE** — double hutang jika lock approve pernah di-bypass. | hutang migration | Unique `po_id` where not null. |
| PO-D3 | P2 | Tidak ada UNIQUE `(po_id, product_id, unit_used)` di detail. | detail migration | Unique bila kebijakan anti-dup. |
| PO-D4 | P2 | Index list: ada `tanggal_po`, `status`, `(supplier_id, tanggal_po)`; belum `(status, tanggal_po)` / `(warehouse_id, tanggal_po)`. | migrations + `2026_04_16_*` | Tambah bila EXPLAIN lambat. |
| PO-D5 | P3 | `scopeSearch` `orWhereHas('supplier')` — OK dengan paginate; pantau. | Model | — |
| PO-D6 | — | Index list eager + `withCount` — anti-N+1 list OK. | Controller index | — |

---

## Matriks aksi UI

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `po.view` |
| Filter supplier / WH / status / tanggal | Ya | same |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view (+ harga strip) |
| Export Excel | Tidak | — |
| Create / Edit / Delete draft | Ya | create / edit / delete |
| Approve | Ya (list + detail) | approve |
| Cancel / Void / Lock | Tidak | — |
| Price history UI | Tidak (API only) | view_harga |
| Prefill last price | Ya | create (leak) |
| Cash lunas langsung | Ya | form + approve settle |
| Serial lines | Tidak (redirect PBS) | — |

---

## Antrian patch (usulan prioritas)

1. **P0** PO-B2/C1 — lock + re-check draft pada update/destroy.  
2. **P0** PO-B1 — keputusan cancel vs koreksi docs.  
3. **P1** PO-S1/S2/S3/S4 — harga/HPP gate helper + form; `create\|\|edit`.  
4. **P1** PO-B3 — keputusan diskon header → HPP.  
5. **P1** PO-B4/B5 — resolve konversi master; qty cast.  
6. **P1** PO-U2/U3/U4 — history UI atau drop; round line; cash maxlength.  
7. **P2** PO-U5/U6, D2/D3, throttle, tax-settings can, DRY retur form.  
8. **P2+** tes race/update/destroy/permission matrix.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `CreatePurchaseOrderActionTest` | Math draft: disc item/header, biaya, pajak, alokasi, unit conversion, HTTP duplikat |
| `ApprovePurchaseOrderActionTest` | Stok, HPP, kartu, hutang, cash settle, history, double-approve, `data:verify`, multi-line |
| `PurchaseOrderDateFilterTest` | `byDateRange` DATETIME |
| `PembelianAccessCoverageTest` | smoke permission |
| `PurchaseMoneyStripTest` | strip harga list/show |

**Tidak / tipis:** UpdateAction suite, destroy, race update×approve, unit_konversi forgery, `(int)` truncation, last-price/products leak, form `view_harga`, calculate tanpa create.

---

## Ringkasan eksekutif

Happy path create→approve (stok/HPP/hutang/history/cash) **kuat dan teruji**, termasuk anti double-approve. Gap dalam tersisa: **race update vs approve (P0)**, **docs `cancelled` dust**, **diskon header ≠ HPP**, **konversi/qty dipercaya client**, dan **kebocoran/inkonsistensi `po.view_harga`** (last-price, avg_cost, form). FE list sudah pakai pola transaksi shared; form masih monolit + dead price-history.

**Fix hanya jika user bilang execute.** Berikut antrian: Hutang / Pembayaran / Retur / Deposit / atau Pembelian Serial sesuai AppMenu.
