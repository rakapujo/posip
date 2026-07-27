# Audit menu — 30 Pembelian → Pembayaran Hutang

> **Status:** audit complete (belum patch; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/pembelian/PembayaranHutangPage.vue` · `PembayaranHutangFormPage.vue` · `api/modules/pembayaranHutangs.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/PembayaranHutangController.php` · `Actions/PembayaranHutang/{Create,Update,Complete}PembayaranHutangAction.php` · `Models/DocPembayaranHutang{,Detail,Deposit}.php`  
> - Turunan: `SupplierHutang::recordPayment` · `SupplierDeposit::use` · caller `SettlesCashPayment` (PO/PBS cash approve)  
> - Twin: `PembayaranPiutang*` (Complete sudah lock + always-match deposit — **port ke hutang**)  
> - Routes FE: `pembelian-pembayaran-hutang*` · API: `/pembayaran-hutangs*` (`api.php` 577–586)  
> - Menu: `AppMenu.vue` Pembelian → Pembayaran Hutang (setelah Hutang Supplier)  
> - Tes: `tests/Feature/PembayaranHutang/{PembayaranHutangCrud,UpdatePembayaranHutangAction}Test.php` · `PurchaseMoneyStripTest` · `PembelianAccessCoverageTest`  
> - Cross: audit [29-hutang-supplier.md](29-hutang-supplier.md) SH-X1/X2  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Hutang Supplier; sebelum Retur Pembelian.

## Scope

Dokumen pembayaran hutang supplier: **draft → completed (terminal)**. Alokasi multi-hutang (cash dan/atau deposit supplier). Complete: lock hutang (+ deposit), `recordPayment`, `deposit->use`. **Tidak:** void/cancel setelah complete. Cash settle otomatis dari approve PO/PBS memakai Create+Complete Actions (bypass HTTP perm, butuh approve PO/PBS).

| Endpoint | Permission | Dipakai |
|----------|------------|---------|
| `GET /` | `pembayaran-hutang.view` (+ strip nominal via `hutang.view_nominal`) | List |
| `GET /{ulid}` | `view` (+ strip) | Detail + edit hydrate |
| `POST /` | `create` | Draft |
| `PUT /{ulid}` | `update` | Draft only |
| `DELETE /{ulid}` | `delete` | Draft hard-delete |
| `POST /{ulid}/complete` | `complete` + throttle 30/1 | Settle |
| `GET /outstanding-hutangs` | **`create` saja** | Form picker |
| `GET /available-deposits` | **`create` saja** | Form deposit |
| FE create / edit | `create` / `update` | |
| AppMenu | `view` | |
| Seeded roles | admin/super-admin saja — **kasir & gudang: 0** `pembayaran-hutang.*` | |

**CRUD:** create/update/delete draft; complete; list/detail/PDF. Nested: detail hutang (cash/deposit sumber) + deposit_usages.

---

## Status & complete (ringkas)

```
draft → complete → completed
      → update/delete (draft only)
```

Complete (kode hari ini): cek draft **di luar** TX → TX → rekonsiliasi cash/deposit totals → **jika** usages>0 match deposit total → lock hutangs → validate sisa → lock deposits → `use` → `recordPayment` → status completed.

Piutang sudah: `lockForUpdate` header **di dalam** TX + re-check draft; **selalu** `abs(usagesSum − depositTotal)` meski usages kosong.

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-B1 | P0 | **Deposit free lunch:** detail `sumber=deposit` tanpa `deposit_usages` lolos Create (cek usages hanya jika array non-empty) dan Complete (match usages hanya jika `count()>0`) → hutang turun, deposit utuh. | Create ~56–75; Complete 61–69 vs 140–143; Piutang Complete 40–42 always match | Always `abs(usagesSum − totalDeposit)`; Create wajib usages bila deposit>0. |
| PH-B2 | P0 | **Double-complete race:** `isDraft()` di luar TX; header **tanpa** `lockForUpdate`. Dua complete paralel → dobel `recordPayment`/`use`. | Complete 24–38 vs Piutang 22–26 | Lock header + re-assert draft di dalam TX. |
| PH-B3 | P1 | Complete **tidak** re-cek `hutang.supplier_id` / `deposit.supplier_id` = header (Piutang cek owner). | Complete 81–88 | Reject mismatch supplier. |
| PH-B4 | P1 | Draft boleh alokasi hutang **paid** / over-sisa / over deposit sisa — baru gagal di complete; multi-draft oversubscribe. | Create no sisa check | Optional preflight Create; minimal dokumentasikan. |
| PH-B5 | P1 | Duplikat `hutang_id` / `deposit_id` diizinkan (Piutang tolak). | Create/Update loops | Reject duplicates. |
| PH-B6 | P2 | Doc number pakai `now()` bukan `tanggal` dokumen (Piutang pakai tanggal). | Create 28–32 | Pass `date: tanggal`. |
| PH-B7 | P3 | Tidak void setelah complete — by design (sama hutang register). | enum draft\|completed | Docs. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-S1 | P1 | Outstanding / available-deposits **leak nominal** tanpa `hutang.view_nominal` (hanya gate `create`). | Controller 356–415 | Strip atau require view_nominal. |
| PH-S2 | P1 | Helper outstanding/deposits hanya `create` — role `update` saja 403 di form edit. | Controller 358, 391; router edit `.update` | `create \|\| update`. |
| PH-S3 | P2 | SettlesCashPayment bypass HTTP perm — OK jika SoD approve; dokumentasikan. | SettlesCashPayment | Docs. |
| PH-S4 | P3 | Transfer tanpa wajib bank/ref. | validation | Optional tighten. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-C1 | P0 | = PH-B1/B2 | Complete/Create | Port Piutang |
| PH-C2 | P1 | Lock hutang/deposit tanpa `orderBy('id')` → deadlock risk. | Complete 75–78 | orderBy id (Piutang). |
| PH-C3 | P2 | N+1 find per row Create/Update. | Create 40–65 | whereIn batch |
| PH-C4 | P2 | Tidak ada `ValidatesHutangAllocations` (Piutang punya trait). | vs ValidatesPiutangAllocations | Extract mirror |
| PH-C5 | P2 | Tes P0 deposit-tanpa-usages & concurrent complete **hilang**. | CrudTest | Tambah |
| PH-C6 | P3 | Wide fillable includes status/completed_*. | Model | Guard |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-X1 | P1 | Outstanding/show eager **PO only** — PBS nomor blank (= SH-X1). | Controller 366, 205 | Eager `serialIntake`. |
| PH-X2 | P1 | Tidak ada deep-link ke Hutang/PO/PBS/Deposit dari list/form/detail; Hutang page belum CTA Bayar (= SH-X2). | FE | Links + CTA |
| PH-X3 | — | Cash settle PO/PBS → Create+Complete — OK path | SettlesCashPayment | — |
| PH-X4 | P2 | Deposit `use` soft `min()` mask bug validation. | SupplierDeposit 215 | Prefer hard fail |

### Tampilan / DRY / FE

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-U1 | P1 | Sumber dok hanya No. PO — PBS `-` | Form/Detail/PDF | Tampilkan PBS nomor + link |
| PH-U2 | P1 | Deposit InputNumber max = sisa hutang **bukan** sisa pool deposit; Auto Alokasi tidak auto on change | Form | Cap + auto allocate |
| PH-U3 | P1 | `confirmApprove` teks “Perubahan stok” — salah untuk pembayaran | useTransactionList 268–270 | Custom confirm message |
| PH-U4 | P1 | `activeFilterCount` hitung default bulan | Page | Ignore default range |
| PH-U5 | P2 | Totals hand-rolled; tidak `MoneySummaryPanel` | Form/Detail | Reuse optional |
| PH-U6 | P2 | DRY ≈ clone Pembayaran Piutang | twin pages | Extract later |
| PH-U7 | P2 | FE list tidak gate `hutang.view_nominal` (BE strip → Rp 0) | Page | Hide/gate kolom |
| PH-U8 | P3 | Metode Select tanpa filter; E2E shell only | Form; e2e | Polish |

### DB / query

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| PH-D1 | — | Index list withCount — OK | index | — |
| PH-D2 | P3 | Deposit pivot tanpa ULID | DocPembayaranHutangDeposit | YAGNI |
| PH-D3 | P2 | SoftDeletes tidak ada — hard delete draft | destroy | OK bila disengaja |

---

## Matriks aksi UI

| Aksi | Ada? | Gate |
|------|------|------|
| List / filter / detail / PDF | Ya | view |
| Create / Edit / Delete draft | Ya | create / update / delete |
| Complete | Ya (list + detail) | complete |
| Void / Cancel completed | Tidak | — |
| Outstanding picker + deposit alokasi | Ya | create (broken for update-only) |
| Deep-link hutang/PO/PBS | Tidak | — |

---

## Antrian patch (usulan)

1. **P0** PH-B1/B2 — port Piutang Complete lock + always-match deposit; Create wajib usages jika deposit>0.  
2. **P1** PH-B3/B5, PH-C2 orderBy; PH-S1/S2 strip + create\|update helpers.  
3. **P1** PH-X1/U1 serial eager + FE nomor/link; PH-U2/U3/U4.  
4. **P2** ValidatesHutangAllocations extract; N+1 batch; tes regresi P0.  
5. Cross SH deep-links dari Hutang → Bayar (audit 29).

---

## Tes terkait

| File | Coverage |
|------|----------|
| `PembayaranHutangCrudTest` | draft, complete cash/deposit, over-sisa, foreign supplier, data:verify |
| `UpdatePembayaranHutangActionTest` | replace details, guards |
| `PurchaseMoneyStripTest` | index/show strip |
| PO/PBS cash settle | SettlesCashPayment path |

**Tipis:** deposit tanpa usages complete; double-complete; outstanding strip; wrong-supplier complete; duplicate ids; destroy completed HTTP.

---

## Ringkasan

Happy path OK; **P0** sama kelas yang sudah diperbaiki di Piutang: **race complete** + **deposit tanpa usages**. Cross PBS/eager/links ikut hutang #29. Port pola Piutang = shortest fix.

**Gabung plan #27–#29.** Fix hanya jika **execute**. Berikut: Retur Pembelian.
