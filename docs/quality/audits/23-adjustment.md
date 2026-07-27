# Audit menu — 23 Inventory → Adjustment

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/AdjustmentPage.vue` · `AdjustmentFormPage.vue` · `api/modules/adjustments.js` · nested `SerialUnitPicker`  
> - BE: `syilex/app/Http/Controllers/Api/V1/AdjustmentController.php` · `Actions/Adjustment/{Create,Update,Approve}AdjustmentAction.php` · `Models/DocAdjustment*`  
> - Turunan: Stock Opname `ApproveStockOpnameAction::createAndApproveAdjustment` (`source=opname`) · `InventoryStock` · `StockCard` (`ADJUSTMENT_IN`/`OUT` + `HPP_RESET`) · `SerialUnit` / movement `OUT`  
> - Routes FE: `inventory-adjustment*` · API: `/adjustments*` (`routes/api.php` 392–401)  
> - Menu: `AppMenu.vue` Inventory → Adjustment (setelah Stock Opname)  
> - Tes: `tests/Feature/Adjustment/AdjustmentCrudTest.php` · `AdjustmentHppResetTest.php` · `tests/Feature/Serial/SerialAdjustmentTest.php` · partial `InventoryAccessCoverageTest` · `InventoryDownstreamGuardTest` · opname turunan di `StockOpnameCrudTest` / `SerialOpnameTest`  
> - Domain: `docs/domain/serial.md` §Adjustment · `docs/ai/business-rules.md` §B  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Stock Opname di `AppMenu.vue` (sebelum Transfer).

## Scope

Koreksi stok manual per gudang: baris **debit (masuk)** / **kredit (keluar)** → dokumen **draft → approved** (tanpa lock / void / cancel). Approve: `lockForUpdate` baris stok + produk, mutasi `inventory_stock` + `stock_card` `ADJUSTMENT_IN|OUT`, serial kredit → status `rusak`/`hilang` + movement. Sumber: **`manual`** (CRUD UI) atau **`opname`** (auto-create + auto-approve dari Stock Opname). Serial: debit **dilarang**; kredit wajib unit; opname → semua `hilang`.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /adjustments` | `adjustment.view` | List |
| `GET /adjustments/{ulid}` | `adjustment.view` | Detail + form edit + PDF |
| `POST /` | `adjustment.create` | Draft create |
| `PUT /{ulid}` | `adjustment.update` | Draft update |
| `DELETE /{ulid}` | `adjustment.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `adjustment.approve` + throttle 30/1 | Approve + mutasi stok |
| `GET /products` | `adjustment.create` **saja** | Autocomplete + scan barcode |
| `GET /stock-setting` | **tanpa** `can()` | FE preflight negatif stok |
| FE route list | `adjustment.view` | |
| FE create / edit | `adjustment.create` / `adjustment.update` | |
| AppMenu | `adjustment.view` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search/warehouse/status/date); detail dialog; export PDF **client-side**. **Tidak:** lock dokumen, void/cancel, Excel, filter `source`, Policy Spatie, input cost debit, Metode A pada serial kredit.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `ADJ` | `SettingService` map `adjustment` → `ADJ`; `generateDocumentNumber` + `lockForUpdate` sequence |
| Status string: `draft` \| `approved` saja (enum DB) | migration `2026_01_24_100001_*` 21 |
| `source`: `manual` (default) \| `opname` + `opname_id` nullable | migration `2026_01_24_130003_*` |
| Duplikat produk per dokumen | UNIQUE `(adjustment_id, product_id)` + controller `hasDuplicateProducts` |
| Debit → `ADJUSTMENT_IN` + `recalculateAvgCost($qty, $oldHpp)` (cost = HPP lama) | `ApproveAdjustmentAction` 137–140 |
| Kredit → `ADJUSTMENT_OUT`; HPP **tidak** weighted-recalc; bila stok global ≤0 → `HPP_RESET` | Action 230–238; business-rules §B |
| Serial debit ditolak; kredit wajib `serial_unit_ids`; qty = `count(ids)` | Create/Update Action; Approve guard 81–88 |
| Manual serial fate: `serial_unit_statuses` map `{ulid: rusak\|hilang}`, default `rusak` | Create `buildSerialUnitStatuses`; Approve 204–212 |
| Opname serial fate: **semua** `hilang` (abaikan map) | Approve 204–209; Opname Action 146–182 |
| SoftDeletes: **tidak** pada header/detail | models; `destroy` hard + cascade detail |
| Resolusi unit valid (milik produk, WH, `tersedia`) **hanya saat approve** | `ResolvesSelectedUnits` di Approve — **bukan** Create/Update |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| AD-B1 | P0 | **Race double-approve → pecah invariant stok↔kartu.** `isDraft()` dicek **di luar** TX; header **tidak** `lockForUpdate`. Dua request paralel lolos draft → keduanya mutasi `inventory_stock`; request kedua melihat `existingStockCard` → `continue` **setelah** `updateOrCreate` stok → **skip** `StockCard::record` (+ skip serial). Hasil: stok dobel, kartu sekali → `data:verify` merah. | `ApproveAdjustmentAction` 31–35 vs 37–256; skip card 174–182 **setelah** mutasi 144–153 | `lockForUpdate` header di dalam TX + re-check draft; pindahkan cek kartu **sebelum** mutasi atau hapus skip (idempotensi via status saja). |
| AD-B2 | P0 | **Skip `existingStockCard` anti-pola.** Cek keberadaan kartu dipakai sebagai “idempotent continue” padahal stok/detail **sudah** diubah di iterasi yang sama. Bukan defense — memperbesar kerusakan race AD-B1. | Action 174–182 vs 144–162 | Hapus cabang skip; andalkan lock status. |
| AD-B3 | P1 | **Debit memakai cost = `avg_cost` lama** (`recalculateAvgCost($qty, $oldHpp)`). Barang “ketemu” / koreksi masuk tidak punya input modal → HPP bisa tetap/terdistorsi tanpa bukti nilai (sama pola surplus opname). | Action 137–140; `AdjustmentHppResetTest` komentar 370–371 | Keputusan: cost input wajib / cost 0 / skip recalc; dokumentasikan. |
| AD-B4 | P1 | **Serial kredit tidak Metode A.** Setelah unit → `rusak`/`hilang`, `avg_cost` produk **tidak** di-recompute dari unit `tersedia` tersisa (beda sales/void/retur). Hanya `checkAndResetHppIfStockEmpty` bila global 0. Domain serial.md sengaja “OUT tanpa recalc agregat”, tapi avg **stale** sampai habis. | Action 200–238 vs `RevertsSerialUnits::recomputeSerialAvgCost`; `docs/domain/serial.md` ~199–203 | Keputusan: Metode A setelah adj serial OUT (parity sales) **atau** dokumentasikan “avg sengaja stale hingga HPP_RESET”. |
| AD-B5 | P1 | **Create/Update tidak memvalidasi `serial_unit_ids`** (ada, milik produk, WH, `tersedia`, unique). Draft boleh ulid palsu/duplikat; `qty = count($ids)` tanpa `array_unique`. Approve: `ResolvesSelectedUnits` unique → sering **qty mismatch 422** atau unit invalid. | Create 77–84; Update 77–84; Resolves 36–37, 68–70 | Panggil resolve (atau mirror ringan) di create/update + `array_unique` sebelum count. |
| AD-B6 | P1 | **Snapshot `stok_sistem` di draft stale.** Create/Update mencatat stok saat simpan; approve mengunci stok aktual lalu overwrite `stok_sistem`/`stok_akhir`. FE warning negatif memakai snapshot lama → bisa false positive/negative vs approve. | Create 88–94; Approve 158–162; Form 352–406 | Refresh stok di form sebelum approve; atau label “perkiraan saat draft”. |
| AD-B7 | P1 | **Tidak ada void/cancel setelah approve.** Enum hanya draft/approved. Salah approve (qty/jenis/unit) hanya dilawan adj balik / opname baru. | migration status; no void route | Keputusan produk: void dengan reverse kartu + restore serial, atau SOP adj kompensasi. |
| AD-B8 | P2 | **Tanggal masa depan:** FE tolak (`isAfterNow` + `maxDate`); BE hanya `required\|date` — API boleh tanggal > now. | Form 369–370, 494; Controller 30 | `before_or_equal:now` di BE. |
| AD-B9 | P2 | **Kredit melebihi stok:** create/update **tidak** blok (draft OK); approve hormati `negative_stock_allowed`. FE Message warn saja — `errors.negative_stock` **tidak** memblok `validate()` (difilter). Konsisten, tapi API tanpa FE bisa draft “mustahil approve”. | Form 400–406; Approve 72–78 | OK bila disengaja; optional preflight API. |
| AD-B10 | P2 | **Update boleh ganti `warehouse_id`.** FE konfirmasi reset detail; BE tidak memaksa reset bila client kirim WH baru + detail lama (unit serial WH lama). | Update Action 51–55; Form watch 176–198 | BE: larang ganti WH **atau** wajib kosongkan/revalidasi serial vs WH baru. |
| AD-B11 | P2 | **Pesan validasi qty** `'Qty minimal 1'` vs rule `min:0.0001`; kolom DB `unsignedInteger` + cast `integer` — fraksi FE `InputNumber` bisa terpotong diam-diam. | Controller 35, 61; Detail cast 55; migration qty | Samakan integer min:1; batasi FE fraction 0 untuk adj. |
| AD-B12 | P3 | **Serial “found” (debit) mustahil** — by design (lahir via PBS). FE disable jenis debit untuk serial. Operator yang butuh “ketemu SN” harus alur lain. | Create 72–75; Form 589–598; serial.md | Hint UI / docs ops. |
| AD-B13 | P3 | Notes header `max:1000` vs detail `max:255`; FE tanpa maxlength. | Controller 31, 37; Form 505, 640 | Samakan + maxlength. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| AD-S1 | P1 | **Helper `getProducts` hanya `adjustment.create`:** user custom dengan `adjustment.update` saja **tidak** bisa autocomplete/scan saat edit (403), sementara route edit = `adjustment.update`. | Controller 322–324; router 242–245 | Gate `create \|\| update`. |
| AD-S2 | P1 | **`getStockSetting` tanpa permission** (siapa pun authenticated). | Controller 373–378 | `can('adjustment.view')` minimal. |
| AD-S3 | P2 | **Throttle hanya approve**; store/update/delete tanpa throttle. | `api.php` 392–401 | Throttle write paths. |
| AD-S4 | P2 | Filter `status` / `warehouse_id` tidak `Rule::in` / resolve ULID — garbage status lolos; inkonsisten ULID-first. | Controller 96–103 | Validasi ketat. |
| AD-S5 | P2 | **Access coverage tipis:** index/create/approve 403; **tidak** tes update/delete/products/stock-setting permission. | `InventoryAccessCoverageTest` 141–170 | Tambah kasus. |
| AD-S6 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK; dokumentasikan. |
| AD-S7 | P3 | **Gudang tidak punya `adjustment.approve`** — SoD benar; Dashboard pending pakai `adjustment.approve`. | Seeder 126 vs 91; DashboardController 84 | OK bila chip respect permission. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| AD-C1 | P0 | = **AD-B1/B2** TOCTOU approve + skip kartu. | Approve Action | Lock header; hapus skip berbahaya. |
| AD-C2 | P1 | **Coverage race/HTTP tipis:** CrudTest banyak Action-level; double-approve **sequential** diuji, **bukan** paralel; tidak ada tes skip-card path, duplikat serial ids, future date, products tanpa create, source filter, void. | `AdjustmentCrudTest` 297–316 | Tes race + HTTP permission matrix. |
| AD-C3 | P1 | **N+1** di `getProducts`: 1 query `InventoryStock` **per produk**. | Controller 349–352 | `whereIn` + map. |
| AD-C4 | P2 | `catch (\Exception)` generik → 500 string message di store/update/approve — bisa bocor detail. | Controller 168–169, 255–256, 311–312 | Catch domain exceptions saja. |
| AD-C5 | P2 | `destroy` tidak di TX eksplisit; cascade FK OK. Tidak soft-delete — audit trail dokumen hilang. | Controller 279; migration `onDelete('cascade')` | SoftDeletes opsional / archive. |
| AD-C6 | P2 | `runningStocks` disiapkan “untuk multiple lines same product” padahal UNIQUE + controller menolak duplikat — dead complexity (defense-in-depth OK, tapi menyesatkan). | Approve 44–50, 100–104 | Komentas/clarifikasi atau andalkan unique saja. |
| AD-C7 | P3 | Index eager `details:id,adjustment_id,notes` di list — FE list tidak pakai notes detail. | Controller 86 | Hapus with details atau pakai. |
| AD-C8 | P3 | `getTotalItemsAttribute` N+1 bila dipakai; list sudah `withCount`. | Model 159–162 | Hindari accessor di list. |
| AD-C9 | P3 | Duplikasi `buildSerialUnitStatuses` Create vs Update. | Create 125–134; Update 123–132 | Trait/shared private. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| AD-X1 | P0 | = **AD-B1** vs invariant **Stok / Kartu Stok / `data:verify`** — race approve merusak padanan. |
| AD-X2 | P1 | **Stock Opname:** selisih → adj `source=opname` auto-approve **tanpa** `adjustment.approve` (path opname). By design Flow A; jejak di menu Adjustment tampak seperti dokumen sistem. User tanpa permission adj tetap “menghasilkan” adj approved. | `ApproveStockOpnameAction` 136–184; SO audit SO-X2 |
| AD-X3 | P1 | **HPP / Pergerakan HPP:** debit → `ADJUSTMENT_IN` recalc; kredit → no weighted; serial kredit tanpa Metode A (**AD-B4**); kosong global → `HPP_RESET`. Opname surplus = debit dengan cost = old HPP. |
| AD-X4 | P1 | **Register Unit Serial:** status `rusak`/`hilang` + movement `ADJUSTMENT` OUT setelah approve; **tidak** ada deep-link Adj ↔ Register / Opname di UI detail. |
| AD-X5 | P1 | **UI list/detail tidak menampilkan `source` / `opname_id` / nomor OPN.** Operator sulit bedakan manual vs auto-opname; reverse-link dari Opname hanya teks nomor adj. | Page 288–293; show tidak load `opname` |
| AD-X6 | P2 | **POS / Transfer / PO** selama draft adj: tidak ada freeze — draft hanya snapshot. Race stok vs approve di-handle lock stok (bukan header) — unit serial bisa terjual dulu → approve 422 (baik), tapi debit race tetap AD-B1. |
| AD-X7 | P2 | **Elektronik OFF:** create/update tolak `serial_unit_ids`; `getProducts` filter non-serial. Adj **tidak** di bawah `feature.elektronik` (menu tetap) — benar. |
| AD-X8 | P2 | **Dashboard** pending `adjustment` → permission `adjustment.approve`; reset DB: `refuseIfHasNonDraft` untuk target adjustment. |
| AD-X9 | P2 | **Koreksi HPP (retail/serial):** orthogonal; adj tidak memanggil koreksi. Serial avg stale (AD-B4) bisa mendorong user ke Koreksi HPP Serial secara manual. |
| AD-X10 | P3 | SoftDeletes unit + draft adj menahan ulid di JSON — unit force-delete tidak diblok FK; approve orphan ids → 422. |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| AD-U1 | P1 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`, `SerialUnitPicker`, scan barcode. | Page/Form imports | — |
| AD-U2 | P1 | **Tidak ada kolom/badge Source** (Manual / Opname) di list & detail — = AD-X5. | Page columns | Tag + link ke Opname. |
| AD-U3 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten Stok/Register. | API module | Optional Excel. |
| AD-U4 | P2 | Filter status hanya draft/approved (default composable) — **baik** (enum tidak punya cancelled). Tidak ada filter source/jenis. | `useTransactionList` 68–69 | Filter source. |
| AD-U5 | P2 | Form: serial fate Select hanya setelah `serial_unit_objs` dari `@change` picker — bila edit load tanpa buka expansion, objs kosong → **UI status fate hilang** meski `serial_unit_statuses` tersimpan (approve tetap pakai map JSON). | Form 678–686; load 152–153 | Hydrate objs dari show `serial_units` atau selalu tampilkan dari ids+statuses. |
| AD-U6 | P2 | `errors.negative_stock` di-set tapi tidak dirender sebagai field error (hanya Message `hasNegativeStock`). | Form 401–403 vs 532–537 | Hapus dead error key atau tampilkan. |
| AD-U7 | P3 | Warehouse edit tidak di-disable (beda opname) — OK dengan confirm reset. | Form 487 | Hint “ganti WH reset baris”. |
| AD-U8 | P3 | PDF info tidak sertakan Source / Opname / Approved di header info (approved ada di audit block). | Page 123–128 | Tambah source. |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| AD-D1 | P1 | Index header: `status`, `tanggal`, `warehouse_id`, `source`, `opname_id`, UNIQUE `nomor_dokumen`/`ulid` — cukup list tipikal. Search `LIKE %…%` lemah skala besar. | migrations; `scopeSearch` 122–127 | Prefix / fulltext opsional. |
| AD-D2 | P1 | Detail UNIQUE `(adjustment_id, product_id)` — bagus. JSON `serial_unit_ids` / `serial_unit_statuses` tanpa constraint DB. | migration detail 27; serial migrations | Validasi app-level (AD-B5). |
| AD-D3 | P2 | SoftDeletes: tidak dipakai — hard delete draft menghapus jejak + cascade detail. | destroy 279 | — |
| AD-D4 | P2 | Approve: lock N stock + N produk + 1 card (+N unit) per baris — OK skala tipikal; dokumen besar = TX panjang. | Approve foreach | — |
| AD-D5 | P3 | `qty` unsignedInteger vs validasi `numeric|min:0.0001` — mismatch tipe. | migration; Controller 35 | Integer validation. |
| AD-D6 | P3 | Nomor dokumen: sequence `lockForUpdate` di dalam TX create — race nomor relatif aman. | SettingService 468–472; Create TX | — |

---

## Manual vs Opname (matriks)

| Aspek | Manual (`source=manual`) | Opname (`source=opname`) |
|-------|--------------------------|---------------------------|
| Pembuatan | CRUD FE + `CreateAdjustmentAction` | `ApproveStockOpnameAction::createAndApproveAdjustment` |
| Permission path | `adjustment.create` → `adjustment.approve` | `opname.approve` saja (auto-approve adj) |
| Status akhir | draft lalu approve user | langsung `approved` dalam TX opname |
| Serial fate | per-unit `rusak`/`hilang` (default rusak) | semua `hilang` |
| `serial_unit_statuses` | disimpan dari FE | biasanya null |
| Edit/hapus di UI Adj | draft saja | sudah approved → tidak |
| Terlihat di list Adj | ya | ya (tanpa badge source — AD-U2) |
| Debit serial | ditolak | surplus serial ditolak di opname (tidak buat debit serial) |

---

## Matriks permission role (seed)

| Permission | super-admin | admin | gudang | kasir |
|------------|:-----------:|:-----:|:------:|:-----:|
| `adjustment.view` | ✓ | ✓ | ✓ | — |
| `adjustment.create` | ✓ | ✓ | ✓ | — |
| `adjustment.update` | ✓ | ✓ | ✓ | — |
| `adjustment.delete` | ✓ | ✓ | ✓ | — |
| `adjustment.approve` | ✓ | ✓ | — | — |

Sumber: `RolePermissionSeeder` 42, 91, 126.

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `adjustment.view` |
| Filter WH / status / tanggal | Ya | same |
| Filter source (manual/opname) | Tidak | — |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view |
| Export Excel | Tidak | — |
| Create draft | Ya | `adjustment.create` |
| Edit draft | Ya | `adjustment.update` |
| Delete draft | Ya | `adjustment.delete` |
| Approve | Ya | `adjustment.approve` |
| Lock / Void / Cancel | Tidak | — |
| Scan barcode produk | Ya | form |
| Serial picker + fate rusak/hilang | Ya (jika `serialEnabled`) | form |
| Stock setting negatif | Ya (FE load) | API tanpa can |

---

## Antrian patch (usulan prioritas)

1. **P0** AD-B1/B2/C1/X1 — `lockForUpdate` header + re-check draft; hapus/pindahkan skip `existingStockCard` setelah mutasi stok.  
2. **P1** AD-B5 — validasi serial units di create/update + unique ids.  
3. **P1** AD-B3/B4 — keputusan cost debit + Metode A serial OUT.  
4. **P1** AD-S1/S2 — gate products `create\|\|update`; stock-setting permission.  
5. **P1** AD-U2/X5 — tampilkan source + deep-link Opname.  
6. **P1** AD-C2/C3 — tes race + N+1 products.  
7. **P2+** tanggal BE, void keputusan, Excel, hydrate fate UI edit, throttle, SoftDeletes.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `AdjustmentCrudTest` | create draft snapshot; update replace details; block update approved; approve debit/kredit + kartu; negatif block/allow; double-approve **sequential**; multi-produk; HPP_RESET habis; duplikat produk HTTP 422; `data:verify` |
| `AdjustmentHppResetTest` | OUT → HPP_RESET; partial OUT no reset; IN no reset; multi-WH no reset; balance kartu; weighted IN (cost=oldHpp) |
| `SerialAdjustmentTest` | kredit → rusak default; per-unit hilang/rusak; show + fate; debit ditolak; wajib units; WH lain / non-tersedia 422 approve; status invalid 422; status unselected diabaikan |
| `StockOpnameCrudTest` / `SerialOpnameTest` | adj turunan `source=opname` auto-approve + serial hilang |
| `InventoryAccessCoverageTest` | index/create/approve 403 (tanpa update/delete/products) |
| `InventoryDownstreamGuardTest` | create/approve tolak WH/produk nonaktif |
| `SerialFase1Test` | guard adj serial |
| `ResetTargetMatrixTest` | reset adjustment refuse non-draft |

**Tidak ada** e2e Playwright fungsional khusus (hanya screenshot/docs locator `15-adjustment`).

---

## Ringkasan eksekutif

Alur inti (draft → approve → `ADJUSTMENT_IN/OUT` + serial rusak/hilang, opname auto-adj, SoD gudang tanpa approve, tes sequential + serial fate) **berfungsi di happy path**. Kerapuhan utama: **race double-approve** dengan pola `existingStockCard` yang **melewatkan kartu setelah mutasi stok** (pecah invariant), **validasi unit serial hanya di approve**, **debit tanpa input cost / serial OUT tanpa Metode A**, dan **UI yang menyembunyikan `source=opname`** sehingga jejak auto-adj sulit diaudit dari menu Adjustment.
