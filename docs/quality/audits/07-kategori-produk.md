# Audit menu — 07 Kategori Produk

> **Status:** patched (scope A)  
> **SSoT kode:** `KategoriController` / simple master · `KategoriPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Klasifikasi Produk → Kategori (`/app/master/kategori`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| KT-S1 | P1 | Export FE = `kategori.view` (bukan `laporan.export`) | FIXED |
| KT-B1 | P1 | Delete/toggle: guard produk yang masih memakai `kategori_id` (bukan hanya grup) | FIXED |

Scope A: export gate + hierarchy product guards.
