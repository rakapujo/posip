# Audit menu — 39 POS → Terminal

> **Status:** patched Wave A + R + D + B (2026-07-26) + mobile dialog height/grid (2026-07-30)  
> **SSoT:** `PosTerminalPage.vue` · `PosTerminalController.php` · `MasterPosTerminal.php`  
> **Cross:** [38-pos-shift.md](38-pos-shift.md) · [40-pos-kasir.md](40-pos-kasir.md) · [72-mobile-filters-modals.md](72-mobile-filters-modals.md)  
> **Jika konflik:** ikuti kode.

## Ringkas

CRUD/ops Terminal: destroy guard (sales/cash/returns), `isInUse` = active_user **atau** open shift, start/end/list butuh `pos.access` (list: `terminal.view` **atau** `pos.access`), default metode ∈ allow-list aktif, shared-WH compare by `ulid`, form Batal → `hideDialog`, link Riwayat Shift, email struk XOR `none|smtp|resend`.

## Patched

| ID | Fix |
|----|-----|
| TM-U1 | Batal form → `hideDialog` |
| TM-B1 | Destroy 422 jika ada sales/cash/returns |
| TM-B2 | `isInUse()` termasuk open `activeShift` |
| TM-B3 | Shared WH self-exclude by `ulid` |
| TM-B4 | Default metode harus ∈ allow-list (BE+FE) |
| TM-B5 / KS-B09 | startShift cek warehouse saleable |
| TM-S1 | start/end require `pos.access` |
| TM-S2 | list scoped `terminal.view` \| `pos.access` |
| TM-X2 | Link card → `/app/pos/shift` |
| R-TM01 | Dialog breakpoints + form grid (kini `lg:grid-cols-2` + `col-span-full`) |
| Part D | `mail_driver` XOR + secrets encrypted; strip dari list |
| Mobile dialog (2026-07-30) | Height global 90vh; Detail `sm:grid-cols-2`; **crush-grid** via [72](72-mobile-filters-modals.md) force 1-col ≤991 + form `lg:` |

## Sisa (opsional / deferred)

- SerialUnitPicker DRY penuh: UX POS single-add ≠ BO multi-select — skip rewrite; reuse nanti via mode prop jika duplikasi tumbuh
- Pivot reverse indexes (`user_id` alone): YAGNI sampai reverse lookup hot

## Patched Wave B (2026-07-26)

| Item | Fix |
|------|-----|
| Indexes | `master_pos_terminal.status`; shifts `(terminal_id,ended_at)`, `(user_id,ended_at)` |
| store/update TX | `DB::transaction` create/update + pivot |
| CollapsibleSection | Pengaturan Lanjutan + Email Struk |
| mail-test | `POST /pos-terminals/{ulid}/mail-test` + UI uji kirim |
| TerminalMailer | DRY shared SMTP\|Resend sender |

## Branding outlet (2026-07-28)

Override nullable `store_*` + `receipt_footer` — lihat [71-terminal-store-branding.md](71-terminal-store-branding.md). UI: CollapsibleSection «Identitas Toko (Override)».
