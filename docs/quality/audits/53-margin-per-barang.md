# 53 — Margin per Barang

> **Status:** patched 2026-07-29 — serial expand + Excel/PDF parent-child flat + shared PDF width guard
> **Perm:** `laporan.keuangan` + `stok.view_hpp` (+ `laporan.export` untuk Excel/PDF)
> **BE:** `MarginPerBarangReportController` · `MarginPerBarangReportBuilder` · `MarginPerBarangExport`
> **FE:** `MarginPerBarangPage.vue`

## Semantik

Snapshot **setup harga** (bukan margin nota / Gross Profit):

| Jenis | HPP / Modal | Harga jual | Child |
|-------|-------------|------------|-------|
| Non-serial (RETAIL) | `master_produk.avg_cost` | `harga_N` (default `harga_4`) | — |
| Serial | `AVG(serial_units.cost_per_unit)` status=`tersedia` | `AVG(harga_jual)` unit tersedia | expand unit (kode_internal, SN, margin per unit) |

`tanpa_harga` bila harga efektif ≤ 0 → FE Tag **Tanpa harga** (bukan `0%` danger).

## UI

- DataTable expander ala Stok per Gudang (`StockPage`) — hanya bermakna untuk `is_serial`
- Default sort: `nama_asc`
- Export Excel (BE flat) + PDF (`useExportPdf`, flat client dari list+units, parent SERIAL + child UNIT)
- Field harga filter hanya mempengaruhi RETAIL
- PDF: guard autoscale lebar kolom saat total width > area landscape (anti kepotong)

## Excel flat

Kolom: No, Tipe (RETAIL/SERIAL/UNIT), Kode, Nama, Kategori, Kode Internal, SN, HPP/Modal, Harga, Margin, Margin % (`Tanpa harga` string bila perlu).

Catatan flatten:
- SERIAL tanpa unit tersedia di-skip (tidak lagi keluar baris placeholder `Rp0` tanpa SN/KI).
- SERIAL dengan unit tersedia: 1 baris parent (AVG) + N baris child UNIT.

## Anti N+1

1 query produk (+ join aggregat unit) · 1 query batch `serial_units` untuk ID serial di halaman / export set.

## Coverage sebelumnya

Wave A/B MoneySummary + filter parity — tetap. Temuan “banyak margin 0” pada serial = `harga_4` scaffold 0 (lihat `docs/domain/serial.md` §4.8).
