---
name: Verify Audit Findings
overview: "Verifikasi ulang semua temuan audit terbalik terhadap kode aktual: memisahkan bug nyata yang wajib difix, desain sengaja (false positive / intentional), dan defer. Rencana implementasi hanya untuk item CONFIRMED."
todos:
  - id: p0-checkout-discount
    content: "P0: Enforce pos.discount + sanitize override_promo in CheckoutSalesAction + tests"
    status: pending
  - id: p0-strip-sales-hutang
    content: "P0: sales.view_harga strip ManualSales + hutang.show serialIntake hide + fix doc_sales_returns"
    status: pending
  - id: p1-reset-import
    content: "P1: Import soft-delete restore; reset inventory/serial/promo; refuse approved per-doc; FE labels"
    status: completed
  - id: p2-dash-router-repack
    content: "P2: Dashboard date/status/serial pending; router *.update; repack blockSerial"
    status: pending
isProject: false
---

# Verifikasi Temuan Audit → Rencana Fix

## Metode

Setiap klaim dicek ulang di source (bukan ulang laporan audit). Label:

- **CONFIRMED** — bug/mismatch nyata, masuk rencana fix
- **INTENTIONAL** — sesuai kontrak produk/docs (`CLAUDE.md` / `promosi.md`) — **jangan fix**
- **PARTIAL** — benar sebagian; scope dipersempit atau defer
- **FALSE_POSITIVE** — salah / tidak berlaku di stack ini
- **DEFER** — benar tapi bukan P0 (polish / epic)

---

## Hasil verifikasi (ringkas)

### CONFIRMED — wajib fix

| ID | Temuan | Bukti |
|----|--------|-------|
| C1 | `pos.discount` tidak di-enforce di BE checkout | [`CheckoutSalesAction.php`](syilex/app/Actions/Sales/CheckoutSalesAction.php) tidak `can('pos.discount')`; docs [`promosi.md`](syilex/docs/modules/promosi.md) map ke `diskon_nota_3` + `diskon_5` |
| C2 | `override_promo=true` skip rebuild, percaya slot FE | L214–218 `continue` tanpa zero-out slot |
| C3 | Manual Sales tidak strip `sales.view_harga` | Perm ada di seeder; controller tidak `makeHidden` |
| C4 | Hutang `show` hide PO money tapi leak `serialIntake` cost | [`SupplierHutangController.php`](syilex/app/Http/Controllers/Api/V1/SupplierHutangController.php) ~124–144 |
| C5 | Typo tabel User delete: `doc_sales_return` | Real: `doc_sales_returns` — count skip → delete boleh padahal ada retur |
| C6 | Import upsert SoftDeletes (produk/customer/supplier) | `DB::table` update tanpa clear `deleted_at` |
| C7 | Reset `inventory` tidak clear `serial_units` | Orphan register vs stok |
| C8 | Reset tidak pernah truncate `doc_promo*` | Promo hidup setelah “full” reset |
| C9 | Label Reset `sales`/`shift` (dan PO) understate cascade | UI label pendek vs truncate set besar |
| C10 | Router edit opname/adj/hpp meta `*.create` bukan `*.update` | FE/API mismatch; transfer/repack sudah benar |
| C11 | Dashboard POS payment chart tanpa `date_to` | `$to` dipakai piutang, tidak POS |
| C12 | Dashboard `recent_sales` tanpa `status=completed` | Draft/void bisa muncul |
| C13 | Serial pending di KPI tanpa entry `pending_items` + route map | Dead-end UX |
| C14 | Repack API bisa terima produk serial (`blockSerial: false`) padahal Action tidak handle serial | UI picker block; API crafted = desync |

### INTENTIONAL / FALSE_POSITIVE — jangan fix

| Klaim lama | Verdict | Alasan |
|------------|---------|--------|
| Export hanya `laporan.export` (tanpa kategori) | **INTENTIONAL** | [`CLAUDE.md`](syilex/CLAUDE.md): export = **satu** izin global; list tetap gate kategori |
| `ProdukController::list` expose `harga_4` | **INTENTIONAL** | Dropdown POS/form; butuh harga jual untuk kasir |
| SimpleMaster/`list` tanpa `*.view` | **INTENTIONAL** | Dropdown auth-only |
| `getOutstandingHutangs` tanpa `hutang.view_nominal` | **INTENTIONAL** | Butuh nominal untuk alokasi bayar (`pembayaran-hutang.create`) |
| Serial-intake `update` boleh lihat harga di show | **INTENTIONAL** | Komentar controller: editor form |
| Omzet dashboard gate `stok.view_hpp` | **INTENTIONAL** | Shared “financial” gate (bukan leak) |
| Arus kas `DATE(created_at)` TZ bug | **FALSE_POSITIVE** | `LocalDateTime` + MySQL session TZ sync di `AppServiceProvider` |
| Force list UI ke Settings/Import/Reset | **SKIP** | Archetype form/wizard (sudah diputus) |
| Policies mass / merge CalculationService / httpOnly token | **SKIP/DEFER epic** | Bukan lubang yang CONFIRMED di atas |

### PARTIAL → keputusan di rencana

| Klaim | Keputusan |
|-------|-----------|
| Per-doc reset orphan stock | **Bukan auto-bug** — desync hanya jika reset doc **approved** tanpa `inventory`/`transaksi`. Fix: **blok reset** jika ada doc `approved`/`completed`, atau peringatan UI keras + dokumentasi (pilih: **refuse jika ada approved**) |
| Retur beli “leak harga” | **Tidak ada** `retur-beli.view_harga` di seeder. Bukan bug strip yang hilang — **DEFER** (perlu product decision: tambah perm atau reuse `po.view_harga`) |
| Deposit/pembayaran tanpa `view_nominal` | **Tidak ada** perm di seeder. **DEFER** (perlu seed + Role UI) — jangan invent perm di fix batch ini |
| Ungated `getTaxSettings` | **DEFER ringan** — data non-rahasia (tax %); gate `po.view`/`retur-beli.view` nice-to-have |

### DEFER (benar, bukan batch ini)

- POS mount tidak `fetchRuntimeSettings` (stale mid-shift)
- Exception `$e->getMessage()` ke client (PO dkk.)
- ProductPromo report N+1
- Token localStorage → httpOnly
- a11y pending rows / purple KPI / `useReportList` konsistensi / CustomerPage composable / dedupe `getStockSetting`

---

## Rencana implementasi (hanya CONFIRMED)

Urutan P0 → P1 (ponytail: sedikit file, root-cause di shared path).

### P0 — Keamanan uang & privilege data

1. **Checkout** ([`CheckoutSalesAction.php`](syilex/app/Actions/Sales/CheckoutSalesAction.php)):
   - Jika `!can('pos.discount')`: force `diskon_nota_3` = none/0 dan line `diskon_5` = none/0
   - Jika `override_promo`: setelah skip rebuild, **zero** slot promo `diskon_1..4` (jangan percaya FE)
   - Feature test: kasir tanpa `pos.discount` → API reject/zero; dengan perm → OK
2. **Manual Sales** ([`ManualSalesController.php`](syilex/app/Http/Controllers/Api/V1/ManualSalesController.php)): strip/`makeHidden` field uang bila `!sales.view_harga` (pola PO)
3. **Hutang show**: hide `serialIntake` money fields bila `!hutang.view_nominal`
4. **User delete**: rename `doc_sales_return` → `doc_sales_returns` + test kecil

### P1 — Reset & Import integrity

5. **Import**: pada upsert existing soft-deleted (produk/customer/supplier), set `deleted_at = null` (atau Eloquent `withTrashed`)
6. **Reset**:
   - `inventory`: ikut truncate `serial_units` (+ movements bila ada) **atau** refuse jika `serial_units` count > 0 — **pilih: ikut clear serial_units** agar label “Stok” konsisten dengan register
   - `all`/`transaksi`/`master`: tambah truncate `doc_promo` + `doc_promo_details`
   - Per-doc reset: **refuse** jika ada row status approved/completed (cegah desync)
   - FE [`ResetDatabasePage.vue`](syilex-frontend/src/views/pengaturan/ResetDatabasePage.vue): perpanjang `desc`/Message untuk sales/shift/PO cascade
7. **Repack**: `blockSerial: true` di payload validation (selaras UI)

### P2 — Dashboard & router (kecil, pasti)

8. Dashboard: POS payment `whereDate <= $to`; `recent_sales` → `status=completed`; tambah serial modules ke `pending_items` + `pendingRouteMap`/`pendingIconMap`
9. Router: opname/adjustment/hpp-correction **edit** → `*.update`

### Tidak dikerjakan di rencana ini

- Perm baru `retur-beli.view_harga` / `deposit-*.view_nominal`
- Dual-gate export kategori (sudah by design)
- Gate dropdown `list` / produk harga jual
- httpOnly, PosKasir split, N+1 promo report, a11y polish

---

## Definition of done

- Feature tests hijau untuk C1–C5 (minimal)
- Manual smoke: reset inventory kosongkan serial register; reset transaksi wipe promo; edit adj dengan hanya `update` (tanpa `create`)
- Tidak menambah abstraksi/permission baru di luar yang sudah ada di seeder
