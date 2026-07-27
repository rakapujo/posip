# Audit menu — 22 Inventory → Stock Opname

> **Status:** patched (scope P0+P1 + review deltas; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/StockOpnamePage.vue` · `StockOpnameFormPage.vue` · `api/modules/opnames.js` · nested `SerialUnitPicker`  
> - BE: `syilex/app/Http/Controllers/Api/V1/StockOpnameController.php` · `Actions/StockOpname/{Create,Update,Approve}StockOpnameAction.php` · `Models/DocStockOpname*`  
> - Turunan: `ApproveAdjustmentAction` (`source=opname`) · `InventoryStock` · `StockCard` (`STOCK_OPNAME` + `ADJUSTMENT_IN/OUT`) · `SerialUnit` / movement  
> - Routes FE: `inventory-opname*` · API: `/opnames*` (`routes/api.php` ~429–440)  
> - Menu: `AppMenu.vue` Inventory → Stock Opname  
> - Tes: `tests/Feature/StockOpname/StockOpnameCrudTest.php` · `tests/Feature/Serial/SerialOpnameTest.php` · partial `InventoryAccessCoverageTest` · `InventoryDownstreamGuardTest`  
> - Domain: `docs/domain/serial.md` §opname · `docs/domain/architecture.md`  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Koreksi HPP Serial di `AppMenu.vue` (sebelum Koreksi HPP retail / Adjustment).

## Scope

Hitung fisik stok per gudang → dokumen **draft → approved** (tanpa lock/void/cancel API). Mode `full` | `partial`. Saat approve: refresh `qty_system`, tulis kartu audit `STOCK_OPNAME` (qty 0/0), lalu bila ada selisih **auto-create + auto-approve** `DocAdjustment` (`source=opname`). Serial: checklist SN **hadir**; unit tidak hadir → `hilang` via adj kredit; selisih **lebih** serial **ditolak**.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /opnames` | `opname.view` | List |
| `GET /opnames/{ulid}` | `opname.view` | Detail + form edit + PDF |
| `POST /` | `opname.create` | Draft create |
| `PUT /{ulid}` | `opname.update` | Draft update |
| `DELETE /{ulid}` | `opname.delete` | Draft hard-delete |
| `POST /{ulid}/approve` | `opname.approve` + throttle 30/1 | Approve + adj turunan |
| `GET /products` | `opname.create` **saja** | Autocomplete partial + scan |
| `GET /all-products` | `opname.create` **saja** | Load full mode |
| `GET /check-draft` | `opname.create` **saja** | Guard 1 draft/WH |
| `POST /refresh-stock` | `opname.create` **saja** | Refresh qty sistem form |
| `GET /stock-setting` | **tanpa** `can()` | Dead API (FE tidak panggil) |
| FE route list | `opname.view` | |
| FE create / edit | `opname.create` / `opname.update` | |
| AppMenu | `opname.view` | |

**CRUD capability:** create/update/delete **draft only**; approve; list (search/warehouse/status/mode/date); detail dialog; export PDF **client-side**. **Tidak:** lock dokumen, void/cancel (meski enum `cancelled`), recount khusus, Excel, Policy Spatie, freeze stok selama draft.

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix nomor `OPN` | `SettingService` map `stock_opname` → `OPN` |
| Status string: `draft` \| `approved` \| `cancelled` (enum DB) — **cancel tidak diimplementasi** | migration `2026_01_24_130001_*` 21; model `isCancelled` 159–164 |
| Mode `full` \| `partial` | migration 20; form |
| 1 draft aktif per warehouse (create) | `CreateStockOpnameAction` 39–47; FE `checkDraft` |
| Duplikat produk per dokumen | UNIQUE `(opname_id, product_id)` + controller `hasDuplicateProducts` |
| Serial: `qty_physical` = `count(serial_unit_ids_present)` | Create/Update Action 80–86 / 71–77 |
| Approve refresh `qty_system` dari stok aktual + `lockForUpdate` stock row | `ApproveStockOpnameAction` 49–60 |
| Selisih → adj debit/kredit auto-approve | Action 111–114, 136–185 |
| Serial surplus ditolak | Action 85–90 |
| Serial kurang → unit `tersedia \ present` → adj `hilang` | Action 91–98; `ApproveAdjustmentAction` 204–210 |
| SoftDeletes: **tidak** pada header/detail | models; `destroy` hard + cascade detail |
| Cost UI gate `stok.view_hpp` | FE list/form/PDF; **API products/show tetap kirim `avg_cost`** |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SO-B1 | P0 | **Tidak ada freeze/lock stok selama draft opname.** POS, transfer, adj, PO tetap boleh mutasi WH yang sedang di-opname. Approve **sengaja** refresh `qty_system` lalu paksa stok ke `qty_physical` — transaksi antara hitung fisik ↔ approve **hilang dari hasil opname** (dijadikan selisih / tertelan). Test bahkan mengunci perilaku ini. | `ApproveStockOpnameAction` 56–60; `StockOpnameCrudTest` 277–311; tidak ada guard di sales/transfer | Keputusan produk: freeze WH/SKU saat draft, atau wajib “refresh + re-confirm” sebelum approve, atau dokumentasikan “opname = set-to-physical at approve time” + SOP tutup kasir. |
| SO-B2 | P0 | **Race double-approve:** `isDraft()` dicek **di luar** transaksi; header **tidak** `lockForUpdate`. Dua request paralel → dua set kartu `STOCK_OPNAME` + **dua** adjustment auto-approve + mutasi stok dobel. | Action 29–36 vs 36–130; banding pola dokumen lain | `lockForUpdate` header di dalam TX + re-check draft. |
| SO-B3 | P0 | **Race double-draft per WH:** cek draft existing tanpa lock/unique partial. Dua create paralel → dua draft (`checkDraft`/create keduanya lolos). | `CreateStockOpnameAction` 39–47; tidak ada UNIQUE partial `(warehouse_id) WHERE status=draft` | Unique partial index + `lockForUpdate` / `firstOrFail` pattern. |
| SO-B4 | P0 | **Serial: save sebelum picker selesai load → `present=[]` → approve menandai SEMUA unit `hilang`.** Form default `serial_unit_ids_present: null` (“auto”); payload save memaksa `null → []` dan `qty_physical = length`. Picker hanya emit `defaultAll` setelah `load()` di expansion. Full mode + banyak SKU serial = race sangat realistis. | Form 321, 448, 532–536; `SerialUnitPicker` 127–129 | Jangan save bila serial `present === null`; block submit sampai picker hydrated; atau BE tolak serial line tanpa `present` eksplisit + validasi unit. |
| SO-B5 | P0 | **Create/Update tidak memvalidasi `serial_unit_ids_present`** (milik produk, WH, status `tersedia`, unique). Boleh ulid palsu/duplikat → `qty_physical` menggelembung; approve: surplus serial ditolak **atau** shortage dengan daftar unit kosong → adj gagal / state menyesatkan. | Controller rules 38–39 (`string` saja); Create 80–84 `count($presentIds)` tanpa resolve | Pakai `ResolvesSelectedUnits` (atau mirror) di create/update + `array_unique`. |
| SO-B6 | P1 | **Mode `full` bukan full count warehouse.** `loadAllProducts` hanya produk yang **punya baris** `inventory_stock` (boleh qty 0); produk tanpa record tidak ikut. User harus “Load Lebih Banyak”; save mid-pagination = partial yang berlabel full. Tidak ada langkah “SKU tidak dihitung = 0”. | Controller 392–401; Form 429–467, 933–937 | Rename/UX “semua ber-record stok”; atau true full = semua aktif + opsi zero-fill; blok save bila `!allProductsLoaded`. |
| SO-B7 | P1 | **Selisih LEBIH (non-serial) → `ADJUSTMENT_IN` rekalkulasi HPP** dengan cost = `avg_cost` lama (`recalculateAvgCost($qty, $oldHpp)`). Barang “ketemu” dianggap berharga sama HPP berjalan — bisa menggeser HPP tanpa bukti nilai. | `ApproveAdjustmentAction` 137–140; business-rules: ADJUSTMENT_IN memang recalc | Keputusan: opname surplus pakai cost 0 / cost input / skip recalc; dokumentasikan dampak HPP. |
| SO-B8 | P1 | **Status `cancelled` + filter FE ada; API cancel/void tidak ada.** Model `isCancelled()` mati. Approved terminal — salah approve hanya dilawan adj/opname baru. | migration 21; Page statusOptions 90–94; no cancel route | Hapus filter Cancelled **atau** implement cancel draft + keputusan void approved. |
| SO-B9 | P1 | **Update boleh ganti `warehouse_id` / `mode` di BE** tanpa cek draft bentrok WH baru. FE disable WH/mode saat edit — inkonsisten; API bypass mudah. | Update Action 51–56; Form `:disabled="isEdit"` 701, 716 | BE: larang ganti WH; atau cek draft uniqueness + reset serial. |
| SO-B10 | P1 | **Serial race dengan penjualan:** unit di-checklist hadir lalu terjual sebelum approve → `qty_physical` stale vs `qty_system` refresh → sering **surplus serial → 422**; atau unit yang seharusnya hilang sudah `terjual` (tidak masuk `array_diff`) → jejak opname “bersih” padahal fisik bermasalah. | Approve 56–60, 91–98 vs Form qty_physical frozen | Recompute physical dari present∩tersedia saat approve; tolak bila present non-tersedia; atau freeze unit. |
| SO-B11 | P2 | **Approve shortage gagal bila `negative_stock_allowed=false` dan stok aktual &lt; kebutuhan kredit** (setelah refresh). Opname sudah tulis kartu di loop yang sama — rollback TX OK, tapi UX “approve gagal” tanpa hint opname. FE **tidak** load `getStockSetting` (dead endpoint). | Adj Action 72–78; Controller `getStockSetting` 437–442; Form tidak memanggil | Mirror AdjustmentForm: preflight + pesan; wire stock-setting. |
| SO-B12 | P2 | **Tanggal masa depan:** FE tolak (`isAfterNow`); BE hanya `required\|date` — API boleh tanggal &gt; now. | Form 491–492; Controller 30 | `before_or_equal:now` di BE. |
| SO-B13 | P2 | **Detail/PDF hanya unit HADIR** — unit yang akan/`hilang` tidak ditampilkan; operator sulit audit “siapa yang hilang”. | `attachDocSerialUnits(..., 'serial_unit_ids_present')` Controller 208–209; Page 493–498 | Tampilkan missing (sistem − hadir) di detail pasca-approve / pre-approve preview. |
| SO-B14 | P3 | **Notes** header `max:1000` vs detail `max:255`; FE textarea tanpa maxlength. | Controller 33, 37; Form 723 | Samakan + maxlength. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SO-S1 | P0 | **Bypass `stok.view_hpp` di API helper:** `getProducts` / `loadAllProducts` / `refreshStock` / `show` selalu expose `avg_cost`. Role **gudang** punya `opname.*` CRUD **tanpa** `stok.view_hpp` (seeder) — FE menyembunyikan kolom, response JSON + DevTools tetap bocor. | Controller 350–365, 410–419, 464–482, 185; `RolePermissionSeeder` 125–129 vs 41 | Strip `avg_cost` tanpa `stok.view_hpp` di semua endpoint opname (mirror Register/strip pattern). |
| SO-S2 | P1 | **Helper write-path hanya `opname.create`:** user custom dengan `opname.update` saja **tidak** bisa `products`/`refresh-stock`/`all-products` saat edit (403), sementara route edit = `opname.update`. | Controller 324–326, 379–380, 450–451; router 224–227 | Gate `create \|\| update` (seperti serial-hpp units). |
| SO-S3 | P1 | **`getStockSetting` tanpa permission** (siapa pun authenticated). | Controller 437–442 | `can('opname.view')` minimal. |
| SO-S4 | P2 | **Throttle hanya approve**; store/update/delete/refresh tanpa throttle. | `api.php` 429–440 | Throttle write paths. |
| SO-S5 | P2 | Filter `status`/`mode`/`warehouse_id` tidak `Rule::in` / resolve ULID — garbage status lolos; inkonsisten ULID-first. | Controller 91–104 | Validasi ketat. |
| SO-S6 | P3 | Tidak ada Policy resource; hanya `can()` — konsisten POSIP. | Controller | OK; dokumentasikan. |
| SO-S7 | P3 | **Gudang tidak punya `opname.approve`** — benar untuk SoD; pastikan dashboard pending tidak menyesatkan user gudang. | Seeder 129 vs 94; Dashboard `opname.approve` | OK bila UI chip respect permission. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SO-C1 | P0 | = **SO-B2** TOCTOU approve. | Action 29–36 | Lock header. |
| SO-C2 | P0 | = **SO-B4/B5** serial present contract lemah FE↔BE. | Form save; Create Action | Kontrak eksplisit + validasi BE. |
| SO-C3 | P1 | **Coverage tipis API/HTTP:** CrudTest mostly Action-level (tanpa permission HTTP); tidak ada tes race draft/approve, present null→hilang, surplus HPP, update WH, products tanpa create, strip HPP, full-mode partial save, cancel status, future date. | `StockOpnameCrudTest`; `SerialOpnameTest` (4 happy); AccessCoverage hanya delete 403 | Tambah kasus di atas. |
| SO-C4 | P1 | **N+1** di `getProducts` / `refreshStock`: 1 query `InventoryStock` **per produk**. | Controller 351–355, 469–472 | `whereIn` + map. |
| SO-C5 | P2 | `catch (\Exception)` generik → 500 string message di store/update/approve — bisa bocor detail. | Controller 169–170, 257–258, 313–314 | Catch domain exceptions saja. |
| SO-C6 | P2 | `destroy` tidak di TX eksplisit; cascade FK OK. Tidak soft-delete — audit trail hilang. | Controller 281; migration `onDelete('cascade')` | SoftDeletes opsional / archive status. |
| SO-C7 | P2 | Approve dari **list** tanpa `details`: pesan konfirmasi selalu versi “tanpa adjustment” (`data.details` undefined). | Page `customConfirmApprove` 297–299 | `get()` dulu atau pesan generik netral. |
| SO-C8 | P3 | `checkDraft` `select` tanpa `created_by` → `with('createdBy')` sering null. | Controller 503–507 | Include `created_by` di select. |
| SO-C9 | P3 | `getTotalItemsAttribute` / difference accessors N+1 bila dipakai. | Model 177–188 | Hindari di list (sudah `withCount`). |

### Cross-modul

| ID | Sev | Temuan |
|----|-----|--------|
| SO-X1 | P0 | = **SO-B1** vs **POS / Transfer / Adj / PO** selama draft — mutasi WH tidak diblok. |
| SO-X2 | P0 | **Adjustment turunan** (`source=opname`, `opname_id`) auto-approve memakai permission path opname saja — user tanpa `adjustment.approve` tetap menghasilkan adj approved. By design Flow A; jejak di menu Adjustment terlihat sebagai dokumen sistem. |
| SO-X3 | P1 | **Kartu Stok:** `STOCK_OPNAME` **di luar** `TYPES_IN/OUT/NO_QTY` — baris audit qty 0/0; `qty_balance` tetap dihitung (0 delta) sehingga jadi “last record” ending. Mudah disalahbaca (= **KS-B5**). Summary `sum(qty_in/out)` tidak terpengaruh, ending dari last balance OK. |
| SO-X4 | P1 | **HPP:** shortage (`ADJUSTMENT_OUT`) tidak ubah avg (benar); surplus ubah avg (**SO-B7**). Serial hilang: cost card dari `cost_per_unit` unit; avg Metode A **tidak** di-recalc di path adj kredit (hanya `checkAndResetHppIfStockEmpty`). |
| SO-X5 | P1 | **Register Unit Serial:** status `hilang` muncul setelah approve; tidak ada deep-link Opname ↔ Register / Adjustment di UI detail (hanya nomor adj). |
| SO-X6 | P1 | **Pergerakan HPP:** baris `STOCK_OPNAME` cost-noop + `ADJUSTMENT_IN` dari surplus — jejak HPP campur; filter tipe perlu sadar. |
| SO-X7 | P2 | **Dashboard** pending `opname` → `inventory-opname` (permission `opname.approve`). Reset DB: chip draft-only `stock_opname`. |
| SO-X8 | P2 | **Elektronik OFF:** create/update tolak `serial_unit_ids_present`; `getProducts`/`loadAllProducts` filter non-serial. Opname **tidak** di bawah `feature.elektronik` middleware (menu tetap hidup) — benar. |
| SO-X9 | P2 | **Stok menu:** tidak menandai WH “sedang opname draft”. |
| SO-X10 | P3 | SoftDeletes unit + draft opname menahan ulid di JSON (bukan FK) — unit force-delete tidak diblok DB; approve bisa orphan present ids. |

### UI / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SO-U1 | P0 | = **SO-B4** — Tag “N hadir” bisa 0 sementara user belum buka/tunggu picker; Simpan tetap aktif. | Form 860–862, 950 | Disable save / warning serial unhydrated. |
| SO-U2 | P1 | Reuse bagus: `useTransactionList`, `DetailDialog`, `ListFiltersSheet`, `RowActionButtons`, `useExportPdf`, `SerialUnitPicker`. | Page imports | — |
| SO-U3 | P2 | Tidak ada Excel export (hanya PDF client) — inkonsisten Stok/Register. | API module | Optional Excel. |
| SO-U4 | P2 | Detail adj link teks saja — tidak `router-link` ke Adjustment. | Page 524–528 | Deep-link. |
| SO-U5 | P2 | Filter status **Cancelled** menyesatkan (SO-B8). | Page 90–94 | Hapus opsi. |
| SO-U6 | P2 | Form: `loading` spinner OK; partial scan barcode bagus; full mode tanpa progress “berapa SKU belum diload” di tombol Simpan. | Form | Badge `allProductsLoaded`. |
| SO-U7 | P3 | `getStockSetting` di API module dead code FE. | `opnames.js` 91 | Pakai atau hapus. |
| SO-U8 | P3 | Warehouse edit disabled tanpa penjelasan “satu draft/WH”. | Form 701 | Hint. |

### DB / performa

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SO-D1 | P0 | **Tidak ada UNIQUE partial** draft-per-warehouse → SO-B3. | migration header indexes 29–31 | `UNIQUE (warehouse_id) WHERE status='draft'` (MySQL 8 functional/partial via generated col atau app lock). |
| SO-D2 | P1 | Index header: `status`, `tanggal_opname`, `warehouse_id`, UNIQUE `nomor_dokumen`/`ulid` — cukup list tipikal. Search `LIKE %…%` lemah skala besar. | migration; `scopeSearch` 124–129 | Prefix / fulltext opsional. |
| SO-D3 | P1 | Detail UNIQUE `(opname_id, product_id)` — bagus. JSON `serial_unit_ids_present` tanpa constraint. | migration detail 26; migration serial 2026_06_14 | Validasi app-level (SO-B5). |
| SO-D4 | P2 | N+1 list mitigated (`with` + `withCount`). Approve loop: 1 lock stock + 1 StockCard per baris — OK skala opname tipikal; full WH ribuan SKU = TX panjang. | Approve foreach | Chunk / queue keputusan. |
| SO-D5 | P2 | SoftDeletes: tidak dipakai — hard delete draft menghapus jejak + cascade detail. | destroy 281 | — |
| SO-D6 | P3 | `STOCK_OPNAME` unclassified di himpunan tipe (= unit test sadar) — hutang model kartu stok. | `StockCardTransactionTypesTest` 163–191 | Pertimbangkan masuk `TYPES_NO_QTY`. |

---

## Matriks permission role (seed)

| Permission | super-admin | admin | gudang | kasir |
|------------|:-----------:|:-----:|:------:|:-----:|
| `opname.view` | ✓ | ✓ | ✓ | — |
| `opname.create` | ✓ | ✓ | ✓ | — |
| `opname.update` | ✓ | ✓ | ✓ | — |
| `opname.delete` | ✓ | ✓ | ✓ | — |
| `opname.approve` | ✓ | ✓ | — | — |
| `stok.view_hpp` (orthogonal cost) | ✓ | ✓ | — | — |

Sumber: `RolePermissionSeeder` 45, 94, 114–120, 122–138.

---

## Matriks aksi FE

| Aksi | Ada? | Gate |
|------|------|------|
| List / sort / paginate / search | Ya | `opname.view` |
| Filter WH / status / mode / tanggal | Ya | same |
| Detail dialog | Ya | view |
| Export PDF | Ya (client) | view (+ HPP cols jika `stok.view_hpp`) |
| Export Excel | Tidak | — |
| Create draft | Ya | `opname.create` |
| Edit draft | Ya | `opname.update` |
| Delete draft | Ya | `opname.delete` |
| Approve | Ya | `opname.approve` |
| Lock / Void / Cancel / Recount | Tidak | — |
| Scan barcode produk (partial) | Ya | form |
| Serial checklist hadir | Ya (jika `serialEnabled`) | form + picker |
| Refresh stok sistem | Ya | form (API: create) |

---

## Antrian patch (usulan prioritas)

1. **P0** SO-B2/C1 — `lockForUpdate` header approve + re-check draft.  
2. **P0** SO-B3/D1 — cegah double-draft (lock + unique partial).  
3. **P0** SO-B4/B5/U1/C2 — kontrak serial present + validasi unit + block save unhydrated.  
4. **P0** SO-S1 — strip `avg_cost` tanpa `stok.view_hpp`.  
5. **P0** SO-B1/X1 — keputusan freeze vs SOP “set-at-approve” + UI warning.  
6. **P1** SO-B6–B10, SO-S2, SO-C3/C4, SO-D3.  
7. **P2+** cancel/filter, tanggal BE, Excel, deep-link, throttle, dead stock-setting.

---

## Tes terkait (coverage map)

| File | Yang diuji |
|------|------------|
| `StockOpnameCrudTest` | create snapshot selisih; block draft ganda (sequential); approve cocok/kurang/lebih + adj + `STOCK_OPNAME` card; refresh qty_system saat approve; double-approve sequential 422; `data:verify` |
| `SerialOpnameTest` | missing → `hilang`; all present; none present; show attach present units |
| `InventoryAccessCoverageTest` | delete 403 tanpa `opname.delete` |
| `InventoryDownstreamGuardTest` | approve ditolak WH nonaktif |
| `StockCardTransactionTypesTest` | `STOCK_OPNAME` sengaja di luar IN/OUT/NO_QTY |

**Tidak ada** e2e Playwright khusus halaman (hanya locator menu `menu-inventory-opname` di `docs-helpers.js`).

---

## Ringkasan eksekutif

Alur inti retail (draft → approve → kartu audit + adj turunan, 1 draft/WH, serial hilang / tolak surplus) **berfungsi di happy path** dan punya tes invariant yang cukup untuk skenario berurutan. Kerapuhan utama: **tidak ada freeze stok selama opname** (approve = paksa ke fisik saat itu), **race double-approve & double-draft**, **kontrak serial `present` yang bisa menyimpan `[]` sebelum picker hydrate → mass `hilang`**, **validasi unit serial absen di create**, dan **kebocoran `avg_cost` ke role tanpa `stok.view_hpp`**. Mode “full” dan filter “Cancelled” cenderung menyesatkan secara semantik.
