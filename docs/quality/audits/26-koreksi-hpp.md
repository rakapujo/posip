# Audit menu — 26 Inventory → Koreksi HPP (retail / non-serial)

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/HppCorrectionPage.vue` · `HppCorrectionFormPage.vue` · `api/modules/hppCorrections.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/HppCorrectionController.php` · `Actions/HppCorrection/{Create,Update,Approve}HppCorrectionAction.php` · `Models/DocHppCorrection*`  
> - Mutasi: `MasterProduk.avg_cost` overwrite → `syncAvgCostToInventoryStocks` · `StockCard` `HPP_CORRECTION` (**`warehouse_id` null**, qty 0) — **tidak** ubah `inventory_stock.qty`  
> - Routes FE: `inventory-hpp-correction*` · API: `/hpp-corrections*` (`routes/api.php` 444–454)  
> - Menu: `AppMenu.vue` Inventory → Koreksi HPP (setelah Repack; **bukan** “Koreksi HPP Serial”)  
> - Tes: `tests/Feature/HppCorrection/HppCorrectionCrudTest.php` · partial `InventoryAccessCoverageTest` · `InventoryDownstreamGuardTest` (create inactive) · `SerialFase1Test` picker · `SettingServiceTest` prefix · **tanpa** tes race paralel / update+serial / approve inactive / HTTP `hpp=0`  
> - Domain/bisnis: `docs/ai/business-rules.md` §B (HPP weighted) + §F tipe kartu; **tidak** ada seksi khusus menu ini; jejak WH-null = **HM-B2** di `19-pergerakan-hpp.md`  
> - Banding serial: `21-koreksi-hpp-serial.md` (prefix temuan `SH-`)  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Repack di `AppMenu.vue` (akhir grup Inventory sebelum Pembelian).

## Scope

Koreksi **HPP agregat** (`master_produk.avg_cost`) untuk produk **non-serial** (`is_serial=false`). Alur **draft → approved** saja (tanpa lock dokumen / void / cancel). Tidak terikat gudang — HPP global. Satu **draft aktif global** di seluruh sistem; produk di draft terkunci dari draft lain (efek praktis terbatas karena hanya 1 draft).

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /hpp-corrections` | `hpp.view` | List |
| `GET /hpp-corrections/{ulid}` | `hpp.view` | Detail + form edit + PDF client |
| `POST /` | `hpp.create` | Draft create |
| `PUT /{ulid}` | `hpp.update` | Draft update |
| `DELETE /{ulid}` | `hpp.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `hpp.approve` + throttle 30/1 | Apply avg_cost + kartu |
| `GET /products` | `hpp.create` **saja** | Autocomplete (exclude serial + locked) |
| `GET /check-draft` | `hpp.create` | Preflight satu draft |
| `GET /locked-products` | `hpp.create` | **API ada; FE tidak memanggil** |
| `GET /alasan-options` | **tanpa** `can()` | **API ada; FE hardcode enum** |
| FE route list | `hpp.view` | |
| FE create / edit | `hpp.create` / `hpp.update` | |
| AppMenu | `hpp.view` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search / status / date); detail dialog; export PDF **client-side**. **Tidak:** lock dokumen, void/cancel, Excel, filter produk/gudang, barcode scan, Policy Spatie, nested serial.

**Lifecycle nyata:** satu `approve` = set `avg_cost` = `hpp_baru` per baris + sync semua `inventory_stock.avg_cost` + 1 kartu `HPP_CORRECTION` per produk (qty 0, WH null) + refresh `hpp_lama` ke nilai saat approve. Stok fisik **tidak** berubah.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `HPC` | `SettingService` map `hpp_correction` → `HPC`; `generateDocumentNumber` + sequence lock |
| Status string: `draft` \| `approved` saja | migration `2026_01_24_140001_*` 19 |
| Satu draft global | `CreateHppCorrectionAction` 25–32; FE `checkDraftAndCreate` |
| Product lock antar draft | Detail di status draft → exclude picker; Action create/update conflict | Controller `getLockedProductIds`; Create 44–51; Update 31–40 |
| Unique produk per dokumen | UNIQUE `(correction_id, product_id)` + `hasDuplicateProducts` | migration detail 31; Controller 66–69 |
| Serial ban | Picker `is_serial=false`; Create Action cek serial; **Update Action / DocumentErrors / PayloadErrors tanpa `blockSerial`** | Controller 342; Create 37–42; `InventoryMasterRules` 57–59, 106–112 |
| Approve HPP | Overwrite `avg_cost` (bukan weighted); sync stok; kartu WH null | `ApproveHppCorrectionAction` 42–76 |
| `hpp_lama` | Snapshot create/update; **di-overwrite** lagi saat approve dari `avg_cost` terkini | Create 75–88; Approve 75–76; tes 253–274 |
| SoftDeletes | **tidak** pada header/detail | models; `destroy` hard + cascade |
| Draft ≠ ubah HPP | create/update tidak touch `avg_cost` | CrudTest 92–97 |
| Alasan enum | `KOREKSI_HARGA_BELI` / `KOREKSI_DATA` / `MIGRASI_SISTEM` / `LAINNYA` (+ notes wajib) | Controller 22–27, 39–40, 75–83 |

**Rumus approve (aktual):**

```
∀ detail:
  currentHpp = product.avg_cost          // lockForUpdate produk
  product.avg_cost := detail.hpp_baru    // overwrite absolut
  syncAvgCostToInventoryStocks()
  StockCard HPP_CORRECTION (WH=null, qty_in=qty_out=0,
    avg_before=currentHpp, avg_after=hpp_baru, cost_per_unit=hpp_baru)
  detail.hpp_lama := currentHpp
header.status := approved
```

Bukan `recalculateAvgCost` weighted. Kompetisi dengan PO/Adj IN/Repack hasil = **last writer wins** pada `avg_cost`.

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HC-B1 | P0 | **Race double-approve → dobel kartu `HPP_CORRECTION` + overwrite avg berulang.** `isDraft()` dicek **di luar** TX; header **tidak** `lockForUpdate` / re-check status di dalam TX. Dua request paralel lolos draft → dua putaran update produk + dua baris kartu (qty tetap, jejak HPP dobel / `avg_before` menipu). | `ApproveHppCorrectionAction` 23–27 vs 29–89; status update baru di 80–84 | `lockForUpdate` header di dalam TX + re-check draft (pola price-change). |
| HC-B2 | P0 | **Race double-create → dua draft global** (melanggar invariant “hanya 1 draft”). Cek `where status=draft` tanpa lock baris/advisory; dua TX paralel keduanya lihat kosong → dua `HPC-…`. Product-lock & FE checkDraft tidak menutup race. | `CreateHppCorrectionAction` 25–32 (di dalam TX tapi tanpa lock “slot” draft) | Lock unique partial index `status=draft` **atau** `lockForUpdate` sentinel / `SELECT … FOR UPDATE` pada draft existing sebelum insert. |
| HC-B3 | P1 | **Update & approve tidak re-block serial.** Create menolak `is_serial`; `UpdateHppCorrectionAction` **tanpa** cek serial; `hppCorrectionPayloadErrors` / `hppCorrectionDocumentErrors` memanggil `inventoryProductLinesErrors` **tanpa** `blockSerial: true`. Produk di-flip serial setelah draft, atau API update menyisipkan `product_id` serial → approve **overwrite `avg_cost` master** tanpa sentuh `serial_units` / Metode A → desync register vs HPP agregat. | Create 37–42 vs Update 30–75; `InventoryMasterRules` 57–59, 106–112; Approve tidak cek `is_serial` | `blockSerial: true` di payload + document errors; mirror guard di Update/Approve Action. |
| HC-B4 | P1 | **Tidak ada void/cancel setelah approve.** Enum hanya draft/approved. Salah nilai HPP hanya dilawan dokumen koreksi baru (atau mutasi masuk weighted). | migration status; no void route | Keputusan produk: dokumentasikan SOP reverse via koreksi baru **atau** void reverse avg + kartu reverse. |
| HC-B5 | P1 | **Overwrite absolut mengalahkan weighted masuk yang terjadi setelah draft.** Tes sengaja: avg berubah 10k→13k sebelum approve → kartu `before=13k`, after=`hpp_baru`. Operator kira `hpp_lama` di form (snapshot draft) = dampak; PDF/detail draft menampilkan selisih menipu sampai approve. | Form `hpp_lama` dari draft; Approve 42–76; CrudTest 253–274 | UI: “HPP saat approve bisa beda”; optional warn jika `product.avg_cost ≠ hpp_lama` sebelum approve. |
| HC-B6 | P1 | **StockCard ditulis meski noop** (`hpp_baru === currentHpp`). Polusi Pergerakan HPP / Kartu (tipe no-qty). Mirror **SH-B5**. | Approve 45–73 tanpa epsilon guard | Skip card + skip update bila `abs(delta) < epsilon` (atau tetap update detail notes saja). |
| HC-B7 | P1 | **HTTP `hpp_baru` wajib `gt:0`; Action+tes mengizinkan 0.** `approve_hpp_correction_dapat_set_hpp_menjadi_nol` lewat Action langsung — jalur API/FE (`gt:0`, InputNumber `min:0.0001`) **tidak** bisa set 0. Reset HPP ke 0 hanya via `HPP_RESET` stok kosong atau Action internal. | Controller 39; Form 426; CrudTest 282–298 | Samakan: izinkan `gte:0` di API **atau** hapus/ubah tes & dokumentasikan “0 hanya HPP_RESET”. |
| HC-B8 | P1 | **Tanggal masa depan:** FE `maxDate=now`; BE hanya `required\|date` — API boleh tanggal > now. | Form 348; Controller 35 | `before_or_equal:now` di BE. |
| HC-B9 | P2 | **Approve dari form tidak flush dirty form.** Tombol Approve panggil API dokumen tersimpan; edit HPP di UI tanpa Simpan → approve nilai lama. | Form 276–299 vs 223–274 | Disable Approve jika dirty, atau autosave, atau konfirmasi “simpan dulu”. |
| HC-B10 | P2 | **Satu draft global = bottleneck operasional.** Koreksi produk A menahan seluruh perusahaan; tidak bisa paralel multi-user / multi-SKU batch terpisah. Product-lock hampir redundant di atas aturan ini. | Create 25–32; Page 99–116 | Keputusan: tetap (anti-chaos) atau longgarkan ke lock per-produk saja (parity kebutuhan vs Serial yang multi-draft). |
| HC-B11 | P2 | **`product_id` numerik** di payload (bukan ULID) — inkonsisten ULID-first modul baru; bocor ID internal. | Controller 38; Form payload 235 | Resolve ULID → id di BE. |
| HC-B12 | P3 | Notes header `max:1000` / detail `max:255`; FE Textarea/InputText tanpa maxlength. Detail DB `text` longgar vs validasi 255. | Controller 36, 41; Form 354, 454–460; migration detail 26 | maxlength mirror; samakan tipe. |
| HC-B13 | P3 | Alasan `LAINNYA` tanpa notes ditolak BE+FE — OK. Enum tidak extensible tanpa deploy. | Controller 75–83 | — |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HC-S1 | P1 | **Bypass `stok.view_hpp`:** `getProducts` selalu return `avg_cost`; `show` load `product.avg_cost`; detail/PDF/form autocomplete tampilkan HPP **tanpa** gate `stok.view_hpp`. Role seed **gudang tidak punya `hpp.*`** (lebih baik dari Serial HPS), tapi role custom `hpp.view/create` tanpa `view_hpp` tetap bocor. | Controller 343, 361–369, 193–194; Form 405; Page detail/PDF 161–172; Seeder 122–138 vs 41 | Strip `avg_cost` / kolom HPP kecuali `stok.view_hpp`; gate UI. |
| HC-S2 | P1 | **`getProducts` / `check-draft` / `locked-products` hanya `hpp.create`:** user `hpp.update` saja → autocomplete 403 di edit (route edit = `hpp.update`). | Controller 331–333, 382–384, 402–404; router 296–299 | Gate `create \|\| update`. |
| HC-S3 | P1 | **`getAlasanOptions` tanpa permission** (siapa pun authenticated). | Controller 416–420 | `can('hpp.view')` minimal. |
| HC-S4 | P2 | **Throttle hanya approve**; store/update/delete/check-draft tanpa throttle. Serial HPS throttle store/update juga. | `api.php` 444–454 vs 180–187 | Throttle write paths. |
| HC-S5 | P2 | Filter `status` tidak `Rule::in` — garbage → query kosong. `sort_order` **tidak** di-whitelist (`asc`/`desc`) meski `sort_field` aman. | Controller 107–125 | Validasi status + sort_order. |
| HC-S6 | P2 | **Access coverage tipis:** create/approve 403 saja; **tidak** tes update/delete/products/check-draft/alasan/index/`view_hpp` strip. | `InventoryAccessCoverageTest` 211–229 | Tambah matrix. |
| HC-S7 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK. |
| HC-S8 | P3 | **Gudang tanpa `hpp.*` + tanpa `hpp.approve`** — SoD kuat vs Serial (gudang punya `serial-hpp` CRUD). Dashboard pending `hpp` → `hpp.approve`. | Seeder 122–138 vs 46/95; DashboardController 88, 275 | OK; dokumentasikan beda dengan #21. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HC-C1 | P0 | = **HC-B1** TOCTOU approve tanpa lock header. | Approve Action | Lock + re-check. |
| HC-C2 | P0 | = **HC-B2** TOCTOU create draft ganda. | Create Action | Unique/lock draft. |
| HC-C3 | P1 | **Coverage race/HTTP tipis:** CrudTest = Action-level sequential; **bukan** paralel HTTP; tidak ada tes update+serial, approve+serial flip, approve inactive, future date, products tanpa create, noop card, `view_hpp`. Tes `hpp=0` kontradiksi validasi HTTP (HC-B7). | `HppCorrectionCrudTest` | Tes race + HTTP matrix + edge. |
| HC-C4 | P1 | **N+1** create/update: `MasterProduk::find` per baris detail. | Create 74–77; Update 57–60 | `whereIn` + map. |
| HC-C5 | P2 | `catch (\Exception)` generik → 500 string message di store/update/approve — bisa bocor detail. | Controller 179–180, 265–266, 321–322 | Catch domain exceptions saja. |
| HC-C6 | P2 | `destroy` tidak di TX eksplisit; cascade FK OK. Hard-delete hapus jejak dokumen (header punya `HasAuditLog` sebelum delete). | Controller 289 | SoftDeletes opsional. |
| HC-C7 | P2 | Duplikasi validasi duplikat/LAINNYA/InventoryMasterRules di store & update. | Controller 151–169 ≈ 237–255 | Private `assertHppPayload`. |
| HC-C8 | P2 | `getLockedProducts` + `getAlasanOptions` dead API dari sisi FE (alasan hardcode; lock sudah di `getProducts`). | `hppCorrections.js` 83–89; Form 48–53; Page tidak call locked | Hapus endpoint atau pakai di FE (SSoT alasan). |
| HC-C9 | P3 | Create/Update Actions hampir identik untuk write lines + notes format. | Create 73–92; Update 53–75 | Shared writer. |
| HC-C10 | P3 | Accessor `hpp_difference` / `alasan_label` di detail — OK jika tidak di-append list. | Detail model 73–84 | — |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| HC-X1 | P0 | Writer `HPP_CORRECTION` WH **null** → **hilang di Pergerakan HPP** saat filter gudang (= **HM-B2 / HM-X1 / SH-X1**). Koreksi retail “berhasil” tapi operator filter WH tidak melihat jejak. | Approve 58–62; audit 19 |
| HC-X2 | P0 | = **HC-B1** vs jejak Kartu Stok / Pergerakan HPP — race → kartu dobel; qty invariant `data:verify` tetap hijau (TYPES_NO_QTY) sehingga **salah HPP tidak ketahuan verify**. | StockCard `TYPES_NO_QTY`; CrudTest verify hanya happy path |
| HC-X3 | P1 | = **HC-B3** vs **Register Unit Serial / Koreksi HPP Serial (#21)** — approve serial lewat jalur retail merusak Metodе A / unit cost. Serial menu tanpa product-lock draft (SH-B4) — dua standar. |
| HC-X4 | P1 | **Stok / valuation:** sync `inventory_stock.avg_cost` saat approve — list stok & valuation ikut (benar). Drift ST-B5 tidak relevan di sini karena sync dipanggil. |
| HC-X5 | P1 | **POS / Sales COGS:** koreksi **tidak** mengubah COGS historis penjualan; jual berikutnya pakai `avg_cost` baru (non-serial). Kompetisi waktu draft↔jual = ekspektasi margin. |
| HC-X6 | P1 | **PO approve / Adj IN / Repack hasil / Transfer biaya:** semua bisa ubah `avg_cost` saat draft koreksi hidup → approve koreksi **menimpa** (HC-B5). Transfer biaya juga tulis `HPP_CORRECTION` WH null — jejak campur tanpa deep-link HPC vs TRF. |
| HC-X7 | P2 | **Opname / Adjustment qty:** tidak ubah HPP (kecuali Adj IN weighted); tidak freeze produk selama draft koreksi. |
| HC-X8 | P2 | **HPP_RESET** (stok global ≤0) vs koreksi manual — keduanya di Pergerakan HPP; RESET punya WH pemicu, CORRECTION null (HM-B3). |
| HC-X9 | P2 | **Dashboard** pending `hpp` → `inventory-hpp-correction`. Reset DB: `refuseIfHasNonDraft` untuk `hpp_correction` (chip draft-only). `ResetTargetMatrixTest` **tidak** cover target hpp (beda repack/adj). |
| HC-X10 | P2 | **Kartu Stok:** `HPP_CORRECTION` di `TYPES_NO_QTY`; summary bug KS-B1 berlaku. Deep-link `source_doc` ke HPC **tidak** ada (sama RPK/HPS). |
| HC-X11 | P3 | **Elektronik OFF:** menu retail **tidak** di bawah `feature.elektronik` (benar). Guard serial tetap. |
| HC-X12 | P3 | **FK `product_id` cascadeOnDelete** pada detail: hapus master produk menghapus baris detail dokumen **termasuk approved** → jejak koreksi HPP hilang / header orphan item count. | migration detail 17 |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HC-U1 | P1 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`. | Page imports | — |
| HC-U2 | P1 | = **HC-S1** — HPP selalu terlihat di form/detail/PDF tanpa `view_hpp`. | Form 405; Page 344–352, 161–163 | Gate visual. |
| HC-U3 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten Stok/Register. | API module | Optional Excel. |
| HC-U4 | P2 | Tidak ada scan barcode produk — hanya AutoComplete. | Form | Optional scan. |
| HC-U5 | P2 | List tidak kolom ringkasan selisih / alasan dominan — audit dari grid sulit. | Page columns 261–289 | Kolom opsional. |
| HC-U6 | P2 | = **HC-B9** Approve tanpa dirty-check. | Form footer | — |
| HC-U7 | P2 | Filter FE: status + tanggal saja (OK mirror BE). Tidak filter produk/alasan meski search notes ada. | Page 217–226 | Optional. |
| HC-U8 | P3 | `checkDraft` error → tetap navigate create (bisa 422 BE) — fallback agresif. | Page 117–119 | Toast error, jangan push create. |
| HC-U9 | P3 | Warna selisih + = merah / − = hijau (naik HPP “bahaya”) — OK operasional; beda sedikit dari Repack cost cards. | Page/Form severity helpers | — |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| HC-D1 | P1 | Index header: `status`, `tanggal_koreksi`, UNIQUE `nomor_dokumen`/`ulid` — cukup; search `LIKE %…%` lemah skala besar. **Tidak ada** unique partial “satu draft”. | migration 140001 | Unique partial `WHERE status='draft'` (HC-B2). |
| HC-D2 | P1 | Detail UNIQUE `(correction_id, product_id)` — bagus. Index `product_id`. | migration 140002 | — |
| HC-D3 | P1 | = **HC-X12** cascade delete produk → hapus history detail. | FK cascade | `restrict` / soft-delete produk. |
| HC-D4 | P2 | SoftDeletes: tidak dipakai — hard delete draft hilangkan jejak. | destroy | — |
| HC-D5 | P2 | Approve: lock N produk + sync all WH avg — OK tipikal; banyak baris = TX lebih panjang; **header tidak dilock**. | Approve foreach | — |
| HC-D6 | P3 | Nomor dokumen: sequence `lockForUpdate` di dalam TX create — race nomor relatif aman; race **draft ganda** tetap (HC-B2). | SettingService; Create TX | — |
| HC-D7 | P3 | Decimal HPP 15,4 — OK; FE currency fraction settings bisa beda display vs simpan. | migration; Form InputNumber | — |

---

## Lifecycle matriks (aktual)

| Aspek | Perilaku kode |
|-------|----------------|
| Create | Draft; `avg_cost` **tidak** berubah; nomor `HPC-…`; `hpp_lama` snapshot; tolak jika sudah ada draft global / serial / locked product |
| Update / Delete | Draft only; replace details; hard delete + cascade detail |
| Approve | Overwrite `avg_cost` + sync stok + kartu WH null + refresh `hpp_lama`; status `approved` |
| Lock / Void / Cancel | **Tidak ada** |
| Serial | Ditolak create + disembunyikan picker; **update/approve tidak re-block** |
| Stok qty | Tidak berubah |
| Double approve sequential | 422 status (tes Action ada) |
| Double approve / create paralel | **Tidak diuji**; rentan HC-B1 / HC-B2 |

---

## Matriks permission role (seed)

| Permission | super-admin | admin | gudang | kasir |
|------------|:-----------:|:-----:|:------:|:-----:|
| `hpp.view` | ✓ | ✓ | — | — |
| `hpp.create` | ✓ | ✓ | — | — |
| `hpp.update` | ✓ | ✓ | — | — |
| `hpp.delete` | ✓ | ✓ | — | — |
| `hpp.approve` | ✓ | ✓ | — | — |
| `stok.view_hpp` (orthogonal) | ✓ | ✓ | — | — |

Sumber: `RolePermissionSeeder` 46, 95; gudang 122–138 **tanpa** `hpp.*` (beda tajam vs `serial-hpp.*` di gudang).

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `hpp.view` |
| Filter status / tanggal | Ya | same |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view (**HPP cols tanpa** `view_hpp`) |
| Export Excel | Tidak | — |
| Create draft (+ checkDraft) | Ya | `hpp.create` |
| Edit draft | Ya | `hpp.update` |
| Delete draft | Ya | `hpp.delete` |
| Approve (list + form) | Ya | `hpp.approve` |
| Lock / Void / Cancel | Tidak | — |
| Product lock UX | Via picker exclude + checkDraft | create |
| Scan barcode | Tidak | — |
| `getLockedProducts` / `getAlasanOptions` | API only | — |

---

## Parity singkat vs #21 Koreksi HPP Serial

| Aspek | Retail (#26) | Serial (#21) |
|-------|--------------|--------------|
| Target | `avg_cost` agregat non-serial | `harga_modal` / `cost_per_unit` unit `tersedia` |
| Nomor | `HPC` | `HPS` |
| Draft | **1 global** + product lock | Multi-draft; **tanpa** unit/product lock (SH-B4) |
| Feature flag | Tidak | `feature.elektronik` |
| Gudang role | **Tidak** punya `hpp.*` | Punya CRUD `serial-hpp.*` tanpa approve |
| Race approve | HC-B1 (= SH-B3) | SH-B3 |
| Silent-skip unit | N/A | SH-B1 P0 |
| Copy “tidak ubah avg” | N/A (retail memang ubah avg) | SH-B2 bohong |
| `HPP_CORRECTION` WH null | Sama → HM-B2 | Sama |
| Throttle write | Hanya approve | store/update/approve |
| Views path | `views/inventory/` | `views/master/` (SH-U6) |

Retail lebih ketat di SoD seed & single-draft; serial lebih ketat di throttle write & coverage elektronik. Keduanya rentan race approve + jejak WH-null.

---

## Antrian patch (usulan prioritas)

1. **P0** HC-B1/C1/X2 — `lockForUpdate` header + re-check draft sebelum mutasi.  
2. **P0** HC-B2/C2/D1 — cegah draft ganda (unique partial / lock).  
3. **P0** HC-X1 — ikut fix filter Pergerakan HPP (HM-B2) include `warehouse_id IS NULL`.  
4. **P1** HC-B3/X3 — `blockSerial` di update/approve + Action.  
5. **P1** HC-S1/U2 — strip/gate HPP vs `stok.view_hpp`.  
6. **P1** HC-S2/S3 — products `create\|\|update`; alasan-options permission.  
7. **P1** HC-B5/B6/B7 — UX hpp_lama drift; skip noop card; samakan aturan `hpp=0`.  
8. **P1** HC-C3/C4/D3 — tes race/HTTP + batch load produk + FK restrict.  
9. **P2+** tanggal BE, throttle writes, SoftDeletes/void keputusan, Excel, barcode, dirty-approve, dead API, deep-link KS/HM ke HPC.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `HppCorrectionCrudTest` | create snapshot `hpp_lama` + prefix HPC; draft tidak ubah avg; blok draft kedua (Action sequential); approve avg + sync stok + kartu WH null qty 0; qty stok tetap; sequential double-approve; tolak serial create; nilai eksak + `data:verify`; re-capture `hpp_lama` saat approve; **hpp=0 via Action**; sequential no double card |
| `InventoryAccessCoverageTest` | create/approve 403 (tanpa update/delete/products/check-draft/`view_hpp`) |
| `InventoryDownstreamGuardTest` | create reject inactive product (**tidak** approve inactive / update) |
| `SerialFase1Test` | picker `getProducts` exclude serial |
| `SettingServiceTest` | prefix `HPC` |
| `StockCardTransactionTypesTest` | tipe `HPP_CORRECTION` ∈ `TYPES_NO_QTY` |
| `ResetTargetMatrixTest` | **tidak** ada kasus `hpp_correction` |
| Transfer / Serial HPS tests | writer `HPP_CORRECTION` lain (bukan menu ini) |

**Tidak ada** e2e Playwright fungsional khusus (hanya screenshot/docs locator `menu-inventory-hpp-correction`).

---

## Ringkasan eksekutif

Alur inti (**satu draft global → approve** = overwrite `avg_cost` non-serial + sync stok + kartu `HPP_CORRECTION` WH null, serial ditolak di create/picker, SoD gudang tanpa `hpp.*`, tes sequential + verify qty) **berfungsi di happy path**. Kerapuhan utama: **race double-approve tanpa lock header**, **race create dua draft**, **update/approve tidak re-block serial** (risiko desync vs #21), **jejak koreksi hilang di Pergerakan HPP bila filter gudang (HM-B2)**, **kebocoran HPP tanpa `stok.view_hpp`**, serta **tidak ada void** / inkonsistensi **HPP=0** Action vs API.
