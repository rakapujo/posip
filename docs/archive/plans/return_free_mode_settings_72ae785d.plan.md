---
name: Return free mode settings
overview: "Bag A: allow free retur. Bagian B: Elektronik OFF whole-app. Role matrix serial DISEMBUNYIKAN saat OFF (preserve perm di DB). Reset chip serial TETAP. Audit terbalik codebase→plan selesai."
todos:
  - id: seed-migration-helpers
    content: Migration + SettingSeeder + SettingService helpers returns.* (default true)
    status: completed
  - id: enforce-api-free
    content: Gate create/update free di sales return & purchase return
    status: completed
  - id: runtime-settings-ui
    content: runtimeSettings + SettingsPage toggles returns + Pinia
    status: completed
  - id: form-gate-fe-free
    content: "SalesReturnFormPage + PurchaseReturnFormPage: wajib dokumen saat allow_free OFF"
    status: completed
  - id: elektronik-be-search
    content: Filter is_serial=false di Pos/Transfer/Adj/Opname/PR/Produks product-search (+ drop SN/KI search POS)
    status: completed
  - id: elektronik-be-mutations
    content: 422 serial payload baru; PBS 403; POS checkout+ProcessSalesReturn+ManualSales save+PR+Transfer+Adj+Opname+BO retur
    status: completed
  - id: elektronik-fe-forms
    content: serialEnabled di Sales/SalesReturn/PR/Transfer/Adj/Opname/POS; ProdukPage default; SerialUnitPicker self-gate
    status: completed
  - id: elektronik-fe-surfaces
    content: 5 laporan hide filter Serial; PrintBarcode tip; Stock/StockCard/HppMovement; Import template; lock+PBS; Role hide+preserve
    status: completed
  - id: tests-docs
    content: Tests A+B; Role matrix kosong serial saat OFF tapi update tak wipe perm; CLAUDE+serial.md
    status: completed
isProject: false
---

# Setting Mode Bebas Retur + Gate Elektronik OFF (seluruh codebase)

## Keputusan terkunci

### 1. Role editor saat Elektronik OFF — **sembunyikan** modul serial

Filter di [RoleController::permissions](syilex/app/Http/Controllers/Api/V1/RoleController.php): buang module prefix `serial-change`, `serial-hpp`, `serial-intake` dari `groups` (+ jangan masuk `all_permissions` yang dipakai counter matrix) saat `!isElektronikEnabled()`.

**Wajib preserve:** saat `PUT` role update dan elektronik OFF, **jangan hapus** permission `serial-*` yang sudah tersimpan di role (merge: request + existing serial perms). Kalau tidak, save Role saat OFF akan wipe akses serial diam-diam → putus saat modul ON lagi.

### 2. Reset Database — **chip serial tetap tampil**

[ResetDatabasePage](syilex-frontend/src/views/pengaturan/ResetDatabasePage.vue) / [ResetController](syilex/app/Http/Controllers/Api/V1/ResetController.php): biarkan agar bisa wipe sisa PBS/unit/draft.

### 3. P2 sekarang

- Import template omit `is_serial` saat OFF.
- Elektronik-lock hitung `doc_serial_intake` (+ draft `doc_serial_change` / `doc_serial_hpp_correction`).

### Lainnya

- `returns.sales_allow_free` / `returns.purchase_allow_free`, default **true**.
- Free enforce create (+ update jadi free); draft free preexisting OK.
- Historis SN: **DISPLAY_ONLY_OK**.
- Revert existing `serial_unit_ids` (void/retur/lock): **tetap izinkan** saat OFF. Blok hanya payload/picker/PBS **baru**.

---

## Bagian A — Allow free retur

Migration + seeder + helpers + enforce create/update + runtimeSettings + SettingsPage section Retur + form FE wajib dokumen + Pinia.

---

## Bagian B — Elektronik OFF

**FE:** `serialEnabled && is_serial` → picker/PBS/SN.  
**BE search:** `where('is_serial', false)` (+ POS no SN/KI).  
**BE mutate baru:** `serial_unit_ids` / `serial_intake_id` / line serial → 422.  
**Dedicated `/serial-*`:** sudah `feature.elektronik`.

### FE hide (kerja)

| Surface | Aksi |
|---------|------|
| SerialUnitPicker | **Self-gate** `serialEnabled` (+ call-site) |
| SalesForm / SalesReturnForm / PurchaseReturnForm (PBS) / Transfer / Adj / Opname | Gate picker + cabang serial |
| PosKasirPage | Gate click SN / card / chips / retur-SN (lookup sudah OK) |
| ProdukPage | `initForm.is_serial = false` saat OFF |
| Stock / StockCard / HppMovement | Hide badge SERIAL + deep-link PBS |
| 5 laporan pembelian | Hide opsi filter `Serial` + deep-link intake |
| PrintBarcodePage | Hide tip Register Unit |
| Import template | Omit kolom `is_serial` |
| Role matrix | Hide 3 modul serial (lihat keputusan #1) |

### BE block/filter (kerja)

Pos products/barcode/checkout; SalesReturnController POS + ProcessSalesReturn (payload baru); CreateManualSalesAction::save (create+update); BO sales return; PR + PBS routes; Transfer/Adj/Opname products + mutations; Produks index force non-serial; PurchaseReportSource skip serial; Import template; elektronikLockStatus + PBS/draft docs.

---

## Audit terbalik: semua surface serial di codebase → status vs plan

Metode: grep FE (`SerialUnitPicker`, `is_serial`, `serialEnabled`, PBS, menu serial) + BE (`serial_unit_ids`, `SerialUnit`, `isElektronikEnabled`, controllers) + routes. Klasifikasi tiap file.

### Infrastruktur — covered / already OK

| Surface | Verdict |
|---------|---------|
| SettingService::isElektronikEnabled | OK |
| EnsureElektronikEnabled + 4 route groups | OK |
| settingsStore.serialEnabled + router requiresElektronik | OK |
| Settings Modul toggle + lock dasar | OK → **perluas** lock count (plan) |
| Installer elektronik_enabled | OK |
| PostsSalesInventory `isElektronik && is_serial` | OK partial; draft ManualSales masih perlu 422 (plan) |

### Dedicated serial cluster — already OK (route/menu)

SerialIntake* pages, SerialChange*, SerialHpp*, SerialUnitRegister, useSerialLabelPrint, api modules serial* — gated route/menu/middleware. **Tidak perlu hide ekstra** di dalam page.

### Master

| Surface | Verdict |
|---------|---------|
| ProdukPage checkbox | OK gated |
| ProdukPage initForm default true | **PLAN** → false saat OFF |
| ProdukPage list/detail/export Jenis SERIAL | DISPLAY_ONLY / lock kosong saat OFF — **OK** |
| ProduksExport Jenis Serial | DISPLAY_ONLY — **OK** |
| PrintBarcode tip | **PLAN** hide |
| ImportController reject + template | reject OK; template **PLAN** omit |
| RoleController 3 modul serial | **PLAN** hide + preserve on update |
| PriceChange (no serial tip in page) | OK (produk filter non-serial) |

### Pembelian

| Surface | Verdict |
|---------|---------|
| PO form hint serialEnabled | OK |
| PO getProducts always non-serial | OK |
| PurchaseReturnForm PBS+picker | **PLAN** |
| PR PBS API + mutations | **PLAN** |
| SupplierHutang sumber Serial | DISPLAY_ONLY — **OK** |
| PurchaseMasterRules blockSerial | OK (selalu blok serial di doc tertentu) |

### Penjualan BO

| Surface | Verdict |
|---------|---------|
| ManualSales getProducts filter OFF | OK |
| ManualSales rules + CreateManualSalesAction::save | **PLAN** 422 serial_unit_ids |
| SalesFormPage picker | **PLAN** |
| SalesReturnFormPage | **PLAN** |
| BackofficeSalesReturn + calc/lock | **PLAN** payload baru; revert OK |
| SalesPage / SalesReturnPage SN detail | DISPLAY_ONLY — **OK** |

### POS

| Surface | Verdict |
|---------|---------|
| Scan lookup serialEnabled | OK |
| PosKasir click/card/chips/retur SN | **PLAN** |
| usePosCart helpers | OK jika caller gated |
| PosController products/barcode/checkout | **PLAN** |
| SalesReturnController + ProcessSalesReturn | **PLAN** payload baru |
| ShiftReportDialog serial_units_sold | DISPLAY_ONLY — **OK** |
| useReceiptPdf / EscPos / SalesInvoicePdf | DISPLAY_ONLY — **OK** |

### Inventory transactional

| Surface | Verdict |
|---------|---------|
| Transfer/Adj/Opname Form + picker | **PLAN** |
| Transfer/Adj/Opname Controller products + actions | **PLAN** |
| TransferPage / AdjPage / OpnamePage SN detail | DISPLAY_ONLY — **OK** |
| StockPage / StockCardPage / HppMovementPage badge+PBS | **PLAN** hide |
| StockCardController source serial-intake | OK kirim data; FE hide link |
| InventoryStockController is_serial flag | OK; FE badge hide |
| HppCorrection / Repack / PO products non-serial | OK |

### Laporan / Dashboard / Print

| Surface | Verdict |
|---------|---------|
| 5 laporan pembelian filter Serial | **PLAN** hide opsi |
| Tag sumber Serial di hasil | DISPLAY_ONLY — **OK** |
| PurchaseReportSource UNION | **PLAN** skip saat OFF |
| DashboardController unset serial approvals | OK |
| Dashboard.vue route map keys | OK (keys tak datang) |

### Pengaturan / Reset / Role

| Surface | Verdict |
|---------|---------|
| Reset chips serial | **TETAP** (keputusan) |
| Role matrix | **SEMBUNYIKAN** + preserve (keputusan) |
| Settings help text Modul | OK (kontrol surface) |

### Shared / out of scope hide

| Surface | Verdict |
|---------|---------|
| SerialUnitPicker.vue | Call-site gate only — **PLAN** |
| AttachesSerialUnits* | DISPLAY_ONLY — **OK** |
| Models / migrations / seeders / VerifyDataInvariants | OUT_OF_SCOPE |
| tests Feature/Serial* + ElektronikModuleTest | Perluas test — **PLAN** |
| docs serial.md / CLAUDE | Update — **PLAN** |
| e2e serial specs | OUT_OF_SCOPE hide |
| UserController / delete guards doc_serial_intake | Integrity — **OK** keep |

### Temuan audit terbalik yang menambah/memperjelas plan

1. **Role hide harus preserve permission** saat update (edge case wipe) — **ditambahkan keputusan #1**.
2. **CreateManualSalesAction::save** dipakai create+update — satu gate cukup.
3. Tidak ada tip serial di PriceChangePage — tidak perlu kerja ekstra.
4. Detail list pages (Transfer/Sales/…) DISPLAY_ONLY — confirmed non-goal.
5. StockCard **BE** boleh tetap resolve PBS source; cukup FE hide link saat OFF.
6. Tidak ditemukan surface serial FE/BE baru di luar tabel di atas setelah grep full `src/` + `app/`.

**Kesimpulan audit terbalik:** plan menutup semua gap **MUST_HIDE / MUST_BLOCK**. Sisanya already OK, DISPLAY_ONLY, atau keputusan sadar (Reset keep / Role hide). Tidak ada domain serial transaksi lain yang belum tercakup.

### Residual (bukan gap fungsional — defense / polish)

| Item | Risiko | Keputusan plan |
|------|--------|----------------|
| `SerialUnitPicker.vue` tidak self-gate `serialEnabled` | Kalau ada call-site baru lupa gate, picker bisa muncul lalu API 403 | **Ikut sekarang:** early `v-if="serialEnabled"` / skip load di picker sendiri (belt-and-suspenders) |
| Deep-link historis PerDokumen → intake saat OFF | Router sudah bounce `requiresElektronik` | FE tetap disable/hide click (sudah di plan) |
| Komentar kode “Perubahan Harga Serial” di PriceChangeController | Tidak ada menu itu; harga serial = per-unit | Docs/comment only — **skip** |
| Yakin 100% tanpa implementasi + test | Selalu ada risiko miss | Ditutup oleh suite ElektronikModuleTest yang diperluas |

---

## Tests

- Bagian A: free ON/OFF + draft preexisting.
- Bagian B: ElektronikModuleTest — PBS 403; product filters; reject payloads; template tanpa is_serial; lock counts intake; **Role permissions response tanpa modul serial saat OFF**; **update role saat OFF tidak menghapus serial-* existing**.
- Docs: CLAUDE + serial.md §4.14 whole-app + Role/Reset notes + returns settings.
