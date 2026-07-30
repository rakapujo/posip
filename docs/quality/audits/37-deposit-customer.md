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

## Sisa residual

Wave C Tabs+Excel sudah ada; polish deep-link minor.

## Patched — gap close (2026-07-29)

| Item | Fix |
|------|-----|
| Edit customer_id | show `makeVisible` customer.id + customer_id |
| Usage | hanya pembayaran `completed`; link edit hanya draft |
| Index N+1 | `withExists('pembayaranUsages')` + `canBeEdited` baca flag |

**Export:** Excel + PDF list ada (`laporan.export` ∧ `view_nominal`).
