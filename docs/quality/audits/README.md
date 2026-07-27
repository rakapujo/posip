# Audits per menu

> **Status:** draft (living index)  
> **SSoT:** file `NN-*.md` di folder ini + kode terkait  
> **Jika konflik:** ikuti kode.

Deep audit berurutan mengikuti `AppMenu.vue` (Home → Master Data).  
**Scope A** (P0 + P1 default) untuk menu 01–16 **sudah di-patch di kode** — lihat status di tabel.

| # | Menu | File | Status |
|---|------|------|--------|
| 01 | Dashboard (Home) | [01-dashboard.md](01-dashboard.md) | patched (scope A) |
| 02 | Master → Produk | [02-produk.md](02-produk.md) | patched (scope A) |
| 03 | Master → Perubahan Harga | [03-price-change.md](03-price-change.md) | patched (scope A) |
| 04 | Master → Perubahan Data Serial | [04-serial-change.md](04-serial-change.md) | patched (scope A) |
| 05 | Master → Brand | [05-brand.md](05-brand.md) | patched (scope A) |
| 06 | Klasifikasi → Tipe Produk | [06-tipe-produk.md](06-tipe-produk.md) | patched (scope A) |
| 07 | Klasifikasi → Kategori Produk | [07-kategori-produk.md](07-kategori-produk.md) | patched (scope A) |
| 08 | Klasifikasi → Grup Produk | [08-grup-produk.md](08-grup-produk.md) | patched (scope A) |
| 09 | Master → Supplier | [09-supplier.md](09-supplier.md) | patched (scope A) |
| 10 | Master → Customer | [10-customer.md](10-customer.md) | patched (scope A) |
| 11 | Klasifikasi Customer → Tipe Customer | [11-tipe-customer.md](11-tipe-customer.md) | patched (scope A) |
| 12 | Klasifikasi Customer → Kategori Customer | [12-kategori-customer.md](12-kategori-customer.md) | patched (scope A) |
| 13 | Master → Warehouse | [13-warehouse.md](13-warehouse.md) | patched (scope A) |
| 14 | Master → Metode Pembayaran | [14-metode-pembayaran.md](14-metode-pembayaran.md) | patched (scope A) |
| 15 | Master → Promo | [15-promo.md](15-promo.md) | patched (scope A) |
| 16 | Master → Print Barcode | [16-print-barcode.md](16-print-barcode.md) | patched (scope A) |
| — | **Review QA plan #17–#26** | [00-inventory-plan-review.md](00-inventory-plan-review.md) | review complete — executed |
| — | **Review QA plan #27–#32** | [00-pembelian-plan-review.md](00-pembelian-plan-review.md) | review complete — **executed** |
| — | **Review QA plan #33–#38** | [00-penjualan-plan-review.md](00-penjualan-plan-review.md) | Wave A+B+C **executed** |
| 17 | Inventory → Stok | [17-stok.md](17-stok.md) | patched (P0+P1) |
| 18 | Inventory → Kartu Stok | [18-kartu-stok.md](18-kartu-stok.md) | patched (P0+P1) |
| 19 | Inventory → Pergerakan HPP | [19-pergerakan-hpp.md](19-pergerakan-hpp.md) | patched (P0+P1) |
| 20 | Inventory → Register Unit Serial | [20-register-unit-serial.md](20-register-unit-serial.md) | patched (P0+P1) |
| 21 | Inventory → Koreksi HPP Serial | [21-koreksi-hpp-serial.md](21-koreksi-hpp-serial.md) | patched (P0+P1) |
| 22 | Inventory → Stock Opname | [22-stock-opname.md](22-stock-opname.md) | patched (P0+P1) |
| 23 | Inventory → Adjustment | [23-adjustment.md](23-adjustment.md) | patched (P0+P1) |
| 24 | Inventory → Transfer | [24-transfer.md](24-transfer.md) | patched (P0+P1) |
| 25 | Inventory → Repack | [25-repack.md](25-repack.md) | patched (P0+P1) |
| 26 | Inventory → Koreksi HPP (retail) | [26-koreksi-hpp.md](26-koreksi-hpp.md) | patched (P0+P1) |
| 27 | Pembelian → Purchase Order | [27-purchase-order.md](27-purchase-order.md) | patched (P0+P1 core) |
| 28 | Pembelian → Pembelian Serial | [28-pembelian-serial.md](28-pembelian-serial.md) | patched (P0+P1 core) |
| 29 | Pembelian → Hutang Supplier | [29-hutang-supplier.md](29-hutang-supplier.md) | patched (P0+P1 core) |
| 30 | Pembelian → Pembayaran Hutang | [30-pembayaran-hutang.md](30-pembayaran-hutang.md) | patched (P0+P1 core) |
| 31 | Pembelian → Retur Pembelian | [31-retur-pembelian.md](31-retur-pembelian.md) | patched (P0+P1 core) |
| 32 | Pembelian → Deposit Supplier | [32-deposit-supplier.md](32-deposit-supplier.md) | patched (P0+P1 core) |
| 33 | Penjualan → Penjualan (Sales BO) | [33-penjualan-sales.md](33-penjualan-sales.md) | patched Wave A + walk-in BO |
| 34 | Penjualan → Retur Penjualan | [34-retur-penjualan.md](34-retur-penjualan.md) | patched Wave A + walk-in BO |
| 35 | Penjualan → Piutang Customer | [35-piutang-customer.md](35-piutang-customer.md) | patched Wave A + walk-in BO |
| 36 | Penjualan → Pembayaran Piutang | [36-pembayaran-piutang.md](36-pembayaran-piutang.md) | patched Wave A + walk-in BO |
| 37 | Penjualan → Deposit Customer | [37-deposit-customer.md](37-deposit-customer.md) | patched Wave A + walk-in BO |
| 38 | POS → Shift | [38-pos-shift.md](38-pos-shift.md) | patched Wave A P0 |
| 39 | POS → Terminal | [39-pos-terminal.md](39-pos-terminal.md) | patched Wave A + R + D + B |
| 40 | POS → Kasir (Run Terminal) | [40-pos-kasir.md](40-pos-kasir.md) | patched Wave A + R + D + B |
| — | **Review QA plan Laporan #41–#62** | [00-laporan-plan-review.md](00-laporan-plan-review.md) | Wave A + **Wave B executed** |
| 41 | Laporan → Penjualan → Per Nota | [41-per-nota.md](41-per-nota.md) | Wave A+B patched |
| 42 | Laporan → Penjualan → Per Barang | [42-per-barang-jual.md](42-per-barang-jual.md) | Wave A+B patched |
| 43 | Laporan → Penjualan → Pembulatan | [43-pembulatan.md](43-pembulatan.md) | Wave A+B patched |
| 44 | Laporan → Penjualan → Disc Line | [44-disc-line.md](44-disc-line.md) | Wave A+B patched |
| 45 | Laporan → Penjualan → Disc Nota | [45-disc-nota.md](45-disc-nota.md) | Wave A+B patched |
| 46 | Laporan → Penjualan → Biaya | [46-biaya.md](46-biaya.md) | Wave A+B patched |
| 47 | Laporan → Pembelian → Per Dokumen | [47-per-dokumen.md](47-per-dokumen.md) | Wave A+B patched |
| 48 | Laporan → Pembelian → Per Barang | [48-per-barang-beli.md](48-per-barang-beli.md) | Wave A+B patched |
| 49 | Laporan → Pembelian → Per Supplier | [49-per-supplier.md](49-per-supplier.md) | Wave A+B patched |
| 50 | Laporan → Pembelian → Diskon | [50-diskon-beli.md](50-diskon-beli.md) | Wave A+B patched |
| 51 | Laporan → Pembelian → Harga Terakhir | [51-harga-terakhir.md](51-harga-terakhir.md) | Wave A+B patched |
| 52 | Laporan → Keuangan → Gross Profit | [52-gross-profit.md](52-gross-profit.md) | Wave A+B patched |
| 53 | Laporan → Keuangan → Margin per Barang | [53-margin-per-barang.md](53-margin-per-barang.md) | Wave A+B patched |
| 54 | Laporan → Keuangan → Arus Kas | [54-arus-kas.md](54-arus-kas.md) | Wave A+B patched |
| 55 | Laporan → Promo → Usage | [55-promo-usage.md](55-promo-usage.md) | Wave A+B patched |
| 56 | Laporan → Promo → Produk | [56-produk-dapat-promo.md](56-produk-dapat-promo.md) | Wave A+B patched |
| 57 | Laporan → Promo → Customer | [57-customer-dapat-promo.md](57-customer-dapat-promo.md) | Wave A+B patched |
| 58 | Laporan → Performa → Kasir | [58-performa-kasir.md](58-performa-kasir.md) | Wave A+B patched |
| 59 | Laporan → Performa → Metode | [59-metode-pembayaran-lap.md](59-metode-pembayaran-lap.md) | Wave A+B patched |
| 60 | Laporan → Performa → Top Customer | [60-top-customer.md](60-top-customer.md) | Wave A+B patched |
| 61 | Laporan → Inventory → Retur Pattern | [61-retur-pattern.md](61-retur-pattern.md) | Wave A+B patched |
| 62 | Laporan → Inventory → Dead Stock | [62-dead-stock.md](62-dead-stock.md) | Wave A+B patched |
| 63 | Pengaturan → User | [63-user.md](63-user.md) | patched (P0+P1) |
| 64 | Pengaturan → Role & Permission | [64-role.md](64-role.md) | patched (P0+P1) |
| 65 | Pengaturan → Global Settings | [65-global-settings.md](65-global-settings.md) | patched (P0+P1) |
| 66 | Pengaturan → Reset Database | [66-reset-database.md](66-reset-database.md) | patched (P0+P1) |
| 67 | Pengaturan → Import Master | [67-import-master.md](67-import-master.md) | patched (P0+P1) |
| 68 | Penjadwalan / TZ / Cetak Thermal | [68-penjadwalan-timezone-thermal.md](68-penjadwalan-timezone-thermal.md) | patched |

Rule Cursor: `.cursor/rules/posip-menu-audit.mdc`
