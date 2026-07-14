# Modul Serial (A+) — Pelacakan Per Nomor Seri

> **Status: TERIMPLEMENTASI** (Fase 1, Lapis A, Fase 2). Fase 3–5 = roadmap.
> Pendekatan **A+**: produk tetap NORMAL + register `serial_units` ringan + intake draft→approved.
> Menggantikan blueprint lama [`elektronik-serialized.md`](elektronik-serialized.md) (modul "serialized" berat — **ditolak**, tidak dibangun).

---

## 1. Konsep Inti

Untuk barang ber-nomor-seri (mis. MacBook/HP second), tiap unit unik: **SN, kondisi, modal (HPP), harga jual sendiri**. Tapi alih-alih mengganti seluruh mesin stok (seperti blueprint lama), modul ini **aditif**:

- **Identitas unik unit = `kode_internal`** (UNIQUE global, auto `KI-{id}`, boleh override). **SN boleh kembar** (bahkan dalam 1 produk). Lihat §3 + §4.10b (scan pintar).
- **Produk tetap normal** → semua menu existing (POS, opname, adjustment, transfer, perubahan harga) tetap jalan tanpa perubahan.
- **HPP produk tetap weighted-average** (perilaku existing). Modal asli per-unit disimpan terpisah di `serial_units` sebagai basis **laporan laba akurat** (Fase 5) — **bukan** dengan menimpa COGS penjualan.
- Penanda: kolom **`master_produk.is_serial`** (boolean). Saat serial, field qty/harga/stok di-scaffold (`UNIT`/`1`/`0`, barcode null).

---

## 2. Master Produk — toggle `is_serial`

- Checkbox **"Produk Serial"** di form. **Immutable** setelah create (`:disabled="isEdit"`).
- Saat dicentang: sembunyikan Barcode, Satuan & Harga, Stok. Backend auto-scaffold (`applySerialScaffolding`): `unit_*=UNIT`, `konversi_*=1`, `harga_*=0`, `minimum_stok=0`, `barcode=null`.
- Validasi dilonggarkan: serial cukup `kode_produk` + `nama_produk` wajib.
- File: [MasterProduk.php](../../app/Models/MasterProduk.php), [ProdukController.php](../../app/Http/Controllers/Api/V1/ProdukController.php), migrasi `*_add_is_serial_to_master_produk.php`.

### Lapis A — display/IO sadar-serial
| Fitur | Perilaku serial |
|---|---|
| List Produk | badge **SERIAL**; Barcode/Harga/Satuan → "—" |
| Detail + Download PDF | sembunyikan Satuan&Harga/Stok; tampilkan catatan "dilacak per unit" |
| Export PDF/Excel list | kolom "Jenis" (Retail/Serial); field qty/harga dikosongkan utk serial |
| Import produk | kolom **"Serial" (Ya/Tidak)**; baris serial auto-scaffold, wajib cuma kode+nama |

---

## 3. Data Layer

### `serial_units` — register per unit fisik
`product_id, warehouse_id, intake_id, serial_number, kode_internal (UNIK global), harga_modal (kotor), cost_per_unit (landed), harga_jual?, status, sale_id?, sale_detail_id?, sold_at?, catatan, + atribut kondisi, softDeletes`

- **status**: `pending` (draft intake) → `tersedia` (approved, bisa dijual) → `terjual` / `rusak`.
- **`harga_modal`** = harga beli kotor (input); **`cost_per_unit`** = landed cost (modal + alokasi diskon/biaya/pajak header) → dipakai HPP weighted-avg saat approve & basis laporan laba Fase 5.
- **Identitas unit = `kode_internal`** — UNIQUE **global** (index DB), auto-generate `KI-{id}` saat kosong (hook model `created`), **boleh override** (divalidasi unik, `withTrashed`). Migrasi `2026_06_17_100001` + backfill `KI-{id}`. **Override berpola `KI-<angka>` ditolak** (dicadangkan untuk auto-generate → cegah tabrakan dgn kode auto unit lain di masa depan), validasi di [HandlesSerialUnits](../../app/Actions/SerialIntake/Concerns/HandlesSerialUnits.php).
  - **Form Pembelian Serial:** kode_internal **WAJIB** & jadi kolom #1 (sebelum SN); tombol **Generate per-baris** ambil nomor lanjutan via `GET /serial-units/peek-kode` (KI-####### tertinggi +1, pertimbangkan kode yang sudah ada di form) — tetap bisa diketik manual. Backend tetap terima kosong (hook jaring pengaman) agar kontrak API/test tak rusak.
  - **Tampil di semua surface:** detail+PDF Pembelian/Perubahan/Koreksi-HPP **+ Adjustment/Transfer/Retur-Beli/Opname** (detail dokumen me-resolve `serial_unit_ids` → unit via trait [AttachesSerialUnitsToDocDetails](../../app/Http/Controllers/Concerns/AttachesSerialUnitsToDocDetails.php); Adjustment ikut tampil `fate` rusak/hilang), form unit, Register (+filter status rusak/hilang/retur/pending), label, nota POS/online/per-nota/closing, Excel Register + Excel Penjualan per Nota.
- **`serial_number` TIDAK unik** — boleh kembar, bahkan dalam 1 produk (SN ponsel sering kembar/typo dari supplier). Semua transaksi memilih unit via **`ulid`** (bukan SN), jadi aman. SN hanya info/cari.
- **Atribut kondisi (elektronik bekas):** `grade` (A–F), `battery_condition` (Original/Replacement/Service Center/Refurbished), `battery_health` decimal(5,2) %, `account_status` (locked/unlocked).
- Model: [SerialUnit.php](../../app/Models/SerialUnit.php). Scope: `tersedia()`, `terjual()`, `byProduct()`.

### `doc_serial_intake` — header dokumen pembelian serial
`nomor_dokumen (PBS), tanggal, product_id, warehouse_id, supplier_id?, no_doc_referensi?, total_unit, total_modal, status, approved_at?, approved_by?, notes` + **finansial** (subtotal, diskon 1–3, biaya kirim/lain, dpp, pajak, pembulatan, grand_total, tempo_hari, tanggal_jatuh_tempo) — sama seperti Purchase Order.

- 1 intake = **1 produk serial** + N unit. Status: `draft` → `approved` (atau `cancelled`).
- Model: [DocSerialIntake.php](../../app/Models/DocSerialIntake.php). Prefix `PBS` di `SettingService::getPrefix()` + `getPrefixesWithInfo()`.

---

## 4. Alur Pembelian Serial — draft → approved

Konsisten dengan Purchase Order (gudang buat draft, admin approve). **Segregation of duties**: yang input tidak commit stok.

```
CREATE/EDIT (gudang)              APPROVE (admin)
─────────────────────            ──────────────────────────────────────────
status = draft                   status = approved
unit status = pending            unit status = tersedia
TIDAK sentuh stok/HPP            inventory_stock.qty += N   (lockForUpdate)
                                 avg_cost recalc weighted-avg (modal rata batch)
                                 stock_card PURCHASE  (pola StockCard::$skipObserver)
                                 approved_at / approved_by terisi
```

- **HPP weighted-average** dihitung dari **landed cost** `sum(unit.cost_per_unit)/N` → `MasterProduk::recalculateAvgCost(N, avgBatchCost)` (pola persis [ApprovePurchaseOrderAction](../../app/Actions/PurchaseOrder/ApprovePurchaseOrderAction.php)).
- **Invariant** `SUM(stock_card.qty_in-qty_out) per (produk,gudang) == inventory_stock.qty` tetap terjaga (unit `pending` tak menyumbang stok).
- **Edit/Hapus hanya saat draft.** **Approve anti-balapan:** header di-`lockForUpdate()` + cek ulang `isDraft()` **di dalam** transaksi → dua request approve paralel (double-click/retry) → yang kedua ditolak (tak ada dobel stok/hutang/pelunasan). Pola sama di [ApprovePurchaseOrderAction](../../app/Actions/PurchaseOrder/ApprovePurchaseOrderAction.php). Delete draft → `forceDelete` unit pending.
- Actions: [CreateSerialIntakeAction](../../app/Actions/SerialIntake/CreateSerialIntakeAction.php) · [ApproveSerialIntakeAction](../../app/Actions/SerialIntake/ApproveSerialIntakeAction.php) · [UpdateSerialIntakeAction](../../app/Actions/SerialIntake/UpdateSerialIntakeAction.php) · trait [HandlesSerialUnits](../../app/Actions/SerialIntake/Concerns/HandlesSerialUnits.php).
- API: `GET/POST /api/v1/serial-intakes`, `GET/PUT/DELETE /{ulid}`, `POST /{ulid}/approve`.

### Frontend
- **Input Pembelian Serial** (menu Inventory): form pilih produk serial (filter `is_serial`) + gudang + supplier opsional; tabel unit baris-per-baris (SN + modal + jual + grade/baterai/health/akun). `dataKey="_uid"` (key stabil, cegah input kehilangan fokus). Mode **edit** untuk draft.
- **List** pakai `useTransactionList` (persis Purchase Order): filter Supplier/Gudang/Status/Tanggal + search + Aksi (Lihat/PDF/Edit/Hapus/Approve sesuai status & permission).
- File: [SerialIntakePage.vue](../../../syilex-frontend/src/views/inventory/SerialIntakePage.vue), [SerialIntakeFormPage.vue](../../../syilex-frontend/src/views/inventory/SerialIntakeFormPage.vue).

---

## 4.5 Finansial — penuh seperti Purchase Order

Header intake punya **Diskon Header (3 line)**, **Biaya Tambahan** (kirim + lain), **Pajak (DPP/PPN)**, **Pembulatan**, **Grand Total**, **Tempo**, **Catatan** — identik dengan PO karena **memakai ulang** [`PurchaseOrderCalculationService`](../../app/Services/PurchaseOrderCalculationService.php) (DRY): tiap unit dipetakan jadi 1 "detail" (qty 1, harga = modal).

- **Alokasi ke HPP (landed cost):** biaya tambahan + pajak (bila `tax.purchase_included_in_hpp`) + pembulatan dialokasikan proporsional ke `cost_per_unit` tiap unit. **Diskon header TIDAK memengaruhi HPP** (persis perilaku PO). Saat approve, HPP = `sum(cost_per_unit)/N`.
- **Hutang supplier:** **supplier WAJIB** di PO Serial (tanpa supplier, hutang tak terbentuk → tak muncul di Hutang/Pelunasan). Saat **approve** (`grand_total > 0`) dibuat `SupplierHutang` bersumber **`serial_intake_id`** (kolom `po_id` dibuat nullable; `supplier_hutang` kini bisa rujuk PO **atau** serial intake). Tempo → `tanggal_jatuh_tempo`. Modul Hutang menampilkan **sumber (PO/Serial)** di list & detail.
- **Cash / "Lunas langsung" (opsi):** checkbox `cash_payment` + helper `cash_metode` (cash/transfer), `cash_no_referensi` (= bukti/kwitansi, **teks** bukan file), `cash_bank_nama`/`cash_bank_rekening` (untuk transfer). Saat approve, hutang **tetap dibuat** lalu **otomatis dilunasi penuh** (buat + complete `DocPembayaranHutang` sumber `cash`, nominal = sisa) **di transaksi yang sama** → hutang langsung `paid`. Validasi: `cash_metode` **wajib** bila cash dicentang (cegah fallback diam ke 'cash'); panjang `cash_*` dibatasi sesuai kolom `doc_pembayaran_hutang` (`no_referensi`/`bank_nama` ≤50, `bank_rekening` ≤30) supaya tak rollback saat settle. Trait DRY [SettlesCashPayment](../../app/Actions/Concerns/SettlesCashPayment.php) — dipakai **sama persis** di Purchase Order standar.
- **Ringkasan live di form:** endpoint **`POST /api/v1/serial-intakes/calculate`** (preview tanpa simpan, debounced) — backend yang menghitung (anti-tamper), bukan duplikasi JS. Backend tetap hitung ulang otoritatif saat simpan.
- Helper: trait [`HandlesSerialUnits`](../../app/Actions/SerialIntake/Concerns/HandlesSerialUnits.php) (`calculateFinance` + `financialColumns`).

> **Menu & isolasi:** "Purchase Order Serial" ada di grup **Pembelian**. **PO standar & Perubahan Harga standar menyembunyikan produk serial** (`is_serial=false` di dropdown produk) — produk serial dikelola lewat menu serial-nya sendiri. Atribut unit (grade/baterai/health/akun/harga jual) **wajib**. Badge **SERIAL/RETAIL** tampil di list produk, detail, PDF, Stok, Kartu Stok, Pergerakan HPP (badge SERIAL ber-tooltip "HPP rata-rata tertimbang").

---

## 4.6 Perubahan Data Serial — koreksi unit tersedia

Koreksi data unit serial yang **sudah TERSEDIA** (mis. salah input SN padahal pembelian sudah approve). Menu **"Perubahan Data Serial"** di grup **Master**. Alur draft → approved (gudang buat/edit/hapus draft, admin approve).

- **Field bisa dikoreksi:** `serial_number`, `harga_jual`, `grade`, `battery_condition`, `battery_health`, `account_status`, `catatan`.
- **`harga_modal` DIKECUALIKAN** (memengaruhi HPP). Modul ini **tak menyentuh stok/HPP**.
- **Hanya unit `tersedia`** yang bisa dikoreksi (unit terjual → SN tercetak di nota, terkunci).
- **SN tidak dicek unik** (boleh kembar; swap/duplikat SN antar unit diperbolehkan). `kode_internal` adalah identitas unik dan **tidak diubah** oleh modul ini.
- **Audit:** tiap detail simpan snapshot **`before` (JSON)** → ditampilkan **lama → baru** di detail dialog & Export PDF.
- **Form:** pilih produk serial → muat unit tersedia → **centang** yang dikoreksi + edit per-unit + **bulk "Harga Jual (semua)"**.
- Tabel `doc_serial_change` + `doc_serial_change_detail`. Prefix **PDS**. Actions [Create](../../app/Actions/SerialChange/CreateSerialChangeAction.php)/[Update](../../app/Actions/SerialChange/UpdateSerialChangeAction.php)/[Approve](../../app/Actions/SerialChange/ApproveSerialChangeAction.php) + trait [HandlesSerialChangeUnits](../../app/Actions/SerialChange/Concerns/HandlesSerialChangeUnits.php).
- API: `GET/POST /api/v1/serial-changes`, `GET /units?product_id=`, `GET/PUT/DELETE /{ulid}`, `POST /{ulid}/approve`.
- File FE: [SerialChangePage.vue](../../../syilex-frontend/src/views/master/SerialChangePage.vue) (list + detail + PDF), [SerialChangeFormPage.vue](../../../syilex-frontend/src/views/master/SerialChangeFormPage.vue).

---

## 4.7 Auditability — Register Unit Serial & jejak dokumen

Produk serial tetap NORMAL → di `inventory_stock`/`stock_card`/`avg_cost` semuanya **agregat + weighted-avg**. Detail per-unit (SN, modal riil, atribut) hidup di register `serial_units`. Agar tetap **bisa ditelusuri** (audit unit-level) tanpa mengubah model agregat:

- **Register Unit Serial** — menu grup **Inventory** (read-only). Daftar tiap unit fisik lintas produk: `kode_internal`, `serial_number`, `harga_modal`, `harga_jual`, atribut, **status** (tersedia/terjual), **asal dokumen** (intake), gudang, waktu terjual. Filter: produk / gudang / status / cari (kode internal / SN). Ringkasan **total / tersedia / terjual** (global, tak terpengaruh filter status). **Export PDF** daftar. Asal dokumen **bisa diklik → buka dokumen Pembelian Serial**.
  - API: `GET /api/v1/serial-units` (paginated + `summary`). Permission baca **reuse `serial-intake.view`**. **Kolom cost (`harga_modal` + `cost_per_unit`) di-gate `stok.view_hpp`** — user tanpa izin HPP tak menerima field cost (backend `makeHidden`, FE sembunyikan kolom + PDF); `harga_jual` tetap tampil. **Export Excel/PDF** ikut strip kolom cost untuk yang tak berizin.
  - File: [SerialUnitController.php](../../app/Http/Controllers/Api/V1/SerialUnitController.php), [SerialUnitRegisterPage.vue](../../../syilex-frontend/src/views/inventory/SerialUnitRegisterPage.vue), route `inventory-serial-units`.
- **Kartu Stok** — baris `PURCHASE` produk serial menyertakan `source_doc` (ulid intake) di response → No. Dokumen **bisa diklik buka dokumen PBS** (auto-open lewat query `?detail={ulid}` di SerialIntakePage). Lihat [StockCardController.php](../../app/Http/Controllers/Api/V1/StockCardController.php) (resolve `serialIntakeMap`).
- **Hutang Supplier** — hutang ber-sumber intake serial (`serial_intake_id`) kini tampil benar di **Export PDF & Excel**: kolom **"No. Dokumen"** (PO atau PBS) + kolom **"Sumber"** (PO/Serial). Sebelumnya kolom hard-code "No. PO" → kosong untuk serial. Lihat [SupplierHutangExport.php](../../app/Exports/SupplierHutangExport.php).

> **Catatan model agregat:** stok & HPP per produk tetap agregat by design. Modal riil per-unit dibaca dari register (bukan dari Kartu Stok/Pergerakan HPP yang menampilkan rata-rata batch). Ledger per-unit penuh di `stock_card` (FK `serial_unit_id`) **tidak** dibangun — kebutuhan audit sudah ditutup register + link dokumen.

---

## 4.8 Laporan Pembelian — pembelian serial ikut

Pembelian serial = pembelian nyata (stok + hutang) → WAJIB tercermin di laporan Pembelian. Sumber data terpadu [PurchaseReportSource](../../app/Services/PurchaseReportSource.php) meng-UNION `doc_purchase_order(_detail)` + `doc_serial_intake`/`serial_units` (dinormalkan). Branch PO **identik** query lama → data tanpa serial = hasil sebelumnya.

- **5 laporan ikut serial:** Per Dokumen, Per Supplier, Diskon, Per Barang, Harga Terakhir (on-screen + Export Excel + Export PDF).
- **Filter "Sumber": PO / Serial / Semua** (`?source=`, default `all`) via `ReportHelperService::resolveSource`. Per Dokumen/Diskon/Harga Terakhir punya kolom/badge **Sumber**.
- **Per Barang & Harga Terakhir** = level-baris: `serial_units` → 1 unit = qty 1, subtotal = `harga_modal`, cost = `cost_per_unit` (landed). Harga Terakhir ambil baris **terbaru per produk** via `ROW_NUMBER`.
- **Drill-down** (Per Supplier → dokumen, Per Barang → riwayat) ikut serial. Per Dokumen: baris serial **diklik → buka dokumen Pembelian Serial** (`showPo` hanya untuk PO).
- **Izin:** akses laporan pembelian = **`laporan.pembelian`** (per-kategori, lihat CLAUDE.md §Permission Check); nilai uang di-strip kecuali **`po.view_harga`**. **Laporan Diskon** isinya 100% nilai diskon → di-gate **penuh `po.view_harga`** (view + export 403 tanpa izin), menu Diskon disembunyikan tanpa `po.view_harga`.
- Test: [PurchaseReportSerialTest](../../tests/Feature/PurchaseReport/PurchaseReportSerialTest.php) (per laporan + filter source + export jalan, **+ Diskon wajib `po.view_harga`**).

### Laporan yang TIDAK perlu serial (terverifikasi)
- **Penjualan, Performa (Kasir/Metode/Top Customer), Promo & Diskon, Gross Profit** — basis `doc_sales*`; serial belum dijual (POS diparkir) → tak ada baris serial.
- **Arus Kas Harian** — basis kas penjualan + manual; pembelian (PO maupun serial) memang tak masuk cashflow (by design).
- ⚠️ **Margin per Barang** — pakai `master_produk.harga_4` (= **0** untuk produk serial, scaffold) + `avg_cost`. Produk serial muncul dengan **harga jual 0 → margin menyesatkan**. Harga jual riil ada per-unit di `serial_units.harga_jual`. **Dibiarkan** sampai modul jual serial live (Fase 5) — abaikan baris serial di laporan ini.
- ⚠️ **Dead Stock** — produk serial belum bisa dijual → `last_sold` NULL → selalu ter-flag "dead stock" bila `include_never_sold=true`. Mitigasi: pakai toggle `include_never_sold=false`.

## 4.9 Integrasi Pengaturan

Modul serial tersambung ke grup **Pengaturan**:
- **Role & Permission** — modul **Purchase Order Serial** (`serial-intake`, grup Pembelian), **Perubahan Data Serial** (`serial-change`) & **Koreksi HPP Serial** (`serial-hpp`) (grup Master) di editor role ([RoleController::permissions](../../app/Http/Controllers/Api/V1/RoleController.php)). Operasi inventory serial (Transfer/Adjustment/Opname/Retur) pakai permission modul existing (`transfer`/`adjustment`/`opname`/`retur-beli`) → sudah ada di editor.
- **Reset Database** — semua tabel serial ikut di `counts()` + reset **all/transaksi/produk/supplier** + case individual: `doc_serial_intake`, `serial_units`, `serial_unit_movements` (ledger), `doc_serial_change(_detail)`, `doc_serial_hpp_correction(_detail)`. Case individual: **`serial_intake`** (hapus `supplier_hutang` ber-`serial_intake_id` DULU krn FK), **`serial_change`**, **`serial_hpp_correction`** ([ResetController](../../app/Http/Controllers/Api/V1/ResetController.php)).
- **Global Settings** — prefix **PBS**, **PDS** & **HPS** di `SettingService::getPrefix`/`getPrefixesWithInfo` (label + table).
- **Toggle Modul Elektronik (on/off)** — setting `modules.elektronik_enabled` (boolean, **default true**) di **Pengaturan → tab "Modul"**. Retail selalu aktif (tanpa toggle). Detail lengkap di §4.14.
- **Import Master** — kolom `is_serial` + auto-scaffold produk serial ([ImportController](../../app/Http/Controllers/Api/V1/ImportController.php)). **Tidak membuat stok/unit** (kolom impor hanya `minimum_stok`, bukan stok awal → unit hanya lahir via Pembelian Serial, tak ada stok hantu). **`is_serial` IMMUTABLE pada upsert** — baris yang mencoba flip status serial produk existing ditolak (skipped + error) untuk cegah desync. Operasi inventory serial (transfer/adjustment/opname/retur) tidak lewat import.
- Test: `tests/Feature/Reset/ResetSerialTest.php`, `tests/Feature/Role/RoleSerialPermissionTest.php`.

## 4.10 Print Label Unit Serial

Cetak label barcode **per unit** (beda dari barcode produk biasa yang dibagi semua unit).
- **Barcode = `kode_internal`** (CODE128, UNIK → scan label = identitas pasti; SN tak dipakai sbg barcode karena boleh kembar). SN tetap **ditampilkan sebagai teks** di label. **1 unit = 1 label**.
- **Isi label** (spek dari pembelian): kode + nama (wrap maks 2 baris, sisanya `…`), **Grade · Baterai** (kondisi+health %), **Akun**, **SN** (teks), **Keterangan** (teks bebas opsional, dicetak **apa adanya** tanpa prefix), barcode (kode_internal), **Harga Jual** (bold), **No. PBS · tgl masuk**. ⚠️ Harga modal/HPP **tidak** dicetak.
- **Ukuran**: preset **Kecil 40×30 · Sedang 50×40 (default) · Besar 60×45 · Custom**; barcode otomatis mengecil bila nama 2 baris → tak pernah overflow.
- **Jumlah kolom**: **Otomatis** (isi sebanyak yang muat) atau paksa **1–6 kolom** (dibatasi jumlah yang muat agar tak overflow); baris/halaman tetap otomatis dari tinggi label.
- **Entry**: (1) **Register Unit Serial** — centang unit / semua sesuai filter → tombol **Cetak Label**; (2) **dokumen Pembelian Serial** — tombol **Print Label** (semua unit dokumen).
- File: [useSerialLabelPrint.js](../../../syilex-frontend/src/composables/useSerialLabelPrint.js) + [SerialLabelPrintDialog.vue](../../../syilex-frontend/src/components/common/SerialLabelPrintDialog.vue) (reuse `useBarcodePrint`). **Tanpa endpoint baru** — data dari `/serial-units` + `SerialIntakeController::show`.

## 4.10b Scan pintar (kode_internal → SN)

Karena SN boleh kembar, scan/ketik di POS & picker memakai **scan pintar** lewat `GET /serial-units/lookup?code=&warehouse_id=` ([SerialUnitController::lookup](../../app/Http/Controllers/Api/V1/SerialUnitController.php)):
1. cocokkan **`kode_internal`** (UNIK) dulu → langsung 1 unit (`matched_by=kode_internal`);
2. fallback **`serial_number`** → kalau **>1 unit sellable** di gudang ini = **ambigu** → respons `{ ambiguous:true, candidates[] }` → frontend buka **picker kandidat** (kasir pilih). Tepat 1 → langsung; 0 sellable → tampilkan unit + alasan tak bisa jual.

Label unit mencetak barcode `kode_internal`, jadi scan label = jalur (1) yang pasti. Param lama `serial_number` masih diterima sbg alias `code` (kompat). `SerialUnitPicker` & POS picker mencari/scan **kode_internal lebih dulu**, lalu SN.

---

## 4.11 Integrasi Inventory & Pembelian (pilih unit eksplisit)

Operasi yang memutasi stok produk serial kini **menyentuh `serial_units`** agar tak desync. Pemilihan unit **eksplisit per SN** (komponen reusable [SerialUnitPicker.vue](../../../syilex-frontend/src/components/common/SerialUnitPicker.vue), endpoint `GET /serial-units/available?product_id=&warehouse_id=`). **Picker menyembunyikan `harga_modal`/`cost_per_unit`** untuk operator tanpa `stok.view_hpp` (pilih unit cukup by SN/grade/status; cost bukan rahasia yang perlu operator). Retur Beli tetap benar: `harga_per_unit` dihitung **server-side** dari `unit.harga_modal` ([PreparesSerialReturnDetails](../../app/Actions/PurchaseReturn/Concerns/PreparesSerialReturnDetails.php)), tak bergantung nilai yang dikirim picker.

### Ledger & fondasi
- **`serial_unit_movements`** (paralel `stock_card`, level unit): tiap perpindahan/perubahan state unit dicatat saat approve/lock. Model [SerialUnitMovement](../../app/Models/SerialUnitMovement.php) + `record()`. Kolom: `serial_unit_id, doc_type, doc_id, doc_no, movement_type, from/to_warehouse_id, from/to_status, tanggal`.
- **Pilihan unit saat draft** disimpan JSON di tabel detail: `serial_unit_ids` (transfer/adjustment/retur detail) + `serial_unit_ids_present` (opname detail). Produk serial → `qty` diturunkan dari jumlah unit (input qty disabled).
- **Status unit baru:** `hilang` (opname), `retur` (retur beli). Kolom `status` string (tanpa migrasi enum). Validasi unit terpilih: trait [ResolvesSelectedUnits](../../app/Actions/Serial/Concerns/ResolvesSelectedUnits.php) (milik produk + di gudang sumber + `tersedia` + count==qty).
- **Aturan valuasi serial:** pergerakan stok serial dinilai pakai **`cost_per_unit` unit** (biaya riil), BUKAN `avg_cost` produk. `avg_cost` agregat **tidak** direkalkulasi pada OUT (§2B). Movement bersifat valuasi/jejak; invariant qty tetap.

### Per modul
- **Transfer** — unit terpilih pindah `warehouse_id` (status tetap `tersedia`), 2 movement (TRANSFER_OUT/IN). [ApproveTransferAction](../../app/Actions/Transfer/ApproveTransferAction.php).
- **Adjustment** — **keluar (kredit)**: pilih unit → status **per-unit pilihan user `rusak`/`hilang`** (default `rusak`; map JSON `serial_unit_statuses` di detail) + movement OUT, stock_card cost = `cost_per_unit`. Bila `source=opname` → semua `hilang` (abaikan map). **Masuk (debit) DILARANG** (unit lahir hanya via Pembelian Serial). [ApproveAdjustmentAction](../../app/Actions/Adjustment/ApproveAdjustmentAction.php).
- **Stock Opname** — checklist SN **hadir** (`serial_unit_ids_present`, default semua tercentang di form); SN tak hadir → selisih kurang → unit `hilang` via adjustment turunan (`source=opname`). Selisih **lebih** untuk serial **ditolak**. [ApproveStockOpnameAction](../../app/Actions/StockOpname/ApproveStockOpnameAction.php).
- **Retur Beli** — 1 baris per produk serial; **harga retur = rata-rata `harga_modal` unit** (subtotal = Σ modal = kredit supplier), valuasi stok keluar = rata-rata `cost_per_unit` (landed). Saat **lock**: unit → status `retur` + movement OUT. Trait [PreparesSerialReturnDetails](../../app/Actions/PurchaseReturn/Concerns/PreparesSerialReturnDetails.php), [LockPurchaseReturnAction](../../app/Actions/PurchaseReturn/LockPurchaseReturnAction.php).
- **Repack** — **guard-only**: produk serial ditolak di picker (`where('is_serial', false)`), tanpa handler.
- **Hapus Produk** — diblok bila masih punya `serial_units` (selain cek stok & stock_card).
- **UI:** form Transfer/Adjustment/Opname/Retur memunculkan picker sebagai **row-expansion** tepat di bawah baris produk serial (deteksi via flag `is_serial` di getProducts).

### Integritas
- `php artisan data:verify` invariant serial: `COUNT(serial_units 'tersedia' per produk+gudang) == inventory_stock.qty` + integritas terjual/sale_id. [VerifyDataInvariants](../../app/Console/Commands/VerifyDataInvariants.php).
- **Reversal:** dokumen inventory `draft → approved` terminal (tak ada cancel/void) → reversal-after-approve tak dibangun; ledger menyimpan transisi penuh untuk *future hook* `SerialUnitMovement::reverseFor()`.

## 4.12 Koreksi HPP Serial (per-unit)

Koreksi biaya pokok per unit (`harga_modal` & `cost_per_unit`) — **terpisah** dari Penyesuaian HPP agregat (yang meng-guard tolak produk serial). Menu **"Koreksi HPP Serial"** grup **Inventory** (route `inventory/serial-hpp`), prefix **HPS**, alur draft → approved (cermin Perubahan Data Serial).
- **Form input per komponen** (bukan landed manual): **Modal + Biaya Kirim + Biaya Lain** diisi user; **Pajak** dihitung otomatis dari setting pajak pembelian (PPN%, masuk HPP hanya bila `tax_purchase_included_in_hpp` aktif — bila tidak, kolom Pajak disabled + keterangan); **HPP/Landed = Modal+Biaya+Pajak** dihitung live & read-only (hilangkan kebingungan). Kolom **HPP/Landed Lama** (cost_per_unit sekarang, sudah termasuk modal) tampil sebagai acuan. Rincian (`biaya_kirim_baru`/`biaya_lain_baru`/`pajak_baru`) disimpan di detail untuk audit. Catatan: rincian biaya lama tak tersimpan per unit → saat koreksi, Biaya Kirim/Lain diisi ulang (Modal Baru pre-fill dari modal lama); bandingkan dgn kolom HPP/Landed Lama.
- Hanya unit `tersedia`; approve apply `harga_modal` (=Modal) & `cost_per_unit` (=Landed) ke unit + snapshot `before` + movement `HPP_SERIAL`.
- **Propagasi ke HPP agregat (AKTIF, Metode A):** setelah unit dikoreksi, `avg_cost` produk **direkalkulasi = rata-rata `cost_per_unit` SEMUA unit tersedia** (`Σ cost_per_unit ÷ jumlah tersedia`), `syncAvgCostToInventoryStocks()`, lalu catat `stock_card` tipe **`HPP_CORRECTION`** (avg lama → baru, qty 0, warehouse null) → **muncul di Pergerakan HPP** (konsisten dgn Koreksi HPP non-serial). Ini koreksi eksplisit (sah meski §2B membatasi recalc otomatis ke PURCHASE/ADJUSTMENT_IN).
- Tabel `doc_serial_hpp_correction(_detail)`. [Actions](../../app/Actions/SerialHppCorrection/) + [Controller](../../app/Http/Controllers/Api/V1/SerialHppCorrectionController.php). FE: [SerialHppCorrectionPage.vue](../../../syilex-frontend/src/views/master/SerialHppCorrectionPage.vue) + [Form](../../../syilex-frontend/src/views/master/SerialHppCorrectionFormPage.vue).

## 4.13 Dashboard
Pending-approval dashboard kini memuat **serial-intake, serial-change, serial-hpp** (hitung draft, hormati permission `*.approve`). [DashboardController](../../app/Http/Controllers/Api/V1/DashboardController.php). Saat **Modul Elektronik OFF** (§4.14) ketiganya dibuang dari `$approvalModules` → tak muncul di dashboard.

## 4.14 Toggle Modul Elektronik (on/off)

Seluruh modul serial dapat **dimatikan** untuk toko **retail-only**. Setting `modules.elektronik_enabled` (boolean, **default `true`**) di **Pengaturan → tab "Modul"**. **Retail selalu aktif** (tanpa toggle). Saat OFF, semua fitur serial disembunyikan & diblok; retail tetap berjalan normal.

- **Helper:** [`SettingService::isElektronikEnabled()`](../../app/Services/SettingService.php) — **default TRUE** bila baris setting belum ada (instalasi lama / lingkungan tes → fitur serial existing tetap jalan). Seed: migration `2026_06_19_100000_add_elektronik_module_setting` (idempotent) + [SettingSeeder](../../database/seeders/SettingSeeder.php).
- **Backend gate (saat OFF):**
  - Middleware [`feature.elektronik`](../../app/Http/Middleware/EnsureElektronikEnabled.php) (alias di `bootstrap/app.php`) → **403** di 4 grup route serial (`serial-intakes`, `serial-changes`, `serial-units`, `serial-hpp-corrections`).
  - [`ProdukController::store`](../../app/Http/Controllers/Api/V1/ProdukController.php) & [`ImportController`](../../app/Http/Controllers/Api/V1/ImportController.php) tolak `is_serial=true`.
  - [`DashboardController`](../../app/Http/Controllers/Api/V1/DashboardController.php) buang kartu approval serial.
- **Frontend gate (saat OFF):** getter `useSettingsStore().serialEnabled` (dari `publicSettings.modules`, default true) → sembunyikan 4 menu serial (AppMenu), guard route (`meta.requiresElektronik` → redirect dashboard), sembunyikan checkbox "Produk Serial" (ProdukPage), POS **skip scan-lookup serial** (cegah error tiap scan retail).
- **Lock disable:** modul **tak bisa dimatikan** selama masih ada produk/unit serial (cegah data yatim) — guard di `SettingController::updateGroup` (grup `modules`) + endpoint `GET settings/elektronik-lock` (`checkElektronikLock`). Toggle UI disabled + tampil jumlah data.
- **Setelah toggle**, `settingsStore.refresh()` dipanggil → menu/route serial update tanpa reload.
- **Test:** [ElektronikModuleTest](../../tests/Feature/Settings/ElektronikModuleTest.php) (default ON, OFF→403 4 endpoint, tolak produk serial, lock disable, `checkElektronikLock`, publicSettings ekspos `modules`).

---

## 5. Permission

| Permission | super-admin | admin | gudang | kasir |
|---|---|---|---|---|
| `serial-intake.view` | ✓ | ✓ | ✓ | — |
| `serial-intake.view_harga` | ✓ | ✓ | **✗** | — |
| `serial-intake.create/update/delete` | ✓ | ✓ | ✓ (draft) | — |
| `serial-intake.approve` | ✓ | ✓ | **✗** | — |
| `serial-change.view` | ✓ | ✓ | ✓ | — |
| `serial-change.create/update/delete` | ✓ | ✓ | ✓ (draft) | — |
| `serial-change.approve` | ✓ | ✓ | **✗** | — |
| `serial-hpp.view` | ✓ | ✓ | ✓ | — |
| `serial-hpp.create/update/delete` | ✓ | ✓ | ✓ (draft) | — |
| `serial-hpp.approve` | ✓ | ✓ | **✗** | — |

**Lihat harga Pembelian Serial** digate **`serial-intake.view_harga`** (sensitif, hanya admin/super-admin — pola sama `po.view_harga`). Tampilan read-only (detail dokumen, kolom Grand Total di list, PDF) **menyembunyikan** Modal/Jual/total untuk yang tak berizin. **Form create/edit tetap menampilkan harga** (memang harus diisi) → karena itu `show` tetap mengirim harga bila user punya `view_harga` **ATAU** `serial-intake.update` (editor butuh memuat form edit); list digate murni `view_harga`. Backend strip (`makeHidden`) + frontend gate (`canViewHarga`).

Register Unit Serial (`GET /serial-units`, read-only) memakai permission baca **`serial-intake.view`** (tak ada permission khusus). Operasi inventory serial (Transfer/Adjustment/Opname/Retur) pakai permission modul masing-masing yang sudah ada (`transfer.*`, `adjustment.*`, `opname.*`, `retur-beli.*`) — tak ada permission baru. Diseed di [UserSeeder.php](../../database/seeders/UserSeeder.php).

### Model izin nilai sensitif (3 sumbu, orthogonal)
Harga/cost unit serial muncul di banyak permukaan; izinnya dibedakan per **konteks**, bukan satu izin:

| Izin | Mengatur |
|---|---|
| `serial-intake.view_harga` | harga di **dokumen** Pembelian Serial (detail/list/PDF intake) |
| `stok.view_hpp` | **cost/modal unit** di mana pun: Register, picker `available`, export Register, scan `lookup`, kartu stok, valuasi |
| `po.view_harga` | harga/total di **Purchase Order + Laporan Pembelian** (PO + serial digabung) |

Konsekuensi by-design: laporan pembelian (gate `po.view_harga`) menampilkan total/cost pembelian serial walau user tak punya `serial-intake.view_harga` — `po.view_harga` adalah payung "lihat harga beli". `harga_jual` (harga jual unit) **bukan** rahasia → selalu tampil.

---

## 6. Format Global

Semua angka/teks ikut Global Settings (jangan hardcode): currency input pakai `currencySettings` + `getLocale` + `getCurrencyMin/MaxFractionDigits`; `battery_health` pakai **format persen** (`getPercentMin/MaxFractionDigits` + `formatPercent`); `total_unit` pakai `formatNumber`; tanggal `getPrimeDateFormatShort`; teks `shouldUppercase`.

---

## 7. Roadmap

### ✅ Selesai — Integrasi Inventory & Pembelian (lihat §4.11–4.13)
Transfer, Adjustment-keluar, Stock Opname, Retur Beli, Koreksi HPP Serial, guard (Repack/HppCorrection/Adjustment-IN/hapus-produk), ledger `serial_unit_movements`, invariant `data:verify`, Dashboard.

### ✅ Selesai — Domain Penjualan (POS) serial
Produk **retail tak berubah**; produk serial mendapat dimensi SN (kolom `serial_unit_ids` JSON di `doc_sales_detail` + `doc_sales_return_detail`).
- **Checkout** ([CheckoutSalesAction](app/Actions/Sales/CheckoutSalesAction.php)): unit terpilih dikunci & divalidasi (milik produk, di gudang POS, `tersedia`, jumlah=qty) → `terjual` + `sale_id`/`sale_detail_id`/`sold_at`; `hpp_at_time` = rata `cost_per_unit` unit terjual; `avg_cost` agregat = **Metode A** (rata unit tersisa) dicatat di baris stock_card `SALES`; movement `OUT` per unit.
- **Guard known-issue (DITUTUP):** produk serial **wajib** pilih SN di POS ([PosController](app/Http/Controllers/Api/V1/PosController.php)) — tak bisa lagi dijual sebagai produk biasa.
- **Void** ([VoidSalesAction](app/Actions/Sales/VoidSalesAction.php)): semua unit nota → `tersedia` + movement `IN`, avg di-recompute. **Retur** ([ProcessSalesReturnAction](app/Actions/Sales/ProcessSalesReturnAction.php)): pilih SN yang kembali (jumlah=qty) → `tersedia`. Trait DRY [`RevertsSerialUnits`](app/Actions/Sales/Concerns/RevertsSerialUnits.php).
- **Kartu unit POS:** scan SN di kotak cari → `serial-units/lookup` → tampilkan identitas/kondisi/harga jual (HPP di-gate `stok.view_hpp`) + status sellable; auto-tambah. Chip SN di keranjang.
- **Laporan laba otomatis akurat** — semua laporan baca `hpp_at_time` (kini = biaya riil unit untuk serial); **tak ada laporan yang diubah**.
- Test: `SerialSalesCheckoutTest`, `SerialSalesReturnVoidTest`.

### ✅ Selesai — Scan Barcode (UX, tak ubah stok/HPP)
- **Scan unit** di `SerialUnitPicker.vue` (Transfer/Adjustment/Opname/Retur): scan/ketik **kode internal** (utama) atau nomor seri → tandai unit (cocok client-side ke unit yang sudah dimuat, tanpa endpoint). Tombol **Centang semua** / **Kosongkan** (mis. Opname: kosongkan lalu scan yang hadir).
- **Scan barcode produk** di Adjustment & Stock Opname (mode partial): reuse `getProducts` (cocok exact `barcode`, fallback hasil tunggal). Adjustment → tambah baris / qty+1; Opname → hitung fisik (+1 `qty_physical` per scan). Produk serial → tambah baris lalu arahkan scan SN di pemilih unit.

### ✅ Selesai — Biaya Kirim + Biaya Lain pada Transfer (opsional masuk HPP)
- Header `doc_transfer`: `biaya_kirim`, `biaya_lain`, `biaya_lain_nama`, `masuk_hpp`; detail `doc_transfer_detail.biaya_dialokasikan` (porsi per baris, audit).
- **Alokasi adil by-value** = qty × `avg_cost` (fallback by-qty bila nilai 0); sisa pembulatan ke baris terakhir → Σ alokasi == total biaya.
- **`masuk_hpp` = true** (per dokumen, opt-in):
  - **Serial** → porsi dibagi rata ke `cost_per_unit` unit yang dipindah, lalu `avg_cost` produk via **Metode A** (rata cost_per_unit unit tersedia). Akurat — hanya unit dipindah.
  - **Non-serial (Opsi B)** → `avg_cost` global naik = `avg_lama + (porsi ÷ qty_global)`. Konsekuensi: rata ke semua unit (HPP global, bukan per-gudang).
  - Dicatat `stock_card` `HPP_CORRECTION` (warehouse null) → tampil di **Pergerakan HPP**.
- **`masuk_hpp` = false** → biaya hanya informasi (alokasi tetap dicatat di `biaya_dialokasikan`, HPP tak berubah).
- Test: `tests/Feature/Serial/SerialTransferBiayaTest.php` (Opsi B, serial+Metode A, opt-out no-op, alokasi 2 produk; `data:verify` hijau).

### Fitur ditunda (diputuskan user)
- _(belum ada — Penjualan/POS serial lihat "Domain Penjualan" di atas)_

---

## 8. Test

- `tests/Feature/Produk/ProdukSerialTest.php` (toggle/scaffold/immutable)
- `tests/Feature/Produk/ImportProdukSerialTest.php` (import serial)
- `tests/Feature/SerialIntake/SerialDataLayerTest.php` (model/relasi)
- `tests/Feature/SerialIntake/SerialIntakeTest.php` (alur draft→approve, invariant, weighted-avg, **finansial: biaya→HPP landed, diskon tak ubah HPP, hutang, endpoint calculate**, supplier wajib, permission, edit/delete, **cash auto-settle + validasi cash [metode wajib, panjang ≤50], override `KI-angka` ditolak**)
- `tests/Feature/SerialChange/SerialChangeTest.php` (koreksi unit: apply, sold-unit ditolak, SN boleh kembar/swap/duplikat, gudang tak approve)
- `tests/Feature/SerialUnit/SerialUnitRegisterTest.php` (register: list+ringkasan+asal dokumen, filter status global, filter produk, permission, **proteksi cost `stok.view_hpp` di index & picker `available`**)
- `tests/Feature/SerialIntake/SerialUnitExportTest.php` (export Excel register, **strip kolom cost tanpa `stok.view_hpp`**)
- `tests/Feature/Serial/SerialFase1Test.php` (guard Repack/HPP/Adjustment-IN/hapus-produk, endpoint available, invariant data:verify)
- `tests/Feature/Serial/SerialTransferTest.php` (transfer unit pindah gudang + movement)
- `tests/Feature/Serial/SerialTransferBiayaTest.php` (biaya kirim/lain → HPP: Opsi B non-serial, Metode A serial, opt-out, alokasi by-value)
- `tests/Feature/Serial/SerialAdjustmentTest.php` (adjustment-keluar → rusak default + status per-unit rusak/hilang, cost unit; debit ditolak)
- `tests/Feature/Serial/SerialOpnameTest.php` (checklist hadir → unit hilang via turunan)
- `tests/Feature/Serial/SerialPurchaseReturnTest.php` (harga avg modal, cost landed, status retur)
- `tests/Feature/Serial/SerialHppCorrectionTest.php` (koreksi cost per-unit, avg_cost agregat tak berubah)
- `tests/Feature/Serial/SerialSalesCheckoutTest.php` (jual serial: unit terjual+sale_id, hpp=cost unit, avg Metode A, guard wajib SN, lookup, regresi retail)
- `tests/Feature/Serial/SerialSalesReturnVoidTest.php` (void balikkan semua unit; retur pilih SN; tolak unit bukan-terjual; regresi retail)
