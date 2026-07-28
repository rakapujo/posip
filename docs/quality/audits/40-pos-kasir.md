# Audit menu — 40 POS → Kasir / Run Terminal

> **Status:** patched Wave A P0/P1 + Wave R + Wave R2 + Part D email + Wave B (2026-07-26)  
> **SSoT kode:** `PosController` · `CheckoutSalesAction` · `VoidSalesAction` · `ProcessSalesReturnAction` · `PosCheckoutRules` · `CashTransactionController` · `SalesReturnController` (prefix `pos/`) · `PosKasirPage` · `usePosCart` · routes `api.php` `pos/*`  
> **Cross:** [38-pos-shift.md](38-pos-shift.md) · [39-pos-terminal.md](39-pos-terminal.md) · [00-penjualan-plan-review.md](00-penjualan-plan-review.md) Q5  
> **Jika konflik:** ikuti kode.

## Ringkas (post-patch)

Idempotency **wajib** di checkout; `qty_base`/`konversi`/`harga_satuan` di-rebuild dari master (+ serial `harga_jual`); warehouse terikat terminal; allow-list kosong = 422; `izinkan_retur` enforced; Q5 retur SN saat elektronik OFF; ownership show/search/products; sticky GT+Hold+Lainnya+BAYAR mobile; email struk via terminal mailer.

**Cross 2026-07-28:** wrap teks/serial laporan shift + saudara (ringkas retur di struk, `Ket:` kas) — lihat [38-pos-shift.md](38-pos-shift.md).

**Cross 2026-07-28 (retur SN):** char-break `_wrap` ESC; SN di ringkas retur struk jual (ESC+PDF) + StrukOnline; `buildReturReceipt` siap SN (wire cetak PosKasir deferred); `POST pos/returns` attach `serial_units`.

## Patched P0/P1 (utama)

| ID | Fix |
|----|-----|
| KS-B01 | FE `Idempotency-Key` + MW `idempotency:required` |
| KS-B02 | Rebuild `qty_base`/`konversi` dari master unit |
| KS-B03 | Bind `warehouse_id` = terminal WH |
| KS-B04 | Rebuild harga master / serial `harga_jual` (null → 422) |
| KS-B05 | `izinkan_retur` di search/store + FE gate |
| KS-B06 Q5 | Retur SN diizinkan saat elektronik OFF |
| KS-B07 | Bind `sales.terminal_id` ↔ request terminal |
| KS-B08 | Allow-list kosong → 422 |
| KS-X1 | Zombie poll independen `promo.enabled` |
| KS-S02–S05 | show/search/products ownership + WH bind |
| Wave R | `md` split, sticky GT+BAYAR `<md`, dialog breakpoints, touch ≥44 `<lg` |
| Wave R2 | Q2=C Katalog\|Cart; Q3=B Drawer Lainnya; header wrap; cart card `<md`; sticky clearance di scroll; tabs 25% `<md` only |
| Email HTML | Blade `emails/pos-receipt` (+text) tone Opsi A; `TerminalMailer` html+text SMTP/Resend |
| Part D | `POST .../email-receipt` + tombol Email; body HTML Opsi A (blade) + plain-text + PDF |

## Sisa (opsional / deferred)

- SerialUnitPicker DRY penuh (POS single ≠ BO multi) — add mode prop when needed

## Patched Wave B (2026-07-26)

| Item | Fix |
|------|-----|
| Dead dialog | Hapus `salesDetailDialog` (~230 baris, never opened) |
| Shortcuts | Help sync F2=Disc Nota; Esc close; Ctrl+/ help |
| KS-D01 | `products` leftJoin stock sekali (bukan correlated subquery) |
| mail-test | Via Terminal (lihat 39) |
| TerminalMailer | emailReceipt pakai service bersama |

## Patched Wave R2 (2026-07-26)

| Lock | Implementasi |
|------|----------------|
| Q2=C | `SelectButton` Katalog \| Keranjang (`mobilePane`); `md+` dual pane |
| Q3=B | Sticky **Lainnya** → PrimeVue `Drawer` bottom (Disc/Regen/Biaya/Hapus); sticky icon-only di HP |
| Header | Chrome desktop di **wrapper `div.hidden md/lg:flex`** (Tag/Button PrimeVue menimpa util `hidden`); phone = back+logo+judul+lock+Menu |
| Cart | Card layout `<md`; tabel `md+` (tanpa scroll horizontal di HP) |
| Katalog | Stok plain; toggle 50:50; sticky clearance di scroll; focus ring `ring-inset` (hindari clip `overflow-y-auto`) |
| Transaksi | List sembunyi saat detail/retur open `<lg`; empty panel desktop-only |
| Kas | `overflow-y-auto` stack; history `overflow-x-auto` |
| Held | `grid-cols-1` phone; touch targets |

## Catatan responsive PrimeVue

Jangan andalkan `class="hidden md:inline-flex"` langsung di `Tag`/`Button` — `display` komponen menang. Bungkus dengan `<div class="hidden md:flex">`.

---

# Audit detail (pre-patch snapshot)

> Konten di bawah adalah audit draft sebelum execute — dipertahankan sebagai histori. **Ikuti status patched di atas** bila konflik.

## 1. Endpoint matrix vs FE `PosKasirPage` / `posApi`

| Method | Route | Controller | Perm | FE call | Catatan |
|--------|-------|------------|------|---------|---------|
| GET | `/pos/active-terminal` | `activeTerminal` | `pos.access` | `getActiveTerminal` | Shift via `active_user_id` |
| GET | `/pos/active-promos` | `activePromos` | `pos.access` | `getActivePromos` | + `shift_active` poll |
| GET | `/pos/products` | `products` | `pos.access` | `searchProducts` | `warehouse_id` dari FE |
| GET | `/pos/products/barcode/{b}` | `productByBarcode` | `pos.access` | `getProductByBarcode` | idem |
| POST | `/pos/calculate` | `calculate` | `pos.access` | `calculate` | Preview only; trust subtotal FE |
| POST | `/pos/checkout` | `checkout` | `pos.access` | `checkout` | + `idempotency` MW; **FE tidak kirim key** |
| GET | `/pos/history` | `history` | `pos.access` | (history UI) | Owner shift |
| GET | `/pos/sales/{ulid}` | `show` | `pos.access` | `getSales` | **tanpa ownership / source=pos** |
| POST | `/pos/sales/{ulid}/void` | `void` | `pos.void` | `voidSales` | Owner shift; tutup shift → 422 |
| GET | `/pos/shift-report/{ulid}` | `shiftReport` | mixed | `getShiftReport` | Closed: `terminal.view`; open: owner/force |
| GET | `/pos/returns/search-sales` | `SalesReturnController::searchSales` | `pos.retur` | `searchSalesForReturn` | **tanpa ownership shift** |
| GET | `/pos/returns/sales/{ulid}` | `salesDetail` | `pos.retur` | `getSalesForReturn` | Any POS sale |
| POST | `/pos/returns` | `store` | `pos.retur` | `processReturn` | Action cek shift owner |
| GET | `/pos/returns` | `index` | `pos.retur` | `getReturns` | **tanpa ownership** |
| GET/POST | `/pos/cash` · `/cash/summary` | `CashTransactionController` | `pos.access` | cash APIs | Read: owner\|force; write: owner |
| POST | `/pos/lock` · `/unlock` | lock/unlock | `pos.access` | lock/unlock | Owner |
| POST | `/pos-terminals/{ulid}/start-shift` | `startShift` | **none explicit** | Terminal / kasir flow | Assigned user only |
| POST | `/pos-terminals/{ulid}/end-shift` | `endShift` | **none** (active user) | `endShift` | |
| GET | `/public/receipt/{ulid}` | `publicReceipt` | public | Struk online | throttle 30/min |

Permission seed: `pos.access`, `pos.discount`, `pos.void`, `pos.retur` — **tidak ada `pos.checkout`** (komentar Action menyesatkan).

---

## 2. Business rules (KS-B*)

| ID | Sev | Temuan | Bukti | Arah fix |
|----|-----|--------|-------|----------|
| **KS-B01** | **P0** | **Idempotency mati di path nyata:** MW skip jika header absen; FE `client.js` / `usePosCart.checkout` **tidak** set `Idempotency-Key` → double-click / retry = double sale + double stock out. | `IdempotencyKey.php:42-45`; `syilex-frontend/src/api/client.js`; `usePosCart.js:860` | Wajibkan header di route checkout **atau** FE generate UUID per submit. |
| **KS-B02** | **P0** | **`qty_base` dipercaya FE** — stok keluar pakai `qty_base`, harga pakai `qty × harga_satuan`. Payload `qty=1, konversi=1, qty_base=100` kurangi 100 stok, tagih 1 unit. | `PosController.php:322-324`; `PostsSalesInventory.php:50-51,110,156` | Rebuild `qty_base = qty × konversi` dari master unit; validasi konversi match `unit_N`. |
| **KS-B03** | **P0** | **`warehouse_id` checkout tidak di-bind ke `terminal.warehouse_id`** — kasir bisa jual dari gudang lain (asal saleable+active). | `PosController.php:302,373-376`; `CheckoutSalesAction.php:41` | Assert `(int)$warehouse_id === (int)$terminal->warehouse_id`. Mirror di `PosCheckoutRules`. |
| **KS-B04** | **P1** | **`harga_satuan` full trust** — min 1, tanpa cap ke `harga_*` master / permission edit harga. | `PosController.php:325`; `PostsSalesInventory.php:90` | Policy: rebuild dari master unit **atau** `pos.price_override` + audit log. |
| **KS-B05** | **P1** | **`izinkan_retur=false` hanya teks struk** — tidak memblok `POST /pos/returns` / search. FE juga tidak gate tombol retur. | `PosController.php:591-594` (public only); `SalesReturnController::store`; `useReceiptPdf.js` | Enforce di search/store; FE hide UI. |
| **KS-B06** | **P1** | **Q5 dilanggar:** plan “Elektronik OFF → retur SN tetap boleh”; POS `store` **tolak** `serial_unit_ids` saat OFF; Action tetap wajib SN untuk produk serial → retur serial **mustahil**. | `SalesReturnController.php:218-226`; `00-penjualan-plan-review.md` Q5; `34-retur-penjualan.md` | Izinkan SN pada retur/void revert saat OFF (blok hanya jual/payload baru). |
| **KS-B07** | **P1** | Retur POS: **tidak assert** `sales.terminal_id` / gudang / shift terminal sama dengan request → refund kas bisa masuk laci shift lain untuk nota terminal lain. | `ProcessSalesReturnAction.php:192,315-321`; `store` validate | Bind `sales.terminal_id === terminal_id` (+ optional same warehouse). |
| **KS-B08** | **P1** | Allow-list: jika pivot kosong mid-shift, **semua** metode aktif lolos (`isNotEmpty` guard). Start-shift butuh ≥1, tapi detach setelah start = bypass. | `PosController.php:431-434`; `PosCheckoutRules.php:56-59` | Jika terminal punya config allow-list expected: treat empty as **reject all** (kecuali legacy) **atau** re-check count like startShift. |
| **KS-B09** | **P2** | `startShift` cek warehouse **active** saja, **bukan `is_saleable`** (create/update terminal sudah require saleable). | `PosTerminalController.php:446-447` vs `89` | Tambah `is_saleable` di startShift completeness. |
| **KS-B10** | **P2** | Customer ganti bebas (list tanpa filter `jenis`); walk-in inactive tetap OK. Sesuai POS, tapi default walk-in bisa diganti ke spesifik berdiskontipe besar — nota L1/L2 di-rebuild dari DB (OK). | `PosKasirPage` `customersApi.getList`; `buildNotaDiscounts` | Dokumentasikan BY DESIGN; optional filter POS customer. |
| **KS-B11** | **P2** | `CheckoutSalesAction` re-lock shift untuk `ended_at`, **tidak** re-check `is_locked` → race lock layar vs checkout. | `CheckoutSalesAction.php:49-54` vs controller `362-364` | Re-assert `!$shift->isLocked()` setelah `lockForUpdate`. |
| **KS-B12** | **P3** | `calculate` trust subtotal FE — preview only, checkout rebuild. OK. | `PosController.php:264-287` | — |
| **KS-B13** | **OK** | Promo 1–4 rebuild; `override_promo` zero slots; disc5 butuh `pos.discount`; fee bayar dari master; nota L0/L1 dari DB (override hanya clear). | `CheckoutSalesAction.php:65-69,197-252,255-330`; `applyMasterPaymentFees` | — |
| **KS-B14** | **OK** | Elektronik OFF: checkout blok produk serial + `serial_unit_ids`; products constrain non-serial. | `PosController.php:393-404,165`; `SettingService::constrainNonSerialWhenDisabled` | — |

---

## 3. Security (KS-S*)

| ID | Sev | Temuan | Bukti | Arah fix |
|----|-----|--------|-------|----------|
| **KS-S01** | **P0** | Sama **KS-B01–B03** (fraud harga/qty/warehouse + double checkout). | di atas | Patch P0 dulu. |
| **KS-S02** | **P1** | `GET /pos/sales/{ulid}`: cukup `pos.access`, **tanpa** owner shift / `source=pos` → baca detail nota BO/POS manapun (incl. HPP via attach serial path tergantung load). | `PosController.php:503-530` | Scope: POS + (shift owner \| same terminal assignee \| reprint perm). |
| **KS-S03** | **P1** | `searchSales` / `returns` index: `pos.retur` saja, **tanpa** verifikasi `shift.user_id` / terminal assignment. | `SalesReturnController.php:21-53,242-265` | Mirror cash `authorizeShiftAccess`. |
| **KS-S04** | **P1** | `salesDetail` + `store` by numeric `sales_id`: enumerasi retur nota POS asing → **KS-B07**. | `store:196`; `salesDetail:116-126` | Ownership + terminal bind. |
| **KS-S05** | **P1** | `products` / `productByBarcode`: `warehouse_id` arbitrary → leak stok gudang lain. | `PosController.php:143-195,215-248` | Bind ke warehouse terminal aktif user. |
| **KS-S06** | **P2** | `startShift`/`endShift`: **tidak** `can('pos.access')` — andalkan assignment + active_user. User kehilangan perm tapi masih assigned bisa start. | `PosTerminalController.php:425-527` | Require `pos.access`. |
| **KS-S07** | **P2** | Void: jika `shift` null (edge/BO), cek owner **dilewati** (`if ($sales->shift && …)`). `canVoid()` tetap; risiko void BO via endpoint POS bila status completed. | `PosController.php:624-627` | Require `source=pos` + shift owner. |
| **KS-S08** | **P2** | `publicReceipt`: unauth full line items + customer name (by ULID). By design share link; risk if ULID leaked. | `PosController.php:536-602` | Rate limit sudah ada; optional redact. |
| **KS-S09** | **P3** | Numeric IDs di checkout (`terminal_id`, `shift_id`, …) — ownership dicek untuk shift; OK dengan catatan ULID-first debt. | validate exists | Bertahap ULID. |
| **KS-S10** | **OK** | Cash read/write ownership (patched SHF-S01); void after close (SHF-B01); checkout shift user + terminal active_user + locked. | `CashTransactionController`; `checkout:351-370` | — |
| **KS-S11** | **OK** | Payment fee tidak trust FE (`applyMasterPaymentFees`). | `SalesCalculationService.php:182-205` | — |

---

## 4. N+1 / locks / DB (KS-D*)

| ID | Sev | Temuan | Bukti | Arah fix |
|----|-----|--------|-------|----------|
| **KS-D01** | **P2** | `products` `orderByRaw` correlated subquery stok per row — mahal di katalog besar. | `PosController.php:185-187` | Join/sort via subquery sekali / denormalize. |
| **KS-D02** | **P2** | `shiftReport` load **semua** sales+details+payments shift ke memory. | `PosController.php:668` | Aggregate SQL untuk omzet/diskon bila shift besar. |
| **KS-D03** | **P3** | Cash `index` query sales completed **dua kali** (tunai + non-tunai). | `CashTransactionController.php:68-107` | Satu query + partition. |
| **KS-D04** | **OK** | Checkout: `lockForUpdate` shift → produk → stock → serial units; stock_card pair SALES. | `CheckoutSalesAction`; `PostsSalesInventory` | — |
| **KS-D05** | **OK** | Void/retur: lock stock+produk; serial revert Metode A. | `VoidSalesAction`; `ProcessSalesReturnAction` | — |
| **KS-D06** | **P3** | Idempotency cache TTL 10m; tanpa key = no protection (lihat B01). | `IdempotencyKey.php:33` | — |

---

## 5. Cross-module vs Terminal (allow-list, saleable, isInUse)

| Topic | Terminal admin | Kasir runtime | Gap |
|-------|----------------|---------------|-----|
| `isInUse` / `active_user_id` | start/end/force lock TX | checkout asserts active_user + shift | OK (38 patched) |
| `is_saleable` warehouse | required on create/update | checkout + PosCheckoutRules | startShift **tidak** cek saleable (**KS-B09**) |
| Allow-list payment | required ≥1 active on start | checkout/rules if non-empty | empty mid-shift = allow all (**KS-B08**) |
| Default payment / customer | start completeness | FE default only; BE accepts other customer/method (dalam list) | OK untuk customer; method harus in list |
| `izinkan_retur` / `durasi_retur` | stored | durasi enforced on previous search; izinkan **teks struk saja** | **KS-B05** |
| Print/auto_* / paper / printer / template | stored on terminal | **FE-only** (lihat §6) | BE ignore by design |

---

## 6. Terminal config fields ignored at BE runtime (POS path)

Dipakai FE / struk saja (tidak dibaca Action checkout/void/retur/cash):

| Field | Runtime BE POS |
|-------|----------------|
| `template_struk_id` | ignored |
| `default_printer` | ignored |
| `auto_open_tray` | ignored |
| `auto_print_receipt` / `_retur` / `_kas` / `_report` | ignored (FE; retur/kas/report manual per UI Terminal) |
| `auto_lock_minutes` | ignored (FE timer) |
| `paper_width`, `char_per_line`, `paper_mode`, `print_feed_before_cut` | ignored (FE printOpts) |
| `keterangan` | ignored |
| `izinkan_retur` | **hampir** ignored (hanya `publicReceipt.retur_policy`) |
| `default_metode_pembayaran_id` | not enforced (FE preselect) |
| `default_customer_id` | not enforced (FE default; clear resets) |
| `warehouse_id` | **should** bind checkout — currently **not** (**KS-B03**) |

Enforced di BE: allow-list (jika non-empty), `durasi_retur` (search previous), warehouse saleable+active on checkout, terminal assignment on startShift, `active_user_id` on checkout/end.

---

## 7. Tests coverage gaps

| Suite | Cover | Missing vs temuan |
|-------|-------|-------------------|
| `PosShiftEndpointStrictTest` | cash IDOR, void after close, report ACL, shifts scope | — |
| `PosAccessCoverageTest` | 403 matrix pos.* | startShift without pos.access; show IDOR |
| `CheckoutSalesActionTest` | stock, promo override, fees | qty_base mismatch; warehouse≠terminal; harga forge; empty allow-list |
| `IdempotencyKeyTest` | MW unit | **integration** checkout **wajib** key + FE |
| `e2e/pos-checkout.spec.js` | happy path | double-submit; retur izinkan=false |
| — | — | ProcessSalesReturn terminal bind; Q5 retur SN saat OFF |

---

## Antrian patch (usulan)

1. **P0:** FE+BE idempotency wajib; rebuild/validate `qty_base`; bind `warehouse_id` ke terminal.  
2. **P1:** harga policy; izinkan_retur enforce; Q5 retur SN OFF; retur terminal bind; allow-list empty semantics; show/search/returns ownership.  
3. **P2:** startShift saleable + pos.access; is_locked recheck; void source=pos; products warehouse bind.  
4. Docs: update audit status setelah patch; sync Q5 vs kode.

---

## File map (utama)

- `syilex/app/Http/Controllers/Api/V1/PosController.php`
- `syilex/app/Actions/Sales/CheckoutSalesAction.php`
- `syilex/app/Actions/Sales/VoidSalesAction.php`
- `syilex/app/Actions/Sales/ProcessSalesReturnAction.php`
- `syilex/app/Actions/Sales/Concerns/PostsSalesInventory.php`
- `syilex/app/Services/PosCheckoutRules.php`
- `syilex/app/Services/SalesCalculationService.php`
- `syilex/app/Http/Controllers/Api/V1/CashTransactionController.php`
- `syilex/app/Http/Controllers/Api/V1/SalesReturnController.php`
- `syilex/app/Http/Controllers/Api/V1/PosTerminalController.php` (start/end)
- `syilex/app/Http/Middleware/IdempotencyKey.php`
- `syilex/routes/api.php` ~641–676
- FE: `syilex-frontend/src/api/modules/pos.js`, `composables/usePosCart.js`, `views/pos/PosKasirPage.vue`

## Branding + katalog (2026-07-28)

- Serial di grid katalog: harga master ≤ 0 → label **Pilih SN** (bukan dialog scan).
- Header/footer dokumen POS dari `storeForPrint` (terminal override) — lihat [71-terminal-store-branding.md](71-terminal-store-branding.md). Login/Topbar tetap global.
