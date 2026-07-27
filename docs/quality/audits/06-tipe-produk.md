# Audit menu — 06 Tipe Produk

> **Status:** patched (scope A)  
> **SSoT kode:** `TipeController` / simple master · `TipePage.vue` · hierarki klasifikasi  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Klasifikasi Produk → Tipe (`/app/master/tipe`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| TP-S1 | P1 | Export FE = `tipe.view` (bukan `laporan.export`) | FIXED |
| TP-B1 | P1 | Delete/toggle: guard produk yang masih memakai `tipe_id` (bukan hanya kategori) | FIXED |

Scope A: export gate + hierarchy product guards.
