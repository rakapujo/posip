# Audit menu — 05 Brand Produk

> **Status:** patched (scope A)  
> **SSoT kode:** `BrandController` + `HandlesSimpleMasterCrud` · `BrandPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Brand (`/app/master/brand`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| BR-S1 | P1 | Export FE = `brand.view` (bukan `laporan.export`); API export = view | FIXED |
| BR-D2 | P2 | Soft-deleted produk tidak dihitung `can_delete` → FK nullOnDelete | BY DESIGN |

Scope A: export gate master. Tidak ada P0 kritis di Brand.
