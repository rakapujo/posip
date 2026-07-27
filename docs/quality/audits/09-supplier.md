# Audit menu — 09 Supplier

> **Status:** patched (scope A)  
> **SSoT kode:** `SupplierController` · `SupplierPage.vue` · SoftDeletes model  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Supplier (`/app/master/supplier`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| SU-B1 | P1 | Soft-delete + unique ignore deleted + copy kode arsip (tanpa UI restore) | FIXED |
| SU-S1 | P1 | Export FE = `supplier.view` (bukan `laporan.export`) | FIXED |

Scope A: soft-delete kode + export gate.
