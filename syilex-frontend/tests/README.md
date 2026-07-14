# Testing — POSIP Frontend

Sumber kebenaran: file test + `package.json` scripts di repo ini. Dokumen ini merangkum **semua jenis test** yang ada.

## Ringkasan cepat

| Jenis | Perintah | Butuh server? | Cakupan |
|-------|----------|:-------------:|---------|
| Unit (Node) | `npm run test:unit` | Tidak | Print thermal, policy, isolation, formatters |
| E2E (Playwright) | `npx playwright test` | Ya (Laravel + DB seed) | Login, checkout POS, laporan smoke |
| Build | `npm run build` | Tidak | Compile Vue/Vite |
| Lint | `npm run lint` | Tidak | ESLint |

Backend PHPUnit ada di `../syilex` — lihat [Backend tests](#backend-phpunit).

---

## Unit tests (`tests/unit/`)

```bash
cd syilex-frontend
npm run test:unit
```

Runner: `tests/unit/run-all.mjs` — menjalankan semua suite berurutan; gagal satu = exit 1.

### Suite print (migrasi thermal browser-native)

| File | Fokus | Edge cases yang diuji |
|------|--------|------------------------|
| `printStorage.test.mjs` | `localStorage` key `posip-thermal-printer` | JSON corrupt, kind invalid, trim ULID/label, terminal scoping |
| `base64Bytes.test.mjs` | Base64 + urutan byte drawer/feed/cut | Feed clamp 0–10, feed negatif, urutan drawer salah, whitespace base64 |
| `printTransport.test.mjs` | Web API transport core | Silent reconnect, port sudah open, API tidak ada, short-circuit active conn |
| `printAdapter.test.mjs` | Facade + legacy fallback | Empty/invalid payload, legacy-only path, write fail → legacy, needPicker |
| `shiftPenjualanEscpos.test.mjs` | Parity shift vs PDF | Diskon L1–L5, nota L1–L3, label wajib, line diskon nol disembunyikan |
| `printIsolation.test.mjs` | Barcode/label/export tidak tersentuh | No import adapter, localStorage keys tetap |
| `printPolicy.test.mjs` | Kebijakan PosKasir (static) | Hanya `auto_print_receipt`, openDrawer di encoder, reconnect dialog, `ENABLE_LEGACY=true` |
| `deadCodeGuard.test.mjs` | Leftover Sakai/unused files stay deleted | BlockViewer, EmptyState, types/index |

### Suite lain

| File | Fokus |
|------|--------|
| `transactionTypeSeverity.test.js` | Severity badge Stock Card / HPP |

### Yang **belum** di-cover unit test

- Pairing UI (`PrinterPickerPanel.vue`) — butuh browser Web API
- `useReceiptEscPos.buildReceipt()` full output — butuh mock Pinia/settings store
- E2E auto-print thermal — butuh printer fisik atau mock hardware

---

## E2E tests (`e2e/`)

```bash
npx playwright install chromium   # sekali
E2E_BASE_URL=http://127.0.0.1:8000 npx playwright test
```

Detail: [`e2e/README.md`](../e2e/README.md).

| File | Tests |
|------|-------|
| `auth.spec.js` | Login, proteksi route |
| `pos-checkout.spec.js` | Kasir, cart, checkout cash |
| `reports.spec.js` | Smoke halaman laporan |

**Catatan:** E2E tidak menguji cetak thermal browser (tidak ada printer di CI). Thermal diverifikasi unit + smoke manual (lihat `docs/print-support-matrix.md`).

---

## Backend PHPUnit

```bash
cd ../syilex
php artisan test
```

- Database test: `posip_db_test` (lihat `syilex/phpunit.xml`)
- **Prasyarat:** MySQL jalan, database `posip_db_test` ada, migrasi applied
- Jika banyak test gagal dengan `BadMethodCallException::askQuestion` → environment/migrasi interaktif; bukan bagian suite frontend print

---

## Manual smoke (thermal)

Checklist di [`docs/print-support-matrix.md`](../../docs/print-support-matrix.md) — pairing Chrome, test print terminal, checkout auto-print, PDF di Firefox.

---

## Menambah test print

1. Logic murni → `src/composables/print/*.js` + suite `.test.mjs` baru
2. Kebijakan migrasi (auto-print, import terlarang) → `printPolicy.test.mjs` atau `printIsolation.test.mjs`
3. Daftarkan file di `tests/unit/run-all.mjs`
