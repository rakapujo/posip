# 42 — Per Barang (Penjualan)

> **Status:** Wave A + Wave B + PerBarang↔GP awam (2026-07-21)
> **Perm:** `laporan.penjualan`
> **BE:** `SalesProductReportController`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G · `perbarang_vs_gp_awam_02fa7bf0`

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

## Wave A applied

Terminal×retur free fix; label bruto jual; NETT shared

## Wave B (patched 2026-07-26)

See [00-laporan-plan-review.md](00-laporan-plan-review.md). Leaf residuals closed; skip/out: ACC-4 proporsi accuracy (B1.4), Aging AR/AP (B3.7).

## Per Barang ↔ GP (awam labels)

- **Nilai penjualan** = `total_pendapatan` (NETT setelah disc nota, **belum** − retur uang)
- **Setelah dikurangi retur** = `total_pendapatan_net` = NETT − retur (tanggal = `doc_sales_returns.tanggal`, terminal/WH on return) — **selaras GP** `revenue_net`
- Card **Modal barang / Perkiraan untung / Margin %** = net (setelah retur)
- Baris list/export tetap nilai jual per SKU (belum net per-SKU)

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR (= Nilai penjualan)
- Setelah dikurangi retur = NETT − retur (S1; sama GP)
- Kas fisik = tunai net ± laci
