# CLAUDE.md — Agent Guide for SIPOS

**Untuk AI coding assistants (Claude Code, etc.) yang bekerja di codebase ini.** File ini merangkum konvensi, gotcha, dan aturan khusus yang tidak terlihat dari kode saja.

---

## 0. ATURAN WAJIB (TIDAK ADA PENGECUALIAN)

### 0.1 Single Source of Truth
Semua keputusan WAJIB berpatokan pada:
- File CLAUDE.md ini
- Global Settings yang relevan (tabel `settings`)
- Dokumentasi modul (kalau ada di `docs/modules/`)
- Kode aktual (ground truth terakhir)

Kalau konflik/ambigu → **HENTIKAN dan TANYAKAN user**, jangan berasumsi.

### 0.2 No Assumption Rule
- DILARANG berasumsi, menebak kebutuhan, atau mengisi kekosongan instruksi
- Setiap tindakan HARUS berbasis: perintah eksplisit user ATAU dokumentasi valid
- Instruksi tidak jelas → **MINTA KLARIFIKASI dulu**

### 0.3 Reusable Priority
- WAJIB cek [src/components/common/](../sipos-frontend/src/components/common/) dan [src/composables/](../sipos-frontend/src/composables/) sebelum bikin baru
- DILARANG bikin component/composable baru tanpa approval user
- Component tidak tersedia → laporkan + tanya

### 0.4 Design Consistency & DRY
- Konsistensi desain PRIORITAS TERTINGGI — tidak boleh dikompromikan
- DRY wajib — tidak ada duplikasi logic atau variasi UI tanpa alasan sistematis

### 0.5 Cross-Module Dependency Check
- Perubahan berdampak ke modul lain → identifikasi SEMUA dependensi dulu
- Relasi antar modul tidak dipahami → **STOP dan TANYA**

### 0.6 Permission Management
- Setiap create/update endpoint: permission HARUS didefinisikan eksplisit
- Update `UserSeeder.php` permission list setiap tambah `->can('xxx')` di controller baru

### 0.7 Task Completion Protocol
Setiap tugas selesai WAJIB diakhiri dengan:
1. Ringkasan perubahan (faktual, ringkas)
2. Status (selesai / tertunda / butuh klarifikasi)
3. Tanya: "Apakah perubahan ini perlu di-update ke dokumentasi?"

---

## 1. Tech Stack

**Backend** (`C:\laragon\www\sipos`):
- Laravel 12 (PHP 8.2+)
- MySQL 8+ (prod & PHPUnit — `phpunit.xml` → DB `posip_db_test`)
- Sanctum API token auth, Spatie Permission, Spatie ActivityLog
- Queue: `database` driver (NOT `sync` di prod)

**Frontend** (`C:\laragon\www\POSIP\syilex-frontend`):
- Vue 3 + Composition API + Vite 5
- Pinia stores, Vue Router 4, PrimeVue 4.5 (auto-imported via `@primevue/auto-import-resolver`)
- Tailwind CSS 4, `vee-validate` + `zod` untuk form validation

---

## 2. Critical Business Rules (WAJIB dibaca sebelum touch)

### A. Anti-fraud Checkout
- Frontend HANYA kirim `items[]` + `diskon_5_*` (manual kasir).
- Backend REBUILD `diskon_1..4` dari DB promo di [CheckoutSalesAction.php](app/Actions/Sales/CheckoutSalesAction.php).
- **JANGAN** trust frontend untuk nilai promo/diskon 1-4.

### B. HPP Weighted Average
- Direkalkulasi HANYA di `PURCHASE_RECEIVE` dan `ADJUSTMENT_IN` (plus).
- **TIDAK** direkalkulasi di `SALES`, `SALES_RETURN`, `PURCHASE_RETURN`, `TRANSFER`, `ADJUSTMENT_OUT` (by design — lihat komentar di [LockPurchaseReturnAction.php:142](app/Actions/PurchaseReturn/LockPurchaseReturnAction.php#L142)).
- Formula di [MasterProduk.php::recalculateAvgCost](app/Models/MasterProduk.php).
- Division-by-zero guard: `if ($totalQty <= 0) return $currentAvgCost`.

### C. Stock Invariants (CRITICAL)
- Tiap mutation `inventory_stock` **WAJIB** ada entry `stock_card` padanan di transaction yang sama.
- Action skip observer dan manually call `StockCard::record()` inside transaction.
- `SUM(stock_card.qty_in - qty_out) per (product, warehouse) === inventory_stock.qty` — diverifikasi oleh `php artisan data:verify`.

### D. Idempotency
- POS checkout pakai header `Idempotency-Key: {uuid}` (regex `/^[A-Za-z0-9_\-]{16,128}$/`).
- Cache 10 menit per (user, route, key). Replay 2xx response, bukan 4xx/5xx.
- Middleware: [IdempotencyKey.php](app/Http/Middleware/IdempotencyKey.php).

### E. Concurrency Locks
- `SettingService::generateNomor` pakai `lockForUpdate()` untuk sequence nomor dokumen.
- Stock decrement pakai `InventoryStock::lockForUpdate()` + `MasterProduk::lockForUpdate()` di checkout.
- Price change apply pakai atomic `Cache::lock('price_change_running', 300)` + cooldown cache.

### F. Document Status Enum (Strings — belum PHP Enum)
Stay consistent, typo = bug diam:
- Sales: `completed`, `voided` (POS langsung final — TIDAK ada `draft`; enum `doc_sales.status`)
- Purchase/Return: `draft`, `approved`, `locked`, `cancelled`
- Price Change: `draft`, `scheduled`, `applied`, `cancelled`
- Stock Card `transaction_type` (enum di [migration](database/migrations/2026_01_23_210001_create_stock_card_table.php) + [hpp_correction addon](database/migrations/2026_01_24_140004_add_hpp_correction_to_stock_card_type.php)):
  `PURCHASE`, `SALES`, `PURCHASE_RETURN`, `SALES_RETURN`, `ADJUSTMENT_IN`, `ADJUSTMENT_OUT`, `STOCK_OPNAME`, `TRANSFER_IN`, `TRANSFER_OUT`, `REPACK_IN`, `REPACK_OUT`, `HPP_RESET`, `HPP_CORRECTION`

### G. Serial / Pembelian Serial (modul A+ — [docs/modules/serial.md](docs/modules/serial.md))
- Produk `is_serial` tetap NORMAL (stok qty + HPP weighted-avg). Register tambahan `serial_units` (modal/jual per unit) = basis laporan laba akurat, **bukan** override COGS penjualan.
- **Pembelian Serial = draft → approved** (seperti PO): create/edit = draft (unit `pending`, TIDAK sentuh stok); **approve** baru komit stok + HPP weighted-avg + `stock_card` PURCHASE + unit `tersedia`. Gudang create/edit/delete draft; admin `serial-intake.approve`.
- **Identitas unit = `kode_internal`** (UNIQUE **global** di DB; auto `KI-{id}` via hook model `created` saat kosong, boleh override divalidasi `withTrashed`). **`serial_number` TIDAK unik** — boleh kembar bahkan dalam 1 produk; semua transaksi pilih unit via **`ulid`**, bukan SN. JANGAN tambah lagi cek unik SN (sudah dihapus di intake/perubahan/approve). Scan pakai `lookup?code=` (kode_internal → SN, ambigu → kandidat). HPP di-recalc HANYA saat approve (sesuai §2B). `doc_serial_intake.status`/`serial_units.status` = **string** (bukan DB enum), prefix `PBS`.
- **Integrasi penuh** (Inventory + Penjualan): Transfer/Adjustment-keluar/Opname/Retur-Beli/Koreksi-HPP + **POS (checkout/void/retur)** semua serial-aware via kolom JSON `serial_unit_ids` di tabel detail + ledger `serial_unit_movements`. Pilih SN eksplisit; `qty = count(serial_unit_ids)`. Valuasi pakai `unit.cost_per_unit` (bukan `avg_cost`); produk **non-serial tetap apa adanya**.
- **Penjualan serial:** checkout tandai unit `terjual` + `sale_id`; `hpp_at_time` = rata `cost_per_unit` unit terjual (COGS per-unit akurat → laporan laba otomatis benar, tak perlu ubah laporan); `avg_cost` agregat = **Metode A** (rata unit `tersedia`). Void/retur balikkan unit ke `tersedia`. Produk serial **wajib pilih SN** di POS (known-issue "serial dijual sebagai produk biasa" sudah ditutup).

---

## 3. Architecture Patterns (Follow These)

### Layered (MVCS + Action)
```
Route → Controller (thin) → Action (transactional write) → Model
                         ↘ Service (reusable logic: HPP, Promo, Settings)
```

**Controller thinness:** Controller HANYA validate, authorize, delegate ke Action. Logic bisnis di Action.

**Actions** di `app/Actions/{Domain}/{Verb}Action.php`. Contoh:
- `CheckoutSalesAction`, `ProcessSalesReturnAction`, `VoidSalesAction`
- `ApprovePurchaseOrderAction`, `LockPurchaseReturnAction`
- `ApplyPriceChangeAction`, `CancelPriceChangeAction`

### API Response Format (WAJIB konsisten)
Pakai `BaseApiController`:
```php
return $this->success(['items' => $data]);       // 200
return $this->created($resource, 'message');     // 201
return $this->error('msg', 422);                 // custom
return $this->forbidden('alasan');               // 403
return $this->notFound('apa yang tidak ada');    // 404
```

Format response:
```json
{ "success": true, "data": { ... }, "message": "..." }
```

### Custom Exceptions (Throw dari Action)
Ada 4, sudah auto-render ke HTTP 422 via `bootstrap/app.php`:
- `BusinessException` — umum
- `StockInsufficientException::forProduct($id, $name, $required, $available)`
- `DocumentStateException` — misal edit doc locked
- `IdempotencyConflictException` — dari middleware

**Jangan** catch generic `\Exception` di controller — biarkan exception bubble up ke renderer. Kalau terpaksa catch untuk infra (mysqldump, file IO), log ke `storage/logs/laravel.log` dan return error message user-friendly.

### Permission Check
Pakai Spatie Permission. Check di Controller:
```php
if (!auth()->user()->can('promo.create')) return $this->forbidden();
```

Permission seeded di [UserSeeder.php](database/seeders/UserSeeder.php). Kalau nambah `->can()` di controller baru, **wajib** tambah permission di seeder juga.

#### Laporan — izin PER-KATEGORI (bukan satu `laporan.view`)
Halaman laporan di-gate per kategori sesuai grup menu, BUKAN satu izin global:
`laporan.penjualan`, `laporan.pembelian`, `laporan.keuangan`, `laporan.performa`, `laporan.promo`, `laporan.inventory`. Export tetap **satu** izin `laporan.export`.
- `laporan.view` sekarang **khusus widget Dashboard** ([DashboardController.php](app/Http/Controllers/Api/V1/DashboardController.php)) — **jangan** dipakai untuk gate halaman laporan baru.
- Controller laporan baru → gate kategori yang sesuai + tambah ke UserSeeder. Role editor menampilkannya otomatis (prefix `laporan`); beri label aksi di `RolePage.vue` `columnLabels`.

#### Nilai sensitif (uang/biaya) = lapisan TERPISAH, orthogonal di atas izin akses
Apa pun yang menampilkan harga/cost/HPP/nominal-hutang WAJIB di-gate value-permission, bukan sekadar izin akses:
- `stok.view_hpp` → HPP/`avg_cost`/`cost_per_unit`/modal unit serial (kartu stok, valuasi, **Register/picker/export unit serial**, margin, gross profit).
- `po.view_harga` → harga beli di Purchase Order + **Laporan Pembelian** (PO + serial digabung).
- `serial-intake.view_harga` → harga di **dokumen** Pembelian Serial (detail/list/PDF intake).
- `hutang.view_nominal` → nominal hutang.

Pola: backend `makeHidden`/strip + frontend `can(...)` hide. **View yang gate HPP/harga WAJIB gate export-nya juga** (teruskan flag ke kelas `App\Exports\*` untuk strip kolom uang — jangan biarkan export hanya cek `laporan.export`).

#### Toggle modul fitur (feature flag) — grup `modules`
Modul opsional di-gate setting `modules.{nama}_enabled` (bukan permission). Saat ini: **`modules.elektronik_enabled`** (default `true`) untuk seluruh fitur **serial** (retail selalu aktif). Pola:
- Helper `SettingService::isElektronikEnabled()` — **default TRUE bila baris belum ada** (jangan bikin tes/instalasi lama jadi OFF mendadak).
- Backend: middleware `feature.elektronik` ([EnsureElektronikEnabled](app/Http/Middleware/EnsureElektronikEnabled.php), alias di `bootstrap/app.php`) di grup route serial + guard `is_serial` di `ProdukController`/`ImportController` + `DashboardController`.
- Frontend: `useSettingsStore().serialEnabled` (dari `publicSettings.modules`) → gate menu/route (`meta.requiresElektronik`)/form/POS; tab **Pengaturan → Modul**.
- **Lock**: modul tak bisa dimatikan selama datanya masih ada (pola sama `price_input_mode`/`negative_mode`). Detail: [docs/modules/serial.md §4.14](docs/modules/serial.md).

### ULID di mana-mana
Semua model pakai `HasUlid` trait. Public API selalu expose `ulid` (bukan `id`). Model `$hidden` = `['id']`.

---

## 3.5 Frontend Conventions (WAJIB untuk modul baru)

### Composables Catalog
Semua di [frontend/src/composables/](../sipos-frontend/src/composables/):

| Composable | Purpose | Dipakai di |
|------------|---------|------------|
| `useFormatters` | Currency, qty, date formatting dari global settings. **WAJIB dipakai** — jangan format manual | ~111 import di 59+ file |
| `useNotification` | Toast standardization. **Jangan pakai `toast.add()` langsung** | ~100 import di 59+ file |
| `useMasterCrud` | CRUD state + pagination + confirmation untuk master pages | 8 master page (−50% LOC) |
| `useTransactionList` | List state untuk transaction pages (approve/delete workflow) | 9 transaction list page |
| `usePosCart` | Cart state + hold/resume + checkout di POS | `PosKasirPage.vue` |
| `useShiftReport` | Load/print/download shift PDF | `PosKasirPage`, `ShiftPage`, `PosTerminalPage` |
| `useReceiptPdf` | Generate receipt 80mm thermal PDF | `PosKasirPage`, `StrukOnlinePage`, `PenjualanPerNotaPage` |
| `useReceiptEscPos` | Build ESC/POS bytes untuk thermal printer direct | Pages yang print langsung |
| `usePrintAdapter` | Facade thermal: browser Web Serial/USB/BT + legacy `:5123` fallback | `PosKasirPage`, `PosTerminalPage`, `ShiftPage`, `PenjualanPerNotaPage` |
| `usePrintTransport` | Pairing & write ESC/POS via Web API | `PrinterPickerPanel`, `usePrintAdapter` |
| `usePrintService` | **Legacy** — Python Print Service `localhost:5123` | `PosTerminalPage` (refresh legacy printer list saja) |
| `useExportPdf` | Export list/document PDF A4 (jspdf + autotable) | `ProdukPage`, `PurchaseOrderPage` |
| `useBarcodePrint` | Generate barcode label PDF (JsBarcode) | `PrintBarcodePage` |
| `useErrorLogger` | Global error handler → backend log (auto-installed di `main.js`) | Auto-installed |

**Aturan:**
- ❌ Jangan bikin `toggleDialog` / `deleteDialog` manual — `useMasterCrud` sudah handle via `useConfirm`
- ❌ Jangan format currency/date manual — pakai `useFormatters`
- ❌ Jangan pakai `toast.add()` langsung — pakai `useNotification`
- ❌ Jangan handle `e.response?.data?.message` manual — pakai `notify.apiError(err, fallback)`

### Components Catalog
Semua di [frontend/src/components/common/](../sipos-frontend/src/components/common/):

| Component | Purpose |
|-----------|---------|
| `ImageUpload.vue` | Upload gambar dengan preview, resize, WebP convert |
| `DetailDialog.vue` | Dialog wrapper dengan audit trail section (created_by, updated_by, dates) |
| `DetailItem.vue` | Label-value pair dengan type: text/badge/datetime/image/currency/percent/qty |
| `DetailTable.vue` | Native HTML table untuk DetailDialog (**WAJIB** — DataTable dalam Dialog bikin shaking issue) |
| `DataTableHeader.vue` | Header dengan search + export button |

### Form Input Rules

#### useFormatters.shouldUppercase
SEMUA `InputText` dan `Textarea` WAJIB pakai:
```vue
<InputText v-model="form.nama" :style="{ textTransform: shouldUppercase ? 'uppercase' : 'none' }" />
```

**Pengecualian** (TIDAK pernah uppercase): `email`, `password`, `pin`, `status`.

#### Kode Field Validation
| Rule | Detail |
|------|--------|
| Pattern | `[A-Z0-9_]+` (huruf kapital, angka, underscore) |
| Max length | 20 karakter |
| No spaces | Spasi tidak dibolehkan |
| Auto uppercase | Otomatis di-uppercase |
| Immutable | `:disabled="isEdit"` — tidak bisa edit setelah create |
| Required & Unique | Wajib diisi dan unik per tabel |

#### Searchable Dropdown (WAJIB)
Semua `Select` HARUS pakai `filter` prop:
```vue
<Select v-model="form.tipe_id" :options="tipeOptions" filter optionLabel="nama" optionValue="id" />
```

---

## 4. Testing Conventions

**Backend (PHPUnit)** — `phpunit.xml` memakai MySQL `posip_db_test` (bukan SQLite in-memory). `use RefreshDatabase` di feature tests.

```bash
cd syilex && php artisan test
php artisan test --filter=Promo
```

Laporan penjualan per-nota: filter `status=completed|retur_partial|retur_full` + kolom `receipt_status` memakai SQL shared di [`ReportHelperService`](app/Services/ReportHelperService.php) (`sqlSalesBoughtBase` / `sqlSalesReturnedBase`). Regression: `SalesReportCoverageTest::test_per_nota_receipt_status_filters_partial_and_full_return`.

**Frontend unit** — Node runner, tanpa browser:

```bash
cd syilex-frontend && npm run test:unit   # 87 tests — print thermal, policy, isolation
```

Detail suite: `syilex-frontend/tests/README.md` · matrix cetak: `docs/print-support-matrix.md`

**Frontend E2E** — Playwright (`syilex-frontend/e2e/`), butuh backend + seed.

- Factory limited (4 model) — sisanya create manual via `Model::create([...])`.
- Test `pos_terminal_shifts`: butuh `terminal_id` di `pos_cash_transactions`, `jenis` di `master_metode_pembayaran` enum terbatas (`bank, qris, credit_card, debit_card, e_wallet, lainnya` — nullable untuk tunai).
- Reset/backup test yang butuh MySQL-specific statement: skip kalau `config('database.default') !== 'mysql'` atau guard via driver check.

---

## 5. Scheduler (Bukan Cron!)

SIPOS **tidak tergantung cron** — scheduled task jalan via middleware saat ada request API:
- **Price change auto-apply** ([ApplyScheduledPriceChanges.php](app/Http/Middleware/ApplyScheduledPriceChanges.php)) — cooldown 5 min
- **Activity log cleanup** ([CleanupActivityLog.php](app/Http/Middleware/CleanupActivityLog.php)) — cooldown 7 hari, hapus record >365 hari

Config via setting table: `scheduler.{type}_enabled`, `scheduler.{type}_cooldown`, `scheduler.{type}_max_batch`.

**Ramah shared hosting.** Kalau ada cron, `php artisan schedule:run` cuma `inspire` (placeholder).

---

## 6. Health & Integrity Monitoring

### Health Check
```
GET /api/v1/health
```
Return JSON status DB ping + storage write + cache r/w. 200 OK atau 503 degraded.

### Data Invariant Checker
```
php artisan data:verify [--json] [--fail-on-mismatch]
```
Cek:
- Stock consistency
- Sales payment totals vs grand_total + total_biaya_pembayaran
- Hutang ledger balance

Rekomendasi: cron harian + alert kalau mismatch.

### Error Tracking
- Backend: Laravel log `storage/logs/laravel-*.log` (daily, retensi 14 hari)
- Frontend: dikirim ke `POST /api/v1/client-errors` → log dengan tag `"Frontend error"`. Monitor: `tail -f storage/logs/laravel-*.log | grep "Frontend error"`
- No Sentry integration (by choice — simple file log cukup). Kalau mau upgrade: cuma ubah implementasi `logClientError()` di [useErrorLogger.js](../sipos-frontend/src/composables/useErrorLogger.js).

---

## 7. Common Gotchas (Hemat Waktu)

### Backend

1. **SoftDeletes di master data** (`MasterProduk`, `MasterCustomer`, `MasterSupplier`, `User`) — dokumen historis tetap resolve via Eloquent relation auto-exclude. **Jangan** tambah `withTrashed()` sembarangan.

2. **`SET FOREIGN_KEY_CHECKS=0`** di `ResetController` — MySQL only. Pakai `toggleForeignKeyChecks()` helper untuk guard SQLite test.

3. **Backup** adalah ZIP berisi `database.sql` + `uploads/` (bukan SQL polos lagi). Restore handle kedua format (zip atau legacy .sql).

4. **Nomor dokumen prefix** harus 3-char: `POR, RPB, RPJ, PBH, OPN, HPC, PRM, INV, RTR`. Validate di [SettingService::formatCode](app/Services/SettingService.php).

5. **Timezone** — pakai setting table, bukan `.env` (fallback). Cast via `App\Casts\LocalDateTime`. Model field yang butuh timezone-aware: tambah `'field' => LocalDateTime::class` di `casts()`.

6. **Mass assignment protection** — semua model pakai `$fillable` explicit, bukan `$guarded`. Model sensitive (User) `$hidden = ['password', 'remember_token']`.

7. **Observer skip** — Action yang butuh manual control sudah `StockCard::withoutEvents(...)` atau similar. Jangan ubah pola ini — observer running implicit di luar transaction bisa break invariant.

8. **Filter date-range pada kolom DATETIME** — kolom `tanggal*` yang di-cast `LocalDateTime` menyimpan jam (mis. `tanggal_po`). Saat memfilter dengan `date_to` (date-only dari frontend), batas-atas **WAJIB** `$endDate . ' 23:59:59'` (dan batas-bawah `$startDate . ' 00:00:00'`), ATAU pakai `whereDate()`. Kalau `where('tanggal', '<=', $endDate)` polos, MySQL coerce `$endDate` ke `00:00:00` → record hari ini (jam > 0) tersaring keluar dan baru muncul kalau filter ditambah 1 hari. **Implementasi tunggal (DRY): trait [`App\Traits\HasDateRangeScope`](app/Traits/HasDateRangeScope.php)** — semua model transaksi `use` trait ini; kolom non-`tanggal` override via `protected $dateRangeColumn = 'tanggal_po';`. Reports lewat `ReportHelperService::parseDateRange`. Regression test: `tests/Feature/PurchaseOrder/PurchaseOrderDateFilterTest.php`.

### Frontend

1. **PrimeVue auto-import** — `Dialog`, `Button`, `InputText`, `InputNumber`, `Textarea`, dll tidak perlu import manual di component. Configured via `vite.config.js`.

2. **Error handling pattern baru** — pakai `notify.apiError(err, 'fallback')` bukan `notify.error(err.response?.data?.message || '...')`. Handle Laravel 422 validation errors, network, timeout, dan HTTP status.

3. **Router guard** — `requiresAuth` + `meta.permission` check di [router/index.js](../sipos-frontend/src/router/index.js). Tambah route baru → set `meta.permission = 'xxx.view'`.

4. **Form validation** — kebanyakan pakai `vee-validate + zod` (60 file sudah adopt). Form CRUD baru ikuti pattern existing (lihat [PurchaseOrderFormPage.vue](../sipos-frontend/src/views/pembelian/PurchaseOrderFormPage.vue) sebagai template).

5. **Shortcut keyboard** di POS — F1 fokus cari, F2 help dialog, F9 hold, F12 bayar, Ctrl+/ help.

6. **Shift close** — endpoint `end-shift` terima `saldo_fisik` + `closing_notes` opsional, compute `saldo_system` otomatis. Reconcile dialog muncul sebelum close shift.

---

## 8. Running the App

```bash
# Backend
cd C:\laragon\www\sipos
composer install
cp .env.example .env            # lalu edit DB
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve                # http://localhost:8000

# Frontend (terminal lain)
cd C:\laragon\www\sipos-frontend
npm install
npm run dev                      # http://localhost:5173
```

Credential default setelah seed (dari [UserSeeder.php](database/seeders/UserSeeder.php)):

| Email | Role | Catatan |
|-------|------|---------|
| `rakapujo@posip.com` | super-admin | Developer account |
| `admin@posip.com` | super-admin | All permissions |
| `manager@posip.com` | admin | Semua kecuali user/role mgmt + settings.update |
| `kasir@posip.com` | kasir | POS + shift + view master |
| `gudang@posip.com` | gudang | Inventory + PO CRUD (tanpa approve) |

Password semua: `password`.

---

## 9. When Making Changes

### Write Operations
- Wrap di `DB::transaction()` kalau multi-table
- Lock row yang dimutasi dengan `lockForUpdate()`
- Match dengan `stock_card` entry kalau touch `inventory_stock`
- Emit activity log via `HasAuditLog` trait kalau relevan untuk compliance

### Tests
- Update/buat test untuk Action baru — WAJIB
- Test `can()` permission kalau nambah controller action
- Run `php artisan test` sebelum commit

### Migrations
- Reversibility via `down()` method
- **Jangan** pakai MySQL-specific statement tanpa guard kalau test pakai SQLite (cek `DB::connection()->getDriverName()`)
- Composite index kalau WHERE multi-column sering

### Data Invariants
- Tambah check baru di `VerifyDataInvariants.php` kalau introduce tabel/ledger baru
- Update test di `VerifyDataInvariantsTest.php`

---

## 10. References

- [README.md](README.md) — quick start user
- [DEPLOY.md](DEPLOY.md) — production deployment
- [API_DOCS.md](API_DOCS.md) — endpoint reference
- [RESTORE_DRILL.md](RESTORE_DRILL.md) — disaster recovery drill
- [ONBOARDING.md](ONBOARDING.md) — dev baru start guide
- [ARCHITECTURE.md](ARCHITECTURE.md) — flow diagrams
- [docs/modules/serial.md](docs/modules/serial.md) — modul Serial (is_serial + Pembelian Serial draft→approved)

## 11. What NOT to Do

### Backend
- ❌ Trust frontend untuk nilai promo/diskon 1-4
- ❌ Ubah `inventory_stock` tanpa `stock_card` entry yang match
- ❌ Catch `\Exception` generic di controller (kecuali untuk infra subprocess yang perlu dicegah leak stack trace)
- ❌ Tambah field ke model tanpa update `$fillable`
- ❌ Recalculate HPP di SALES_RETURN (by design tidak boleh)
- ❌ Pakai `queue:sync` di production (blocking)
- ❌ Commit `.env` atau backup file
- ❌ Pakai `SET FOREIGN_KEY_CHECKS` tanpa driver guard kalau code jalan di test
- ❌ Rename status string value tanpa migrate data existing
- ❌ Tambah endpoint tanpa cek permission via `->can()` + update UserSeeder
- ❌ Delete master tanpa cek relation (supplier → PO/retur/hutang/deposit, customer → doc_sales, dll)
- ❌ Filter kolom `tanggal*` DATETIME dengan `where('tanggal', '<=', $dateOnly)` polos — wajib `. ' 23:59:59'` (batas-bawah `. ' 00:00:00'`) atau `whereDate()`, lihat §7 Backend #8

### Frontend
- ❌ Bikin component/composable baru tanpa cek yang existing dulu
- ❌ Bikin `toggleDialog` / `deleteDialog` manual — pakai `useConfirm` via `useMasterCrud`
- ❌ Pakai `toast.add()` langsung — pakai `useNotification` / `notify.*`
- ❌ Format currency/date manual — pakai `useFormatters`
- ❌ Passthrough `error.response?.data?.message` raw — pakai `notify.apiError(err, fallback)`
- ❌ Pakai `DataTable` PrimeVue di dalam `DetailDialog` — pakai `DetailTable` (anti-shaking)
- ❌ Lupa `shouldUppercase` style di InputText non-email/password
- ❌ Select tanpa `filter` prop (WAJIB searchable)
- ❌ Edit `kode_*` field setelah create (immutable)

### Agent Behavior
- ❌ Berasumsi / menebak kebutuhan user — tanya kalau tidak jelas
- ❌ Lewati Task Completion Protocol (ringkasan + status + tanya perlu update dokumentasi)
- ❌ Mulai refactor besar tanpa approval user (rujuk section 0.3)
