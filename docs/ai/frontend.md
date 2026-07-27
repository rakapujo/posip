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

- Di dalam DetailDialog: pakai `DetailTable` (bukan DataTable PrimeVue)

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
