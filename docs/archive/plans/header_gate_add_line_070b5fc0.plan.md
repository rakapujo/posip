---
name: Header Gate Add Line
overview: Hard-disable semua kontrol yang membuat baris baru sampai header bermakna terisi (mirror Retur Penjualan). Scan/Load Semua/mount auto-row ikut dicakup. Skip HPP/Price Change. UI-only; POS tidak disentuh.
todos:
  - id: gate-sales-po-pr
    content: "Sales/PO: disable Tambah + insertDetailAfter + early-return. PR free: isDocLinked || !supplier || !warehouse (mirror SalesReturn)"
    status: completed
  - id: gate-pbs
    content: "PBS: gate kedua Tambah Baris + mount addEmptyRow on product+warehouse+supplier; product picker header tetap enabled"
    status: completed
  - id: gate-inv
    content: Transfer from+to; Adj Tambah+insert (+ scan sudah disabled); Opname Tambah (+ scan/Load sudah disabled); Repack input+output warehouse; soft notify tetap
    status: completed
  - id: gate-promo
    content: "Promo: disable Tambah Baris + defer mount first detail sampai nama_promo + tanggal_mulai (jam Happy Hour hanya jika restrictHour). Skip HPP/PriceChange"
    status: completed
  - id: smoke
    content: "Smoke: semua entry point row-create; PBS/Promo mount; POS/HPP/PriceChange tidak disentuh"
    status: completed
isProject: false
---

# Header Gate sebelum Tambah Produk/Baris

## Audit terbalik (kodebase → plan) — 23 Jul 2026

Membership form **sudah lengkap** (AppMenu → tidak ada FormPage orphan). Yang masih **gap wording/implementasi** di plan sebelumnya:

| Gap vs live code | Fix di plan ini |
|------------------|-----------------|
| `onScanProduk` (Adj/Opname) memanggil `addDetail` — row-create | Cantumkan sebagai entry point; UI scan **sudah** `:disabled="!warehouse_id"` — pastikan tetap; harden handler |
| `loadAllProducts` (Opname full) bulk-push details | Cantumkan; template **sudah** disabled — pastikan tetap sejajar `canAddLines` |
| PBS `product_id` bisa terbaca “party only” | Gate eksplisit: **product + warehouse + supplier** |
| PR free live hanya `isDocLinked` | Wajib tambah supplier + warehouse |
| `insertDetailAfter` hanya di Sales/PO/Adj | Jangan implied universal |
| Promo `onMounted` `details.push(newDetailRow())` | Sama seperti PBS mount — **defer** sampai header siap |
| Soft notify lemah di Sales/PO/PR/PBS/Promo | Early-return di handler wajib (defense) |
| Promo jam Happy Hour di gate | Sempitkan: jam hanya jika `restrictHour` |

**Bukan gap membership:** HPP/PriceChange OUT benar; SerialUnitPicker bukan line-add; drawer tidak perlu gate terpisah; POS/deposit/serial-checkbox/barcode N/A.

## Verdict scope

| Status | Form |
|--------|------|
| **Sudah OK** | [SalesReturnFormPage.vue](syilex-frontend/src/views/penjualan/SalesReturnFormPage.vue); Pembayaran Hutang/Piutang |
| **IN — hard gate** | Sales, PO, Retur Beli **free**, PBS, Transfer, Adj, Opname, Repack, Promo |
| **OUT — skip hard gate** | Koreksi HPP, Perubahan Harga (header selalu default) |
| **N/A** | Deposit, Serial Change, Serial HPP, Register Unit, Print Barcode, CRUD master, laporan, POS |

**Backend / POS:** tidak diubah.

## Pola referensi (Sales Return)

- Gate: party + warehouse saja — **bukan** tanggal default `now()`.
- Disable **kontrol yang membuat baris**; drawer/search di baris yang sudah ada tidak perlu header-gate terpisah.
- PR linked: tetap `isDocLinked` (item dari dokumen).

```vue
:disabled="!canAddLines"
v-tooltip.top="canAddLines ? null : 'Pilih … dulu'"
```

Handler: `if (!canAddLines) return` (+ soft `notify.selectFirst` di form inventory yang sudah punya).

## Matriks entry point (wajib di-gate)

| Form | `canAddLines` | Kontrol row-create |
|------|---------------|-------------------|
| Sales | `customer_id` + `warehouse_id` | Tambah Produk; `insertDetailAfter` |
| PO | `supplier_id` + `warehouse_id` | Tambah Produk; `insertDetailAfter` |
| Retur Beli free | `supplier_id` + `warehouse_id` | Tambah: `:disabled="isDocLinked \|\| !canAddLines"` |
| PBS | `product_id` + `warehouse_id` + `supplier_id` | Kedua Tambah Baris; **skip** mount `addEmptyRow`; product picker **enabled** |
| Transfer | `warehouse_from_id` + `warehouse_to_id` + distinct | Tambah |
| Adjustment | `warehouse_id` | Tambah; `insertDetailAfter`; scan (sudah disabled — jaga) |
| Opname | `warehouse_id` | Tambah (partial); scan (sudah disabled); Load Semua (sudah disabled — jaga) |
| Repack | `warehouse_id` | Input + Output Tambah; early-return di `addOutput` |
| Promo | `nama_promo` + `tanggal_mulai` (+ jam **hanya** jika Happy Hour/`restrictHour`) | Tambah Baris; **defer** mount `details.push` |

**Bukan line-add:** `SerialUnitPicker`, Autocomplete yang hanya mengisi baris kosong yang sudah ada, `openProductPicker` / drawer.

## Strictness vs `validate()`

- Sengaja **tidak** gate tanggal default (`now()`) di Sales/PO/Transfer/Adj/Opname/Repack/PBS — sama Sales Return.
- PBS **harus** include `product_id` (validate wajib).
- Transfer hard gate **lebih ketat** dari soft notify hari ini (soft hanya `from`) — sengaja, selaras validate.

## Out of scope

- POS, HPP Form, Price Change Form (hard gate)
- Serial Change / Serial HPP / Register Unit / Deposit / Print Barcode / laporan
- Backend API; ProductUnitPickerDrawer internals
- Jangan menambah `insertDetailAfter` ke SalesReturn (tidak punya)

## Urutan kerja

1. Sales + PO + PR free
2. PBS (×2 Tambah + mount)
3. Transfer / Adj / Opname / Repack (scan/Load: verify tetap gated; Tambah/insert/output: harden)
4. Promo (Tambah + mount)
5. Smoke semua entry point di matriks
