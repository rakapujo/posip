# Audit menu — 13 Warehouse

> **Status:** patched (scope A)  
> **SSoT kode:** `WarehouseController` · `PosController` checkout · observer/import stock · `WarehousePage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Warehouse (`/app/master/warehouse`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| WH-S1 | P0 | Checkout POS: warehouse harus `isActive()` **dan** `isSaleable()` | FIXED |
| WH-B1 | P1 | Flip `is_saleable→false` diblok / dijaga jika masih dipakai terminal POS | FIXED |
| WH-X1 | P1 | `can_delete` lengkap (PO / serial intake / ref dokumen) | FIXED |
| WH-I1 | P1 | Import warehouse → init stock (selaras observer) | FIXED |
| WH-E1 | P1 | Export FE = `warehouse.view` | FIXED |
