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

## Sisa residual

Q5 draft over-sisa soft-reserve (YAGNI deferred); deep-link polish.

## Patched — gap close (2026-07-29)

| Item | Fix |
|------|-----|
| view_nominal FE | kolom Total Bayar + PDF + totals detail di-gate |

**Export:** PDF dokumen ada; Excel list **tidak** (by design, seperti Bayar Hutang).
