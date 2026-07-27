# 61 — Retur Pattern

> **Status:** Wave A + Wave B patched (2026-07-26)
> **Perm:** `laporan.inventory`
> **BE:** `ReturPatternReportController`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

## Wave A applied

Soft-delete filter

## Wave B (patched 2026-07-26)

See [00-laporan-plan-review.md](00-laporan-plan-review.md). Leaf residuals closed; skip/out: ACC-4 proporsi accuracy (B1.4), Aging AR/AP (B3.7).

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR
- Revenue GP = NETT − retur (S1)
- Kas fisik = tunai net ± laci
