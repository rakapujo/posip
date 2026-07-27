# Audit menu — 28 Pembelian → Pembelian Serial (PBS)

> **Status:** audit complete (belum patch; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/inventory/SerialIntakePage.vue` · `SerialIntakeFormPage.vue` · `api/modules/serialIntakes.js` · `SerialLabelPrintDialog`  
> - BE: `syilex/app/Http/Controllers/Api/V1/SerialIntakeController.php` · `Actions/SerialIntake/{Create,Update,Approve}SerialIntakeAction.php` · `Concerns/HandlesSerialUnits.php` · `Models/DocSerialIntake.php` · `Models/SerialUnit.php` · trait `SettlesCashPayment`  
> - Calc: reuse `PurchaseOrderCalculationService` (1 produk × N unit sebagai N detail)  
> - Turunan approve: `InventoryStock` + `StockCard` (`PURCHASE`) + `serial_units` pending→tersedia + movements IN + hutang (`serial_intake_id`) + optional cash settle  
> - Cross: Register Unit (#20) · Retur Beli (list/returnable) · Kartu Stok `source_doc` · Laporan Pembelian UNION · PO form link · Dashboard pending  
> - Routes FE: `inventory-serial-intake*` (+ `requiresElektronik`) · API: `/serial-intakes*` + `feature.elektronik` (`api.php` 149–157)  
> - Menu: `AppMenu.vue` **Pembelian** → Pembelian Serial (URL di `/app/inventory/…`)  
> - Tes: `tests/Feature/SerialIntake/{SerialIntake,SerialDataLayer,SerialUnitExport}Test.php` · partial elektronik/access  
> - Domain: `docs/domain/serial.md` §4  
> **Jika konflik:** ikuti kode.  
> **Urutan AppMenu Pembelian:** setelah Purchase Order. Twin Inventory: Register Unit Serial.

## Scope

Pembelian **1 produk serial + N unit** per dokumen: **draft → approved (terminal)**. Approve = terima stok agregat + HPP weighted dari landed `cost_per_unit` + unit `tersedia` + hutang (jika GT>0) + cash settle opsional. **Tidak ada** `DocSerialIntakeDetail` — baris = `serial_units`. **Tidak:** cancel/void, edit approved, multi-produk per dok.

| Endpoint | Permission (kode) | Dipakai |
|----------|-------------------|---------|
| `GET /serial-intakes` | `serial-intake.view` (+ strip harga tanpa `view_harga`) | List |
| `GET /{ulid}` | `view` (+ harga jika `view_harga` **atau** `update`) | Detail + edit hydrate |
| `POST /` | `create` + throttle 30/1 | Draft |
| `PUT /{ulid}` | `update` + throttle | Draft replace units |
| `DELETE /{ulid}` | `delete` | Draft hard + forceDelete units |
| `POST /{ulid}/approve` | `approve` + throttle | Posting |
| `POST /calculate` | `create` **atau** `update` | Ringkasan form |
| FE / AppMenu | `view` + `serialEnabled` | |

---

## Identitas & data rules (ringkas)

| Aturan | Kode |
|--------|------|
| Prefix `PBS` | SettingService |
| Status: `draft` \| `approved` | migration draft-approve flow; docs masih sebut `cancelled` |
| Unit: `pending` → `tersedia` on approve | Approve action |
| `kode_internal` UNIQUE global; `KI-\d+` reserved | HandlesSerialUnits |
| SN boleh duplikat | domain + HandlesSerialUnits |
| Diskon header **tidak** → HPP (parity PO) | serial.md :104; tes; **:56 teks salah** |
| `unit_konversi` hardcoded `1` | HandlesSerialUnits / calculate |
| SoftDeletes unit; draft delete = **forceDelete** | Update 57; destroy 218 |

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-B1 | P0 | **Race update/destroy vs approve.** Draft check di luar TX; update **tanpa** `lockForUpdate`; `forceDelete` semua unit lalu recreate. Setelah approve menang: update bisa hapus unit `tersedia` + tempel `pending` pada header **sudah approved** → stok/kartu tetap, register rusak. Lebih parah dari PO (hapus unit fisik). | Update 24–26, 38–58; destroy 214–219; Approve lock 46–49 | Mirror PO: lock header + re-assert draft di dalam TX update/destroy; destroy dalam TX. |
| SI-B2 | P1 | **Tidak menulis `HistoryHargaBeli`.** PO menulis; skema history hanya `po_id`/`po_detail_id`. Last-price / history harga miss pembelian serial. | ApproveSerialIntake (no History); HistoryHargaBeli model; PO Approve 127–138 | Migrasi `serial_intake_id` (+ optional unit id) + write on approve **atau** dokumentasikan eksklusi. |
| SI-B3 | P1 | **Approve tidak re-validasi** supplier/WH/produk aktif (`poDocumentErrors` di PO ada). Draft lama + master nonaktif tetap bisa approve. | Approve vs PO `validatePoDocumentState` | Panggil rules aktif di approve. |
| SI-B4 | P1 | Docs `serial.md:56` bilang cost termasuk “alokasi **diskon**”; `:104` + tes benar: diskon **tidak** ke HPP. | serial.md 56 vs 104 | Koreksi :56 (+ migration comment bila ada). |
| SI-B5 | P2 | Hutang skip jika `grand_total == 0`; PO selalu buat hutang. | Approve 125–136 | Dokumentasikan / samakan. |
| SI-B6 | P2 | Action izinkan `supplier_id` null; controller require — bypass Action = stok tanpa hutang. | Create 44; controller rules | Require di Action. |
| SI-B7 | P2 | Non-pending units di-skip movement tapi `qtyIn = count(all)` — inkonsisten bila data korup. | Approve 59–62, 97–100 | `qtyIn = pending only` + abort jika ada non-pending. |
| SI-B8 | P3 | Status `cancelled` di docs/migration comment — dead. | serial.md 67 | Align docs (sama PO-B1). |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-S1 | P1 | **store/update/approve response tidak strip harga** — approver tanpa `view_harga` tetap terima cost di JSON. List/show strip OK. | Controller hide hanya index/show | Strip approve (dan store bila tanpa view_harga/update need). |
| SI-S2 | P2 | Cash settle bypass `pembayaran-hutang.*` (sama PO). | SettlesCashPayment | Dokumentasikan SoD / keputusan. |
| SI-S3 | P2 | Sort `grand_total` tanpa `view_harga` = ordering oracle. | index sort | Batasi sort kolom uang. |
| SI-S4 | P3 | Feature `elektronik` OK; gudang create tanpa view_harga/approve — form tetap input harga (by design entry). | Seeder 131 | Dokumentasikan. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-C1 | P0 | = SI-B1 | Update/destroy | Lock |
| SI-C2 | P2 | destroy tidak di TX | Controller 218–219 | TX + lock |
| SI-C3 | P2 | Tes race update×approve / HistoryHarga / inactive master / strip approve — tipis | SerialIntakeTest | Tambah |
| SI-C4 | P3 | Nested TX cash settle (sama PO) | SettlesCashPayment | Docs |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-X1 | P1 | Race SI-B1 rusak Register / Retur returnable / Kartu Stok link | Register #20; PurchaseReturn | Fix lock dulu |
| SI-X2 | P1 | Last-price PO miss serial buys (SI-B2) | HistoryHargaBeli | Schema + write |
| SI-X3 | — | Retur list PBS / returnable units — OK path terpisah | api.php 554–555 | — |
| SI-X4 | P2 | List → Register `?intake_id=` belum (Register → PBS OK) | SU-X5 / audit 20 | Deep-link outbound |
| SI-X5 | P3 | Laporan pembelian gate `po.view_harga` payung serial — by design domain | serial.md 280 | OK |

### Tampilan / DRY / FE

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-U1 | P1 | Edit form **tanpa loading gate** (PO punya spinner) — race hydrate vs Simpan | Form loading ref unused | ProgressSpinner / disable |
| SI-U2 | P1 | Cash maxlength FE > BE (100/100/50 vs 50/50/30) — sama PO | Form vs Controller | Samakan |
| SI-U3 | P1 | `activeFilterCount` `.value` pada reactive — sama PO | Page 33–40 | Fix tanpa `.value` |
| SI-U4 | P1 | Detail/PDF omit cash + tempo (PO punya tempo) | List detail | Tampilkan |
| SI-U5 | P1 | `generateKode` shared loading — multi-row race peek | Form 315–339 | Per-row busy / sequential |
| SI-U6 | P2 | DatePicker clear tanpa refetch; supplier error UI missing | Page / Form | Parity PO fixes |
| SI-U7 | P2 | DRY besar vs PO list/form — extract later | twin pages | Shared dengan PO-U9 |
| SI-U8 | P2 | E2E API→POS saja; form UI tidak | serial-intake-pos.spec | Optional |
| SI-U9 | P3 | Menu Pembelian vs URL inventory; icon kembar Register | AppMenu | Polish |

### DB / query

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SI-D1 | P1 | History harga tanpa FK serial | history migration | `serial_intake_id` nullable |
| SI-D2 | P2 | Index list OK; filter product_id BE ada, FE tidak | Controller index | Optional filter FE |
| SI-D3 | — | Anti-N+1 list withCount — OK | index | — |

---

## Matriks aksi UI

| Aksi | Ada? | Gate |
|------|------|------|
| List / filter / detail / PDF | Ya | view (+ harga) |
| Print label | Ya (detail) | view |
| Create / Edit / Delete draft | Ya | create / update / delete |
| Approve | Ya | approve |
| Cancel / Void | Tidak | — |
| Excel list | Tidak | — |
| Generate KI / SN rows | Ya | form |

---

## Antrian patch (usulan)

1. **P0** SI-B1/C1 — lock update/destroy (+ assert units masih pending sebelum mutasi approve opsional).  
2. **P1** SI-B2/D1 — HistoryHargaBeli + serial_intake_id **atau** docs eksklusi (plan: **tulis history**).  
3. **P1** SI-B3 approve re-validate masters; SI-S1 strip harga approve; SI-B4 docs.  
4. **P1** FE SI-U1–U5 (+ shared PO filter/cash).  
5. **P2** destroy TX, SI-X4 link Register, tes.

---

## Tes terkait

| File | Coverage |
|------|----------|
| `SerialIntakeTest` | draft→approve, HPP, diskon≠HPP, hutang, cash, calculate, perms, KI, view_harga show/list |
| `SerialDataLayerTest` | model/relasi |
| `SerialUnitExportTest` | export strip cost |
| Retur linked | `PurchaseReturnSerialIntakeLinkedTest` |

**Tipis:** race update×approve, HistoryHarga, inactive master approve, strip approve response, destroy TX.

---

## Ringkasan eksekutif

Approve PBS **kuat** (locks, landed HPP, hutang/cash, parity diskon header). Gap kritis sama kelas PO: **update/destroy tanpa lock** — dampak lebih buruk karena `forceDelete` unit. Tambahan: **HistoryHargaBeli absen**, approve tanpa re-check master, leak harga di response approve, FE loading/cash/filter/detail cash.

**Gabung plan patch dengan #27 PO.** Fix hanya jika user bilang **execute**.
