# Audit menu — 35 Penjualan → Piutang Customer

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `CustomerPiutangController` · `CustomerPiutangPage` · twin Hutang patched  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| PC-S1 | Strip `nominal_retur` tanpa `view_nominal` |
| PC-S2 | Strip `paymentDetails.nominal_dibayar` |
| WI-1 | Picker customer `jenis=spesifik` (walk-in POS-only) |

## Sisa P1

aging UI polish; Bayar CTA deep-link residual.

## Patched — gap close (2026-07-29)

| Item | Fix |
|------|-----|
| Summary/aging filters | reuse `applyFilters` (aging tanpa `aging_bucket`) |
| Overdue UI | date-only vs `todayString` |
| Detail | tampilkan `nominal_retur` |

**Export:** Excel + PDF list ada (`laporan.export` ∧ `view_nominal`).
