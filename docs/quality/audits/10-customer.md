# Audit menu — 10 Customer

> **Status:** patched (scope A)  
> **SSoT kode:** `CustomerController` · `CustomersExport` · `CustomerPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Customer (`/app/master/customer`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| CU-B1 | P1 | Soft-delete + unique ignore deleted + copy kode arsip | FIXED |
| CU-E1 | P1 | Excel `jenis` = `walk_in`/`spesifik` (bukan label salah) | FIXED |
| CU-L1 | P1 | `GET /customers/list` hormati `search` | FIXED |
| CU-S1 | P1 | Export FE = `customer.view` (bukan `laporan.export`) | FIXED |
| CU-D1 | P2 | Assign tipe/kategori cukup `customer.update` | BY DESIGN |

Scope A: soft-delete, export jenis, list search, export gate.
