# Audit menu — 03 Perubahan Harga

> **Status:** patched (scope A)  
> **SSoT kode:** `syilex/app/Actions/PriceChange/*` · `syilex/app/Http/Controllers/Api/V1/PriceChangeController.php` · FE `PriceChangePage.vue` / `PriceChangeFormPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Perubahan Harga (`/app/master/price-change`).

## Lifecycle (SSoT kode)

`draft → scheduled → applied`. **Cancel = `scheduled → draft`.** Tidak ada status `cancelled`.

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| PC-R1 | P0 | Race cancel ↔ apply: `lockForUpdate` header doc dalam txn | FIXED |
| PC-R2 | P0 | Create TOCTOU: re-check produk locked dalam txn | FIXED |
| PC-S3 | P1 | Docs/status: cancel → **draft** (bukan `cancelled`) | FIXED (docs) |
| PC-S4 | P0 | Apply header `lockForUpdate` (hindari double apply) | FIXED |
| PC-S1 | P1 | Reject produk `is_serial` di Create/Update (bukan UI-only) | FIXED |
| PC-SCH | P1 | Cron: no `runInBackground`; Auth logout antar-doc; skip invalid created_by; max_batch 1–500 | FIXED (audit 68) |

P2 form/UX (dirty-guard approve, drawer, dll.) di luar Scope A → OPEN kecuali sudah ikut patch.

## Scheduler (ringkas)

- Artisan `price-change:apply` tiap 5 menit (`routes/console.php` + OS `schedule:run`)
- Settings: `scheduler.price_change_enabled`, `scheduler.price_change_max_batch`
- Manual Apply boleh sebelum `tanggal_berlaku`
