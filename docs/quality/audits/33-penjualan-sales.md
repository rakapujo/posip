# Audit menu — 33 Penjualan → Penjualan (Sales BO)

> **Status:** patched Wave A P0 (2026-07-25); walk-in BO exclude 2026-07-25; P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/penjualan/SalesPage.vue` · `SalesFormPage.vue` · `api/modules/sales.js` · `composables/useSalesInvoicePdf.js` · `composables/useTransactionList.js`  
> - Shared: `SerialUnitPicker.vue` · `ProductUnitPickerDrawer.vue` · `ListFiltersSheet` / `DetailDialog` / `RowActionButtons`  
> - BE (konteks FE): `ManualSalesController.php` · `ManualSalesCalculationService.php` · `CreateManualSalesAction` · `ApproveManualSalesAction` (`rebuildPromos: true`) · `VoidManualSalesAction`  
> - Routes FE: `penjualan-sales*` (`router/index.js` 395–411) · API: `/sales*`  
> - Menu: `AppMenu.vue` Penjualan → Penjualan (`sales.view`)  
> - Twin: `PurchaseOrderPage` / `PurchaseOrderFormPage` (audit `27-purchase-order.md`) · POS: `PosKasirPage` (jangan diubah kecuali bug eksplisit)  
> **Jika konflik:** ikuti kode.  
> **Urutan:** pertama di grup Penjualan (sebelum Retur / Piutang / Pembayaran / Deposit).

## Scope

Dokumen **penjualan backoffice** (`source=manual`): **draft → completed → optional voided**. Tidak ada lock (beda Retur Jual). Approve = posting stok + piutang (+ settle cash). Serial line via `SerialUnitPicker` bila `modules.elektronik_enabled`.

| Endpoint / surface | Permission | Dipakai |
|--------------------|------------|---------|
| `GET /sales` | `sales.view` (+ strip tanpa `view_harga`) | List |
| `GET /sales/{ulid}` | `sales.view` (+ strip) | Detail + form hydrate |
| `POST /sales` | `sales.create` | Draft create |
| `PUT /sales/{ulid}` | `sales.update` | Draft update |
| `DELETE` | `sales.delete` | Draft delete |
| `POST …/approve` | `sales.approve` | Posting |
| `POST …/void` | `sales.void` | Void tempo unpaid penuh |
| `GET /sales/products` | `sales.create` **saja** | Picker |
| `POST /sales/calculate` | `create \|\| update` | Ringkasan + Get Promo |
| `GET /sales/tax-settings` | **tanpa `can()`** | Label pajak |
| FE list / create / edit | `sales.view` / `create` / `update` | Router meta |
| AppMenu | `sales.view` | |

**CRUD UI:** list search/filter/sort/paginate; detail dialog; PDF A5; create/edit draft; approve; void. **Tidak:** Excel list, lock, edit completed, deep-link `?ulid=`, CTA piutang/pembayaran/deposit dari detail.

---

## Matriks aksi UI

| Aksi | Ada? | Gate / catatan |
|------|------|----------------|
| List / sort / paginate / search | Ya | `sales.view` |
| Filter customer / WH / status / tanggal | Ya | status: draft/completed/voided |
| Detail dialog | Ya | view; **tanpa** serial lines / piutang strip |
| Export PDF faktur | Ya (`useSalesInvoicePdf`) | strip harga; shared `exporting` |
| Export Excel | Tidak | — |
| Create / Edit / Delete draft | Ya | create / update / delete |
| Approve | Ya (list + detail) | approve; confirm text menyesatkan vs void |
| Void | Ya | void + tempo unpaid + no cash + terbayar 0 |
| Lock | Tidak | by design (vs retur) |
| Get Promo | Ya (form) | `rebuild_promos`; lock Disc 1–4 via `nama_promo` (hilang setelah reload) |
| Serial picker | Ya | expand + `SerialUnitPicker` |
| Cash / lunas | Ya | form + approve settle |
| CTA Piutang / Bayar / Deposit | Tidak | gap cross-menu |
| Customer quick-add | Tidak | no `CustomerFormDialog` |
| `sales.view_harga` di form | **Tidak** | list/detail/PDF gate; form full harga |

---

## Temuan

Severity: **P0** harus / keputusan · **P1** kuat · **P2** perbaikan · **P3** polish.  
ID: **SL-U\*** UI/UX/DRY · **SL-X\*** logic / security-UI / cross / state.

### SL-X — Logic / security UI / cross

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| WI-1 | P1 | **FIXED** Walk-in muncul di picker Sales BO / bisa di-create via API. | `CustomerRules::backofficeBlockMessage` · FE `getList({ jenis: 'spesifik' })` | — |
| SL-X1 | P0 | **Approve rebuild promo ≠ draft.** `ApproveManualSalesAction` memanggil `ManualSalesCalculationService::calculate($data, true)` dan hanya meneruskan `diskon_5_*` dari detail tersimpan → Disc 1–4 di-rebuild dari DB promo (atau di-nolkan). Grand total draft (setelah Get Promo / isi manual 1–4) bisa **berbeda** pasca-approve tanpa warning di FE. | `ApproveManualSalesAction.php` 54–75; CalculationService 35–59; Form `buildPayload` 746–761 (kirim 1–5) | FE: banner “Approve akan hitung ulang promo”; atau BE approve tanpa wipe manual BO; dokumentasikan keputusan. |
| SL-X2 | P0 | **Diskon Header 1–2 di form menipu.** UI editable Diskon 1/2/3 (`SalesFormPage` 1378–1396). `buildPayload` hardcode slot 0–1 `none` dan hanya kirim `diskon_3` sebagai `discounts[2]` (739–743). BE `headerDiscounts` **mengabaikan** FE slot 0–1; isi dari tipe/kategori customer; slot 2 = manual (clamp promo settings). Calculate/save **tidak** memakai edit user di Diskon 1–2. | Form 739–743, 1378–1396; `ManualSalesCalculationService` 79–105 | UI: Diskon 1–2 read-only + label tipe/kategori; hanya Diskon 3 (manual) editable; sync label dari response `labels`. |
| SL-X3 | P1 | **Form tanpa gate `sales.view_harga`.** List/detail/PDF strip; form tetap tampil harga, disc, ringkasan, SerialUnitPicker kolom harga jual (`showSell` default true). Twin PO form **sudah** `canViewHarga` (post-patch 27). | Form: no `useAuthStore`; vs `PurchaseOrderFormPage` 18, 1016+; Page 24–25, 280 | Mirror PO: hide/disable harga/disc/summary; `:showSell="canViewHarga"` di picker. |
| SL-X4 | P1 | **`GET /sales/products` = `sales.create` saja.** User `sales.update` tanpa create → picker 403 di edit. Calculate sudah `create\|\|update`. | Controller 215–218 vs 191–194 | Gate `create \|\| update` (mirror fix PO-S4). |
| SL-X5 | P1 | **`promo_id` tidak dikirim save; lock promo hilang setelah reload.** Get Promo set `promo_id`/`nama_promo` di state (640–648); `buildPayload` tidak kirim `promo_id`. `isPromoLockedSlot` pakai `nama_promo` (975–976) — kolom DB tidak punya `nama_promo`. Setelah simpan+edit ulang, Disc 1–4 terbuka. | Form 723–764, 974–976; CreateAction 84; migration promo_id only | Kirim `promo_id`; hydrate `nama_promo` dari relasi `promo` di show; atau lock by `promo_id`. |
| SL-X6 | P1 | **Ganti warehouse tidak clear `serial_unit_ids`.** Tidak ada `watch(warehouse_id)`. Unit dari gudang lama tetap di form; approve gagal di `resolveSelectedUnits` (warehouse mismatch) — UX error telat. | Form 1355–1360; no warehouse watch; PostsSalesInventory 71–76 | On WH change: clear serial ids / qty serial rows + notify. |
| SL-X7 | P1 | **`voided` status helper jelek.** `useTransactionList` severity/label map tanpa `voided` → Tag severity `secondary`, label mentah `voided` (bukan “Voided”). Filter option sudah “Voided”. | `useTransactionList.js` 306–324; Page 81, 286–289 | Tambah `voided: 'danger'` / `'Voided'`. |
| SL-X8 | P1 | **Cash maxlength FE > BE → 422.** FE `maxlength` 100/100/50; BE `max:50/50/30`. Sama pola PO-U4. | Form 1152–1161; Controller 304–306 | Samakan maxlength. |
| SL-X9 | P2 | **`tax-settings` tanpa `can()`.** | Controller 251–254 | Minimal `sales.view` / create\|update. |
| SL-X10 | P2 | **Approve confirm copy menyesatkan.** `useTransactionList` confirm: “tidak dapat dibatalkan” — Sales BO **bisa void** (tempo unpaid). | `useTransactionList.js` 268–270; Page void 156–189 | Custom confirm message di SalesPage (override) atau opsi message di composable. |
| SL-X11 | P2 | **Detail dialog miskin vs retur / PDF.** Tidak tampil `serial_units`, breakdown disc 1–5, status/sisa piutang, void reason. SalesReturnPage punya serial + deposit. | Page 317–402 vs SalesReturnPage ~599+; PDF `buildBodyRows` serial | Tampilkan serial mono; piutang Tag + link; disc chain. |
| SL-X12 | P2 | **Tidak ada deep-link / CTA cross-menu.** Tidak `?ulid=` buka detail; setelah approve/tempo tidak CTA ke Piutang / Pembayaran Piutang / Deposit. | Page/Form; AppMenu 107–112 | Query open-detail; footer buttons `router.push` dengan gate permission. |
| SL-X13 | P3 | **List warehouse filter tanpa `is_saleable`.** Form pakai `is_saleable: 1` (186); list filter semua WH — OK historis, inkonsisten dokumentasi. | Page 126–134 vs Form 186 | Dokumentasikan atau filter saleable + “termasuk non-saleable jika ada data”. |

### SL-U — UI/UX / DRY / empty / loading

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SL-U1 | P1 | = **SL-X3** form harga. | — | — |
| SL-U2 | P1 | = **SL-X2** header disc UX. | — | — |
| SL-U3 | P1 | **Subtotal baris client drift vs `/calculate`.** `getItemSubtotal` (883–918) round/mode lokal ≠ BE → kolom Subtotal form bisa beda ringkasan. Twin PO-U3. | Form 882–918, 1329–1332 | Pakai hasil line dari calculate atau mirror round BE. |
| SL-U4 | P2 | **`activeFilterCount` salah akses reactive.** `additionalFilters.value?.customer_id` — `additionalFilters` dari composable adalah **reactive**, bukan ref → badge customer/WH undercount. PO Page sudah tanpa `.value` (31–38). | Page 86–94 | `additionalFilters.customer_id` (tanpa `.value`). |
| SL-U5 | P2 | **DatePicker Clear/Today tanpa refetch.** Hanya `@date-select="onFilter"`. Twin PO-U6. | Page 206–209 | `@update:modelValue="onFilter"`. |
| SL-U6 | P2 | **Shared `exporting` global.** Satu ref untuk semua baris + footer → semua tombol PDF loading bersamaan. | Page 21, 296, 408; `useSalesInvoicePdf` 15 | Per-ulid loading map. |
| SL-U7 | P2 | **Ringkasan form hand-rolled**; catalog punya `MoneySummaryPanel`. | Form 1448–1491 | Reuse panel (opsional). |
| SL-U8 | P2 | **DRY monolit vs PO form.** Disc dialog, tipeOptions, calculate debounce, cash block, tempo heal — hampir copy PO + serial/promo. | Form ~1600 LOC vs PO Form | Extract shared doc-form helpers (setelah patch fungsional). |
| SL-U9 | P2 | **Empty/loading cukup di list; form empty OK; filter load gagal sunyi di list.** `loadCustomers`/`loadWarehouses` list hanya `console.error` (tanpa toast); form pakai `notify.apiError`. | Page 114–134 vs Form 172–193 | Toast di list juga. |
| SL-U10 | P2 | **Tidak Excel** di list (Piutang/Deposit punya). | Page | Optional export. |
| SL-U11 | P3 | Unit `Select` tanpa `filter` (aturan frontend). | Form 1243 | `filter`. |
| SL-U12 | P3 | Copy-paste dust: comment “Load suppliers”, log “Failed to load PO”, field name `tanggal_po`. | Page 113; Form 56, 276, 867 | Rename/bersihkan. |
| SL-U13 | P3 | Tidak ada `CustomerFormDialog` quick-add di header customer. | Form 1101–1104 | Optional + `customer.create`. |
| SL-U14 | P3 | Get Promo disabled tanpa tooltip kenapa (customer/lines). | Form 1172–1181 | Tooltip mirror Tambah Produk. |
| SL-U15 | P3 | Serial: disc/harga average read-only OK; empty expansion jika `!serialEnabled` expander tetap ada. | Form 1205, 1352 | Hide expander column jika tidak ada serial rows. |

### POS twin (referensi, bukan ubah POS)

| Topik | POS | Sales BO |
|-------|-----|----------|
| Promo 1–4 | BE rebuild selalu (anti-fraud) | Draft trust FE; approve rebuild (`true`) — **inkonsisten antar tahap** (SL-X1) |
| Disc 5 | Manual kasir | Manual + dialog |
| Header disc 1–2 | Auto customer | Sama di BE; FE BO menampilkan editable palsu (SL-X2) |
| Serial | Inline cart | `SerialUnitPicker` expand |
| Print | Thermal receipt | A5 PDF faktur |

---

## Antrian patch (usulan prioritas)

1. **P0** SL-X1 — keputusan approve rebuild vs draft totals + FE warning.  
2. **P0** SL-X2 / SL-U2 — header disc 1–2 read-only + label master.  
3. **P1** SL-X3 / SL-U1 — `sales.view_harga` di form + picker.  
4. **P1** SL-X4 — products `create\|\|update`.  
5. **P1** SL-X5 — persist/hydrate promo lock.  
6. **P1** SL-X6 — clear serial on warehouse change.  
7. **P1** SL-X7, SL-X8, SL-U3 — voided label; cash maxlength; line subtotal.  
8. **P2** SL-U4/U5/U6, SL-X10–12, tax-settings, detail enrich, DRY.

---

## Ringkasan eksekutif

List Sales BO sudah mengikuti pola `useTransactionList` + permission aksi (termasuk void) dan PDF faktur dengan strip harga. Gap kritis: **Diskon Header 1–2 editable tapi diabaikan BE**, **approve rebuild promo mengubah total draft**, dan **form belum gate `view_harga`** (PO sudah). Serial/promo UX rapuh setelah ganti gudang / reload (lock hilang). Cross-menu: tidak ada CTA piutang/pembayaran/deposit dari detail.

**Fix hanya jika user bilang execute.** Berikut antrian AppMenu: Retur Penjualan → Piutang → Pembayaran → Deposit.

## Patched — gap close (2026-07-29)

| Item | Fix |
|------|-----|
| Picker KI/SN | `products()` pakai `searchPicker` (+ optional `warehouse_id`) |
| Promo lock reload | show eager `details.promo`; FE `promo_id \|\| nama_promo` |
| Diskon header UI | map BE `total_diskon` → `total_diskon_header` |
| Filter badge | `additionalFilters` tanpa `.value` |
| Date Clear/Today | `@update:modelValue="onFilter"` |
| WH change | clear SN + reset qty serial + toast |

**Export:** PDF faktur ada; Excel list **tidak** (by design — lihat Laporan Penjualan).

## Patched — hapus gate harga jual (2026-07-30)

| Item | Fix |
|------|-----|
| `sales.view_harga` | Dihapus dari catalog/admin + `Permission::delete` di seeder |
| ManualSales index/show/products | Uang jual + piutang nested selalu; `hpp_at_time` tetap `stok.view_hpp` |
| FE Sales list/form/PDF | Tanpa `canViewHarga`; PDF composable selalu tampil harga |
| SL-X3 (audit lama) | Dicabut — arah dibalik: jual tidak digate |

## Patched — picker mode + harga serial (2026-07-29)

| Item | Fix |
|------|-----|
| `modeHint` drawer | Prop `ProductUnitPickerDrawer.modeHint` — Sales + Retur + PO + PBS |
| Harga serial Rp.0 | `resolveSerialPickerPrice` di `productUnitLineHelpers.js` (slot unit terakhir / `harga_4..1`); jangan andalkan `harga_1` saja |
| `getPickerUnitPrice` | Null-safe (tanpa strip → `—`, bukan `Rp 0`) |
