# 45 — Disc Nota

> **Status:** Wave A + Wave B patched (2026-07-26)
> **Perm:** `laporan.penjualan`
> **BE:** `SalesFinancialReportController`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

## Wave A applied

ACC-4 Toggle Bruto|Net (summary)

## Wave B (patched 2026-07-26)

See [00-laporan-plan-review.md](00-laporan-plan-review.md). Leaf residuals closed; skip/out: ACC-4 proporsi accuracy (B1.4), Aging AR/AP (B3.7).

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR
- Revenue GP = NETT − retur (S1)
- Kas fisik = tunai net ± laci

## Mode Net (ACC-4) — scope jujur (2026-07-30)

- **Di-net:** hanya `total_diskon` (card, kolom list, Excel) via proporsi retur linked (`retur.grand_total × total_diskon/grand_total`).
- **Tetap bruto:** subtotal, Disc 1–3 hasil, `total_setelah_diskon`.
- **≠** Gross Profit `revenue_gross` / Per Barang `total_pendapatan` (beda metrik & populasi `total_diskon > 0`).
- FE banner Mode Net diperjelas (bukan “semua baris & ringkasan”).
