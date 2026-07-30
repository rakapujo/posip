# Frontend conventions (AI)

> **Status:** canonical  
> **SSoT kode:** `syilex-frontend/src/components/common/`, `composables/`, `views/`, `router/index.js`  
> **Jika konflik:** ikuti kode.

## WAJIB

- Reuse common + composables sebelum bikin baru
- Jangan `toast.add()` — pakai [`useNotification`](../../syilex-frontend/src/composables/useNotification.js)
- Jangan format uang/tanggal manual — pakai [`useFormatters`](../../syilex-frontend/src/composables/useFormatters.js)
- Error API: `notify.apiError(err, fallback)`
- Form: `validate()` lokal + object `errors` (contoh PO/Sales form) — **bukan** vee-validate/zod
- Dokumen: disable tambah baris sampai header wajib (`canAddLines`)
- Route baru: `meta.permission` di [`router/index.js`](../../syilex-frontend/src/router/index.js)
- POS Kasir: jangan diubah kecuali bug eksplisit

## Composables (lihat folder)

[`syilex-frontend/src/composables/`](../../syilex-frontend/src/composables/) + `print/`:

- `useFormatters`, `useNotification`, `useMasterCrud`, `useTransactionList`
- `usePosCart`, `useShiftReport`, `useReceiptPdf`, `useReceiptEscPos`
- `usePrintAdapter`, `usePrintTransport` (di `composables/print/`)
- `useSalesInvoicePdf`, `useSerialLabelPrint`, `useExportPdf`, `useBarcodePrint`
- `useSessionGuard`, report helpers, `useErrorLogger`

## Components common (15 file)

[`syilex-frontend/src/components/common/`](../../syilex-frontend/src/components/common/):

`ImageUpload`, `DetailDialog`, `DetailItem`, `DetailTable`, `DataTableHeader`, `SerialUnitPicker`, `ProductUnitPickerDrawer`, `SerialLabelPrintDialog`, `CustomerFormDialog`, `MoneySummaryPanel`, `AgingBucketPanel`, `ListFiltersSheet`, `CollapsibleSection`, `RowActionButtons`

- `ProductUnitPickerDrawer`: prop `modeHint` (teks mode di atas search); harga serial via `resolveSerialPickerPrice` di `utils/productUnitLineHelpers.js` (bukan `harga_1` saja)
- Di dalam DetailDialog: pakai `DetailTable` (bukan DataTable PrimeVue)
- Filter list/laporan: `ListFiltersSheet` + `.list-filter-control` (bukan strip `w-40` di Toolbar `#end`)
- Dialog isi multi-kolom: `grid-cols-1 md:grid-cols-*`; shell lebar sudah di-cap `95vw` mobile (`_responsive.scss`)
- Footer tombol Dialog/form: wrap (`_responsive.scss` `.p-dialog-footer`, `DetailDialog`, `flex-wrap` di baris aksi form)

## Auth / public pages (2026-07-28)

- Login mobile: **tanpa** strip `.mobile-brand` (logo cukup di form-header)
- `NotFound` / `Access` / `Error`: shell **selalu light** (`bg-surface-100`, tanpa `dark:*`); panel ala Login (`bg-surface-0` + border + `rounded-2xl`); judul/sub pakai `p`/`div` (hindari `h1` + `_typography.scss` `--text-color`); 1 CTA full-width `min-h-11` (`/app` jika auth else `/`); text-link login hanya jika auth; tanpa FloatingConfigurator / Sakai FAQ
- Struk online header: `bg-slate-50` + teks gelap; **nama toko = `p` + `text-slate-800`** (bukan `h1`) agar aman di dark mode

## Menu (AppMenu)

Home · Master · Inventory · Pembelian (termasuk PBS) · Penjualan · POS · Laporan · Pengaturan  
SSoT: [`AppMenu.vue`](../../syilex-frontend/src/layout/AppMenu.vue)

## Form UX

- `InputText`/`Textarea`: `shouldUppercase` (kecuali email/password/pin/status)
- Field `kode_*`: `[A-Z0-9_]+`, max 20, immutable setelah create
- Semua `Select`: prop `filter`
- Shortcut POS: F1 cari, F2 help, F9 hold, F12 bayar

## JANGAN

- Component/composable baru tanpa cek existing
- `toggleDialog` manual bila `useMasterCrud` sudah cukup
- Select tanpa `filter`
- Edit `kode_*` setelah create
