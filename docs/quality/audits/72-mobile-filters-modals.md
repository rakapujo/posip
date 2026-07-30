> **Status:** canonical  
> **SSoT kode:** `syilex-frontend/src/components/common/ListFiltersSheet.vue` · `syilex-frontend/src/assets/layout/_responsive.scss` · `syilex-frontend/src/components/common/DetailDialog.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.

# Audit — Mobile filter sheet + Dialog responsif (2026-07-28)

## Filter toolbar (laporan)

| Masalah | Sebab | Perbaikan |
|---------|-------|-----------|
| Filter melebar + sidebar toggle “mati” | Mobile toolbar `flex-wrap: nowrap` + `#end` tidak shrink; banyak `w-40` di `#end` | `ListFiltersSheet` + `.list-filter-control` + `fluid` |
| SelectButton Gross/Net | Harus tetap terlihat | **Di luar** sheet |

Jangan ubah aturan nowrap global — itu benar untuk `Tambah | Filter (n)`.

## Dialog / modal

| Lapisan | Status |
|---------|--------|
| Shell lebar | OK — `@media (max-width: 991px) .p-dialog { max-width: 95vw }` |
| Shell tinggi (2026-07-30) | OK — global `.p-dialog { max-height: 90vh; flex column }` + `.p-dialog-content { overflow-y: auto }` di `_responsive.scss` |
| `DetailDialog` | `min(..., 95vw)` + breakpoints + **maxHeight 90vh** + contentStyle overflow |
| `ListFiltersSheet` / shared print/shift | Sudah `min(..., 95vw)` + breakpoints |
| Isi `grid-cols-2/3` keras / `md:grid-cols-2` di Dialog | **(2026-07-30)** CSS ≤991px: force 1-col + **`grid-column: auto`** (reset `col-span-*` yang bikin implicit track/crush lagi) + contain Fieldset/table overflow-x. Cover Terminal/Produk/Customer/… Prefer form `lg:` + `col-span-full`. |
| Role permission matrix | Juga `maxHeight: 90vh` + `contentStyle` (redundan tapi OK) |
| Mass `:breakpoints` pada Dialog 350–500px | **YAGNI** (CSS global cukup) |
| POS Kasir Dialog / `.pos-payment-dialog` | **Exclude** dari force 1-col (chip `grid-cols-4`); nested scroll `overflow: hidden` |

### Peer ter-cover force 1-col (ringkas)

| Risk | Contoh |
|------|--------|
| OK (CSS) | PrintBarcode settings (hard `grid-cols-2`), SerialLabelPrint nested 2/3 — force 1-col ≤991 |
| MED | PosTerminal, Produk, Supplier, MetodePembayaran, CustomerFormDialog (`md:`/`lg:` form) |
| LOW | Banyak DetailDialog hard `grid-cols-2` |
| Exclude | `.pos-payment-dialog` |

### Dialog konten lebar / preview (2026-07-30)

| Area | Masalah | Perbaikan |
|------|---------|-----------|
| Produk form Satuan & Harga | `table min-width: 36rem` → scroll horizontal HP | `lg+` tabel; `<lg` kartu per unit |
| SerialLabelPrintDialog | Preview `jsPDF` blob → `<iframe>` blank di banyak mobile | Preview HTML + `generateBarcodeDataURL` (mirror PrintBarcode); Print/Download tetap PDF |
| Price Change / Promo DetailDialog | Tabel HTML Unit/Diskon 1–4 lebar | `lg+` tabel; `<lg` kartu stack |

**Guard test FE:** `syilex-frontend/tests/unit/mobileDialogGuard.test.mjs` (+ assertion di `printIsolation.test.mjs`).

## Tombol / footer (2026-07-28)

| Lapisan | Perbaikan |
|---------|-----------|
| `.p-dialog-footer` + child `.flex` | Wrap + gap di ≤991px (`_responsive.scss`) |
| `DetailDialog` footer | `flex-col-reverse` HP → `sm:flex-row`; `footer-extra` `flex-wrap` |
| Form `*FormPage` aksi bawah | `flex flex-wrap justify-end gap-2` |
| `DataTableHeader` `#extra` | `flex-wrap` |

**Jangan** full-bleed tombol footer; **jangan** ubah POS Kasir.

## Checklist halaman laporan (AppMenu)

Sudah sheet / OK: Per Nota, Gross Profit, Margin, Arus Kas, Kasir, Metode, Top Customer, Dead Stock, Retur Pattern.  
Migrated 2026-07-28: lihat [`00-laporan-plan-review.md`](00-laporan-plan-review.md) § Mobile filter sheet.
