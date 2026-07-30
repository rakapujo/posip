> **Status:** canonical
> **SSoT kode:** `syilex/app/Http/Controllers/InstallerController.php` · `syilex/resources/views/installer/`
> **Jika konflik:** ikuti kode, lalu update dokumen ini.

# Audit: Wizard install — settings hari-1

## Ringkas

Wizard Blade `/install` menulis setting toko/regional/kalkulasi lewat `updateSettings` setelah `SettingSeeder`.

## Field wizard (step 3–5) — 2026-07-29

| Step | Key |
|------|-----|
| 3 | `store.*` name/address/phone/email/npwp + **url** (prefill request host) + **receipt_footer** |
| 4 | regional/currency/number qty + **percent_decimal_places**, **text.uppercase_mode** |
| 5 | tax, **rounding.sales_*** + **rounding.purchase_***, **stock.negative_mode** (`block`/`allow`), discount, elektronik, **returns.*** (4), price_input_mode |

## Bugfix

- `stock.negative_mode=warn` dihapus dari opsi install (nilai tidak dikenali `isNegativeStockAllowed`).

## Di luar wizard

Logo/icon/login_bg, `prefix.*`, `scheduler.*` — hanya Pengaturan setelah install.

## Tes

- `tests/Feature/Installer/InstallerUpdateSettingsTest.php`
- E2E: `syilex-frontend/e2e/install-wizard.spec.js`
