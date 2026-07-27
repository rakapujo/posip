# Audit menu — 67 Pengaturan → Import Master

> **Status:** patched (scope P0+P1)  
> **SSoT kode:** `ImportController` + `ImportTemplateExport` + `ImportMasterPage.vue` + `ProdukRules` / `TipeRules` / `KategoriRules` / `GrupRules`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Pengaturan → Import Master (`/app/pengaturan/import`).  
> **Plan:** `import_master_audit_67_f62cd153.plan.md`

## Inventory

| Area | Path |
|------|------|
| BE | `syilex/app/Http/Controllers/Api/V1/ImportController.php` |
| Rules | `ProdukRules`, `TipeRules`, `KategoriRules`, `GrupRules`, `WarehouseRules`, `CustomerRules`, `SupplierRules`, `MetodePembayaranRules` |
| FE | `syilex-frontend/src/views/pengaturan/ImportMasterPage.vue` |
| Tests | `ImportGuardsTest`, `ImportMasterEntitiesTest`, `ImportProdukSerialTest`, `ImportTemplateIntegrityTest` |

## Gap P0 + P1 (IN_SCOPE)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| IM-BE-1 | P0 | Upsert reactivate → `initializeForProduct/Warehouse` | FIXED |
| IM-BE-2 | P0 | Produk validateUnitsAndPrices via ProdukRules | FIXED |
| IM-BE-3 | P0 | Deactivation guards (WH/Customer/Supplier/Metode/Tipe/Kategori/Grup) | FIXED |
| IM-BE-5 | P1 | Tolak create walk_in; upsert walk-in Rules | FIXED |
| IM-BE-4/14 | P1 | Lookup `status=active` + masterReferenceErrors | FIXED |
| IM-BE-6 | P1 | Metode `validateImportRow` | FIXED |
| IM-BE-7 | P1 | Auto price via ProdukRules | FIXED |
| IM-BE-8/9 | P1 | Chunk counters post-commit; QueryException generik | FIXED |
| IM-BE-10 | P1 | Activity log batch Import | FIXED |
| IM-BE-11 | P1 | Barcode unique precheck | FIXED |
| IM-BE-12 | P1 | Supplier required PIC+telepon | FIXED |
| IM-BE-13 | P1 | WH unsaleable + terminal | FIXED |
| IM-FE-1…7 | P1 | Upsert gate, double-submit, Message, a11y picker, FileUpload.clear, empty, limits | FIXED |

## OUT / P2

Metadata endpoint; Eloquent rewrite; sheet Contoh template; N+1 10k; FE unit tests; silent-coercion total; sample walk_in di template (re-upload ditolak create).
