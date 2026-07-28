# Review — Laporan Wave A + Wave B (41–62)

> **Status:** Wave A executed 2026-07-26 · **Wave B executed 2026-07-26**  
> **Plan SSoT:** `wave_b_laporan_c92a4425.plan.md` · umbrella `audit_laporan_penjualan_8415b523.plan.md`  
> **Reverse-audit:** §G — 22/22 AppMenu leaves covered

## Glossary (4 keluarga angka)

| Nama | Rumus | Dipakai |
|------|--------|---------|
| Omzet nota | `Σ grand_total` | Dashboard Omzet, Kasir/Top (mode bruto) |
| Nilai penjualan / Pendapatan line | `NETT_EXPR` | Per Barang jual (card); Dashboard dual card |
| Setelah dikurangi retur / Revenue GP | `NETT_EXPR − retur` + HPP `qty_base` | Gross Profit (S1); Per Barang card net |
| Kas / tender | payment ± kembalian | Arus Kas, Metode (ACC-2), Dashboard chart Tunai net |

**Per Barang dual (awam):** Nilai penjualan (pre-retur) + Setelah dikurangi retur (= GP `revenue_net`; tanggal retur = dokumen retur). List/export baris tetap pre-retur per SKU.

## ACC Wave A (locked) + Wave B deltas

| ID | Wave A | Wave B |
|----|--------|--------|
| S1 | GP NETT/`qty_base` | — |
| Opsi A | Dual panel Non-Tunai | + kolom harian `penjualan_non_tunai` (info) |
| ACC-1 | Hint Omzet | Dual card Omzet + Pendapatan line |
| ACC-2 | Metode Tunai net | Dashboard chart Tunai net |
| ACC-3 | Toggle summary-only | **List + export** net (LEFT JOIN retur) |
| ACC-4 | Toggle summary (proporsi) | List + export net; proporsi line **tetap** (B1.4 skip) |
| ACC-5 | Free retur pembulatan | — |
| ACC-6 | Docs + kode | Docs Wave B + graphify |

## Wave B delivered (B0–B3)

### B0 Residual
- ACC-3/4 list+export net; FE Message updated  
- FE filter parity (GP/Arus/Margin/Kasir/Metode/Top/Dead/Retur/Promo) + Per Nota `source`  
- Promo `only_terjaring` / `promo_count` pre-paginate; byKategori = resolver  
- Promo Usage net retur (S1)  
- `warehouse_id` Sales Per Barang / Per Nota  
- Kasir Diskon stacked hint  

### B1 Angka
- Dashboard `pendapatan_line` + KPI dual  
- Chart payment Tunai −kembalian  
- Kasir/Top `mode=bruto|net` + export  

### B2 Scale
- Dead Stock SQL-native + limit  
- GP Controller → Resolver DRY  
- `warehouse_id` GP + Arus Kas  
- Export throttle sales report routes  
- `pos_cash_transactions.tipe=refund_retur` (+ backfill)  
- Anti-N+1 ProductPromo + Kasir names  

### B3 Fitur
- PDF client-side GP + Arus Kas  
- Arus Kas daily non-tunai column  
- Promo ROI = diskon/revenue_net  
- byPromo products cap (50)  
- Performa source filter + drill-down nota  
- Retur avg days; Dead Stock `is_serial` + deep-link Kartu Stok  

### Out / skip
- B1.4 ACC-4 akurat (proporsi ceiling)  
- B3.7 Aging AR/AP  
- Omzet Dashboard = NETT saja; soft-HPP strip GP  

## Migration Wave B

`2026_07_26_220000_add_refund_retur_tipe_to_pos_cash_transactions`

## Per-menu files

`41-*.md` … `62-*.md` — residual Wave B marked patched below / in each file.

## UI note — summary cards (2026-07-27)

Summary KPI cards must fit full currency up to ~**Rp 10.000.000.000.000** without horizontal overflow.

- CSS: `.summary-stat-card` + `.summary-money-value` in `syilex-frontend/src/assets/layout/_utils.scss`
- Shared panel: `MoneySummaryPanel.vue`
- Ad-hoc laporan cards + Dashboard KPI use the same classes (full `formatCurrency`, no T/M abbreviate)

## Bugfix — Inventory Stok summary Total Nilai (2026-07-27)

`InventoryStockController::summary` hydrated Eloquent model → accessor `total_value` (`qty * avg_cost` per row) **overrode** SQL `SUM(qty * avg_cost)`. Card “Total Nilai” showed `Rp 0`. Fixed with `toBase()` before aggregate `first()`.

## Mobile filter sheet + modal inner (2026-07-28)

**Root cause filter melebar:** `_responsive.scss` toolbar `nowrap` + `#end { flex: 0 0 auto }` + inline `w-40` tanpa `ListFiltersSheet`.

**Fix:** migrasi laporan ? `ListFiltersSheet` + `list-filter-control` + `fluid` (pola ArusKas/Kasir). SelectButton mode di luar sheet.

| Grup | File |
|------|------|
| Penjualan | PerBarang, Pembulatan, DiscLine, DiscNota, Biaya |
| Pembelian | PerBarang, PerSupplier, Diskon, HargaTerakhir (+ polish PerDokumen) |
| Promo | PromoUsage, CustomerPromo, ProductPromo |

**P0 CustomerPromo:** import `tipeCustomersApi` / `kategoriCustomersApi` + declare refs.

**Modal:** shell OK via `.p-dialog { max-width: 95vw }`. Inner grids `md:` / DiscLine `sm:`; RolePage `maxHeight: 90vh`. Detail: [`72-mobile-filters-modals.md`](72-mobile-filters-modals.md).
