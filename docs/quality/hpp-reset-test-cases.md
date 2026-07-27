# HPP Reset Feature - Manual Test Cases

## Prerequisites
- Login sebagai admin (admin@posip.com / password)
- Pastikan ada produk dengan stok dan HPP > 0

---

## Test Case 1: Adjustment OUT - Stock Habis → HPP Reset

### Setup
1. Buat/pilih produk dengan:
   - Stok: 10 pcs
   - HPP: Rp 15.000

### Steps
1. Buka menu **Inventory > Adjustment**
2. Klik **Tambah Adjustment**
3. Pilih gudang yang sesuai
4. Tambah detail:
   - Produk: (pilih produk di atas)
   - Alasan: RUSAK
   - Qty: **10** (habiskan semua stok)
5. Simpan adjustment
6. Approve adjustment

### Expected Results
- ✅ Stok produk menjadi 0
- ✅ HPP produk di master menjadi 0
- ✅ Di Kartu Stok:
  - Entry ADJUSTMENT_OUT dengan avg_cost_before=15000, avg_cost_after=15000
  - Entry HPP_RESET dengan avg_cost_before=15000, avg_cost_after=0
  - Notes: "Auto Reset HPP (Stock Kosong)"
- ✅ Di Pergerakan HPP, entry HPP_RESET muncul dengan badge warna warning (kuning)

---

## Test Case 2: Adjustment OUT - Stock Sisa → HPP Tidak Reset

### Setup
1. Buat/pilih produk dengan:
   - Stok: 20 pcs
   - HPP: Rp 10.000

### Steps
1. Buat adjustment OUT untuk qty **5** (sisa 15 pcs)
2. Approve adjustment

### Expected Results
- ✅ Stok produk menjadi 15
- ✅ HPP produk TETAP Rp 10.000 (tidak berubah)
- ✅ Di Kartu Stok: Hanya ada ADJUSTMENT_OUT, TIDAK ada HPP_RESET
- ✅ avg_cost_before = avg_cost_after = 10000 di stock card

---

## Test Case 3: Repack - Bahan Habis → HPP Reset

### Setup
1. Buat/pilih produk INPUT dengan:
   - Stok: 5 pcs
   - HPP: Rp 20.000
2. Buat/pilih produk OUTPUT berbeda dengan:
   - Stok: 0 pcs
   - HPP: Rp 0

### Steps
1. Buka menu **Inventory > Repack**
2. Klik **Tambah Repack**
3. Pilih tipe: **Pecah**
4. Tambah bahan (input):
   - Produk: (produk INPUT)
   - Qty: **5** (habiskan semua)
5. Tambah hasil (output):
   - Produk: (produk OUTPUT)
   - Qty: 10
6. Biaya repack: 0
7. Simpan dan Approve

### Expected Results
- ✅ Stok produk INPUT menjadi 0
- ✅ HPP produk INPUT menjadi 0
- ✅ Di Kartu Stok produk INPUT:
  - Entry REPACK_OUT
  - Entry HPP_RESET dengan notes "Auto Reset HPP (Stock Kosong)"
- ✅ Stok produk OUTPUT menjadi 10
- ✅ HPP produk OUTPUT dihitung = (5 * 20000 + 0) / 10 = 10000

---

## Test Case 4: Double Reset Prevention

### Setup
1. Buat produk dengan:
   - Stok: 0 pcs
   - HPP: 0 (sudah di-reset sebelumnya)

### Steps
1. Buat adjustment OUT (dengan negative stock allowed)
2. Qty: 5 (stok jadi -5)
3. Approve

### Expected Results
- ✅ Stok produk menjadi -5
- ✅ HPP tetap 0
- ✅ TIDAK ada entry HPP_RESET baru (karena HPP sudah 0)

---

## Test Case 5: Multi-Warehouse - Global Stock Check

### Setup
1. Buat produk dengan stok di 2 gudang:
   - Gudang A: 5 pcs
   - Gudang B: 10 pcs
   - HPP: Rp 12.000

### Steps
1. Buat adjustment OUT di Gudang A untuk qty **5** (stok Gudang A = 0)
2. Approve

### Expected Results
- ✅ Stok Gudang A = 0
- ✅ Stok Gudang B = 10 (tidak berubah)
- ✅ Total stok global = 10
- ✅ HPP TETAP Rp 12.000 (karena global stock > 0)
- ✅ TIDAK ada HPP_RESET

---

## Test Case 6: Filter HPP_RESET di Kartu Stok

### Steps
1. Buka menu **Inventory > Stok**
2. Klik icon kartu stok pada produk yang pernah mengalami HPP Reset
3. Di filter "Tipe Transaksi", pilih **Reset HPP (Stock Kosong)**

### Expected Results
- ✅ Dropdown filter menampilkan opsi "Reset HPP (Stock Kosong)"
- ✅ Hanya entry HPP_RESET yang ditampilkan
- ✅ Badge berwarna kuning (warning)

---

## Test Case 7: Pergerakan HPP - HPP_RESET Muncul

### Steps
1. Buka menu **Inventory > Stok**
2. Klik icon chart pada produk yang pernah mengalami HPP Reset
3. Lihat tabel pergerakan HPP

### Expected Results
- ✅ Entry HPP_RESET muncul di tabel
- ✅ HPP Sebelum: Rp 15.000 (atau nilai sebelum reset)
- ✅ HPP Sesudah: Rp 0
- ✅ Selisih ditampilkan dengan warna hijau (turun)
- ✅ Badge "Reset HPP (Stock Kosong)" berwarna kuning

---

## Test Case 8: Transfer - Tidak Trigger HPP Reset

### Setup
1. Buat produk dengan stok di 1 gudang:
   - Gudang A: 10 pcs
   - Gudang B: 0 pcs
   - HPP: Rp 8.000

### Steps
1. Buat transfer dari Gudang A ke Gudang B
2. Qty: **10** (semua stok)
3. Approve

### Expected Results
- ✅ Stok Gudang A = 0
- ✅ Stok Gudang B = 10
- ✅ Total stok global = 10
- ✅ HPP TETAP Rp 8.000
- ✅ TIDAK ada HPP_RESET (transfer tidak mengubah global stock)

---

## Checklist Badge Colors

| Transaction Type | Expected Color |
|-----------------|----------------|
| PURCHASE | Green (success) |
| SALES | Red (danger) |
| ADJUSTMENT_IN | Green (success) |
| ADJUSTMENT_OUT | Red (danger) |
| TRANSFER_IN | Green (success) |
| TRANSFER_OUT | Red (danger) |
| REPACK_IN | Green (success) |
| REPACK_OUT | Red (danger) |
| **HPP_RESET** | **Yellow (warn)** |
| STOCK_OPNAME | Yellow (warn) |

---

## Notes
- Semua test dilakukan setelah clear browser cache
- Pastikan backend sudah running dan database sudah migrate
- Screenshot hasil test untuk dokumentasi
