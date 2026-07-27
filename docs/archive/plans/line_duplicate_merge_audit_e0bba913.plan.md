---
name: Line duplicate merge audit
overview: "Audit terbalik semua menu AppMenu + form orphan + API → plan. IN SCOPE: Sales/PO/PBS/Retur free + BE Sales/SalesReturn + fix PR Lock + highlight. POS dikunci. Sisanya OUT/BACKLOG dengan alasan eksplisit."
todos:
  - id: reusable-drawer
    content: ProductUnitPickerDrawer reusable
    status: completed
  - id: wire-sales-po
    content: "Sales+PO: drawer; hapus merge; validate FE; highlight"
    status: completed
  - id: wire-pbs
    content: "PBS: drawer serialOnly header; confirm ganti produk"
    status: completed
  - id: wire-returns-free
    content: "Retur jual/beli free: drawer; highlight; FE validate"
    status: pending
  - id: fix-pr-lock
    content: "CRITICAL: LockPurchaseReturn izinkan product sama beda unit (running stock) — default opsi A"
    status: completed
  - id: backend-unique
    content: "BE unik product+unit: Manual Sales + Sales Return (field unit); tes; PO/PR sudah ada"
    status: completed
  - id: highlight-rows
    content: Highlight soft/tegas Sales, PO, Retur free
    status: pending
  - id: smoke-check
    content: Smoke multi-satuan + lock retur beli PCS+BOX + 422; POS tidak berubah
    status: pending
isProject: false
---

# Audit terbalik: semua menu → plan

Metode: setiap item AppMenu + form orphan + orphan POS/struk + endpoint dokumen API dipetakan ke status plan. Tidak ada menu “diam-diam terlewat”.

**Status:**
- **IN** = dikerjakan gelombang ini
- **OUT** = sengaja tidak diubah (alasan)
- **LOCK** = POS — dilarang disentuh kecuali bug
- **BACKLOG** = bisa nanti, bukan bug gelombang ini

---

## A. Yang IN SCOPE (wajib)

| Menu / form | File | Kenapa masuk |
|-------------|------|--------------|
| Penjualan form | `SalesFormPage.vue` | Merge bug + multi-satuan |
| PO form | `PurchaseOrderFormPage.vue` | Merge bug + multi-satuan |
| Pembelian Serial form | `SerialIntakeFormPage.vue` | Picker produk header → drawer |
| Retur Penjualan form (free) | `SalesReturnFormPage.vue` | Multi-satuan tanpa unit-first; belum validate dup |
| Retur Pembelian form (free) | `PurchaseReturnFormPage.vue` | Multi-satuan; drawer; **fix Lock** |
| BE Manual Sales | `ManualSalesController` | Belum unik `product_id+unit` |
| BE Sales Return | `BackofficeSalesReturnController` | Belum unik free mode |
| BE Lock retur beli | `LockPurchaseReturnAction` | CRITICAL inkonsistensi vs store |
| Highlight | Sales, PO, kedua retur free | Diminta di plan |

List pages (SalesPage, POPage, Retur pages, SerialIntakePage) = hanya list — **OUT** (tidak pick line).

---

## B. Audit terbalik AppMenu + orphan (lengkap)

### Home
| Menu | Status | Alasan |
|------|--------|--------|
| Dashboard | OUT | Tidak pick produk ke dokumen |

### Master Data
| Menu / form | Status | Alasan |
|-------------|--------|--------|
| Produk (master) | OUT | Definisi unit_1..4, bukan line transaksi |
| Perubahan Harga list | OUT | |
| Perubahan Harga **form** | OUT / BACKLOG UX | 1 produk 1 baris; reject dup product_id; edit harga 1–4 — bukan pilih satuan jual line |
| Perubahan Data Serial list/form | OUT / BACKLOG UX | Header 1 produk serial + checklist SN (mirip PBS) — bukan multi-satuan retail; reuse drawer opsional nanti |
| Brand / Tipe / Kategori / Grup | OUT | Klasifikasi |
| Supplier / Customer / Tipe-Kat Customer | OUT | |
| Warehouse / Metode Bayar | OUT | |
| Promo list | OUT | |
| Promo **form** | OUT | Target produk/grup; bukan qty×satuan |
| Print Barcode | OUT / BACKLOG | Sudah unit-dialog + merge list cetak — domain label; jangan samakan dengan Sales |

### Inventory
| Menu / form | Status | Alasan |
|-------------|--------|--------|
| Stok / Kartu Stok / Pergerakan HPP | OUT | Baca / filter produk |
| Register Unit Serial | OUT | Filter/register SN |
| Koreksi HPP Serial list/form | OUT / BACKLOG UX | Header produk + SN (seperti Serial Change) |
| Opname / Adjustment / Transfer / Repack / HPP Correction **forms** | OUT / BACKLOG UX | 1 produk 1 baris base qty; filter+validate product_id — **bukan** bug satuan |
| List pages inventory | OUT | |

### Pembelian
| Menu / form | Status | Alasan |
|-------------|--------|--------|
| PO list | OUT | |
| PO **form** | **IN** | |
| Pembelian Serial list | OUT | |
| PBS **form** | **IN** | |
| Hutang / Pembayaran Hutang / Deposit | OUT | Uang, bukan produk line |
| Retur Beli list | OUT | |
| Retur Beli **form** | **IN** (free + fix lock) | Linked = drawer off |

### Penjualan
| Menu / form | Status | Alasan |
|-------------|--------|--------|
| Sales list | OUT | |
| Sales **form** | **IN** | |
| Retur Jual list | OUT | |
| Retur Jual **form** | **IN** (free) | Linked = drawer off |
| Piutang / Pembayaran Piutang / Deposit | OUT | Uang |

### POS
| Menu / form | Status | Alasan |
|-------------|--------|--------|
| Shift / Terminal | **LOCK** | Tidak relevan picker |
| POS Kasir (orphan) | **LOCK** | Unit dialog sudah ada; **jangan ubah** |
| POS checkout / POS return API | **LOCK** | Tidak tambah unik product+unit di POS kecuali bug |

### Laporan (semua submenu)
| Semua laporan penjualan/pembelian/keuangan/promo/performa/inventory | OUT | Read-only / filter |

### Pengaturan
| Menu | Status | Alasan |
|------|--------|--------|
| User / Role / Settings / Import / Reset | OUT | Settings mempengaruhi serial/harga mode tapi bukan line picker |

### Orphan lain
| Halaman | Status | Alasan |
|---------|--------|--------|
| Login / Access / Error / NotFound | OUT | |
| Struk Online | OUT | Display saja |

---

## C. Audit terbalik API dokumen → plan

| Modul API | Unik line sekarang | Status plan |
|-----------|-------------------|-------------|
| Purchase Order | product+unit_used | Sudah OK; tes HTTP boleh ditambah |
| Purchase Return store | product+unit_used | OK store; **Lock product_id only → IN fix** |
| Manual Sales | **tidak ada** | **IN** tambah `unit` |
| Sales Return BO | **tidak ada** (linked: sales_detail_id) | **IN** free `product_id+unit` |
| POS checkout / POS return | tidak ada | **LOCK** |
| Adjustment / Transfer / Opname / Repack / HPP / Price Change | product_id only | OUT (domain 1 baris) |
| Serial Intake/Change/HPP | 1 product/doc | PBS picker IN; Change/HPP OUT |
| Deposit / Hutang / Pembayaran / Laporan | N/A | OUT |
| Approve Adj/Transfer | product_id only | OUT |
| Approve Manual Sales / Lock Sales Return | tidak tolak multi product line | OUT (multi-satuan OK) |
| Approve PO | tidak tolak multi product | OUT (running stock OK) |

---

## D. Checklist “ada yang terlewat?”

| Pertanyaan | Jawaban |
|------------|---------|
| Ada form multi-satuan line di luar Sales/PO/Retur? | Hanya PrintBarcode + POS → OUT/LOCK |
| Ada merge FE lain? | Hanya Sales, PO, POS cart, PrintBarcode list → POS/Print OUT |
| Ada BE unik yang salah diasumsikan “belum”? | PO & PR store **sudah**; jangan buat ulang |
| Ada BE yang memblokir multi-satuan setelah save? | **Ya: LockPurchaseReturn** → wajib IN |
| Serial Change/HPP mirip PBS? | Ya pola header — **tidak** wajib gelombang 1 (bukan bug merge) |
| calculate() tanpa cek dup? | Catat: ikutkan di Sales/PO bila sentuh controller yang sama |
| Docs/tes? | Extend ManualSales + SalesReturn + PR lock + HTTP PO/PR messages |

**Kesimpulan audit terbalik:** tidak ada menu multi-satuan transaksi BO yang lolos dari IN kecuali yang sengaja OUT/LOCK. Satu celah domain kritis yang harus masuk pekerjaan: **Lock retur beli**.

---

## E. Implementasi (urutan)

1. `ProductUnitPickerDrawer`
2. Sales + PO (hapus merge, FE validate, highlight)
3. PBS drawer header
4. Retur free + highlight
5. **Fix LockPurchaseReturn** (opsi A: running stock, izinkan beda unit)
6. BE unik Manual Sales + Sales Return (+ tes); pastikan PO/PR store tetap
7. Smoke termasuk lock retur PCS+BOX; verifikasi POS tidak berubah

### Keputusan terkunci
- POS: tidak disentuh
- Inventory drawer: backlog saja
- Lock PR: opsi **A** (izinkan product sama beda unit)
- PrintBarcode / Serial Change / Serial HPP / Promo / PriceChange: OUT gelombang ini
