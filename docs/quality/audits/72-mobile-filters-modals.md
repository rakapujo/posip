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
| `DetailDialog` / `ListFiltersSheet` / shared print/shift | Sudah `min(..., 95vw)` + breakpoints |
| Isi `grid-cols-2/3` keras | Perbaiki ke `grid-cols-1 md:` / `sm:` |
| Role permission matrix | `maxHeight: 90vh` + `contentStyle` overflow |
| Mass `:breakpoints` pada Dialog 350–500px | **YAGNI** (CSS global cukup) |
| POS Kasir Dialog | **Jangan sentuh** kecuali bug eksplisit |

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
