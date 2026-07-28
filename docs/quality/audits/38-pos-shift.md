# Audit menu — 38 POS → Shift

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan; **serial/teks wrap laporan shift (2026-07-28)**  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `PosTerminalController` end/force · `ShiftController` · `CashTransactionController` · `PosKasirPage` · `ShiftPage` · `useReceiptEscPos.buildShiftReport` · `useShiftReport` · `ShiftReportDialog`  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| SHF-C01 | end/force TX + lockForUpdate |
| SHF-B01 Q3=A | Void ditolak jika shift sudah tutup |
| SHF-S01 | Cash index/summary/store ownership |
| SHF-U01 | Session guard → `openEndShift()` |
| SHF-X01 Q4=A | Report closed: `terminal.view`; open: owner/force-release |

## Patched — wrap serial/teks laporan shift (2026-07-28)

| Surface | Fix |
|---------|-----|
| ESC `buildShiftReport` | `_twoColOrWrap` produk+harga; `_wrap` KI/SN/meta + Status; ket kas tidak di-slice `_twoCol` |
| PDF `useShiftReport` | `leftRightOrWrap` / `leftWrap` serial + meta + ket kas |
| `ShiftReportDialog` | `min-w-0 break-words` produk & ket kas; KI·SN·nota `flex-wrap`/`break-all` |
| Saudara | Struk jual ringkas retur (ESC+PDF wrap nama); cash `Ket:` `_wrap` |
| BE `shiftReport` | Payload serial sudah lengkap — tidak diubah |

**Spot-check saudara:** struk jual serial sudah OK; retur standalone tidak cetak SN (YAGNI); cart truncate PosKasir out of scope.

## Sisa P1

Double-open lock; list/dailySummary scope; is_locked server; setor_awal unique; terminal↔shift bind; POS retur ownership.
