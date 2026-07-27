# Audit menu — 38 POS → Shift

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `PosTerminalController` end/force · `ShiftController` · `CashTransactionController` · `PosKasirPage` · `ShiftPage`  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| SHF-C01 | end/force TX + lockForUpdate |
| SHF-B01 Q3=A | Void ditolak jika shift sudah tutup |
| SHF-S01 | Cash index/summary/store ownership |
| SHF-U01 | Session guard → `openEndShift()` |
| SHF-X01 Q4=A | Report closed: `terminal.view`; open: owner/force-release |

## Sisa P1

Double-open lock; list/dailySummary scope; is_locked server; setor_awal unique; terminal↔shift bind; POS retur ownership.
