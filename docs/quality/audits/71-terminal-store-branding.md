# Audit — 71 Terminal store branding (POS docs)

> **Status:** patched (2026-07-28)  
> **SSoT kode:** `SettingService::getStoreInfoForTerminal` · `MasterPosTerminal` · `PosTerminalPage` · `PosKasirPage` · `resolveStoreBranding.js` · `useReceiptEscPos` / `useReceiptPdf` / `useShiftReport`  
> **Cross:** [39-pos-terminal.md](39-pos-terminal.md) · [40-pos-kasir.md](40-pos-kasir.md) · [41-per-nota.md](41-per-nota.md) · [65-global-settings.md](65-global-settings.md) · [66-reset-database.md](66-reset-database.md)  
> **Jika konflik:** ikuti kode.

## Desain

Kolom nullable di `master_pos_terminal`: `store_name`, `store_address`, `store_phone`, `store_email`, `store_npwp`, `receipt_footer`.  
Resolve: non-empty terminal override, else global `settings.store.*` via `getStoreInfoForTerminal`.

**POS-facing saja:** thermal, preview struk, PDF struk, email struk, struk-online, laporan shift, reprint Per Nota (sales dengan `terminal_id`).  
**Tetap global:** Settings Toko, Login/Topbar, `useExportPdf`, faktur Sales BO, Excel.

FE kasir membangun `storeForPrint` dari payload `store` active-terminal — **jangan** mutasi Pinia `settings.store`.

## Patched

| Area | Fix |
|------|-----|
| Migration + model + CRUD validate | Kolom + fillable + store/update |
| BE resolve | `getStoreInfoForTerminal`; email/public receipt; active-terminal + shiftReport + SalesReport `show` kirim `store` |
| FE master | CollapsibleSection «Identitas Toko (Override)» |
| FE kasir | `storeForPrint` → EscPos / PDF / preview / WA / footer / shift dialog |
| FE shift / Per Nota | Coalesce dari `store` API atau `sales.terminal` |
| Test | `StoreInfoForTerminalTest` |

## Ops / reset

- Wipe `settings` **tidak** menghapus override terminal.
- Hapus / reset baris `master_pos_terminal` menghilangkan override outlet.
- Deploy: `php artisan migrate` + (opsional) isi branding di Master Terminal.

## Out of scope

Logo per terminal; domain struk-online per outlet; header PDF laporan BO.
