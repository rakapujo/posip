# Audit menu — 37 Penjualan → Deposit Customer

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `CustomerDepositController` · `CustomerDepositPage` · twin Supplier patched  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| DC-S1 | Strip money tanpa `piutang.view_nominal` |
| DC-U1/U2 | FE detail/usage/PDF gate `canViewNominal` |
| WI-1 | Walk-in diblok BE + FE `jenis=spesifik` |

## Sisa P1 / polish

Wave C: Tabs+Excel+deep-link sudah di-patch (lihat `00-penjualan-plan-review.md`).
