# 60 — Top Customer

> **Status:** Wave A + Wave B patched (2026-07-26); tes last_trx_at rentang 30 hari (2026-09-03)
> **Perm:** `laporan.performa`
> **BE:** `TopCustomerReportController`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

Default filter: `date_from` = awal bulan, `date_to` = hari ini. Tes nota `subDays(N)` harus kirim rentang tanggal (bukan default bulan berjalan).

## Wave A applied

Omzet bruto hint

## Wave B (patched 2026-07-26)

See [00-laporan-plan-review.md](00-laporan-plan-review.md). Leaf residuals closed; skip/out: ACC-4 proporsi accuracy (B1.4), Aging AR/AP (B3.7).

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR
- Revenue GP = NETT − retur (S1)
- Kas fisik = tunai net ± laci
