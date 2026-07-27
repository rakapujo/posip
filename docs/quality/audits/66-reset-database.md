# Audit menu — 66 Pengaturan → Reset Database

> **Status:** patched (Wave 1 + Wave 2)  
> **SSoT kode:** `ResetController` + `BackupController` + `ResetDatabasePage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Pengaturan → Reset Database (`/app/pengaturan/reset`).  
> **Plan Wave 1:** `reset_66_plus_settings_p2_4bb6d2c0.plan.md`  
> **Plan Wave 2:** `reset_database_wave2_887c2d59.plan.md`

## Inventory

| Area | Path |
|------|------|
| BE reset | `syilex/app/Http/Controllers/Api/V1/ResetController.php` |
| BE backup | `syilex/app/Http/Controllers/Api/V1/BackupController.php` |
| FE | `syilex-frontend/src/views/pengaturan/ResetDatabasePage.vue` |
| Perm | `settings.reset` (super-admin) |
| Tests | `ResetAuditLogTest`, `ResetTargetMatrixTest`, `ResetSerialTest`, `BackupControllerTest` |
| Deprecated | `TruncateAllDataSeeder` — throws; gunakan API reset |

## Gap P0 + P1 Wave 1 (IN_SCOPE)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| R-P0-1..3 | P0 | refuse granular sales/PO/pembayaran bila stok/serial/AR-AP risk | FIXED |
| R-P0-4 | P0 | refuse naked brand/warehouse/… bila dependents | FIXED |
| R-P0-5 | P0 | restore → `SettingService::clearCache` + FE store refresh | FIXED |
| R-P1-1 | P1 | activity/log hanya setelah sukses | FIXED |
| R-P1-2 | P1 | serial_intake cascade `history_harga_beli` | FIXED |
| R-P1-inventory | P1 | refuse inventory bila docs hidup + FE warn | FIXED |
| R-P1-promo | P1 | counts + chip + target `promo` | FIXED |
| R-TEST | P1 | refuse + audit-after-success coverage | FIXED |

## Gap Wave 2 (FIXED)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| R2-P0-serial | P0 | refuse `serial_intake` bila non-draft / stock_card / qty≠0 / unit non-pending | FIXED |
| R2-P0-master | P0 | refuse `supplier`/`customer`/`pos_terminal` bila stock/serial | FIXED |
| R2-P0-ar | P0 | refuse `customer_piutang`/`customer_deposit`/`supplier_deposit` bila docs terkait | FIXED |
| R2-P0-produk-promo | P0 | case `produk` truncate `doc_promo` + `doc_promo_details` | FIXED |
| R2-P0-promo-ref | P0 | refuse standalone `promo` bila `doc_sales_detail.promo_id` IS NOT NULL | FIXED |
| R2-P1-settings | P1 | `resetSettings()` → `SettingService::clearCache()` | FIXED |
| R2-P1-fk | P1 | FK checks di `finally` (bukan hanya catch `\Exception`) | FIXED |
| R2-P1-zip | P1 | ZIP allowlist `database.sql`+`uploads/**`; wipe `storage/app/public` setelah SQL OK | FIXED |
| R2-P1-restore-ack | P1 | restore wajib `backup_acknowledged` | FIXED |
| R2-P1-seeder | P1 | `TruncateAllDataSeeder` deprecated (throw) | FIXED |
| R2-FE | P1 | busy-guard; restore ack; `clearAuth`+login (bukan logout API); inventory soft-disable; chip hints | FIXED |

## Perilaku terkunci (Wave 2)

- Granular unsafe → **422 refuse**, bukan cascade ledger repair. Prefer Reset Transaksi / Master / Semua.
- Post-restore FE: `authStore.clearAuth()` + clear `posip_public_settings` + POS cart/held keys + redirect login — **jangan** panggil `logout()` API (DB sudah diganti).
- Restore `.sql` saja: **tidak** wipe uploads (sengaja DB-only). ZIP: wipe lalu copy uploads dari arsip.
- OUT: backup timestamp token; full FE chips semua target BE; wipe uploads pada SQL-only.

## OUT / P2 residual

- Wipe `settings` **tidak** menghapus override branding di `master_pos_terminal` — lihat [71-terminal-store-branding.md](71-terminal-store-branding.md).

Verify backup timestamp; full FE chips semua target BE; advisory lock reset; counts N+1.
