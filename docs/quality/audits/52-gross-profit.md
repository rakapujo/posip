# 52 — Gross Profit

> **Status:** Wave A + Wave B + PerBarang↔GP awam (2026-07-21)
> **Perm:** `laporan.keuangan+stok.view_hpp`
> **BE:** `GrossProfitReportController` / `GrossProfitReportResolver`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G · `perbarang_vs_gp_awam_02fa7bf0`

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

## Wave A applied

S1 NETT_EXPR+qty_base; topProducts net retur; MoneySummary hints

## Wave B (patched 2026-07-26)

See [00-laporan-plan-review.md](00-laporan-plan-review.md). Leaf residuals closed; skip/out: ACC-4 proporsi accuracy (B1.4), Aging AR/AP (B3.7).

## UI awam (card)

| Label UI | Field API |
|----------|-----------|
| Setelah dikurangi retur | `revenue_net` |
| Modal barang | `hpp_net` |
| Perkiraan untung | `gross_profit` |
| Margin % | `margin_percent` |

Hint FE: angka “Setelah dikurangi retur” = card sama di Penjualan → Per Barang (filter tanggal/terminal/gudang bersama).

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR
- Setelah dikurangi retur / Revenue GP = NETT − retur (S1)
- Kas fisik = tunai net ± laci
