# Audit menu — 02 Produk

> **Status:** patched (scope A)  
> **SSoT kode:** `syilex/app/Http/Controllers/Api/V1/ProdukController.php` · `syilex-frontend/src/views/master/ProdukPage.vue` · `syilex/app/Http/Controllers/Api/V1/ImportController.php`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master Data → Produk (`/app/master/produk`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| P-S1 | P0 | Hide / strip `avg_cost` dari response yang tidak boleh lihat HPP | FIXED |
| P-I1 | P0 | Import upsert tidak wipe `avg_cost` | FIXED |
| P-I2 | P0 | Import init `inventory_stock` (observer / `initializeForProduct`) | FIXED |
| P-E1 | P1 | Export FE gate `produk.view` (bukan `laporan.export`) | FIXED |

Scope A fokus HPP leak + import stok/HPP + export master. Temuan P2 UI/filter di luar scope tetap OPEN bila belum disentuh.

## Mobile dialog Satuan & Harga (2026-07-30)

Form non-serial Fieldset “Satuan & Harga”: di `<lg` pakai **kartu per unit** (Satuan/Konversi/Harga); `lg+` tetap tabel. Hapus `min-width: 36rem` yang memaksa scroll horizontal di dialog HP. Produk serial: fieldset tidak tampil (harga per unit di modul Serial).
