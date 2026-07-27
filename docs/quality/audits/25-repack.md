# Audit menu — 25 Inventory → Repack

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/RepackPage.vue` · `RepackFormPage.vue` · `api/modules/repacks.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/RepackController.php` · `Actions/Repack/{Create,Update,Approve}RepackAction.php` · `Models/DocRepack*`  
> - Mutasi: `InventoryStock` (satu WH) · `StockCard` `REPACK_OUT` / `REPACK_IN` (+ opsional `HPP_RESET` bahan habis) · `MasterProduk::recalculateAvgCost` pada **hasil**  
> - Routes FE: `inventory-repack*` · API: `/repacks*` (`routes/api.php` 417–426)  
> - Menu: `AppMenu.vue` Inventory → Repack (setelah Transfer, sebelum Koreksi HPP)  
> - Tes: `tests/Feature/Repack/RepackCrudTest.php` · `RepackHppResetTest.php` · `RepackSerialBlockTest.php` · partial `InventoryAccessCoverageTest` · `ResetTargetMatrixTest` · **tanpa** tes HTTP inactive WH/produk khusus repack di `InventoryDownstreamGuardTest`  
> - Domain: `docs/domain/serial.md` §Repack guard-only · `docs/ai/business-rules.md` §B (Repack hasil = weighted recalc)  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Transfer di `AppMenu.vue` (sebelum Koreksi HPP).

## Scope

Konversi stok **dalam satu gudang**: dokumen **draft → approved** (tanpa lock / void / cancel). Dua tipe UI/BE:

| Tipe | Constraint item |
|------|-----------------|
| `pecah` | max **1** input, ≥1 output |
| `gabung` | ≥1 input, max **1** output |

Approve atomik: `REPACK_OUT` bahan (HPP input **tidak** weighted-recalc; snapshot `avg_cost`) + alokasi nilai `(Σ cost input + biaya_repack)` **proporsional qty** ke output → `REPACK_IN` + `recalculateAvgCost` per produk hasil. Serial: **dilarang** (picker + create/update); **bukan** alur unit. Tidak ada master BOM/rasio — qty absolut bebas.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /repacks` | `repack.view` | List |
| `GET /repacks/{ulid}` | `repack.view` | Detail + form edit + PDF |
| `POST /` | `repack.create` | Draft create |
| `PUT /{ulid}` | `repack.update` | Draft update |
| `DELETE /{ulid}` | `repack.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `repack.approve` + throttle 30/1 | Approve + mutasi stok/HPP |
| `GET /products` | `repack.create` **saja** | Autocomplete (+ refresh stok edit) |
| `GET /stock-setting` | **tanpa** `can()` | FE preflight negatif stok |
| FE route list | `repack.view` | |
| FE create / edit | `repack.create` / `repack.update` | |
| AppMenu | `repack.view` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search / WH / tipe / status / date); detail dialog; export PDF **client-side**. **Tidak:** lock dokumen, void/cancel, Excel, barcode scan produk, Policy Spatie, nested serial picker, filter biaya.

**Lifecycle nyata:** satu `approve` = stok bahan turun + stok hasil naik + HPP hasil weighted + header `total_cost_*` terisi. Tidak ada status lain.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `RPK` | `SettingService` map `repack` → `RPK`; `generateDocumentNumber` + sequence lock |
| Status string: `draft` \| `approved` saja (enum DB) | migration `2026_01_24_120001_*` 24 |
| Same-product ban input↔output | Controller `hasOverlappingProducts`; FE validate |
| Duplikat produk per sisi | UNIQUE `(repack_id, product_id)` input & output + controller `hasDuplicateProducts` |
| Tipe item count | Controller `validateItemCountByTipe`; FE `maxInputs`/`maxOutputs` |
| Serial ban | `getProducts` `is_serial=false`; `repackPayloadErrors(..., blockSerial: true)`; **approve** `repackDocumentErrors` **tanpa** `blockSerial` |
| HPP input OUT | snapshot `avg_cost`; kartu `avg_before==avg_after`; boleh `HPP_RESET` jika global qty ≤0 | `ApproveRepackAction` 88–143 |
| HPP output IN | `cpu = (totalCostInput+biaya) / Σ qty_out` (sama untuk semua SKU hasil); lalu weighted global | Action 146–207; `recalculateAvgCost` |
| SoftDeletes | **tidak** pada header/detail | models; `destroy` hard + cascade |
| Draft ≠ reserve stok | create/update tidak touch inventory | Create/Update Actions |

**Rumus HPP approve (aktual):**

```
totalCostInput = Σ (qty_in_i × avg_cost_produk_i)   // snapshot saat approve
totalCostOutput = totalCostInput + biaya_repack
cost_per_unit_out_j = totalCostOutput / Σ qty_out     // identik untuk setiap baris output
total_cost_out_j = cost_per_unit_out_j × qty_out_j
avg_cost_produk_j' = weighted(total_stock_before, avg, qty_out_j, cost_per_unit_out_j)
```

Tidak ada alokasi by-value antar SKU hasil berbeda; “rasio” = hanya proporsi qty terhadap total qty output.

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RP-B1 | P0 | **Race double-approve → dobel mutasi stok + kartu + HPP.** `isDraft()` dicek **di luar** TX; header **tidak** `lockForUpdate` / re-check status di dalam TX. Dua request paralel lolos draft → keduanya `REPACK_OUT`/`IN`, `recalculateAvgCost`, `HPP_RESET` (bila habis). Tidak ada pola skip-card (beda Transfer) — kartu ikut dobel → `data:verify` merah + HPP rusak. | `ApproveRepackAction` 26–31 vs 33–218; status update baru di 211–217 | `lockForUpdate` header di dalam TX + re-check draft sebelum mutasi. |
| RP-B2 | P1 | **Tidak ada void/cancel setelah approve.** Enum hanya draft/approved. Salah approve (qty/tipe/biaya/WH) hanya dilawan repack balik / adjustment / koreksi HPP manual. | migration status; no void route | Keputusan produk: void reverse OUT/IN + reverse weighted HPP (sulit) atau SOP kompensasi ketat. |
| RP-B3 | P1 | **Alokasi biaya/HPP antar output hanya by-qty → semua SKU hasil dapat `cost_per_unit` sama.** Pecah 1 bahan → 2 produk beda nilai jual/BOM tidak bisa bedakan landed cost. Estimasi FE (`estimatedHppPerOutput`) mengunci ekspektasi ini. | Action 155–157; Form 359–364; tes distribusi `RepackCrudTest` 259–292 | Dokumentasikan ketat; atau opsi alokasi by-value / bobot manual per baris. |
| RP-B4 | P1 | **Approve tidak `blockSerial`.** Create/update menolak serial; `repackDocumentErrors` memanggil `inventoryProductLinesErrors` **tanpa** `blockSerial: true`. Draft legacy / produk di-flip jadi `is_serial` setelah draft → approve mengurangi/menambah `inventory_stock` **tanpa** touch `serial_units` → desync register vs stok. | `InventoryMasterRules` 92–103 vs 44–50; Action tidak cek `is_serial` | `blockSerial: true` di `repackDocumentErrors` + guard di Action. |
| RP-B5 | P1 | **Input tanpa baris `inventory_stock` → approve crash (Error null→qty), bukan 422.** Validasi: `$stocks[$id]->qty ?? 0` — missing key = null, akses `->qty` sebelum `??`. Produk stok 0 dari picker tetap valid draft. | Action 59–60; `getProducts` 429–441 | Nullsafe / `($stocks[$id]->qty ?? 0)`; treat missing as 0. |
| RP-B6 | P1 | **Tanggal masa depan:** FE tolak (`isAfterNow` + `maxDate`); BE hanya `required\|date` — API boleh tanggal > now. | Form 383–385, 545; Controller 28 | `before_or_equal:now` di BE. |
| RP-B7 | P1 | **Tidak ada master BOM / rasio wajib** (1 karton = N pcs). Qty absolut bebas — operator bisa pecah 1→1 atau 1→10000 tanpa cek konversi unit master. | Form/Controller (no ratio field); model tidak refer unit konversi | Keputusan: tetap free-form (dokumentasikan) atau validasi terhadap `konversi_*`. |
| RP-B8 | P2 | **Qty > stok bahan:** create/update **tidak** blok; approve hormati `negative_stock_allowed`. FE Message warn; `errors.insufficient_stock` **tidak** memblok `validate()` (difilter). | Form 443–448; Approve 55–73 | OK bila disengaja; optional preflight API. |
| RP-B9 | P2 | **Negatif stok bahan (`≤0` global) memicu `HPP_RESET`.** Mode allow-negative: stok −N tetap “ada” secara fisik negatif tapi HPP bahan jadi 0. | `checkAndResetHppIfStockEmpty` 285–286; Action 137–143 | Reset hanya `=== 0` atau skip reset saat negative mode. |
| RP-B10 | P2 | **Pesan validasi qty** `'Qty … minimal 1'` vs rule `min:0.0001`; kolom DB `unsignedInteger` + cast `integer` — fraksi bisa terpotong; edge `qty`→0 setelah cast → **division by zero** di `totalOutputQty` / `/$output->qty`. | Controller 33–37, 57–63; Detail cast 50; Action 149, 156–157 | Validasi integer `min:1`; guard `totalOutputQty > 0`. |
| RP-B11 | P2 | **Update boleh ganti `warehouse_id` / `tipe`.** FE konfirmasi reset: ganti WH hanya clear **inputs** (outputs tetap); ganti tipe clear keduanya. BE tidak memaksa reset outputs saat WH berubah. | Update Action 38–44; Form watch 196–218 vs 54–78 | BE: revalidasi / kosongkan detail bila WH berubah; FE reset outputs juga. |
| RP-B12 | P2 | **Biaya repack selalu masuk HPP hasil** (tidak ada opt-out seperti transfer `masuk_hpp`). Biaya 0 = no-op; biaya >0 selalu naikkan landed. | Action 147–148; Form field selalu ada | Dokumentasikan; atau flag opt-in bila biaya operasional tidak boleh ke HPP. |
| RP-B13 | P3 | Notes `max:1000`; FE Textarea tanpa maxlength. | Controller 30; Form 572 | maxlength mirror. |
| RP-B14 | P3 | Estimasi FE = batch cost/unit; **bukan** prediksi `avg_cost` master setelah weighted bila hasil sudah punya stok lama. Operator bisa kira HPP final = estimasi. | Form 347–364 vs Action `recalculateAvgCost` | Label “HPP batch masuk”, bukan “HPP master”. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RP-S1 | P1 | **`getProducts` selalu return `avg_cost`; form tampilkan HPP di autocomplete + estimasi total tanpa `stok.view_hpp`.** Role **gudang** punya `repack.create` **tanpa** `stok.view_hpp` — bypass gate HPP list/detail. | Controller 416, 440; Form 626, 670–765; Seeder 122–128 vs 41 | Strip `avg_cost` / estimasi kecuali `stok.view_hpp`; gate UI. |
| RP-S2 | P1 | **Helper `getProducts` hanya `repack.create`:** user custom `repack.update` saja → autocomplete / `refreshStockInfo` 403, padahal route edit = `repack.update`. | Controller 402–404; Form 167–188; router 278–281 | Gate `create \|\| update`. |
| RP-S3 | P1 | **`getStockSetting` tanpa permission** (siapa pun authenticated). | Controller 453–457 | `can('repack.view')` minimal. |
| RP-S4 | P2 | **Throttle hanya approve**; store/update/delete tanpa throttle. | `api.php` 417–426 | Throttle write paths. |
| RP-S5 | P2 | Filter `status` / `tipe` / WH id tidak `Rule::in` ketat — garbage status lolos query kosong. | Controller 124–137 | Validasi `in:draft,approved` / `pecah,gabung`. |
| RP-S6 | P2 | **Access coverage tipis:** update/approve 403; **tidak** tes create/delete/products/stock-setting/index matrix. | `InventoryAccessCoverageTest` 191–236 | Tambah kasus. |
| RP-S7 | P2 | **PDF + DetailItem selalu tampilkan Biaya Repack** (dan PDF info) tanpa `stok.view_hpp`; kolom HPP baris memang di-gate. Biaya = komponen landed cost. | `RepackPage` 156, 339; summary HPP 178–184 gated | Gate biaya di detail/PDF sama HPP, atau pisahkan permission. |
| RP-S8 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK; dokumentasikan. |
| RP-S9 | P3 | **Gudang tidak punya `repack.approve`** — SoD benar; Dashboard pending pakai `repack.approve`. | Seeder 128 vs 44; DashboardController 87 | OK. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RP-C1 | P0 | = **RP-B1** TOCTOU approve tanpa lock header. | Approve Action | Lock + re-check. |
| RP-C2 | P1 | **Coverage race/HTTP tipis:** CrudTest sequential double-approve saja; **bukan** paralel; tidak ada tes future date, products tanpa create, qty→0, missing stock row, approve+serial flip. | `RepackCrudTest` 339–365 | Tes race + HTTP matrix + edge. |
| RP-C3 | P1 | **N+1** di `getProducts`: 1 query `InventoryStock` **per produk**. FE edit `refreshStockInfo` = N× getProducts lagi. | Controller 429–432; Form 170–188 | `whereIn` + map; endpoint batch stok. |
| RP-C4 | P1 | **`InventoryDownstreamGuardTest` tidak cover repack** create/approve inactive WH/produk (adj/transfer/opname/hpp ada). Rule ada di kode tapi tanpa jaring tes. | DownstreamGuardTest (no repack methods) | Tambah kasus mirror transfer. |
| RP-C5 | P2 | `catch (\Exception)` generik → 500 string message di store/update/approve — bisa bocor detail. | Controller 222–223, 335–336, 391–392 | Catch domain exceptions saja. |
| RP-C6 | P2 | `destroy` tidak di TX eksplisit; cascade FK OK. Tidak soft-delete — audit trail dokumen hilang (header punya `HasAuditLog`). | Controller 359; migration cascade | SoftDeletes opsional / archive. |
| RP-C7 | P2 | `runningStocks` “untuk multiple lines same product” padahal UNIQUE + controller menolak duplikat — dead complexity. | Approve 78–82, 118–119 | Komentar atau andalkan unique. |
| RP-C8 | P3 | Duplikasi validasi duplikat/overlap/tipe di store & update. | Controller 182–210 ≈ 293–321 | Private `assertRepackPayload`. |
| RP-C9 | P3 | Accessor `total_input_items` / `total_output_items` N+1 bila dipakai; list sudah `withCount`. | Model 195–206 | Hindari accessor di list. |
| RP-C10 | P3 | Create/Update Actions hampir identik untuk write lines. | Create 49–69; Update 46–70 | Shared writer. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| RP-X1 | P0 | = **RP-B1** vs invariant **Stok / Kartu Stok / `data:verify`** — race approve merusak padanan OUT/IN (+ HPP weighted dobel). |
| RP-X2 | P1 | = **RP-B4** vs **Register Unit Serial** — approve serial (jika lolos) desync qty stok vs unit `tersedia`. |
| RP-X3 | P1 | **Pergerakan HPP / Kartu Stok:** `REPACK_IN` mengubah HPP (benar §B); `REPACK_OUT` tidak. Deep-link `source_doc` ke RPK **tidak** ada (= KS-B6) — hanya nomor teks. | `StockCardPage` 526–531; `HppMovementPage` transaction_no plain |
| RP-X4 | P1 | **HPP_RESET** dari bahan habis: jejak di kartu dengan `transaction_no` RPK; list HPP movement campur dengan reset sumber lain. | Action 137–143; MasterProduk 296–310 |
| RP-X5 | P1 | **POS / Sales:** draft **tidak** reserve. Kompetisi stok dengan POS di-handle `lockForUpdate` stok saat approve (baik); race approve↔approve tidak. |
| RP-X6 | P2 | **Opname / Adjustment / Transfer:** tidak ada freeze WH selama draft repack. Opname set-to-physical bisa menelan/menyembunyikan efek repack antar hitung↔approve. |
| RP-X7 | P2 | **Elektronik OFF/ON:** repack **tidak** di bawah `feature.elektronik` (benar — non-serial). Guard serial tetap aktif. |
| RP-X8 | P2 | **Dashboard** pending `repack` → `repack.approve`. Reset DB: `refuseIfHasNonDraft` untuk target `repack` (tes matrix ada). |
| RP-X9 | P2 | **Koreksi HPP retail:** orthogonal; repack menulis `REPACK_*` + mungkin `HPP_RESET`, bukan `HPP_CORRECTION`. |
| RP-X10 | P3 | Downstream inactive product/WH di-guard create/approve via `InventoryMasterRules` — perilaku OK; tes khusus repack absen (RP-C4). |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RP-U1 | P1 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`, gate `headersReady`/`canAddInput|Output`. | Page/Form imports | — |
| RP-U2 | P1 | = **RP-S1** — HPP terlihat di form untuk role tanpa `stok.view_hpp`. | Form autocomplete/estimasi | Gate visual. |
| RP-U3 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten Stok/Register. | API module | Optional Excel. |
| RP-U4 | P2 | **Tidak ada scan barcode produk** (Adj/Opname punya) — hanya AutoComplete. | Form | Optional scan baris. |
| RP-U5 | P2 | List tidak kolom biaya / total cost; sulit audit landed dari grid. | Page columns 266–306 | Kolom opsional + `view_hpp`. |
| RP-U6 | P2 | Ganti WH: outputs tidak di-reset — sisa produk hasil dari konteks WH lama (biasanya OK, tapi membingungkan). | Form 208–210 | Reset keduanya. |
| RP-U7 | P2 | `errors.insufficient_stock` di-set tapi tidak dirender sebagai field error (hanya Message). | Form 444–446 vs 597–600 | Hapus dead key atau tampilkan. |
| RP-U8 | P3 | Detail summary HPP 3 kartu warna (merah/biru/hijau) — sedikit noisy vs Transfer; masih operasional. | Page 378–392 | — |
| RP-U9 | P3 | Select tipe tanpa soft hint perbedaan pecah/gabung di list filter (hanya form). | Page filter | — |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RP-D1 | P1 | Index header: `status`, `tanggal`, `tipe`, `warehouse_id`, UNIQUE `nomor_dokumen`/`ulid` — cukup; search `LIKE %…%` lemah skala besar. Perf migration ulang index `status`. | migration 120001; `2026_04_15_*` | Prefix/fulltext opsional. |
| RP-D2 | P1 | Detail UNIQUE per sisi `(repack_id, product_id)` — bagus. Tidak ada constraint lintas tabel input∩output (hanya app). | migration 120002/003 | OK + jaga validasi. |
| RP-D3 | P2 | SoftDeletes: tidak dipakai — hard delete draft menghapus jejak + cascade. | destroy 359 | — |
| RP-D4 | P2 | Approve: lock N stock + N produk + weighted + sync all WH avg — OK tipikal; banyak output + sync = TX lebih panjang. | Approve foreach + `syncAvgCostToInventoryStocks` | — |
| RP-D5 | P3 | `qty` unsignedInteger vs validasi `numeric\|min:0.0001` — mismatch tipe (= RP-B10). | migration; Controller | Integer validation. |
| RP-D6 | P3 | Nomor dokumen: sequence `lockForUpdate` di dalam TX create — race nomor relatif aman. | SettingService; Create TX | — |
| RP-D7 | P3 | `cost_per_unit` 4 dp / `total_cost` 2 dp — OK; rounding multi-output dijaga tes delta. | migration; CrudTest | — |

---

## Lifecycle matriks (aktual)

| Aspek | Perilaku kode |
|-------|----------------|
| Create | Draft; stok **tidak** berubah; nomor `RPK-…`; cost baris 0 |
| Update / Delete | Draft only; replace inputs/outputs; hard delete + cascade |
| Approve | OUT bahan + IN hasil + weighted HPP hasil + header totals; status `approved` |
| Lock / Void / Cancel | **Tidak ada** |
| Serial | Ditolak create/update + disembunyikan picker; approve **tidak** re-block |
| Bahan habis (global ≤0) | `HPP_RESET` pada produk input |
| Double approve sequential | 422 status (tes ada) |
| Double approve paralel | **Tidak diuji**; rentan RP-B1 |

---

## Matriks permission role (seed)

| Permission | super-admin | admin | gudang | kasir |
|------------|:-----------:|:-----:|:------:|:-----:|
| `repack.view` | ✓ | ✓ | ✓ | — |
| `repack.create` | ✓ | ✓ | ✓ | — |
| `repack.update` | ✓ | ✓ | ✓ | — |
| `repack.delete` | ✓ | ✓ | ✓ | — |
| `repack.approve` | ✓ | ✓ | — | — |
| `stok.view_hpp` (orthogonal) | ✓ | ✓ | — | — |

Sumber: `RolePermissionSeeder` 44, 93, 128; `stok.view_hpp` 41/90 (bukan gudang).

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `repack.view` |
| Filter WH / tipe / status / tanggal | Ya | same |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view (HPP cols + `stok.view_hpp`) |
| Export Excel | Tidak | — |
| Create draft | Ya | `repack.create` |
| Edit draft | Ya | `repack.update` |
| Delete draft | Ya | `repack.delete` |
| Approve | Ya | `repack.approve` |
| Lock / Void / Cancel | Tidak | — |
| Biaya repack (selalu ke HPP) | Ya (form) | create/update |
| Estimasi HPP form | Ya | **tanpa** `view_hpp` (RP-S1) |
| Scan barcode produk | Tidak | — |
| Serial picker | Tidak (dilarang) | — |
| Stock setting negatif | Ya (FE load) | API tanpa can |

---

## Antrian patch (usulan prioritas)

1. **P0** RP-B1/C1/X1 — `lockForUpdate` header + re-check draft sebelum mutasi.  
2. **P1** RP-B4/X2 — `blockSerial` di approve / `repackDocumentErrors`.  
3. **P1** RP-B5 — null-safe stok missing row.  
4. **P1** RP-S1/U2 — strip/gate HPP di `getProducts` + form estimasi.  
5. **P1** RP-S2/S3 — products `create\|\|update`; stock-setting permission.  
6. **P1** RP-B3 — keputusan produk: dokumentasikan equal-cpu atau bobot alokasi.  
7. **P1** RP-B2 — keputusan void / SOP kompensasi.  
8. **P1** RP-C2/C3/C4 — tes race + N+1 products + downstream guard repack.  
9. **P2+** tanggal BE, qty integer, WH reset outputs, Excel, barcode, throttle, SoftDeletes, deep-link KS/HPP ke RPK.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `RepackCrudTest` | create draft RPK; stok unchanged; gabung tipe; cost 0 until approve; update replace; block update approved; approve stok eksak + kartu OUT/IN + `data:verify`; HPP nilai kekal + distribusi multi-output; stok kurang 422; double-approve **sequential** |
| `RepackHppResetTest` | bahan habis → HPP_RESET; sequence kartu; sisa stok no reset; weighted existing output; multi-WH no reset; distribusi biaya 2 output + verify |
| `RepackSerialBlockTest` | store/update 422 serial input/output; allow non-serial; picker exclude serial |
| `InventoryAccessCoverageTest` | update/approve 403 (tanpa create/delete/products/stock-setting) |
| `ResetTargetMatrixTest` | refuse non-draft repack; truncate drafts |
| `InventoryDownstreamGuardTest` | **tidak** ada kasus repack |
| `SerialFase1Test` | (mention) picker guard sanity |

**Tidak ada** e2e Playwright fungsional khusus (hanya screenshot/docs locator `menu-inventory-repack`).

---

## Ringkasan eksekutif

Alur inti (**draft → approve** = OUT bahan + IN hasil atomik dalam satu WH, pecah/gabung, ban overlap produk, serial ditolak di create/update, HPP hasil = weighted dari nilai bahan + `biaya_repack` by-qty, SoD gudang tanpa approve, tes sequential + HPP reset + serial block) **berfungsi di happy path**. Kerapuhan utama: **race double-approve tanpa lock header**, **approve tidak re-block serial** (risiko desync register), **crash bila bahan belum punya baris stok**, **kebocoran HPP ke role gudang via products/form**, serta **model biaya equal-cpu antar SKU hasil** tanpa BOM/rasio atau void.
