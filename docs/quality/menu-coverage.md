> **Status:** canonical
> **SSoT kode:** syilex/tests · syilex-frontend/e2e
> **Jika konflik:** ikuti kode, lalu update dokumen ini.
# Menu Coverage Ledger

Status: **Fase 1–5 selesai** (PHPUnit Feature deep + Playwright smoke/journey gate).

Standar deep: bukan hanya `index 200`. Layer C — AccessCoverage + CRUD/lifecycle + money invariants + e2e smoke semua list + journey uang.

---

## Ringkasan fase

| Fase | Isi | Status |
|------|-----|--------|
| 1 Gaps | Dashboard, tipe/kategori customer, terminal, user/role, deposit supplier, import non-produk, stok access, AR gates/strip | **Done** |
| 2 Access | `*AccessCoverage*` semua domain + `PenjualanAccessCoverageTest` + `MenuRoutePermissionTest` | **Done** |
| 3 Deepen | Hutang/deposit/serial-HPP/repack×serial/HPP movement/produk retail/reset matrix/strip uang | **Done** |
| 4 E2E | `menu-shell` + journey PO / Sales BO / serial-intake→POS + print-barcode | **Done** |
| 5 Gate | Full PHPUnit + Playwright targeted + ledger ini + CI guidance di e2e README | **Done** |

---

## Ledger per area (PHPUnit Feature)

Depth: **deep** = CRUD/lifecycle + permission + invariant; **access** = deny/allow; **money** = strip nominal/harga + ledger/stok.

| Area / menu | Tes utama | Depth |
|-------------|-----------|-------|
| Dashboard | `Dashboard/DashboardApiTest` | deep (permission) · **product audit:** [audits/01-dashboard.md](audits/01-dashboard.md) |
| Brand / tipe / kategori / grup / … | `Master/MasterAccessCoverageTest` (+ CRUD existing) | access + deep |
| Tipe / Kategori Customer | `Master/TipeKategoriCustomerCrudTest` | deep |
| POS Terminal | `Pos/PosTerminalCrudTest`, `Pos/PosAccessCoverageTest` | deep |
| User | `Pengaturan/UserCrudTest`, `Role/RoleUserPrivilegeEscalationTest` | deep |
| Role | `Pengaturan/RoleCrudTest` | deep |
| Import master (non-produk) | `Import/ImportMasterEntitiesTest` | deep |
| Stok / kartu / HPP movement | `Inventory/InventoryAccessCoverageTest`, `Api/StockCardApiHppResetTest` | access + money |
| Serial intake / units / HPP | `Serial/SerialAccessCoverageTest`, `SerialIntake/*`, `Serial/SerialHppCorrectionTest` | deep |
| Repack × serial | `Repack/RepackSerialBlockTest` | deep |
| PO / retur beli / hutang / pembayaran / deposit supplier | `Pembelian/*Access*`, `SupplierDepositCrudTest`, `PurchaseMoneyStripTest`, `PembayaranHutang/*` | deep + money |
| Sales / retur / piutang / pembayaran / deposit customer | `Sales/PenjualanAccessCoverageTest`, `Sales/CustomerArBackendTest`, `Sales/ManualSalesBackendTest` | access + deep |
| Produk retail | `Produk/ProdukRetailCrudTest` | deep |
| Reset DB targets | `Reset/ResetTargetMatrixTest` | deep |
| Laporan | `Reports/LaporanAnalitikAccessCoverageTest` | access |
| Business (settings/import/reset meta) | `Business/BusinessModuleAccessCoverageTest` | access |
| Menu ↔ permission FE | `Frontend/MenuRoutePermissionTest` (≥52 perm unik) | meta |
| Print barcode | FE e2e shell (`menu-shell` + assert cetak) | smoke |

---

## Playwright gate (Fase 4–5)

| Spec | Peran |
|------|--------|
| `e2e/menu-shell.spec.js` | Smoke semua `ALL_MENU_ROUTES` + print-barcode |
| `e2e/po-approve.spec.js` | PO draft → approve → list |
| `e2e/penjualan-backoffice.spec.js` | List shell + draft → approve → list |
| `e2e/serial-intake-pos.spec.js` | Intake approve → scan POS → checkout |
| `e2e/pos-checkout.spec.js` | POS checkout (existing) |
| `e2e/auth.spec.js` | Auth (existing) |

**Bukan gate (ada di repo, bukan syarat CI gate):** `docs-screenshots.spec.js`, `docs-crud-screenshots.spec.js`, `reports.spec.js`, `install-wizard.spec.js`, `serial-picker-loop-proof.spec.js`.

Perintah:

```bash
cd syilex && php artisan test
cd syilex-frontend
npx playwright test menu-shell.spec.js po-approve.spec.js penjualan-backoffice.spec.js serial-intake-pos.spec.js pos-checkout.spec.js auth.spec.js
```

CI: belum ada workflow di repo; pola job ada di `syilex-frontend/e2e/README.md` (Gate CI).

---

## Verifikasi checkpoint

- Fase 1–3 filter suites: hijau (sesi sebelumnya).
- Fase 4 Playwright gate: **84 passed** (`menu-shell`+journeys+`auth` 76 + `pos-checkout` 8).
- Fase 5 PHPUnit: **1681 passed** (5707 assertions) setelah fix `SalesReportCoverageTest` (`pos.discount` + promo manual).
- CI: belum ada workflow di repo; perintah gate + contoh YAML di `syilex-frontend/e2e/README.md`.
