# Audit menu — 20 Inventory → Register Unit Serial

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24; nota-jual by `source` + checkbox eksplisit 2026-07-28)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/SerialUnitRegisterPage.vue` · `api/modules/serialUnits.js` · `components/common/SerialLabelPrintDialog.vue` · `SerialUnitPicker.vue` (konsumen `available`)  
> - BE: `syilex/app/Http/Controllers/Api/V1/SerialUnitController.php` · `Models/SerialUnit.php` · `Models/SerialUnitMovement.php` · `Exports/SerialUnitExport.php`  
> - Routes FE: `inventory-serial-units` (`serial-intake.view` + `requiresElektronik`) · API: `GET /serial-units*` + middleware `feature.elektronik`  
> - Menu: `AppMenu.vue` Inventory → Register Unit Serial  
> - Tes: `tests/Feature/SerialUnit/SerialUnitRegisterTest.php` · `SerialIntake/SerialUnitExportTest.php` · partial `ElektronikModuleTest` · lookup/available di `SerialSalesCheckoutTest` / `SerialFase1Test` / `SerialAccessCoverageTest`  
> - Domain: `docs/domain/serial.md` §4.7  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Pergerakan HPP di `AppMenu.vue` (sebelum Koreksi HPP Serial).

## Scope

**Read-only register** unit fisik (`serial_units`): list + summary + export Excel/PDF + cetak label. **Bukan CRUD** — tulis lewat PBS / POS / transfer / adj / opname / retur / serial-change / serial-HPP. Tidak ada `show`/`POST`/`PUT`/`DELETE` di controller ini. Tidak ada Policy class (gate inline `can()`).

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /serial-units` | `serial-intake.view` (+ strip cost tanpa `stok.view_hpp`) | List + PDF/print fetch |
| `GET /serial-units/export` | `serial-intake.view` (+ strip kolom cost) | Excel |
| `GET /serial-units/available` | OR: `pos.access` \| `serial-intake.view` \| `transfer.create` \| `adjustment.create` \| `retur-beli.create` \| `retur-jual.*` \| `opname.create` \| `sales.*` | Picker / POS SN |
| `GET /serial-units/lookup` | OR: `pos.access` \| `serial-intake.view` | Scan POS |
| `GET /serial-units/peek-kode` | `serial-intake.create` **atau** `update` | Form PBS Generate KI |
| FE route / AppMenu | `serial-intake.view` + `serialEnabled` | Gate UI |
| Modul OFF | middleware `feature.elektronik` → API **403**; FE `requiresElektronik` → redirect dashboard; menu `visible: serialEnabled && …` | |

**CRUD capability (user di halaman ini):** view / filter / search / sort / paginate / export Excel / export PDF / cetak label. **Tidak:** ubah status, edit atribut, hapus unit, koreksi HPP (itu menu lain).

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Identitas unik = `kode_internal` (UNIQUE DB, cek `withTrashed` di intake) | migration `2026_06_17_100001_*`; `HandlesSerialUnits` 19–74; model boot auto `KI-{id}` |
| SN **boleh kembar** (bahkan 1 produk) | domain + `HandlesSerialUnits` 19–20; lookup ambigu → candidates |
| SoftDeletes pada `SerialUnit`; draft PBS hapus unit = **forceDelete** | model SoftDeletes; `SerialIntakeController` 218; `UpdateSerialIntakeAction` 57 |
| Status string: `pending\|tersedia\|terjual\|rusak\|hilang\|retur` | `SerialUnit::STATUSES` |
| Cost gate orthogonal: `stok.view_hpp` (bukan `serial-intake.view_harga`) | Controller 86–89, 183–185, 325–328; domain §4.7 |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SU-B1 | P0 | **PDF + Cetak Label “semua filter” terpotong max 100 unit.** FE kirim `per_page: 999999`; `getPerPage` hard-cap **100**. Export Excel OK (FromQuery). User dengan >100 unit dapat PDF/label **tidak lengkap tanpa peringatan**. | `SerialUnitRegisterPage.vue` 163, 237; `BaseApiController` 85–89 | Dedicated bulk endpoint / stream; atau paginate-loop di FE sampai `last_page`; atau toast “hanya N dari total”. |
| SU-B2 | P1 | **Detail unit / movements masih belum; link Nota Jual sudah ada tapi routing lama salah.** Link sempat selalu ke `penjualan-sales` (ManualSales only) + `?detail=` diabaikan SalesPage. **Patched (2026-07-28):** API expose `sale.source`; FE branch POS→`struk-online`, manual→BO+deep-link; SalesPage baca `query.detail`. Masih open: `GET /serial-units/{ulid}` + movements. | Controller `sale:…,source`; FE `openSale` / `canOpenSale`; SalesPage onMounted | Movements + detail unit bila dibutuhkan audit penuh. |
| SU-B3 | P1 | **PBS approve tidak menulis `SerialUnitMovement`.** Migration komentar menyebut `SERIAL_INTAKE`; `ApproveSerialIntakeAction` hanya `pending→tersedia`. Jejak masuk hanya relasi `intake_id` (bila masih ada). Opname hilang lewat path adj (ada movement); intake birth = blind spot ledger. | `ApproveSerialIntakeAction` ~94–95; migration movements 24; no `SerialUnitMovement::record` di `Actions/SerialIntake` | Record `SERIAL_INTAKE`/`IN` saat approve (atau dokumentasikan sengaja + andalkan `intake` saja). |
| SU-B4 | P1 | **Summary cards menyesatkan saat filter status.** By design: summary **mengabaikan** `status` (test `status_filter_narrows_list_but_summary_stays_global`). FE menampilkan 3 kartu di atas tabel tanpa disclaimer → filter “Rusak” tetap kartu “Tersedia/Terjual” penuh. Summary juga **tidak** menghitung `pending/rusak/hilang/retur` (hanya total/tersedia/terjual) → unit rusak “hilang” dari KPI. | Controller 56–66; FE 303–317; Test 99–111 | Label “ringkasan (abaikan filter status)”; tambah bucket status lain; atau summary mirror semua filter termasuk status. |
| SU-B5 | P2 | **Unit `pending` (draft PBS) muncul di Register** sebelum stok commit. Filter status punya Pending. Operator bisa mengira stok fisik sudah ada; invariant stok hanya hitung `tersedia`. | Model STATUS_PENDING; FE statusOptions 58; VerifyDataInvariants 289–291 | Default exclude pending; atau badge “Draft PBS”; filter default `tersedia`. |
| SU-B6 | P2 | **`status` query tidak divalidasi** terhadap `STATUSES`. Nilai `ngawur` → list kosong + summary global (test sengaja). | Controller 64–66; Test 237–249 | `Rule::in(SerialUnit::STATUSES)` → 422. |
| SU-B7 | P2 | Export Excel **tidak** menerima `intake_id` (index iya). Filter asal dokumen tidak bisa di-export meski API list support. | `SerialUnitExport` ctor 29–40 vs Controller index 46–51 | Tambah param `intake_id` ke export + FE bila filter ditambah. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SU-S1 | P1 | **Sort `harga_modal` tanpa `stok.view_hpp` = side-channel ordering.** Field di-`makeHidden` tapi whitelist sort tetap mengizinkan `harga_modal`. User tanpa HPP bisa mengurutkan modal. | Controller 76–78, 87–89 | Drop `harga_modal` dari sortable bila !can view_hpp; atau 403. |
| SU-S2 | P2 | **`available` permission OR sangat luas** (`sales.view` saja cukup) → enumerasi semua SN/KI/atribut/harga_jual per produk (± gudang). Cost tetap di-strip tanpa `view_hpp`. Intentional untuk picker, tapi surface besar. | Controller 112–128 | Dokumentasikan; pertimbangkan require create-permission yang relevan per konteks; rate-limit. |
| SU-S3 | P2 | **`lookup.warehouse_id` wajib integer PK**, bukan ULID. Inkonsisten dengan `available`/`index` yang terima ulid/id. POS kebetulan kirim id numerik dari terminal — OK; konsumen ULID-first gagal validasi. | Controller 237–240 vs 145–151 | Terima ulid seperti endpoint lain. |
| SU-S4 | P2 | Export Excel sync **tanpa throttle** (beda write PBS yang `throttle:30,1`). | `routes/api.php` 171–176 vs 151 | Throttle export shared. |
| SU-S5 | P3 | Tidak ada Policy Spatie resource; hanya `can()` — konsisten POSIP, tapi Role editor tidak punya permission khusus “unit.register” (reuse `serial-intake.view`). | RolePermissionSeeder 38, 131 | OK by design; dokumentasikan. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SU-C1 | P1 | = **SU-B1** — FE mengabaikan kontrak `getPerPage` max 100 untuk bulk PDF/print. | FE 163/237; BaseApi 85–89 | Fix FE/BE bersama. |
| SU-C2 | P2 | **`peekKode` pluck semua** `kode_internal` LIKE `KI-%` lalu reduce di PHP (`withTrashed`). Skala unit besar = memory/CPU spike tiap Generate. | Controller 202–209 | SQL `MAX`/`ORDER BY` cast digit, atau sequence table. |
| SU-C3 | P2 | **`available` unpaginated `->get()`** — semua unit matching status/produk. Produk dengan ribuan unit → payload besar / UI picker lambat. | Controller 174–179; `SerialUnitPicker` load all | Cap + search/paginate; atau virtual scroll + server search. |
| SU-C4 | P2 | Index summary = **3× count clone** per request (total/tersedia/terjual). Tanpa filter produk/gudang = full table scan×3. | Controller 57–61 | Satu query `GROUP BY status` + map. |
| SU-C5 | P3 | Tidak ada Policy / FormRequest; validation thin di `available`/`lookup` saja; `index` filter bebas. | Controller | Optional FormRequest untuk status/sort. |
| SU-C6 | P3 | Test Register **tidak** cover: lookup cost strip, export via HTTP + view_hpp flag end-to-end, elektronik OFF pada `/export`/`available`, cap per_page vs FE 999999, sort side-channel. | `SerialUnitRegisterTest` / ExportTest | Tambah kasus di atas. |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| SU-X1 | P1 | **Kartu Stok / Pergerakan HPP** = agregat produk; modal riil unit hanya di Register — by design (`serial.md` §4.7). Tapi Register **tidak** deep-link ke stock card / penjualan → audit trail putus (SU-B2). |
| SU-X2 | P1 | **Serial Change / Serial HPP** mengubah atribut/cost unit yang tampil di Register; tidak ada badge “pernah dikoreksi” / link ke PDS/HPS. Movement HPP_SERIAL ada di DB tapi tidak di UI Register. |
| SU-X3 | P1 | **POS lookup/available** & Register share controller + cost gate `stok.view_hpp` — baik. Kasir **tanpa** `serial-intake.view` tetap bisa lookup (pos.access); Register menu tersembunyi — OK. |
| SU-X4 | P2 | **Transfer / Adj / Retur / Opname** pakai `SerialUnitPicker` → `available`. Register tidak menampilkan gudang history (hanya WH terkini). Setelah transfer, “Asal Dokumen” tetap PBS asal — benar, tapi WH berubah tanpa history di UI. |
| SU-X5 | P2 | **Pembelian Serial** list tidak deep-link *ke* Register (`?intake_id=` didukung BE, tidak di FE Register / tidak dari SerialIntakePage). Sebaliknya Register → PBS (`openIntake`) **ada**. |
| SU-X6 | P2 | **Reset DB** menghapus `serial_units` + movements — Register kosong; lock elektronik selama masih ada produk serial. |
| SU-X7 | P3 | **Print Barcode** (master retail) ≠ **SerialLabelPrintDialog** (unit KI/SN) — dua jalur label; OK tapi mudah bingung user. |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SU-U1 | P1 | = **SU-B1** silent truncate PDF/print. | FE 161–203, 228–249 | Warning + total vs fetched. |
| SU-U2 | P2 | AutoComplete produk **tanpa `forceSelection`** — teks bebas bisa tersisa di model; `selectedProduct?.ulid` falsy → filter produk diam-diam tidak aktif. | FE 273–295 | `forceSelection` + clear on blur. |
| SU-U3 | P2 | Kolom Status / Terjual / Gudang **tidak sortable** di FE meski BE whitelist `status`, `sold_at`. | FE 380–451 vs Controller 76 | Tambah `sortable` + `field`. |
| SU-U4 | P2 | `statusSeverity`: hanya tersedia=success, terjual=info; rusak/hilang/retur/pending = secondary seragam. | FE 155–158 | Map severity per status. |
| SU-U5 | P2 | Tidak ada empty-state khusus modul elektronik / deep-link query; tidak watch `route.query`. | FE onMounted only | Support `?intake_id=&status=&search=`. |
| SU-U6 | P3 | Reuse bagus: `DataTableHeader`, `ListFiltersSheet`, `SerialLabelPrintDialog`, `useExportPdf`. Tidak invent component baru. | FE imports | — |
| SU-U7 | P3 | Warehouse filter `optionValue="id"` — OK karena `/warehouses/list` `makeVisible('id')`; PBS form memakai `ulid` — inkonsistensi pola id vs ulid lintas halaman (bukan bug Register sendiri). | FE 296; HandlesSimpleMasterCrud 330 | Prefer ulid bertahap. |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SU-D1 | P2 | Index `(product_id, serial_number)`, `(product_id, status)`, `warehouse_id`, UNIQUE `kode_internal`. **Search global** `LIKE %…%` pada SN/KI tidak memakai index dengan baik. | migration create + kode_internal; `scopeSearch` 171–175 | Opsional fulltext / prefix search KI exact-first. |
| SU-D2 | P2 | UNIQUE `kode_internal` **termasuk soft-deleted** → kode soft-deleted tidak reusable (sengaja, selaras `withTrashed` validate). SoftDeletes jarang (draft = forceDelete). | migration unique; HandlesSerialUnits 65 | Dokumentasikan; jangan “fix” dengan partial unique tanpa keputusan produk. |
| SU-D3 | P2 | `serial_unit_movements`: index `serial_unit_id`, `(doc_type, doc_id)`, `tanggal`. Cascade delete on unit. **Tidak ada invariant verify** movement↔status (hanya stock count + sold integrity). | VerifyDataInvariants 273–369 | Opsional check movement pair (analog stock_card). |
| SU-D4 | P3 | N+1 list: **mitigated** (`with` product/warehouse/intake). Export sama. | Controller 68–72; Export 45–49 | — |

---

## Modul Elektronik OFF

| Lapisan | Perilaku |
|---------|----------|
| Setting | `modules.elektronik_enabled=false` → `SettingService::isElektronikEnabled()` false |
| API | Semua `/serial-units*` 403 JSON pesan modul nonaktif (`EnsureElektronikEnabled`) — diuji di `ElektronikModuleTest` (index; group SERIAL_ENDPOINTS) |
| FE menu | `serialEnabled.value && can('serial-intake.view')` → item hilang |
| FE router | `meta.requiresElektronik` → redirect `dashboard` |
| Default | Setting absen → **ON** (tidak break install lama) |
| Lock | Tidak bisa OFF jika masih ada produk serial |

---

## Matriks aksi FE (halaman Register)

| Aksi | Ada? | Gate |
|------|------|------|
| List / paginate / sort | Ya | `serial-intake.view` |
| Search KI/SN | Ya | same |
| Filter produk / gudang / status | Ya | same (`intake_id` BE only) |
| Lihat Modal / Modal Landed | Ya bila `stok.view_hpp` | FE + BE strip |
| Lihat Harga Jual | Selalu (diizinkan) | — |
| Export Excel | Ya | view + strip cost |
| Export PDF | Ya (client, cap 100) | same |
| Cetak Label | Ya (checkbox eksplisit per baris / select-all halaman, atau all-filter) | same |
| Buka asal PBS | Ya (link) | butuh `serial-intake.view` di target |
| Buka Nota Jual | Ya — `source=manual` → Penjualan BO `?detail=`; `source=pos` → `struk-online` | manual: `sales.view`; POS: public receipt |
| Ubah status / edit unit | **Tidak** | — |
| Detail movement ledger | **Tidak** | — |

---

## Antrian patch (usulan prioritas)

1. **P0** SU-B1/U1/C1 — PDF/print tidak silent-truncate (loop pages atau endpoint export khusus label/PDF).  
2. **P1** SU-B2 sisa — detail unit + movements (link nota jual by `source` sudah patched).  
3. **P1** SU-S1 — sort cost gated.  
4. **P1** SU-B3/B4 — movement intake + honesty summary UI.  
5. **P2** sisa C/U/D + tes gap SU-C6.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `SerialUnitRegisterTest` | list+summary+intake, **sale.source pos/manual**, status/product/warehouse/intake/search/KI, pagination, sort modal, rusak filter, permission, cost hide/show index+available, export auth+download |
| `SerialUnitExportTest` | Excel fake filters, map kolom, hide cost headings |
| `ElektronikModuleTest` | OFF blocks `/serial-units` |
| `SerialSalesCheckoutTest` | lookup KI/SN/ambiguous + available pos.access |
| `SerialFase1Test` | available warehouse filter |
| `SerialAccessCoverageTest` | index forbidden/ok |
| `SerialIntakeTest` | peek-kode permission |

**Tidak ada** e2e Playwright khusus Register page (hanya locator menu di `docs-helpers.js`).
