# Audit menu — 21 Inventory → Koreksi HPP Serial

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/master/SerialHppCorrectionPage.vue` · `SerialHppCorrectionFormPage.vue` · `api/modules/serialHppCorrections.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/SerialHppCorrectionController.php` · `Actions/SerialHppCorrection/*` · `Models/DocSerialHppCorrection*`  
> - Routes FE: `inventory-serial-hpp*` (`serial-hpp.*` + `requiresElektronik`) · API: `/serial-hpp-corrections*` + middleware `feature.elektronik`  
> - Menu: `AppMenu.vue` Inventory → Koreksi HPP Serial  
> - Tes: `tests/Feature/Serial/SerialHppCorrectionTest.php` · partial `SerialAccessCoverageTest` · `ElektronikModuleTest`  
> - Domain: `docs/domain/serial.md` §4.12  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Register Unit Serial di `AppMenu.vue` (sebelum Stock Opname / Koreksi HPP retail).

## Scope

Koreksi **biaya pokok per unit** (`harga_modal` + komponen → `cost_per_unit` landed) untuk produk `is_serial`, alur **draft → approved** (tanpa lock/void). Saat approve: apply ke unit `tersedia`, movement `HPP_SERIAL`, rekalkulasi `avg_cost` Metode A, `stock_card` `HPP_CORRECTION` (WH null).

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /serial-hpp-corrections` | `serial-hpp.view` | List |
| `GET /serial-hpp-corrections/{ulid}` | `serial-hpp.view` | Detail + PDF client |
| `GET /serial-hpp-corrections/units` | `serial-hpp.create` **atau** `update` | Form load unit + tax |
| `POST /` | `serial-hpp.create` + throttle 30/1 | Draft create |
| `PUT /{ulid}` | `serial-hpp.update` + throttle | Draft update |
| `POST /{ulid}/approve` | `serial-hpp.approve` + throttle | Apply |
| `DELETE /{ulid}` | `serial-hpp.delete` | Draft hapus hard |
| FE route / AppMenu | `serial-hpp.view` (+ create/update di form) + `serialEnabled` | Gate UI |
| Modul OFF | `feature.elektronik` → API **403**; FE `requiresElektronik`; menu `visible: serialEnabled && …` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search/status/date); detail dialog; export PDF **client-side**. **Tidak:** lock, void/cancel, Excel export, filter gudang/produk di FE, Policy Spatie.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `HPS` | `SettingService` map `serial_hpp_correction` → `HPS` |
| Status string: `draft` \| `approved` (migration sebut `cancelled` — **tidak diimplementasi**) | migration `2026_06_14_100003_*` 25; model helpers |
| Hanya unit `tersedia` saat create/update | `HandlesHppCorrectionUnits` 47–51 |
| Landed = modal + kirim + lain + pajak (pajak dari setting pembelian) | trait 64–85; FE live `landedOf` |
| Approve → Metode A avg + `HPP_CORRECTION` WH null | `ApproveSerialHppCorrectionAction` 80–105 |
| SoftDeletes: **tidak** pada header/detail; unit pakai SoftDeletes | models |
| Cost gate `stok.view_hpp`: **tidak** di-strip di endpoint ini | Controller `units`/`show` |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-B1 | P0 | **Approve silent-skip unit non-tersedia → dokumen tetap `approved`.** Unit terjual/rusak/hilang di-`continue` tanpa error, tanpa hitung applied. Detail/PDF tetap tampil “lama → baru” seolah diterapkan, padahal unit tidak berubah. Bisa approve efek-nol (semua unit sudah keluar). | `ApproveSerialHppCorrectionAction` 46–50, 109–113; FE detail `diffCost` 86–93, 241–242 | Tolak 422 jika ada unit non-tersedia **atau** partial-apply dengan flag + jumlah applied/skipped; jangan approve bila applied=0. |
| SH-B2 | P0 | **Copy FE & PHPDoc bohong: “Tidak mengubah avg_cost agregat”.** Approve **selalu** rekalkulasi Metode A + tulis `HPP_CORRECTION`. Subtitle form menyesatkan operator & auditor. | Form 178; Controller 18–19; Model 18; Action 19–22 (benar); domain §4.12 (benar) | Samakan copy FE/controller/model ke domain; hapus klaim “default tidak”. |
| SH-B3 | P0 | **Race double-approve:** `isDraft()` dicek **di luar** transaksi; header **tidak** `lockForUpdate`. Dua request paralel → dua apply + **dua** baris `HPP_CORRECTION` + movements dobel. | Action 32–40 vs 40–116 | `lockForUpdate` header di dalam TX + re-check status (pola dokumen lain). |
| SH-B4 | P1 | **Tidak ada kunci unit/produk antar draft** (beda retail HPP: `getLockedProductIds`). Banyak draft bisa menarget unit/produk sama → last-approve-wins; jejak `before` menipu. | Retail `HppCorrectionController` 426–430; Serial create/update tanpa cek overlap | Lock unit yang sudah ada di draft lain; atau tolak overlap saat create/approve. |
| SH-B5 | P1 | **`StockCard` HPP_CORRECTION ditulis meski `oldAvg === newAvg` (noop)** dan meski **nol unit** di-apply tapi masih ada unit tersedia lain (avg tak berubah dari koreksi). Polusi Pergerakan HPP. | Action 87–105 (tanpa guard delta / applied count) | Skip card bila `abs(old-new) < epsilon` **dan/atau** applied=0. |
| SH-B6 | P1 | **Tidak ada void/cancel** meski migration enum komentar `cancelled`. Approved terminal; salah approve hanya bisa dilawan dokumen koreksi baru. | migration 25; tidak ada endpoint cancel | Dokumentasikan sengaja (mirror Serial Change) **atau** tambah cancel draft-only + keputusan void approved. |
| SH-B7 | P1 | **Form/detail tanpa kolom gudang** — unit dari semua WH digabung. Operator tak tahu unit di gudang mana; salah centang lintas WH mudah. | `units` 110–112 (tanpa `warehouse_id`); Form columns 231–277; Detail 68–74 | Tampilkan WH; opsional filter WH di form. |
| SH-B8 | P2 | **Detail UI hilangkan komponen biaya** (kirim/lain/pajak) — hanya modal + landed. DB punya kolom audit; UI/PDF tidak. | Detail columns 68–74; PDF 112–117; migration biaya | Kolom komponen di detail + PDF. |
| SH-B9 | P2 | **Edit rematch diam:** unit di detail yang sudah tidak `tersedia` tidak muncul di form (`loadUnits` hanya tersedia) → hilang tanpa toast; save rewrite detail. | Form 110–118; Controller `units` 110 | Warning “N unit di dokumen tidak lagi tersedia”. |
| SH-B10 | P2 | **Duplikat `serial_unit_id` dalam satu payload** tidak ditolak → dua baris detail; approve apply berulang. Retail HPP menolak duplikat produk. | trait validate tanpa unique; HppCorrection `hasDuplicateProducts` | `distinct` / unique ulid per payload + unique DB `(correction_id, serial_unit_id)`. |
| SH-B11 | P2 | **FE landed `round2` vs BE landed tanpa round ke 2** — preview form bisa ≠ `cost_per_unit_baru` tersimpan (pecahan modal). | Form 37–40; trait 74–76 | Samakan rounding (BE SSoT; FE mirror). |
| SH-B12 | P3 | **`notes` FE `maxlength=255` vs BE `max:1000`** — inkonsisten. | Form 204; Controller 183 | Samakan. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-S1 | P0 | **Bypass `stok.view_hpp`:** role **gudang** punya `serial-hpp.view/create/update/delete` **tanpa** `stok.view_hpp` (seeder). `/units`, `show`, detail, PDF mengekspos `harga_modal`/`cost_per_unit`/diff. Register Unit **strip** cost tanpa `view_hpp` — jalur ini tidak. | `RolePermissionSeeder` 125–133 vs 41; Controller `units` 110–117, `show` 84–93; banding `SerialUnitController` strip | Require `stok.view_hpp` untuk `units`/`show` cost fields **atau** strip cost tanpa permission; selaraskan seed gudang. |
| SH-S2 | P1 | **`serial-hpp.view` saja cukup untuk lihat semua nilai koreksi + before JSON** di detail/PDF (termasuk setelah approved). Tidak ada strip. | Controller `show`; Page export 95–130 | Gate cost dengan `view_hpp` orthogonal (mirror Register). |
| SH-S3 | P2 | **`DELETE` tanpa throttle** (POST/PUT/approve ada `throttle:30,1`). | `routes/api.php` 180–187 | Throttle delete. |
| SH-S4 | P2 | Filter `status` / `product_id` tidak divalidasi (`Rule::in` / resolve ULID). `product_id` numerik mentah — inkonsisten ULID-first. | Controller index 45–50 | Validasi + resolve ulid. |
| SH-S5 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK; dokumentasikan. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-C1 | P0 | = **SH-B3** TOCTOU approve. | Action 32–40 | Lock header. |
| SH-C2 | P1 | **Komentar/test stale:** file test header bilang avg “TIDAK berubah”; `serial.md` §tes masih “avg_cost agregat tak berubah” — bertentangan test `create_and_approve_corrects_unit_and_propagates_avg_cost`. | `SerialHppCorrectionTest` 21–22, 107–108; `serial.md` ~338 | Update komentar/docs. |
| SH-C3 | P1 | **Coverage tipis:** tidak ada tes silent-skip approve, double-approve race, overlap draft, noop StockCard, permission×`view_hpp`, elektronik OFF khusus HPS, duplikat unit, delete non-draft, update product switch. | `SerialHppCorrectionTest` (5 skenario happy+edge) | Tambah kasus di atas. |
| SH-C4 | P2 | `units` → `->get()` unpaginated seluruh unit tersedia produk. SKU ribuan unit = payload besar. | Controller 110–112 | Paginate / search / virtual. |
| SH-C5 | P2 | Form `loading` di-set tapi **tidak dipakai** di template (no skeleton/disable). | Form 16–17, 42–46 vs template | `v-if`/`BlockUI`. |
| SH-C6 | P2 | Produk form `per_page: 200` — produk serial ke-201+ tidak muncul di Select. | Form 51 | Autocomplete server-side (mirror picker lain). |
| SH-C7 | P3 | Movement `movement_type: STATUS_CHANGE` dengan from=to status — semantik menyesatkan untuk koreksi cost-only. | Action 65–77 | Tipe `COST_CHANGE` / `ATTR_CHANGE` bila katalog mendukung. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| SH-X1 | P0 | Writer `HPP_CORRECTION` WH **null** → **hilang di Pergerakan HPP** saat filter gudang (= **HM-B2 / HM-X1**). Koreksi serial “berhasil” tapi operator filter WH tidak melihat jejak. |
| SH-X2 | P0 | = **SH-S1** vs Register Unit: cost terlihat di HPS, tersembunyi di Register untuk user yang sama (gudang). |
| SH-X3 | P1 | **Koreksi HPP retail** guard tolak `is_serial` + product lock draft; serial menu **tanpa** lock — dua standar anti-fraud/anti-double. | `CreateHppCorrectionAction` 37–40; HppCorrection locked products |
| SH-X4 | P1 | **POS / COGS:** jual memakai `cost_per_unit` unit saat checkout (`PostsSalesInventory` ~78). Koreksi **setelah** jual tidak mengubah COGS historis (benar); koreksi **sebelum** jual + silent-skip bila unit terjual di tengah draft → risiko margin salah-ekspektasi (SH-B1). |
| SH-X5 | P1 | **Metode A** setelah approve ubah `avg_cost` + sync `inventory_stock` → Stok/Kartu Stok/margin ikut; Kartu Stok summary bug `TYPES_NO_QTY` (= **KS-B1**) berlaku. |
| SH-X6 | P1 | **Serial Change** share pola silent-skip approve (`ApproveSerialChangeAction` ~44) — hutang SC-A*; HPS mewarisi anti-pattern yang sama. |
| SH-X7 | P2 | **PBS / intake:** modal salah di intake → koreksi lewat HPS (by design). Form PBS hint koreksi belakangan. Tidak ada deep-link HPS ↔ Register/PBS. |
| SH-X8 | P2 | **Dashboard** pending `serial_hpp` → route `inventory-serial-hpp` (OK). Lock elektronik hitung draft HPS. Reset DB wipe draft-only chip. |
| SH-X9 | P2 | **Transfer biaya** juga tulis `HPP_CORRECTION` — jejak campur dengan HPS di Pergerakan HPP tanpa deep-link dokumen. |
| SH-X10 | P3 | SoftDeletes unit: draft HPS menahan FK `serial_unit_id` (restrict) — forceDelete unit PBS draft yang sudah masuk HPS draft bisa gagal DB. Edge jarang. |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-U1 | P0 | = **SH-B2** subtitle form salah. | Form 178 | Fix copy. |
| SH-U2 | P1 | Detail/PDF tidak menandai unit yang **tidak ter-apply** saat approve (tak ada field `applied`). | Page detail; Action skip | Badge / kolom status apply. |
| SH-U3 | P2 | Tidak ada filter produk di list FE (BE support `product_id`). | Page filters 143–152 vs Controller 45–47 | Tambah filter produk. |
| SH-U4 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten banyak menu inventory. | API module | Optional Excel bila diminta. |
| SH-U5 | P2 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`. | Page imports | — |
| SH-U6 | P3 | Views di folder `views/master/` padahal menu **Inventory** + route `inventory/serial-hpp` — inkonsisten path (Serial Change juga master). | path file vs AppMenu | Pindah ke `views/inventory/` (opsional). |
| SH-U7 | P3 | Message form tentang “rincian biaya lama tidak tersimpan” — bagus; tidak ada link ke Register Unit. | Form 223–225 | Deep-link opsional. |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-D1 | P1 | **Tidak ada UNIQUE** `(correction_id, serial_unit_id)` pada detail. | migration detail 38–54 | Unique index. |
| SH-D2 | P2 | Index header: `tanggal`, `product_id`, `status`, UNIQUE `nomor_dokumen`/`ulid` — cukup untuk list tipikal. Search `LIKE %…%` + `orWhereHas` produk lemah di skala besar. | migration; `scopeSearch` 90–98 | Prefix search / fulltext opsional. |
| SH-D3 | P2 | Approve: `SerialUnit::whereIn lock` + load **semua** tersedia produk untuk avg (`->get(['cost_per_unit'])`) — produk dengan stok unit sangat besar = memory. | Action 44, 85 | SQL `AVG(cost_per_unit)` / selectRaw. |
| SH-D4 | P3 | N+1 list: mitigated (`with` + `withCount`). SoftDeletes header: tidak dipakai (hard delete draft). | Controller 36–40; destroy 170–171 | — |
| SH-D5 | P3 | Status `cancelled` di komentar migration tanpa kolom enum ketat (string bebas) — garbage status lolos filter. | migration 25 | Validasi `Rule::in`. |

---

## Modul Elektronik OFF

| Lapisan | Perilaku |
|---------|----------|
| Setting | `modules.elektronik_enabled=false` |
| API | `/serial-hpp-corrections*` → 403 (`feature.elektronik`) — di `ElektronikModuleTest` |
| FE menu | `serialEnabled && can('serial-hpp.view')` |
| FE router | `requiresElektronik` → dashboard |
| Dashboard | modul `serial_hpp` di-unset dari approval |
| Lock OFF | draft HPS mengunci disable modul (`SettingController::elektronikLockStatus`) |
| Role matrix | prefix `serial-hpp` disembunyikan saat OFF; permission DB preserved |

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `serial-hpp.view` |
| Filter status / tanggal | Ya | same |
| Filter produk / gudang | Tidak (FE) | — |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view (cost selalu) |
| Export Excel | Tidak | — |
| Create draft | Ya | `serial-hpp.create` |
| Edit draft | Ya | `serial-hpp.update` |
| Delete draft | Ya | `serial-hpp.delete` |
| Approve | Ya | `serial-hpp.approve` |
| Lock / Void | Tidak | — |

Seed role: **admin** semua + approve; **gudang** CRUD tanpa approve & tanpa `stok.view_hpp`; **kasir** tidak punya `serial-hpp.*`.

---

## Antrian patch (usulan prioritas)

1. **P0** SH-B1/U2 — approve jangan silent-skip / jangan bohong di detail.  
2. **P0** SH-B3/C1 — `lockForUpdate` header + re-check draft.  
3. **P0** SH-B2/U1 — perbaiki copy FE/PHPDoc.  
4. **P0** SH-S1/X2 — gate cost dengan `stok.view_hpp` (atau strip).  
5. **P0** SH-X1 — ikut fix filter WH Pergerakan HPP (HM-B2).  
6. **P1** SH-B4/B5/B7, SH-D1, tes SH-C3.  
7. **P2+** sisa UI/DB/rounding.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `SerialHppCorrectionTest` | create→approve + landed/pajak + avg Metode A + HPP_CORRECTION + movement; tolak non-tersedia create; units endpoint; tax off; double approve 422; approve butuh `.approve` |
| `SerialAccessCoverageTest` | index 403 tanpa view; create 403 tanpa create |
| `ElektronikModuleTest` | OFF blocks `/serial-hpp-corrections` |
| Domain `serial.md` §tes | **usang** (“avg tak berubah”) |

**Tidak ada** e2e Playwright khusus halaman (hanya locator menu `menu-inventory-serial-hpp` di `docs-helpers.js`).

---

## Ringkasan eksekutif

Fitur inti (komponen biaya → landed server-side, Metode A, movement, permission approve terpisah, gate elektronik) **berfungsi pada happy path**. Kerapuhan utama: **approve yang boleh “sukses kosong/parsial” tanpa transparansi**, **race double-approve**, **copy yang menyangkal propagasi avg_cost**, dan **kebocoran HPP ke role gudang tanpa `stok.view_hpp`**, plus jejak `HPP_CORRECTION` yang mudah hilang di Pergerakan HPP (filter gudang). Banding retail Koreksi HPP: serial **kalah** di product-lock draft dan kejujuran audit apply.
