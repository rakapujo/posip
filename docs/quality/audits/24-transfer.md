# Audit menu — 24 Inventory → Transfer

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/TransferPage.vue` · `TransferFormPage.vue` · `api/modules/transfers.js` · nested `SerialUnitPicker`  
> - BE: `syilex/app/Http/Controllers/Api/V1/TransferController.php` · `Actions/Transfer/{Create,Update,Approve}TransferAction.php` · `Models/DocTransfer*`  
> - Mutasi: `InventoryStock` (from+to) · `StockCard` `TRANSFER_OUT`/`TRANSFER_IN` (+ opsional `HPP_CORRECTION`) · `SerialUnit.warehouse_id` + movement `TRANSFER_OUT`/`IN`  
> - Routes FE: `inventory-transfer*` · API: `/transfers*` (`routes/api.php` 403–414)  
> - Menu: `AppMenu.vue` Inventory → Transfer (setelah Adjustment, sebelum Repack)  
> - Tes: `tests/Feature/Transfer/TransferCrudTest.php` · `TransferHppResetTest.php` · `tests/Feature/Serial/SerialTransferTest.php` · `SerialTransferBiayaTest.php` · `tests/Feature/Enhancements/TransferPatternTest.php` · partial `InventoryAccessCoverageTest` · `InventoryDownstreamGuardTest` / `MasterDownstreamGuardTest`  
> - Domain: `docs/domain/serial.md` §Transfer + §Biaya Kirim · `docs/ai/business-rules.md` §B (TRANSFER tidak weighted-recalc qty)  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Adjustment di `AppMenu.vue` (sebelum Repack).

## Scope

Pindah stok **antar gudang dalam satu langkah**: dokumen **draft → approved** (tanpa ship / receive / lock / void / cancel). Approve atomik: `TRANSFER_OUT` di gudang asal + `TRANSFER_IN` di tujuan; qty global kekal; HPP agregat **tidak** berubah kecuali opt-in **`masuk_hpp`** + biaya → `HPP_CORRECTION`. Serial: unit terpilih pindah `warehouse_id`, status tetap `tersedia`, 2 movement. Tab FE ekstra: **Pattern (Flow)** agregasi frekuensi WH→WH.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /transfers` | `transfer.view` | List |
| `GET /transfers/{ulid}` | `transfer.view` | Detail + form edit + PDF |
| `POST /` | `transfer.create` | Draft create |
| `PUT /{ulid}` | `transfer.update` | Draft update |
| `DELETE /{ulid}` | `transfer.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `transfer.approve` + throttle 30/1 | Approve + mutasi stok |
| `GET /products` | `transfer.create` **saja** | Autocomplete (+ refresh stok edit) |
| `GET /stock-setting` | **tanpa** `can()` | FE preflight negatif stok |
| `GET /pattern-summary` | `transfer.view` | Tab Pattern |
| FE route list | `transfer.view` | |
| FE create / edit | `transfer.create` / `transfer.update` | |
| AppMenu | `transfer.view` | |
| `GET /serial-units/available` (picker) | OR incl. `transfer.create` (**bukan** `update`) | SerialUnitPicker |

**CRUD capability:** create/update/delete **draft only**; approve; list (search / WH from / WH to / status / date); detail dialog; export PDF **client-side**; tab Pattern. **Tidak:** ship, receive, lock dokumen, void/cancel, Excel, filter `masuk_hpp`/biaya, Policy Spatie, barcode scan produk (beda Adj/Opname).

**Lifecycle nyata (bukan dua tahap WH):** tidak ada status `shipped` / `in_transit` / `received`. Satu `approve` = stok & unit langsung di tujuan.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `TRF` | `SettingService` map `transfer` → `TRF`; `generateDocumentNumber` + `lockForUpdate` sequence |
| Status string: `draft` \| `approved` saja (enum DB) | migration `2026_01_24_110001_*` 22 |
| Same-WH ban | validasi `different:warehouse_from_id` + FE `warehouseToOptions` / `canAddLines` |
| Duplikat produk per dokumen | UNIQUE `(transfer_id, product_id)` + controller `hasDuplicateProducts` |
| Approve: OUT + IN; `avg_cost_before == avg_cost_after` pada kartu qty | `ApproveTransferAction` 149–205; business-rules §B |
| Biaya: `biaya_kirim` + `biaya_lain` (+ nama); alokasi by-value → `biaya_dialokasikan` | migration `2026_06_16_120001_*`; Action `allocateByValue` |
| `masuk_hpp`: serial → naik `cost_per_unit` unit dipindah + Metode A; non-serial Opsi B → `avg + porsi/qty_global` + `HPP_CORRECTION` WH null | Action 248–255, 344–405; serial.md §Biaya |
| Serial wajib `serial_unit_ids`; qty = `count(ids)` di create/update | Create 74–81; Update 72–79 |
| Resolusi unit (produk, WH asal, `tersedia`, qty match) **hanya saat approve** | `ResolvesSelectedUnits` di Approve 210–215 |
| SoftDeletes: **tidak** pada header/detail; unit serial **punya** SoftDeletes | models Doc*; `SerialUnit` SoftDeletes; `destroy` hard + cascade detail |
| HPP_RESET: **tidak** dipicu transfer (qty global kekal) | `TransferHppResetTest`; Action tidak panggil reset |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| TR-B1 | P0 | **Race double-approve (non-serial) → pecah invariant stok↔kartu + HPP dobel.** `isDraft()` dicek **di luar** TX; header **tidak** `lockForUpdate`. Dua request paralel lolos draft → keduanya mutasi `inventory_stock` from+to; request kedua melihat `existingOutCard`/`existingInCard` → **skip** `StockCard::record` **setelah** `updateOrCreate` stok. Hasil: stok terpotong/ditambah 2×, kartu 1× → `data:verify` merah. Bila `masuk_hpp` + biaya: **`applyHppNonSerial` / alokasi jalan dua kali** → HPP & `HPP_CORRECTION` dobel. | `ApproveTransferAction` 31–35 vs 37–274; skip card 141–163 & 184–206 **setelah** mutasi 127–136 / 170–179; HPP 248–255 | `lockForUpdate` header di dalam TX + re-check draft; pindahkan cek kartu **sebelum** mutasi atau hapus skip (idempotensi via status saja). |
| TR-B2 | P0 | **Skip `existingOutCard` / `existingInCard` anti-pola** (= AD-B2). Dipakai sebagai “idempotent continue” padahal stok **sudah** diubah. Memperbesar kerusakan race TR-B1. Serial kebetulan lebih aman: resolve unit gagal (sudah di WH tujuan) → rollback TX kedua; non-serial tidak punya guard setara. | Action 141–163, 184–206 vs 127–179; serial path 209–215 | Hapus cabang skip; andalkan lock status. |
| TR-B3 | P1 | **Create/Update tidak memvalidasi `serial_unit_ids`** (ada, milik produk, WH asal, `tersedia`, unique). Draft boleh ulid palsu/duplikat; `qty = count($ids)` tanpa `array_unique`. Approve: `ResolvesSelectedUnits` unique → sering **qty mismatch 422**. | Create 74–81; Update 72–79; Resolves 36–37, 68–70 | Resolve (atau mirror ringan) di create/update + `array_unique` sebelum count. |
| TR-B4 | P1 | **Tidak ada void/cancel setelah approve.** Enum hanya draft/approved. Salah approve (WH/qty/unit/biaya HPP) hanya dilawan transfer balik + koreksi HPP manual. | migration status; no void route | Keputusan produk: void reverse OUT/IN + restore WH unit + reverse HPP_CORRECTION, atau SOP kompensasi. |
| TR-B5 | P1 | **Tidak ada alur ship → in-transit → receive.** Operator/ekspektasi “barang di jalan” tidak terwakili; stok tujuan naik **sebelum** fisik tiba; POS di WH tujuan bisa jual unit yang masih di truk. | Routes hanya `approve`; status enum 2 nilai; domain serial.md Transfer 1-langkah | Keputusan: dokumentasikan “instant transfer” ketat, atau tambah status transit + receive. |
| TR-B6 | P1 | **Opsi B non-serial (`masuk_hpp`): biaya naikkan HPP global** (`porsi ÷ qty_global` semua WH), bukan hanya qty yang dipindah / WH terkait. Unit di gudang lain “menanggung” landed cost. Domain sadar; risiko salah paham operator tinggi. | Action 344–356; Form hint 519–521; serial.md 312–313 | Copy UI lebih tegas + opsi alokasi per-qty-dipindah (keputusan produk). |
| TR-B7 | P1 | **Tanggal masa depan:** FE tolak (`isAfterNow` + `maxDate`); BE hanya `required\|date` — API boleh tanggal > now. | Form 334–335, 486; Controller 31 | `before_or_equal:now` di BE. |
| TR-B8 | P2 | **Qty > stok asal:** create/update **tidak** blok (draft OK); approve hormati `negative_stock_allowed`. FE Message warn; `errors.insufficient_stock` **tidak** memblok `validate()` (difilter). API tanpa FE bisa draft “mustahil approve”. | Form 363–369; Approve 72–83 | OK bila disengaja; optional preflight API. |
| TR-B9 | P2 | **Update boleh ganti `warehouse_from_id` / `to`.** FE konfirmasi reset detail hanya untuk **asal**; ganti **tujuan** tanpa reset. BE tidak memaksa reset bila client kirim WH asal baru + `serial_unit_ids` WH lama. | Update Action 48–57; Form watch 214–241 | BE: larang ganti WH asal tanpa kosongkan detail / revalidasi serial. |
| TR-B10 | P2 | **Pesan validasi qty** `'Qty minimal 1'` vs rule `min:0.0001`; kolom DB `unsignedInteger` + cast `integer` — fraksi FE `InputNumber` bisa terpotong diam-diam. | Controller 39, 62; Detail cast 50; migration qty | Samakan integer `min:1`; batasi FE fraction 0 untuk transfer. |
| TR-B11 | P2 | **`masuk_hpp=true` + `qty_global<=0`:** `applyHppNonSerial` return diam — biaya tercatat di `biaya_dialokasikan` tapi **tidak** masuk HPP / tanpa kartu (edge negatif stok). | Action 346–349 | 422 atau warning eksplisit. |
| TR-B12 | P3 | Notes `max:1000`; `biaya_lain_nama` `max:100`; FE notes/nama tanpa maxlength eksplisit di Textarea (hanya uppercase). | Controller 32–35; Form 494, 511 | maxlength mirror. |
| TR-B13 | P3 | Alokasi by-value memakai `avg_cost` **saat approve** (bukan snapshot draft) — benar untuk landed, tapi draft preview biaya di UI hanya total header (tanpa preview porsi per baris). | `allocateByValue` 295–302; Form 525–527 | Optional preview alokasi di form. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| TR-S1 | P1 | **Helper `getProducts` hanya `transfer.create`:** user custom dengan `transfer.update` saja **tidak** bisa autocomplete / `refreshStockInfo` saat edit (403), sementara route edit = `transfer.update`. | Controller 336–338; Form 192–194; router 260–263 | Gate `create \|\| update`. |
| TR-S2 | P1 | **`SerialUnitPicker` → `/serial-units/available` hanya OR `transfer.create`** (bukan `update`) — edit draft serial putus untuk role update-only. | `SerialUnitController` 112–125 | Tambah `transfer.update`. |
| TR-S3 | P1 | **`getStockSetting` tanpa permission** (siapa pun authenticated). | Controller 387–391 | `can('transfer.view')` minimal. |
| TR-S4 | P2 | **Throttle hanya approve**; store/update/delete/pattern tanpa throttle. | `api.php` 403–414 | Throttle write paths. |
| TR-S5 | P2 | Filter `status` / WH id tidak `Rule::in` / resolve ULID — garbage status lolos; inkonsisten ULID-first (`pattern-summary` bahkan expose numeric WH id). | Controller 96–108, 453–459 | Validasi ketat + ulid di response. |
| TR-S6 | P2 | **Access coverage tipis:** create/approve 403; **tidak** tes update/delete/products/stock-setting/pattern/index permission matrix. | `InventoryAccessCoverageTest` 172–187 | Tambah kasus. |
| TR-S7 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK; dokumentasikan. |
| TR-S8 | P3 | **Gudang tidak punya `transfer.approve`** — SoD benar; Dashboard pending pakai `transfer.approve`. | Seeder 127 vs 43; DashboardController 85 | OK bila chip respect permission. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| TR-C1 | P0 | = **TR-B1/B2** TOCTOU approve + skip kartu. | Approve Action | Lock header; hapus skip berbahaya. |
| TR-C2 | P1 | **Coverage race/HTTP tipis:** CrudTest sequential double-approve saja; **bukan** paralel; tidak ada tes skip-card path, future date, products tanpa create, race+`masuk_hpp`, void. | `TransferCrudTest` 251–268 | Tes race + HTTP permission matrix + biaya race. |
| TR-C3 | P1 | **N+1** di `getProducts`: 1 query `InventoryStock` **per produk**. FE edit `refreshStockInfo` = N× getProducts lagi. | Controller 363–366; Form 189–207 | `whereIn` + map; endpoint batch stok. |
| TR-C4 | P2 | `catch (\Exception)` generik → 500 string message di store/update/approve — bisa bocor detail. | Controller 174–175, 267–268, 325–326 | Catch domain exceptions saja. |
| TR-C5 | P2 | **Duplicate `catch (ValidationException)`** di `update` & `approve` — cabang kedua unreachable dead code. | Controller 263–266, 321–324 | Hapus duplikat. |
| TR-C6 | P2 | `destroy` tidak di TX eksplisit; cascade FK OK. Tidak soft-delete — audit trail dokumen hilang. | Controller 291; migration `onDelete('cascade')` | SoftDeletes opsional / archive. |
| TR-C7 | P2 | `runningStocksFrom/To` “untuk multiple lines same product” padahal UNIQUE + controller menolak duplikat — dead complexity (defense-in-depth OK, menyesatkan). | Approve 105–113 | Komentar/clarifikasi atau andalkan unique. |
| TR-C8 | P3 | `CreateTransferAction` `use HasInventoryStock` **tidak dipakai** (tidak panggil `getCurrentStock`). | Create 9, 18 | Hapus trait. |
| TR-C9 | P3 | Duplikasi logika serial qty/ids Create vs Update. | Create 70–89; Update 67–87 | Shared private/trait. |
| TR-C10 | P3 | Accessor `total_items` / `total_qty` N+1 bila dipakai; list sudah `withCount`. | Model 173–184 | Hindari accessor di list. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| TR-X1 | P0 | = **TR-B1** vs invariant **Stok / Kartu Stok / `data:verify`** — race approve merusak padanan OUT/IN. |
| TR-X2 | P1 | **Pergerakan HPP:** transfer qty → `TRANSFER_*` tanpa ubah HPP (benar §B). Biaya + `masuk_hpp` → `HPP_CORRECTION` WH **null** → **hilang** di list HPP saat filter gudang (= **HM-B2 / HM-X1**). Jejak campur dengan Koreksi HPP retail/serial tanpa deep-link ke TRF. |
| TR-X3 | P1 | **Kartu Stok:** OUT/IN tercatat; `source_doc` deep-link transfer lemah/tidak (lihat audit KS-B6). Pattern `value_total` memakai **`avg_cost` current** (bukan snapshot saat transfer) — menyesatkan setelah koreksi/biaya. | Controller 396, 431; `TransferPage` Pattern |
| TR-X4 | P1 | **Register Unit Serial:** WH berubah + 2 movement; Register **tidak** expose ledger movement / deep-link TRF (= SU-B2). “Asal dokumen” tetap PBS. |
| TR-X5 | P1 | **POS / Sales:** draft transfer **tidak** reserve stok/unit. Unit di draft bisa terjual → approve 422 (baik). Non-serial: kompetisi stok dengan POS/adj di-handle `lockForUpdate` stok saat approve. |
| TR-X6 | P2 | **Stock Opname / Adjustment / Repack:** tidak ada freeze WH selama draft transfer. Opname set-to-physical bisa “menelan” transfer antara hitung↔approve (SO-B1). |
| TR-X7 | P2 | **Elektronik OFF:** create/update tolak `serial_unit_ids`; `getProducts` filter non-serial. Transfer **tidak** di bawah `feature.elektronik` (menu tetap) — benar. |
| TR-X8 | P2 | **Dashboard** pending `transfer` → permission `transfer.approve`. Reset DB: `refuseIfHasNonDraft` untuk target `transfer`. |
| TR-X9 | P2 | **Koreksi HPP (retail/serial):** orthogonal; transfer biaya menulis tipe kartu yang sama (`HPP_CORRECTION`) tanpa membedakan sumber di UI HPP. |
| TR-X10 | P3 | SoftDeletes unit + draft menahan ulid di JSON — unit soft-deleted → approve orphan → 422. |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| TR-U1 | P1 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`, `SerialUnitPicker`, gate header `canAddLines`. | Page/Form imports | — |
| TR-U2 | P1 | **Tab Pattern:** `loadPatternSummary` hanya saat tab change **dan** `items.length === 0`. Ganti filter tanggal (atau WH) **tidak** reload pattern; filter WH list **diabaikan** API pattern (hanya date). Operator bisa lihat angka stale/salah konteks. | Page 38–62, 58–62; Controller 406–412 | Reload on filter change; mirror WH filter atau hide WH filters di tab Pattern. |
| TR-U3 | P2 | PDF **tidak** sertakan biaya / `masuk_hpp` / alokasi baris — detail dialog punya. | Page 161–200 vs 400–405 | Samakan info PDF. |
| TR-U4 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten Stok/Register. | API module | Optional Excel. |
| TR-U5 | P2 | **Tidak ada scan barcode produk** (Adj/Opname punya) — hanya autocomplete; serial scan ada di picker. | Form (no barcode); serial.md Scan Barcode | Optional scan baris seperti Adj. |
| TR-U6 | P2 | List tidak kolom biaya / badge `masuk_hpp`; sulit audit landed cost dari grid. | Page columns 326–360 | Tag atau kolom opsional. |
| TR-U7 | P2 | `errors.insufficient_stock` di-set tapi tidak dirender sebagai field error (hanya Message). | Form 364–366 vs 547–552 | Hapus dead key atau tampilkan. |
| TR-U8 | P3 | Pattern cards “Paling Sering Kirim/Terima” pakai **kode** WH saja; value HPP gated `stok.view_hpp` — baik. | Page 254–290 | — |
| TR-U9 | P3 | Form: ganti WH tujuan tanpa confirm (hanya asal) — OK minor. | Form watch | Hint. |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| TR-D1 | P1 | Index header: `status`, `tanggal`, `warehouse_from_id`, `warehouse_to_id`, UNIQUE `nomor_dokumen`/`ulid` — cukup list tipikal. Search `LIKE %…%` lemah skala besar. | migration 110001; `scopeSearch` 128–133 | Prefix / fulltext opsional. |
| TR-D2 | P1 | Detail UNIQUE `(transfer_id, product_id)` — bagus. JSON `serial_unit_ids` tanpa constraint DB. | migration 110002 23; serial migration | Validasi app-level (TR-B3). |
| TR-D3 | P2 | SoftDeletes: tidak dipakai header/detail — hard delete draft menghapus jejak + cascade. | destroy 291 | — |
| TR-D4 | P2 | Approve: lock N stock from + N to + N produk (+N unit) + opsional HPP — OK skala tipikal; dokumen besar + `masuk_hpp` = TX panjang. | Approve foreach | — |
| TR-D5 | P3 | `qty` unsignedInteger vs validasi `numeric\|min:0.0001` — mismatch tipe. | migration; Controller 39 | Integer validation. |
| TR-D6 | P3 | Nomor dokumen: sequence `lockForUpdate` di dalam TX create — race nomor relatif aman. | SettingService; Create TX | — |
| TR-D7 | P3 | Kolom biaya decimal + `biaya_dialokasikan` 4 dp — OK; tidak ada index khusus (tidak perlu untuk list). | migration biaya | — |

---

## Lifecycle matriks (aktual)

| Aspek | Perilaku kode |
|-------|----------------|
| Create | Draft; stok **tidak** berubah; nomor `TRF-…` |
| Update / Delete | Draft only; hard delete + cascade detail |
| Approve | Instant OUT+IN (+ serial WH move); status `approved` |
| Ship / Receive / Cancel / Void / Lock | **Tidak ada** |
| Biaya tanpa `masuk_hpp` | `biaya_dialokasikan` terisi; HPP tetap |
| Biaya + `masuk_hpp` | Serial Metode A pada unit dipindah; non-serial Opsi B global; kartu `HPP_CORRECTION` |
| Double approve sequential | 422 status (tes ada) |
| Double approve paralel | **Tidak diuji**; non-serial rentan TR-B1 |

---

## Matriks permission role (seed)

| Permission | super-admin | admin | gudang | kasir |
|------------|:-----------:|:-----:|:------:|:-----:|
| `transfer.view` | ✓ | ✓ | ✓ | — |
| `transfer.create` | ✓ | ✓ | ✓ | — |
| `transfer.update` | ✓ | ✓ | ✓ | — |
| `transfer.delete` | ✓ | ✓ | ✓ | — |
| `transfer.approve` | ✓ | ✓ | — | — |

Sumber: `RolePermissionSeeder` 43, 92, 127.

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `transfer.view` |
| Filter WH asal / tujuan / status / tanggal | Ya | same |
| Tab Pattern (flow WH→WH) | Ya | view (+ `stok.view_hpp` untuk nilai) |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view |
| Export Excel | Tidak | — |
| Create draft | Ya | `transfer.create` |
| Edit draft | Ya | `transfer.update` |
| Delete draft | Ya | `transfer.delete` |
| Approve | Ya | `transfer.approve` |
| Ship / Receive / Lock / Void / Cancel | Tidak | — |
| Biaya kirim/lain + `masuk_hpp` | Ya (form) | create/update |
| Scan barcode produk | Tidak | — |
| Serial picker (+ scan SN di picker) | Ya (jika `serialEnabled`) | form |
| Stock setting negatif | Ya (FE load) | API tanpa can |

---

## Antrian patch (usulan prioritas)

1. **P0** TR-B1/B2/C1/X1 — `lockForUpdate` header + re-check draft; hapus/pindahkan skip `existing*Card` setelah mutasi stok.  
2. **P1** TR-B3 — validasi serial units di create/update + unique ids.  
3. **P1** TR-B5 — keputusan produk: dokumentasikan instant transfer **atau** alur transit/receive.  
4. **P1** TR-B4/B6 — keputusan void + kejujuran copy Opsi B global.  
5. **P1** TR-S1/S2/S3 — gate products `create\|\|update`; available `transfer.update`; stock-setting permission.  
6. **P1** TR-U2/X3 — Pattern reload + nilai snapshot; deep-link HPP/KS ke TRF.  
7. **P1** TR-C2/C3 — tes race (+`masuk_hpp`) + N+1 products.  
8. **P2+** tanggal BE, PDF biaya, Excel, barcode scan, throttle, SoftDeletes, hapus catch duplikat.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `TransferCrudTest` | create draft; update replace; block update approved; approve pindah stok + qty global kekal + kartu OUT/IN; negatif block; double-approve **sequential**; delete approved 422; same-WH 422; duplikat produk 422; `data:verify` |
| `TransferHppResetTest` | transfer habiskan stok asal → **tidak** HPP_RESET; kartu OUT/IN correct |
| `SerialTransferTest` | unit pindah WH + 4 movements; wrong WH 422 approve; create tanpa units 422; tanpa biaya avg tetap; show `serial_units` |
| `SerialTransferBiayaTest` | Opsi B non-serial; opt-out; serial Metode A; alokasi by-value 2 produk; rounding 3 produk; biaya 0 no-op; `data:verify` |
| `TransferPatternTest` | permission; agregasi frekuensi; draft excluded; strip value tanpa `stok.view_hpp`; date range; DISTINCT frekuensi multi-detail; boundary tanggal |
| `InventoryAccessCoverageTest` | create/approve 403 (tanpa update/delete/products/stock-setting) |
| `InventoryDownstreamGuardTest` / `MasterDownstreamGuardTest` | create/approve tolak WH/produk nonaktif |
| `ResetTargetMatrixTest` | (transfer ada di ResetController `refuseIfHasNonDraft`; coverage matrix fokus adj/repack) |

**Tidak ada** e2e Playwright fungsional khusus (hanya screenshot/docs locator `menu-inventory-transfer`).

---

## Ringkasan eksekutif

Alur inti (**draft → approve** = OUT+IN atomik, same-WH ban, serial pindah WH + movement, biaya opt-in → `HPP_CORRECTION`, SoD gudang tanpa approve, Pattern summary, tes sequential + biaya) **berfungsi di happy path**. Kerapuhan utama: **race double-approve non-serial** dengan pola skip kartu setelah mutasi stok (dan **HPP dobel** bila `masuk_hpp`), **validasi unit serial hanya di approve**, **tidak ada void / transit-receive** (stok tujuan naik sebelum fisik tiba), **Opsi B menyebar biaya ke HPP global**, serta **tab Pattern yang mudah stale** terhadap filter tanggal.
