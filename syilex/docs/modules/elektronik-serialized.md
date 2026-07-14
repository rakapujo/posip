# Modul Penjualan Elektronik (Serialized) — Blueprint

> ⛔ **SUPERSEDED / TIDAK DIIMPLEMENTASI.** Desain "serialized" berat ini **ditolak** (terlalu banyak yang diubah) lalu **di-rollback**. Modul serial yang benar-benar dibangun memakai pendekatan ringan **A+** — lihat **[`serial.md`](serial.md)**. File ini disimpan sebagai arsip diskusi requirement saja.

> **Status historis: SPEC v1 (K1–K7) — TIDAK dieksekusi.** Dokumen acuan hasil diskusi requirement.
> Semua fitur serialized bersifat **aditif & ter-gate** — modul retail (qty) tidak berubah.

---

## 1. Konsep Inti

POS ini diperluas untuk jual-beli **barang second ber-serial** (mis. MacBook), di mana **tiap unit unik**: serial, kondisi, modal, harga jual sendiri — berbeda dari stok fungible (qty + HPP rata-rata) yang sudah ada.

**Mode dua-lapis:**

```
SETTING GLOBAL (per instance)
  store_type = elektronik | retail_grosir
     → menentukan: MENU yang tampil, DEFAULT produk baru, label
        ▼
PER PRODUK
  tracking_type = serial | qty
     → menentukan: PERILAKU data
        ├─ serial → product_units, modal per unit, POS pilih unit
        └─ qty    → stok qty + HPP rata-rata (modul existing, TAK berubah)
```

- `store_type` = saklar **tampilan/preset**. `tracking_type` = saklar **perilaku data**.
- Toko elektronik **tetap bisa** jual aksesoris `qty` (charger/case) karena `tracking_type` per-produk.
- **Deploy: instance terpisah** dari toko retail live (DB sendiri, `store_type=elektronik`). Codebase tetap satu (bukan fork).

---

## 2. Keputusan Terkunci (decision log)

| Aspek | Keputusan |
|---|---|
| Pelacakan | Per-unit (serialized) |
| Sumber unit | Supplier (tempo/hutang) + beli dari perorangan (data minimal: nama/HP) |
| Mode | `store_type` global + `tracking_type` per-produk (dua-lapis) |
| Master Produk | Halaman terpisah, **tabel `master_produk` tetap satu** (+`tracking_type`), spec di level model |
| Pembelian | Menu + **tabel `doc_pembelian_unit` terpisah** (retail PO tak tersentuh) |
| Modal/unit | harga beli **per unit** + alokasi biaya kirim/lain/pajak **RATA** |
| Penjualan | POS existing + picker unit, diskon **manual** (tanpa promo), filter gudang |
| Retur | Retur penjualan → unit balik `tersedia` (v1) |
| Serial berulang | **Boleh** (buy-back); unik hanya di antara unit aktif (`draft`/`tersedia`) |
| Status unit v1 | `draft` → `tersedia` → `terjual` (retur balik `tersedia`) |
| Garansi | Catat **tanggal garansi** saja (tampil di struk), tanpa modul klaim |
| Struk | Tampilkan **serial** per unit |
| Laporan | Laba per unit + **aging stok** unit (v1) |
| Foto unit | Kolom `foto` JSON (banyak foto), **v1** (reuse ImageUpload) |
| Barcode | Reuse engine **CODE128** + template label unit |
| Gudang | **>1 gudang** → v1 filter/stok per gudang · v1.5 transfer · v2 opname |

---

## 3. Database Schema

### 3.1 `master_produk` — tambah kolom (nullable, dipakai hanya bila serial)

```
tracking_type      ENUM('qty','serial') DEFAULT 'qty'   -- produk lama tetap 'qty'
chip               VARCHAR(50)  NULLABLE   -- M1/M2/...
ram                VARCHAR(20)  NULLABLE
storage            VARCHAR(20)  NULLABLE
tahun              SMALLINT     NULLABLE
warna              VARCHAR(40)  NULLABLE
ukuran_layar       VARCHAR(20)  NULLABLE
```
Produk serial = "model/varian" (mis. *MacBook Air M1 8/256 Gray*). `avg_cost` **tidak dipakai** untuk serial (biarkan null/0).

### 3.2 Tabel baru: `product_units` (1 baris = 1 fisik unit)

```
id                 BIGINT PK
ulid               CHAR(26) UNIQUE
product_id         BIGINT FK master_produk        -- model/varian
warehouse_id       BIGINT FK master_warehouse     -- lokasi fisik
serial_number      VARCHAR(64)                    -- TIDAK unik global (lihat catatan)
grade              VARCHAR(8)   NULLABLE           -- A/B/C/D
battery_health     TINYINT      NULLABLE           -- % (0-100)
battery_cycle      INT          NULLABLE           -- cycle count
battery_condition  VARCHAR(20)  NULLABLE           -- Normal / Service
activation_lock    ENUM('aman','terkunci') DEFAULT 'aman'
kelengkapan        JSON         NULLABLE           -- {charger:bool, box:bool, nota:bool}
foto               JSON         NULLABLE           -- array path foto kondisi (reuse ImageUpload)
keterangan         TEXT         NULLABLE           -- minus/catatan fisik
harga_beli         DECIMAL(18,2)                   -- input per unit
harga_modal        DECIMAL(18,2) NULLABLE          -- = harga_beli + biaya rata (saat approve)
harga_jual         DECIMAL(18,2) NULLABLE          -- diisi belakangan
garansi_sampai     DATE         NULLABLE
status             ENUM('draft','tersedia','terjual') DEFAULT 'draft'
sumber             ENUM('supplier','perorangan')
penjual_nama       VARCHAR(100) NULLABLE           -- bila perorangan
penjual_hp         VARCHAR(30)  NULLABLE
pembelian_unit_id  BIGINT FK doc_pembelian_unit    -- dokumen intake
sale_id            BIGINT FK doc_sales       NULLABLE
sale_detail_id     BIGINT FK doc_sales_detail NULLABLE
created_by / updated_by / timestamps / softDeletes
```
Index: `(product_id, warehouse_id, status)`, `(serial_number)`, `(status)`.

> **Serial berulang:** MySQL tak punya partial-unique-index, jadi keunikan **divalidasi di aplikasi**: tidak boleh ada unit `status IN ('draft','tersedia')` dengan serial sama. Unit `terjual` lama dengan serial sama **boleh tetap ada** (histori) → mendukung buy-back.

### 3.3 Tabel baru: `doc_pembelian_unit` (header intake — pola mirip `doc_purchase_order`)

```
id, ulid
nomor_dokumen      VARCHAR(20) UNIQUE  -- prefix PBE-{YYMM}-{SEQ:4}
tanggal            DATETIME            -- pakai trait HasDateRangeScope
sumber             ENUM('supplier','perorangan')
supplier_id        BIGINT FK NULLABLE  -- bila supplier
penjual_nama/hp    VARCHAR NULLABLE    -- bila perorangan
warehouse_id       BIGINT FK
subtotal, biaya_kirim_*, biaya_lain_*, pajak_*, grand_total   DECIMAL(18,2)
tempo_hari, tanggal_jatuh_tempo
notes, status ENUM('draft','approved','cancelled')
approved_at/by, created_by/updated_by, timestamps
```
**Unit = "detail"-nya** → tiap `product_units` menunjuk `pembelian_unit_id` (tak perlu tabel detail terpisah). Saat `draft`, unit `status='draft'`.

### 3.4 Tabel existing — tambah kolom

```
doc_sales_detail  +  product_unit_id  BIGINT FK NULLABLE   -- baris menjual unit serial
stock_card        +  product_unit_id  BIGINT FK NULLABLE   -- jejak unit per gerakan
supplier_hutang   +  pembelian_unit_id BIGINT FK NULLABLE  -- hutang dari intake unit
supplier_hutang   ~  po_id  ALTER jadi NULLABLE            -- existing NOT NULL → WAJIB diubah; salah satu (po_id | pembelian_unit_id) terisi
```

### 3.5 Settings (tabel `settings`, via `SettingService`)

**Baru:**
```
business.store_type            'elektronik' | 'retail_grosir'  -- mode global; gate menu = (store_type=='elektronik')
business.warranty_default_days int    -- auto-isi garansi_sampai = tgl jual + N hari
business.locked_sale_policy    'warn' | 'block'   -- unit activation-lock saat jual (default warn)
business.allow_negative_margin 'allow' | 'warn'   -- jual di bawah modal/clearance (default allow)
business.warranty_terms         text     -- syarat garansi (dicetak di struk)
business.default_margin_percent decimal  -- saran harga = modal × (1+margin%) di Set Harga/intake (BUKAN POS), bisa override
prefix.pembelian_unit          'PBE'  -- via getPrefix() $defaults + getPrefixesWithInfo()
```
> `serialized_enabled` **TIDAK dipakai** — gate cukup `store_type`.

**Reuse existing (TANPA setting baru) — catatan interaksi:**
- **Nego serial** pakai `promo.allow_manual_discount` (**sudah ada, default `true`**) + cap `promo.max_manual_discount_percent`/`_nominal`. ⚠️ **Jangan set `promo.enabled=false`** di instance elektronik — itu juga mematikan diskon manual ([CheckoutSalesAction:433](../../app/Actions/Sales/CheckoutSalesAction.php)).
- **Pajak:** serial = **persis retail**. Intake pakai `tax.tax_purchase_*` (termasuk perorangan, tanpa auto-0); jual kena PPN keluaran normal. **Tak ada cabang pajak khusus serial.**
- **`stock.negative_mode`:** **diabaikan** untuk serial. **Guard:** produk `tracking_type='serial'` **dilarang** lewat cart qty (`addItem`) — wajib unit picker (`addSerialUnit`).
- **`rounding.*`:** reused; sisa pembulatan modal ke unit terakhir.

---

## 4. Perhitungan Modal (RATA)

```
subtotal_baris   = Σ harga_beli tiap unit            (BUKAN qty × harga)
total_biaya      = biaya_kirim_hasil + biaya_lain_hasil + pajak_nominal
biaya_per_unit   = total_biaya / jumlah_unit          (RATA)
harga_modal[u]   = harga_beli[u] + biaya_per_unit
```
Invarian: `Σ harga_modal == grand_total`. Sisa pembulatan dilekatkan ke unit terakhir agar jumlah persis.

**Contoh:** 2 unit (8.500.000 + 8.000.000) = subtotal 16.500.000; biaya 250.000; rata 125.000 →
modal U1 = 8.625.000, U2 = 8.125.000; Σ = 16.750.000 = grand_total. ✔

---

## 5. Alur (Flows)

### 5.1 Intake (Pembelian Elektronik)
1. Pilih sumber (supplier/perorangan), gudang penerima, tanggal.
2. Add produk (model) + qty → muncul **N sub-form unit** (serial, grade, battery, activation lock, kelengkapan, keterangan, **harga beli per unit**).
3. Isi header biaya kirim/lain/pajak. Preview modal/unit (rata).
4. Simpan Draft (unit `status='draft'`).

### 5.2 Approve Intake — `ApprovePembelianUnitAction` (transaksional, §2E lock)
- Generate `nomor_dokumen` (PBE) via `SettingService`.
- Hitung `harga_modal` tiap unit (rata) → set unit `status='tersedia'`.
- `inventory_stock` (produk, gudang): `qty += jumlah_unit` (`lockForUpdate`). **WAJIB `StockCard::$skipObserver=true` sebelum ubah qty** (Observer auto-buat ADJUSTMENT kalau tidak) → **manual `StockCard::record(PURCHASE)` per unit** (qty_in=1, cost=modal, `product_unit_id`) → reset `$skipObserver=false` di `finally`. Pola: `CheckoutSalesAction`.
- **TIDAK** panggil `recalculateAvgCost` — action terpisah → otomatis bypass HPP (§2B); jangan tiru baris recalc dari `ApprovePurchaseOrderAction:83`.
- Bila supplier & tempo>0 → buat `SupplierHutang` (referensi `pembelian_unit_id`).
- Audit log (`HasAuditLog`).

### 5.3 Penjualan (POS existing + cabang serial)
- Kasir scan/cari serial → `product_units` `status='tersedia'` **di gudang terminal** (filter `warehouse_id`; terminal.warehouse_id mengalir ke checkout via `data['warehouse_id']`).
- `CheckoutSalesAction` (cabang serial): `lockForUpdate` unit → assert `tersedia` & gudang cocok → set `terjual` + `sale_id`/`sale_detail_id`.
- `doc_sales_detail` baris serial **tetap isi kolom existing**: `unit`='UNIT', `konversi=1`, `qty=1`, `qty_base=1`, `product_unit_id`=unit, **`hpp_at_time = harga_modal`** (kolom COGS sudah ada, dipakai laporan laba).
- `inventory_stock` `qty -= 1` (jaga `StockCard::$skipObserver`) + `StockCard::record(SALES)` (qty_out=1, cost=modal, `product_unit_id`).
- **COGS = `harga_modal` unit** (lewat `hpp_at_time`, bukan avg_cost). **Cabang serial WAJIB skip `checkAndResetHppIfStockEmpty()`** & jangan overwrite `avg_cost` produk. Harga jual = `harga_jual` ± `diskon_5` manual. **Tanpa promo**.
- Idempotency reuse (§2D). Struk tampilkan **serial + garansi_sampai**.

### 5.4 Retur penjualan (v1) — cabang serial di `ProcessSalesReturnAction`
- Unit `terjual` → `tersedia`, kosongkan `sale_id`/`sale_detail_id`.
- `inventory_stock` `qty += 1` + `StockCard::record(SALES_RETURN)`.
- Refund via alur retur existing. **Tanpa rekalkulasi HPP** (§11 aman karena serial tak pakai avg).

---

## 6. Akuntansi & Integritas

| Aspek | Perlakuan serial |
|---|---|
| **HPP (§2B)** | Bypass weighted-average. Modal = per unit. COGS = modal unit terjual. |
| **Stock Card (§2C)** | Tetap 1 entry per gerakan unit (+`product_unit_id`, cost=modal). **Pakai `StockCard::$skipObserver`** saat ubah `inventory_stock.qty` (CLAUDE.md §7) agar Observer tak buat ADJUSTMENT ganda. |
| **inventory_stock** | `qty` = jumlah unit `tersedia` per (produk, gudang). |
| **Invariant (`data:verify`)** | Tambah: `COUNT(unit tersedia per produk,gudang) == inventory_stock.qty`; tiap `terjual` punya `sale_id`, tiap `tersedia` tidak. |
| **Audit** | `product_units` pakai `HasAuditLog` (barang mahal). |
| **Nomor dok (§7#4)** | Prefix **`PBE`** ditambah di `SettingService::getPrefix()` $defaults **dan** `getPrefixesWithInfo()` (UI Settings) — **bukan** `formatCode` (itu hanya uppercase). |
| **Date-range filter** | Tabel transaksi baru `use App\Traits\HasDateRangeScope` (konsisten dgn yang lain). |

---

## 7. Barcode (reuse `useBarcodePrint`)

- Engine **CODE128 sudah ada** → serial alfanumerik langsung jalan.
- Penyesuaian: (1) nilai barcode = `serial_number`; (2) **1 label per unit** (tiap unit = 1 item `qty:1`); (3) **template label unit** (Model + Serial + Grade + Harga, buang baris "satuan(konversi)"); (4) pemicu cetak baru di **Pembelian Elektronik** (cetak semua unit pasca-approve) & **Stok Unit** (unit terpilih).
- Retail `PrintBarcodePage` lama tetap apa adanya.

---

## 8. Laporan

- **Aging stok unit** — lama unit `tersedia` (now − tanggal intake) → modal nyangkut.
- **Laba per unit** — `harga_jual_final − harga_modal` untuk unit `terjual`.
- **Modal terikat** — Σ `harga_modal` unit `tersedia`.
- **Laba kotor & penjualan** — laporan existing **otomatis benar TANPA cabang serial**: COGS dibaca dari `doc_sales_detail.hpp_at_time` yang sudah diisi = modal unit saat checkout (qty unit = 1).

---

## 9. Multi-gudang (scoping)

| Fitur | Versi |
|---|---|
| `warehouse_id` di unit, intake assign gudang, stok & invariant per gudang | **v1** |
| POS jual hanya dari gudang terminal (filter `warehouse_id`) | **v1 (wajib)** |
| Transfer unit antar gudang (pindah unit spesifik + stock_card TRANSFER) | **v1.5** |
| Opname serial per gudang (scan vs sistem) | **v2** |

---

## 10. Yang TIDAK berlaku untuk serial (retail-only)

HPP correction & reset · Repack · Price-change batch (harga serial per unit, set manual) · Promo/diskon otomatis (serial: diskon manual `diskon_5` saja).

---

## 11. Permission (§0.6) + UserSeeder

Tambah & daftarkan di `UserSeeder`:
```
produk.*               (reuse — produk elektronik di master_produk yang sama)
pembelian-unit.view / create / approve / delete
unit.view / set_harga          (modal di-gate izin EXISTING stok.view_hpp, BUKAN permission baru)
unit-transfer.*        (v1.5)
```

---

## 12. Perubahan per File

### Backend
- **Migrations:** alter `master_produk` (+tracking_type, spec); create `product_units`, `doc_pembelian_unit`; alter `doc_sales_detail` (+product_unit_id), `stock_card` (+product_unit_id), `supplier_hutang` (+pembelian_unit_id); seed setting `store_type`.
- **Models:** `MasterProduk` (+casts/fillable/scope serial); baru `ProductUnit`, `DocPembelianUnit` (`use HasDateRangeScope`, `HasAuditLog`, `HasUlid`); relasi di `DocSalesDetail`, `StockCard`, `SupplierHutang`.
- **Actions:** baru `CreatePembelianUnitAction`, `ApprovePembelianUnitAction`; modif `CheckoutSalesAction` (cabang serial), `ProcessSalesReturnAction` (cabang serial).
- **Services:** `PurchaseUnitCalculationService` (subtotal Σ unit + modal rata); `SettingService` prefix PBE di `getPrefix()` $defaults + `getPrefixesWithInfo()`.
- **Controllers:** `PembelianUnitController`, `ProductUnitController` (stok unit + set harga **bulk** + cetak label), report `UnitAgingReport`/`UnitProfitReport`/`SupplierUnitPerformance`; checkout terima `product_unit_id`. **Dashboard** varian elektronik (gated `store_type`).
- **Import:** entity `product_unit` di `ImportController` (stok awal, §22). **VerifyDataInvariants:** check serial baru. **UserSeeder:** permission baru.

### Frontend
- **Master produk form:** toggle `tracking_type` + field spec saat serial.
- **Baru:** `PembelianUnitFormPage.vue` (sub-form per unit) + `PembelianUnitPage.vue` (list); `StokUnitPage.vue` (list + set harga + cetak label).
- **POS** (`PosKasirPage`): picker unit (scan serial, filter gudang).
- **Composable:** `useBarcodePrint` (template label unit + entry points); `useReceiptPdf`/`useReceiptEscPos` (serial + garansi di struk).
- **Router + menu:** gated `store_type` (=='elektronik'); akses `business.*` via Pinia settings store.
- **Reports:** halaman aging & laba unit.
- Reuse: `useFormatters`, `useNotification`, `useMasterCrud`/`useTransactionList`, `DataTableHeader`, `DetailDialog`, `ImageUpload`.

---

## 13. Titik Test (§9 wajib)

- Intake draft → approve: generate unit `tersedia` + **modal rata benar** (Σ modal = grand_total).
- Checkout serial: unit `terjual` + **COGS = modal** + qty−1 + stock_card match.
- **Tak bisa jual unit dari gudang lain**; **tak bisa jual unit sama 2×** (lock).
- Retur: unit balik `tersedia` + qty+1 + stock_card.
- **Invariant** stok konsisten (serial check).
- **Serial berulang**: unit serial yang sudah `terjual` boleh dibeli lagi (record baru); ditolak bila serial masih `tersedia`.
- Bypass: produk serial tidak masuk recalkulasi avg_cost.

---

## 14. Scoping Rilis

- **v1 (launch):** mode dua-lapis · Master & Pembelian Elektronik · `product_units` · modal rata · POS jual (filter gudang, diskon manual, serial+garansi di struk) · retur unit · barcode unit · stok & laporan per gudang · aging & laba unit · invariant.
- **v1.5:** transfer unit antar gudang.
- **v2:** opname serial · **tukar-tambah (trade-in)** · (opsional) garansi+klaim, kartu garansi terpisah, retur pembelian ke supplier, booking/DP.

---

## 15. Default Item Minor (tak perlu keputusan ulang)

Tukar unit = retur + jual · buy-back = beli perorangan biasa · opsi Grade/Battery/Kelengkapan = daftar tetap (bisa di-master-kan nanti) · edit/hapus unit hanya saat `tersedia` · jual rugi (di bawah modal) diizinkan · pajak intake = default `tax.tax_purchase_*` (termasuk perorangan, lihat §3.5) · concurrency = `lockForUpdate` unit saat checkout.

---

## 16. Risiko & Catatan

- **Keselamatan toko retail live:** semua ter-gate `tracking_type`/`store_type`; instance retail (`store_type=retail_grosir`) berperilaku persis seperti sekarang. Jaring pengaman: suite test existing + test serial baru.
- **DRY (§0.4):** pisah **menu**, bukan pisah **logika bersama** — `SettingService` (nomor dok), master Supplier, integrasi Hutang, primitif biaya/pajak, composable & komponen FE **di-reuse**, bukan dicopy.
- **Bukan fork:** satu codebase, instance kedua untuk bisnis elektronik (DB sendiri).

---

## 17. Catatan Verifikasi Codebase (cek vs kode aktual)

Diverifikasi terhadap codebase (**greenfield** — 0 match `serial`/`tracking_type`/`product_unit`/`imei`). Koreksi yang sudah diserap dokumen ini:

- **K1 — COGS via `hpp_at_time`:** `doc_sales_detail.hpp_at_time` sudah menyimpan COGS per baris saat checkout (`CheckoutSalesAction:226`). Serial cukup mengisinya = modal → **laporan laba kotor tak perlu cabang serial**.
- **K2 — Bypass HPP:** `recalculateAvgCost` dipanggil eksplisit di `ApprovePurchaseOrderAction:83`; checkout memanggil `checkAndResetHppIfStockEmpty()` (`CheckoutSalesAction:263`) — cabang serial **wajib skip** & tak menyentuh `avg_cost`.
- **K3 — Observer skip:** `inventory_stock` punya Observer auto-stock_card; pakai `StockCard::$skipObserver` (pola `CheckoutSalesAction:271`).
- **K4 — `supplier_hutang.po_id` NOT NULL:** harus di-**ALTER nullable** saat menambah `pembelian_unit_id`.
- **K5 — Prefix `PBE`:** daftar di `SettingService::getPrefix()` $defaults + `getPrefixesWithInfo()` (**bukan** `formatCode`).
- **K6 — Kolom baris serial:** isi `unit`/`konversi`/`qty`/`qty_base` (=1) selain `product_unit_id` & `hpp_at_time`.
- **K7 — Retur mencemari avg serial:** blok restore-HPP di `ProcessSalesReturnAction:182-189` menulis `product.avg_cost`=modal untuk produk serial (avg selalu 0). Cabang serial **wajib skip blok ini** (lihat §19.2).
- **Konfirmasi (akurat):** helper `validateStockAvailability`/`applyPromosToItems`/`buildNotaDiscounts` = private (bisa di-branch); routes `prefix('v1')→auth:sanctum→prefix(modul)`, **permission dicek di controller** via `->can()` (bukan middleware route); role `super-admin/admin/kasir/gudang` via `syncPermissions()` — §18 & §20 cocok.

**Infra terkonfirmasi siap:** `StockCard::record()`+`cost_per_unit`+`$skipObserver` · terminal `warehouse_id`→checkout · `settings`+`SettingService` · `AppMenu visible:can()`+Pinia settings store · `UserSeeder` · `ReportHelperService` · `HasDateRangeScope`.

---

## 18. Kontrak API

Semua via `BaseApiController` (`{success, data, message}`), routing pakai `ulid`, prefix `/api/v1`.

### 18.1 Pembelian Unit (intake)
```
POST   /pembelian-unit                  buat draft          (perm pembelian-unit.create)
PUT    /pembelian-unit/{ulid}           edit draft
POST   /pembelian-unit/{ulid}/approve   approve             (perm pembelian-unit.approve)
DELETE /pembelian-unit/{ulid}           hapus draft         (perm pembelian-unit.delete)
GET    /pembelian-unit                  list (date-range)   (perm pembelian-unit.view)
GET    /pembelian-unit/{ulid}           detail + units
POST   /pembelian-unit/calculate        preview modal rata (tanpa simpan)
```
Request (create/edit/calculate):
```json
{
  "tanggal": "2026-06-11 14:30:00",
  "sumber": "supplier",                     // supplier | perorangan
  "supplier_id": 12,                        // required_if sumber=supplier
  "penjual_nama": null, "penjual_hp": null, // required_if sumber=perorangan
  "warehouse_id": 3,
  "biaya_kirim_tipe": "nominal", "biaya_kirim_nilai": 150000,
  "biaya_lain_nama": null, "biaya_lain_tipe": "nominal", "biaya_lain_nilai": 100000,
  "pajak_persen": 0, "tempo_hari": 0, "notes": null,
  "units": [{
    "product_id": 88, "serial_number": "C02XL0AAGH91",
    "grade": "A", "battery_health": 92, "battery_cycle": 142,
    "battery_condition": "Normal", "activation_lock": "aman",
    "kelengkapan": {"charger": true, "box": true, "nota": false},
    "keterangan": "lecet tipis", "harga_beli": 8500000, "garansi_sampai": null
  }]
}
```
Validasi kunci: `units` required|min:1; `units.*.serial_number` required + **unik di antara `product_units` status draft/tersedia** (rule custom); `units.*.harga_beli` required|numeric|min:0.
Response approve → `{ pembelian_unit:{...}, units:[{ulid, serial_number, harga_modal, status:"tersedia"}] }`.

### 18.2 Stok Unit / ProductUnit
```
GET   /units                    list (filter status,warehouse_id,product_id,grade,search)  (unit.view)
GET   /units/{ulid}             detail
PATCH /units/{ulid}/harga-jual  {harga_jual}                            (unit.set_harga)
POST  /units/harga-jual/bulk    {items:[{ulid,harga_jual}]}             (unit.set_harga)
GET   /units/available          ?product_id=&warehouse_id= → 'tersedia' & harga_jual!=null (POS)
GET   /units/by-serial/{serial} ?warehouse_id= → resolve scan (404 bila terjual/beda gudang/harga null)
POST  /units/print-labels       {ulids:[]} → data label barcode
```
`harga_modal` di response hanya muncul bila `stok.view_hpp`.

### 18.3 POS Checkout — perluasan payload
`PosController::checkout` validation **tambah**: `'items.*.product_unit_id' => 'nullable|integer|exists:product_units,id'`.
Baris serial dari FE: `product_unit_id` terisi, `unit`="UNIT", `konversi`=1, `qty`=1, `qty_base`=1, `harga_satuan`=`harga_jual` unit, `diskon_1..4`="none" (promo tak berlaku), `diskon_5` opsional.

### 18.4 Retur Penjualan — serial
`SalesReturnController` validation tambah `items.*.product_unit_id` nullable (di-resolve dari `sales_detail_id` bila serial). qty serial selalu 1.

### 18.5 Laporan unit
```
GET /reports/unit/aging   ?warehouse_id=&grade=  → unit tersedia + lama_hari + modal   (laporan.view)
GET /reports/unit/profit  ?date_from=&date_to=   → unit terjual + (harga_jual_final − harga_modal)
```

---

## 19. Sub-desain Checkout / Retur Serial + UX Cart POS

Acuan: [CheckoutSalesAction.php](../../app/Actions/Sales/CheckoutSalesAction.php) (alur penuh terverifikasi), [usePosCart.js](../../../sipos-frontend/src/composables/usePosCart.js).

### 19.1 `CheckoutSalesAction` — titik sisip cabang serial
1. **Lock (≈:58-69):** selain lock `inventory_stock` per (product,gudang), **tambah** `ProductUnit::whereIn('id',$serialUnitIds)->lockForUpdate()->get()->keyBy('id')` (cegah double-sell §2E).
2. **`validateStockAvailability` (:72):** baris serial **tak** dicek qty; validasi `status='tersedia'` & `warehouse_id==$warehouseId` & `harga_jual` not null. Baris qty → existing.
3. **`applyPromosToItems` (:76):** **skip baris serial** (punya `product_unit_id`) — slot 1-4 tetap 'none'; `diskon_5` manual tetap diproses di loop processedItems (:80-103, tanpa ubahan).
4. **Loop detail+stok (:194-269) — branch per item:**
   - **Serial:** `$hpp=$unit->harga_modal`; `DocSalesDetail::create([... 'unit'=>'UNIT','konversi'=>1,'qty'=>1,'qty_base'=>1,'product_unit_id'=>$unit->id,'hpp_at_time'=>$hpp])`; `inventory_stock.qty -= 1` + `StockCard(SALES)` cost=$hpp + `product_unit_id`; `$unit->update(['status'=>'terjual','sale_id'=>$sales->id,'sale_detail_id'=>$detail->id])`; **JANGAN** `checkAndResetHppIfStockEmpty()`.
   - **Qty:** logika existing (:194-268) utuh. `runningStocks` (:186) per product_id tetap valid (serial −1/unit).
5. `StockCard::$skipObserver` (:184/:271) tetap membungkus seluruh loop.

### 19.2 `ProcessSalesReturnAction` — cabang serial
Item di-resolve unit via `sale_detail.product_unit_id`. Bila terisi (serial):
- **SKIP blok restore-HPP** ([:182-189](../../app/Actions/Sales/ProcessSalesReturnAction.php)) — blok `if (avg_cost==0 && hpp_sales>0) { product.avg_cost=modal; syncAvgCostToInventoryStocks(); }` untuk produk serial (avg selalu 0) **akan mencemari** avg_cost & semua inventory_stock dgn modal 1 unit. Serial: **jangan sentuh avg_cost**.
- `$unit->update(['status'=>'tersedia','sale_id'=>null,'sale_detail_id'=>null])`.
- `inventory_stock.qty += 1` + `StockCard(SALES_RETURN)` cost=`harga_modal` + `product_unit_id`. `DocSalesReturnDetail` isi `unit`='UNIT', `konversi`=1, `qty`=1, `hpp_at_time`=`harga_modal`.
- Retur = operasi POS dalam shift (butuh `shift_id`/`terminal_id`); refund **tunai** via `PosCashTransaction` (existing). Baris qty → existing.

### 19.3 UX Cart POS (`usePosCart`)
Cart existing me-merge per (product+unit) & naikkan qty ([:337-352](../../../sipos-frontend/src/composables/usePosCart.js)). Serial **berbeda**:
- **`addSerialUnit(unit)`** baru → push **baris distinct** `{product_id, product_unit_id, serial_number, unit:'UNIT', konversi:1, qty:1, harga_satuan:unit.harga_jual, diskon_*:'none', _serial:true}`. **Tak pernah merge.**
- **Scan serial** → `GET /units/by-serial/{serial}?warehouse_id` → valid → `addSerialUnit`; tidak → `notify.warn`.
- **Cari unit (sales-floor)** *(v1)*: panel filter chip/RAM/storage/grade/rentang-harga/battery → daftar unit `tersedia` di gudang terminal (`/units/available` +filter) → pilih → `addSerialUnit`. Untuk pelanggan yang tak tahu serial.
- **Qty terkunci 1:** guard `if (item._serial) return` di `updateQty`/`changeUnit`.
- **Blokir harga null:** endpoint `available`/`by-serial` hanya kembalikan unit ber-harga.
- **Multi-unit model sama** = beberapa baris (natural).
- **Checkout payload (:710-726):** map tambahkan `product_unit_id: i.product_unit_id ?? null`.
- **`getMaxQty` (:133-139):** `if (item._serial) return null`.

---

## 20. Detail implementasi yang dikunci (menutup sisa gap)

- **Lifecycle draft intake:** edit draft = **hapus semua `product_units` draft dokumen → buat ulang** dari payload (hindari diff). Hapus dokumen draft → cascade hapus unit draft. Approve mengunci.
- **Pembulatan modal:** sisa (`grand_total − Σ modal`) dilekatkan ke **unit terakhir** (index array input).
- **`harga_jual` gate:** unit tanpa harga **tak muncul** di `/units/available` & ditolak `/units/by-serial` → POS otomatis tak bisa menjualnya.
- **Permission→role (`UserSeeder`):** super-admin/admin = semua `pembelian-unit.*`,`unit.*`. gudang = `pembelian-unit.*` + `unit.view`/`set_harga`. kasir = `unit.view` + jual via `pos.access` (BUKAN `pos.checkout` — tak ada; checkout di-gate `pos.access`, lihat `PosController:251`). **Modal/laba di-gate izin EXISTING `stok.view_hpp`** (admin punya; kasir/gudang tidak) — tanpa permission baru.
- **Factory/fixture test:** `MasterProdukFactory` + `tracking_type='serial'`; `ProductUnit`/`DocPembelianUnit` via `::create()` manual (CLAUDE.md §4).
- **Opsi master (v1 = daftar tetap):** Grade `['A','B','C','D']`; Battery Condition `['Normal','Service']`; Kelengkapan `['charger','box','nota']`. Jadi master/setting di v2.
- **Template label barcode:** varian `drawLabel` unit → Nama Model · Serial (CODE128+teks) · Grade · Harga Jual (buang baris satuan/konversi).

---

## 21. Edge Cases & Koreksi

### 21.1 Edit pasca-approve (kebijakan)
- **Field deskriptif** (serial, grade, battery_*, activation_lock, kelengkapan, keterangan, garansi, **harga_jual**): **boleh edit inline** di halaman Stok Unit **selama `tersedia`**, ber-`HasAuditLog`; serial di-re-cek unik (antar unit aktif). → "salah serial/kondisi" cukup di sini.
- **Field finansial/struktural** (harga_beli/modal, jumlah unit, supplier, gudang, biaya/pajak): **tidak inline**. Koreksi via **Cancel dokumen intake** — **hanya bila belum ada unit terjual** → reverse: void unit, `inventory_stock.qty -= n` + stock_card pembalik (`ADJUSTMENT_OUT`/void), batalkan hutang → buat ulang yang benar.
- **Unit `terjual` = TERKUNCI total** (tak ada edit, termasuk serial). Koreksi serial unit terjual ditangani di luar sistem.

### 21.2 Reversal penjualan — Retur & VOID (keduanya restore unit serial)
- **Retur** (`ProcessSalesReturnAction`): lihat §19.2.
- **VOID** (`VoidSalesAction`) — **wajib ada cabang serial**: batalkan nota utuh → tiap baris serial: unit `terjual`→`tersedia`, kosongkan `sale_id`/`sale_detail_id`, `inventory_stock.qty += 1` + `StockCard` pembalik + `product_unit_id`. **Jangan sentuh `avg_cost`** (sama seperti K7).

### 21.3 Keunikan serial — dicek di DUA titik
Tak boleh ada unit `status IN (draft, tersedia)` dengan serial sama, divalidasi saat **(a) simpan draft** intake **dan (b) approve** (ulang — draft bisa basi: serial sempat dipakai intake lain yang approve duluan).

### 21.4 Serial berulang / buy-back
- **Normal: tidak bermasalah.** Unit lama `terjual` (tak aktif) → pembelian baru serial sama **diizinkan** (record unit baru); histori per serial = beberapa record. Lookup `by-serial` POS ambil yang **`tersedia`**.
- **Edge langka:** retur/void nota lama "menghidupkan" serial yang sudah di-buy-back → **2 unit aktif serial sama** (fisik mustahil ⇒ kemungkinan salah-input). Penanganan: **jangan blokir retur**, tapi **tandai untuk review** (`data:verify` + notifikasi). *[default: flag; bisa diubah ke block]*

### 21.5 POS guard
- **Activation Lock `terkunci`:** POS beri **peringatan konfirmasi** saat add/checkout (boleh lanjut bila kasir konfirmasi).
- **Harga null / beda gudang / `terjual`:** `by-serial` menolak.
- **Race / hold:** `lockForUpdate` saat checkout; keduluan → gagal halus "unit sudah terjual".

### 21.6 Tunda (v2)
Unit hilang/dicuri → write-off (opname) · garansi lewat → informatif · reprint label pasca-koreksi serial → operasional.

---

## 22. Stok Awal & Go-live

Toko sudah punya unit fisik saat mulai pakai sistem → masuk sebagai unit `tersedia` **tanpa pembelian baru**.

- **Dua jalur (v1):**
  1. **Bulk import** (Excel/CSV) — **reuse infra `ImportController`** (upload/template/validasi + mekanisme `lookups` kode_produk→product_id). ⚠️ **Handler per-baris CUSTOM**, bukan upsert-by-kode generik: **insert unit baru** + tulis `stock_card ADJUSTMENT_IN` + `inventory_stock.qty` + status `tersedia`. Izin: `pembelian-unit.create`.
  2. **Intake manual** `sumber='saldo_awal'` (form Pembelian Unit) — untuk sedikit unit / tambahan.
- **Output keduanya:** unit `tersedia` + `inventory_stock.qty` + **`stock_card ADJUSTMENT_IN`** (note "Saldo Awal") + **tanpa hutang**.
- **Bukan via modul Adjustment qty / HPP Correction** — keduanya qty-only; serial **tak punya `avg_cost`**, modal **per unit**. `ADJUSTMENT_IN` di sini hanya **label ledger**, bukan dokumen Adjustment.
- **Katalog model dulu:** `master_produk` (serial+spec) **pre-create** (via import produk existing/manual). Unit **reference model by `kode_produk`** — **jangan** auto-buat model dari baris unit (jaga konsistensi).
- **Kolom template import unit:** `kode_produk` (model), `serial_number`, `grade`, `battery_health`, `battery_cycle`, `battery_condition`, `activation_lock`, `kelengkapan`, `modal`, `harga_jual`, `warehouse`, `garansi_sampai`.
- **Validasi import:** serial unik (antar unit aktif), model wajib ada (by kode), enum (grade/battery_condition/activation_lock) valid, `modal` required.

## 23. Laporan & Dashboard (mode elektronik)

**4 laporan inti** (reuse `ReportHelperService`):
| Laporan | Isi |
|---|---|
| **Modal Terikat / Stok Unit** | Σ unit `tersedia` + Σ `modal` per gudang/model/grade (uang nyangkut) |
| **Aging Stok** | Unit per bucket umur (0-30/31-60/61-90/>90 hr `tersedia`) + modal berisiko |
| **Laba per Unit** | Unit `terjual`: `harga_jual_final − modal`, per periode/model/grade/supplier |
| **Performa Supplier/Model** | Unit beli/jual, avg margin, avg hari-sampai-terjual, return rate |

- **Laba per unit = `(jumlah baris − alokasi diskon nota proporsional) − modal`** → diskon nota **di-prorate** ke unit agar presisi walau 1 nota banyak unit.
- **Data cukup** (umur = tgl intake→jual; grade/supplier joinable). Laporan **Laba Kotor existing** sudah benar via `hpp_at_time` (K1).
- **Dashboard:** saat `store_type='elektronik'`, **ganti widget retail** (omzet/qty) → **unit tersedia (count+modal)** · **terjual hari/bulan (count+laba)** · **alert aging** (>N hari) · **top model by laba**.

## 24. Halaman Stok Unit & Operasi Massal (UI)

Hub harian (reuse `useTransactionList`/`useMasterCrud`, `DataTable`, `DetailDialog`, `useBarcodePrint` — **tanpa skema baru**):
- **List + filter:** status, gudang, model, grade, umur, rentang harga, cari serial/model.
- **Kolom:** serial · model · grade · battery · **modal** (gated `stok.view_hpp`) · harga_jual · umur · status.
- **Operasi massal** (nilai utama): pilih banyak unit → **Set Harga** (nominal sama / **margin %**) + **Cetak Label** sekaligus.
- **Aksi baris:** Detail+Riwayat+Audit · **Edit kondisi** (serial/grade/dll, hanya saat `tersedia`, §21.1) · Set Harga · Label.
- **Detail dialog:** kondisi penuh + info intake (supplier/tgl/modal) + info jual (bila terjual) + audit trail + riwayat serial (bila berulang).
