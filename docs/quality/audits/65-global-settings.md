# Audit menu — 65 Pengaturan → Global Settings

> **Status:** patched (scope P0+P1)  
> **SSoT kode:** `SettingController` + `SettingService` + `SettingsPage.vue` + `ResetDatabasePage.vue` (store refresh)  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Pengaturan → Global Settings (`/app/pengaturan/settings`).  
> **Plan:** `audit_global_settings_0c3a1d79.plan.md`

## Inventory

| Area | Path |
|------|------|
| BE | `syilex/app/Http/Controllers/Api/V1/SettingController.php` |
| Service | `syilex/app/Services/SettingService.php` |
| Seeder | `syilex/database/seeders/SettingSeeder.php` |
| FE | `syilex-frontend/src/views/pengaturan/SettingsPage.vue` |
| Store | `syilex-frontend/src/stores/settings.js` |
| Cron | `ApplyScheduledPriceChangesCommand` + `routes/console.php` |
| Cross | `ResetDatabasePage.vue` (refresh setelah reset settings/all) |
| Perms | `settings.view\|update\|reset` |
| Tests | `tests/Feature/Settings/*`, command price-change |

## Gap P0 + P1 (IN_SCOPE)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| GS-BE-1 | P0 | price/stock locks on update + bulk + updateGroup | FIXED |
| GS-BE-2 | P0 | allowlist enums/ranges/TZ + separator collide | FIXED |
| GS-BE-3 | P0 | ignore client `type` (boolean cast via filter_var) | FIXED |
| GS-FE-1 | P0 | prefix exactly 3 chars FE+BE | FIXED |
| GS-SCH-1 | P0 | wire max_batch via nullable `--limit`; drop dead price_change_cooldown | FIXED (cooldown dihapus seed+FE; lihat audit 68) |
| GS-THERMAL | P1 | Hapus card Cetak Thermal legacy + download installer | FIXED (audit 68) |
| GS-SCH-2 | P0 | activity_log label = cooldown; retensi editable (P2) | FIXED (+ retention days setting) |
| GS-BE-4 | P1 | updateGroup existing keys only | FIXED |
| GS-BE-5 | P1 | txn + activity old→new + prefix activity | FIXED |
| GS-BE-6 | P1 | seed serial prefixes PBS/PDS/HPS | FIXED |
| GS-BE-7 | P1 | relabel `code_only` | FIXED |
| GS-FE-2 | P1 | fieldset `!canUpdate` + ImageUpload/prefix | FIXED |
| GS-FE-3 | P1 | confirm elektronik/promo/returns OFF on save | FIXED |
| GS-FE-4 | P1 | sequential saveTab | FIXED |
| GS-FE-5 | P1 | settingsStore.refresh after reset settings/all | FIXED |
| GS-FE-6 | P1 | drop dead tabGroups[7] | FIXED |
| GS-TEST | P1 | feature coverage | FIXED |

## OUT / P2 follow-ups

| Item | Keputusan / status |
|------|---------------------|
| Full Reset #66 | → `66-reset-database.md` (patched P0+P1) |
| Gate runtime `pos.access` | **DITOLAK** — runtime sengaja auth-only (hydrate `auth.js` untuk semua role). Gate `pos.access` mematahkan BO Sales/Retur/Promo. Tetap 200 untuk user tanpa `pos.access`. |
| Throttle `/settings/public` | PATCHED (`throttle:60,1`) |
| Strip NPWP di `publicSettings` only | PATCHED (receipt/`getStoreInfo` tetap boleh NPWP) |
| Batch `getPrefixesWithInfo` | PATCHED |
| `cost_allocation_mode` installer | PATCHED (dihapus; calc tetap by_value) |
| FormRequest extract | SKIP (YAGNI; guards di controller) |
| Dirty leave | PATCHED (`onBeforeRouteLeave` + `useConfirm`) |
| Soft confirm fiscal save + shift aktif | PATCHED (FE only; bukan BE 422) |
| Editable `activity_log_retention_days` | PATCHED (seed + middleware config override + FE InputNumber) |
