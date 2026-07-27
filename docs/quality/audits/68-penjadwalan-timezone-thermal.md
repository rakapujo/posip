# Audit — 68 Penjadwalan, Timezone, Cetak Thermal

> **Status:** patched  
> **SSoT kode:** `routes/console.php`, `ApplyScheduledPriceChangesCommand`, `DocPromo`, `AppServiceProvider` + `LocalDateTime`, `SettingsPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Plan:** `audit_jadwal_tz_thermal_ee0a05d3.plan.md`

## Inventory

| Area | Path |
|------|------|
| Schedule | `syilex/routes/console.php` |
| Apply cron | `syilex/app/Console/Commands/ApplyScheduledPriceChangesCommand.php` |
| Promo window | `syilex/app/Models/DocPromo.php` (`effective` / `byDisplayStatus`) |
| TZ bootstrap | `config/database.php` (`DB_TIMEZONE`), `AppServiceProvider`, `SettingService::getTimezone*` |
| TZ cast | `syilex/app/Casts/LocalDateTime.php` (wall-clock lokal) |
| Settings FE | `syilex-frontend/src/views/pengaturan/SettingsPage.vue` |
| Thermal live | `usePrintAdapter` / POS Terminal pairing / PosKasir |
| Removed | `syilex/print-service/**`, `public/downloads/*.exe` |

## Temuan → status

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| SCH-1 | P1 | Hapus `runInBackground()` agar `withoutOverlapping` efektif | FIXED |
| SCH-2 | P1 | Cron Auth logout antar-doc; skip `created_by` invalid | FIXED |
| SCH-3 | P1 | Clamp `price_change_max_batch` 1–500 (service + schema) | FIXED |
| SCH-4 | P1 | Drop dead `price_change_cooldown` (seed + FE) | FIXED |
| PROMO-1 | P1 | `byDisplayStatus('upcoming')` include out-of-hour happy hour | FIXED |
| TZ-1 | — | Wall-clock lokal; `DB_TIMEZONE` bootstrap only; **bukan** UTC-in-DB | KEEP (documented) |
| THERMAL-1 | P1 | Hapus card Cetak Thermal + download legacy | FIXED |
| THERMAL-2 | P1 | Hapus print-service + downloads exe dari git | FIXED |
| DOCS-1 | P2 | deploy/promo/install-shared-hosting/frontend-unit drift | FIXED |

## Timezone (KEEP — bukan migrasi UTC)

Model: DB menyimpan **waktu toko (wall-clock)** sama dengan display (`LocalDateTime`).  
`regional.timezone` = SSoT runtime.  
`DB_TIMEZONE` (+07:00) = bootstrap MySQL session sebelum settings sync.  
Migrasi UTC-in-DB = OUT (YAGNI untuk toko ID satu zona).

## Thermal

- **Live:** browser Web Serial/USB/BT via Master → POS Terminal.  
- **Removed:** Settings “Cetak Thermal”, installer `:5123`, rewrite `.htaccess` `/downloads/`.  
- **OUT:** drop kolom `default_printer` / `legacyPrinterId` stub.

## Verifikasi

- `php artisan schedule:list` → `price-change:apply` tanpa background flag  
- Settings tanpa card Cetak Thermal  
- Promo filter upcoming di luar jam masih muncul  
- Unit print policy tetap larang `:5123`
