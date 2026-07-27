# Audit menu — 36 Penjualan → Pembayaran Piutang

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `PembayaranPiutangController` · Complete action · Form/Page  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| PP-B1 | Destroy TX + lockForUpdate + re-assert draft |
| PP-X1 | Unique `(pembayaran_id, piutang_id, sumber)` — cash+deposit OK |
| WI-1 | Walk-in diblok BE `assertActiveBackofficeCustomer` + FE `jenis=spesifik` |

## Sisa P1

Q5 draft over-sisa; view_nominal strip; helpers create\|update; deep-link; confirmComplete copy; deposit pool clamp; hard-fail CustomerDeposit::use.
