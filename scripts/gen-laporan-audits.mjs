import fs from 'fs';

const dir = 'docs/quality/audits';
const menus = [
  [41, 'per-nota', 'Per Nota', 'SalesReportController', 'laporan.penjualan'],
  [42, 'per-barang-jual', 'Per Barang (Penjualan)', 'SalesProductReportController', 'laporan.penjualan'],
  [43, 'pembulatan', 'Pembulatan', 'SalesFinancialReportController', 'laporan.penjualan'],
  [44, 'disc-line', 'Disc Line', 'SalesFinancialReportController', 'laporan.penjualan'],
  [45, 'disc-nota', 'Disc Nota', 'SalesFinancialReportController', 'laporan.penjualan'],
  [46, 'biaya', 'Biaya', 'SalesFinancialReportController', 'laporan.penjualan'],
  [47, 'per-dokumen', 'Per Dokumen', 'PerDokumenReportController', 'laporan.pembelian'],
  [48, 'per-barang-beli', 'Per Barang (Pembelian)', 'PerBarangReportController', 'laporan.pembelian'],
  [49, 'per-supplier', 'Per Supplier', 'PerSupplierReportController', 'laporan.pembelian'],
  [50, 'diskon-beli', 'Diskon Pembelian', 'DiskonReportController', 'laporan.pembelian+po.view_harga'],
  [51, 'harga-terakhir', 'Harga Terakhir', 'HargaTerakhirReportController', 'laporan.pembelian'],
  [52, 'gross-profit', 'Gross Profit', 'GrossProfitReportController', 'laporan.keuangan+stok.view_hpp'],
  [53, 'margin-per-barang', 'Margin per Barang', 'MarginPerBarangReportController', 'laporan.keuangan+stok.view_hpp'],
  [54, 'arus-kas', 'Arus Kas Harian', 'CashFlowReportController', 'laporan.keuangan'],
  [55, 'promo-usage', 'Promo Usage', 'PromoUsageReportController', 'laporan.promo'],
  [56, 'produk-dapat-promo', 'Produk Dapat Promo', 'ProductPromoReportController', 'laporan.promo'],
  [57, 'customer-dapat-promo', 'Customer Dapat Promo', 'CustomerPromoReportController', 'laporan.promo'],
  [58, 'performa-kasir', 'Performance Kasir', 'KasirPerformanceReportController', 'laporan.performa'],
  [59, 'metode-pembayaran-lap', 'Metode Pembayaran', 'PaymentMethodReportController', 'laporan.performa'],
  [60, 'top-customer', 'Top Customer', 'TopCustomerReportController', 'laporan.performa'],
  [61, 'retur-pattern', 'Retur Pattern', 'ReturPatternReportController', 'laporan.inventory'],
  [62, 'dead-stock', 'Dead Stock', 'DeadStockReportController', 'laporan.inventory']
];

const waveA = {
  41: 'Date/export span; FE filters DRY',
  42: 'Terminal×retur free fix; label bruto jual; NETT shared',
  43: 'ACC-5 free retur ikut net pembulatan',
  44: 'ACC-4 Toggle Bruto|Net (summary)',
  45: 'ACC-4 Toggle Bruto|Net (summary)',
  46: 'ACC-4 Toggle Bruto|Net (summary)',
  47: 'ACC-3 Toggle Bruto|Net (summary)',
  48: 'ACC-3 Toggle Bruto|Net (summary)',
  49: 'ACC-3 Toggle Bruto|Net (summary)',
  50: 'Router+FE po.view_harga; ACC-3 toggle',
  51: 'Count-only; no money toggle',
  52: 'S1 NETT_EXPR+qty_base; topProducts net retur; MoneySummary hints',
  53: 'MoneySummary hints; export filter parity',
  54: 'Opsi A Non-Tunai panel; hints Kas Fisik; created_at sargable+index',
  55: 'Revenue NETT_EXPR',
  56: 'Setup report (eligibility via shared helpers where applied)',
  57: 'eligiblePromosFor AND = checkout',
  58: 'Export column parity; Omzet bruto hint',
  59: 'ACC-2 Tunai diterima/Kembalian/Tunai net',
  60: 'Omzet bruto hint',
  61: 'Soft-delete filter',
  62: 'Totals before limit; block value_desc tanpa HPP'
};

for (const [n, slug, title, ctrl, perm] of menus) {
  const nn = String(n).padStart(2, '0');
  const body = `# ${nn} — ${title}

> **Status:** Wave A patched (2026-07-26)
> **Perm:** \`${perm}\`
> **BE:** \`${ctrl}\`
> **SSoT plan:** audit_laporan_penjualan_8415b523.plan.md §A–F + §G

## Coverage

Menu AppMenu ↔ FE ↔ API mapped. See plan reverse-audit §G (22/22).

## Wave A applied

${waveA[n] || 'See plan Wave A list'}

## Residual / Wave B

See plan Wave B backlog (B1–B3). ACC-3/4 list-row full netting still Wave B if needed.

## Formula family (glossary)

- Omzet nota = grand_total
- Pendapatan line = NETT_EXPR
- Revenue GP = NETT − retur (S1)
- Kas fisik = tunai net ± laci
`;
  fs.writeFileSync(`${dir}/${nn}-${slug}.md`, body);
}

console.log('wrote', menus.length);
