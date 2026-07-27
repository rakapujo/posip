# Audit menu — 08 Grup Produk

> **Status:** patched (scope A)  
> **SSoT kode:** `GrupController` / simple master · `GrupPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Klasifikasi Produk → Grup (`/app/master/grup`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| GR-S1 | P1 | Export FE = `grup.view` (bukan `laporan.export`) | FIXED |
| GR-B1 | P2 | Soft-deleted produk vs `can_delete` / nullOnDelete | BY DESIGN |

Scope A: export gate. Hierarki delete sudah lewat `products()` (aktif).
