# Audit menu — 32 Pembelian → Deposit Supplier

> **Status:** audit complete (belum patch; 2026-07-25)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/pembelian/SupplierDepositPage.vue` · `api/modules/supplierDeposits.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/SupplierDepositController.php` · `Models/SupplierDeposit.php` · `Exports/SupplierDepositExport.php` · `Models/DocPembayaranHutangDeposit.php`  
> - Creator auto: `ApprovePurchaseReturnAction` (excess → deposit)  
> - Consumer: `Create|Update|CompletePembayaranHutangAction` → `SupplierDeposit::use`  
> - Twin: `CustomerDeposit*` (edit stricter; complete ownership; lock update/destroy)  
> - Routes FE: `/app/pembelian/deposit` · API: `/api/v1/supplier-deposits*` (`api.php` ~564–573)  
> - Menu: `AppMenu.vue` Pembelian → Deposit Supplier (`deposit-supplier.view`)  
> - Tes: `tests/Feature/Pembelian/SupplierDepositCrudTest.php` · `Enhancements/DepositUsageTest.php` · `PurchaseMoneyStripTest` (index only)  
> - Cross: audit [30-pembayaran-hutang.md](30-pembayaran-hutang.md) PH-B1/B2/S1 · [31-retur-pembelian.md](31-retur-pembelian.md) approve excess  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Retur Pembelian; AppMenu berikutnya = Penjualan (atau sisa Pembelian jika ada).

## Scope

Register **deposit supplier**: manual CRUD + auto dari retur approve (excess). Pemakaian hanya lewat Pembayaran Hutang (draft pivot → Complete `use`). **Tidak:** void/refund deposit setelah terpakai; aging due-date (N/A).

| Endpoint | Permission | Nominal gate | Dipakai |
|----------|------------|--------------|---------|
| `GET /` | `deposit-supplier.view` | strip tanpa `hutang.view_nominal` | List |
| `GET /summary` | `view` | sums → null | Cards |
| `GET /by-supplier` | `view` | **tidak strip** | API orphan (FE PH pakai endpoint lain) |
| `GET /export` | **`laporan.export` saja** | **tidak strip** | Excel |
| `POST /` | `create` | response full | Manual |
| `GET /{ulid}` | `view` | strip + PR nilai_* | Detail |
| `GET /{ulid}/usage` | `view` | strip | Tab pemakaian (incl. draft PH) |
| `PUT /{ulid}` | `update` | full | Manual only |
| `DELETE /{ulid}` | `delete` | — | Manual + `terpakai==0` |
| PH `GET …/available-deposits` | `pembayaran-hutang.create` | **tidak strip** | Form PH |
| Seeded | admin full; **gudang: view only**; kasir: none | SoD via `hutang.view_nominal` | |

**CRUD nested:** tidak ada child line di deposit; nested = usage history (read). Form: supplier, tanggal, nominal, no_ref, keterangan.

```
manual create → available
retur approve excess → available (retur_id set, immutable)
draft PH → pivot usages (sisa belum turun)
complete PH → lock deposit → use() → used_partial|used_all
```

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-B1 | P0 | **= PH-B1** Deposit free-lunch di Complete/Create PH — hutang turun, deposit utuh. Integrity deposit hidup di boundary PH. | `CompletePembayaranHutangAction` 61–69; Create 56–75 | Port Piutang always-match (sudah di plan #30). |
| SD-B2 | P0 | **= PH-B2** Double-complete PH → dobel `use`/`recordPayment`. | Complete 24–38 | Header lock (plan #30). |
| SD-B3 | P1 | `/by-supplier` pakai `hasBalance()` **+** `available()` → deposit `used_partial` (sisa>0) **hilang** dari picker API. | Controller 394–400; Model `scopeAvailable` = status only | Drop `available()`; mirror PH `getAvailableDeposits`. |
| SD-B4 | P1 | Manual deposit **bisa diedit** meski `nominal_terpakai>0` (naik/turun awal; ganti `supplier_id`). Customer: edit hanya jika unused. | Controller 182–215; `canBeEdited()` = `isManual()` only; Customer 99–101 | **Keputusan produk** — default: align Customer + blok ganti supplier jika ada usage/draft. |
| SD-B5 | P1 | Delete cek hanya `terpakai==0`; **abaikan** draft PH pivot → FK RESTRICT 500 atau inkonsistensi. | destroy 258–263 | Block jika ada row `doc_pembayaran_hutang_deposit`. |
| SD-B6 | P1 | Multi-draft PH bisa oversubscribe sisa yang sama; gagal baru di Complete. | Create PH no sisa reserve | Preflight under lock **atau** blok over-bayar Create (Q5 plan). |
| SD-B7 | P1 | `summary.available_count` hitung `status=available` saja — undercount partial ber-sisa. Customer pakai `sisa>0`. | Controller 368 | `hasBalance()` / `sisa_deposit>0`. |
| SD-B8 | P1 | Store/update terima supplier **inactive**. Customer cek `active()`. | validate exists only | `SupplierRules` / active. |
| SD-B9 | P2 | Auto deposit retur: **tanpa `created_by`**; **tanpa UNIQUE(`retur_id`)**. | ApprovePurchaseReturnAction 84–94 | Set `created_by`; unique nullable. |
| SD-B10 | P2 | `use()` soft `min()` — bisa mask mismatch validation. | Model 213–215 | Hard fail jika amount > sisa+ε. |
| SD-B11 | — | Retur-linked immutable edit/delete | `canBeEdited` / `isManual` | BY DESIGN |
| SD-B12 | — | Usage history include draft PH | DepositUsageTest | BY DESIGN |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-S1 | P0 | Excel export: gate **`laporan.export` only** + **full nominal** (tanpa strip `hutang.view_nominal`, tanpa `deposit-supplier.view`). | Controller 271–286; Export raw; FE 26,459–477 | Require `deposit-supplier.view` (+ `hutang.view_nominal` atau strip kolom uang). |
| SD-S2 | P1 | `/by-supplier` + PH `available-deposits` leak nominal tanpa `view_nominal`. | Controller 384–408; PH ~389 | Strip atau require nominal. |
| SD-S3 | P1 | Complete PH **tidak** re-cek `deposit.supplier_id` = header (= PH-B3). | Complete 117–130 | Port Piutang ownership. |
| SD-S4 | P2 | store/update response selalu full nominal (creator tanpa `view_nominal`). | Controller 161–164 | Strip seperti show. |
| SD-S5 | — | Index/show/usage/summary strip OK | Controller | — |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-C1 | P1 | update/destroy **tanpa** `lockForUpdate` (race vs Complete `use`). Customer lock di TX. | Controller 176–263 | TX + lock row. |
| SD-C2 | P2 | N+1 `SupplierDeposit::find` per usage di Create/Update PH. | Create PH loops | `whereIn` batch. |
| SD-C3 | P2 | Duplikat `deposit_id` di usages diizinkan; pivot tanpa unique `(pembayaran_id, deposit_id)`. | Create PH; migration | Reject + unique. |
| SD-C4 | P2 | `$timestamps=false`; `use()` tidak bump `updated_at`; no `HasAuditLog` (Customer punya). | Model | Align twin ringan. |
| SD-C5 | P2 | `sort_order` tidak di-whitelist. | Controller 54–59 | `asc\|desc`. |
| SD-C6 | P2 | Tes gap: free-lunch, double-complete, by-supplier filter, export perm, delete+draft pivot, inactive supplier. | CrudTest / PH tests | Tambah. |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-X1 | P0/P1 | Integrity deposit = PH Complete (SD-B1/B2/S3) — jangan patch hanya di controller deposit. | audit 30 | Satu fix di PH Actions. |
| SD-X2 | P1 | Retur detail tampil deposit block **tanpa** link ke register deposit; usage punya `pembayaran_ulid` **tidak** di-link FE. | PurchaseReturnPage; Deposit usage tab | Deep-link. |
| SD-X3 | P1 | Hutang page **tidak** mention deposit; PH form OK via available-deposits. | Hutang FE | Optional CTA. |
| SD-X4 | P2 | `data:verify` cek customer deposit balance, **bukan** supplier. | VerifyDataInvariants | Mirror. |
| SD-X5 | P2 | `/by-supplier` orphan vs PH `available-deposits` — duplikat + filter salah. | FE tidak panggil bySupplier | Fix atau deprecate. |

### Tampilan / FE / DRY

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-U1 | P1 | **Tidak ada** FE `canViewNominal` — Hutang sudah hide kolom/summary; Deposit selalu render uang + PDF; `\|\| 0` → fake **Rp 0** saat BE null. | Page 22–26, 42–48 vs Hutang | Mirror Hutang gate. |
| SD-U2 | P1 | Date Clear/Today (`showButtonBar`) **tidak** refetch — hanya `@date-select`. | Page 505–508 | `@update:model-value`. |
| SD-U3 | P1 | `activeFilterCount` selalu ≥2 (default dates); `hasBalanceOnly` tidak dihitung. | Page 56–57, 97–104 | Parity Hutang fix pattern. |
| SD-U4 | P1 | Summary hanya filter `supplier_id` — cards ≠ list (dates/status/balance). | loadSummary 160–165 | Pass filter yang sama / dokumentasikan. |
| SD-U5 | P2 | Custom tab buttons + raw `<table>` usage (bukan Tabs/DataTable); Badge usage 0 sampai tab load. | Page 636–691 | Prime Tabs + DetailTable; optional count di show. |
| SD-U6 | P2 | Status filter EN vs label ID; InputNumber tanpa max; empty CTA lemah; confirm delete tutup detail dulu. | Page | Polish. |
| SD-U7 | P2 | Clone ~840 baris vs `CustomerDepositPage` — DRY later (YAGNI extract sampai Customer juga dapat Excel+strip). | twin | Tunda extract. |
| SD-U8 | — | Reuse MoneySummaryPanel / ListFiltersSheet / RowActionButtons — OK | Page imports | — |
| SD-U9 | — | Aging N/A (tidak ada due date) | — | BY DESIGN jangan paksa parity Hutang |

### Database / optimize

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SD-D1 | P2 | UNIQUE nullable `retur_id` hilang | migration | Tambah |
| SD-D2 | P2 | Unique `(pembayaran_id, deposit_id)` hilang | pivot migration | Tambah |
| SD-D3 | P1 | SQLite: ALTER nullable `retur_id` skip → test invent dummy PR | migration 210001 | Document / SQLite-safe |
| SD-D4 | — | Index `(supplier_id,status)`, list eager supplier+PR — anti-N+1 OK | index | — |

---

## Matriks aksi UI

| Aksi | Ada? |
|------|------|
| List / filter / sort / paginate | Ya |
| Summary cards | Ya (filter sempit; nominal FE leak UX) |
| Detail + tab pemakaian | Ya |
| Create / edit / delete manual | Ya (retur locked) |
| Excel / PDF | Ya (Excel SoD lemah) |
| Aging | Tidak (N/A) |
| Deep-link Retur / PH | Tidak |
| Menu lanjutan nested | Usage tab saja |

---

## Keputusan produk (deposit)

| ID | Pertanyaan | Keputusan |
|----|------------|-----------|
| SD-Q1 | Edit setelah terpakai? | **C** — tidak edit nominal; CTA “Tambah deposit” di detail |
| SD-Q2 | Ganti supplier jika tersentuh? | **A** — Tidak |
| SD-Q3 | Aging idle? | **Tidak** |
| SD-Q4 | Keep `/by-supplier`? | **Fix filter** |
| SD-Q5 | Export SoD | **A** — view + laporan.export + nominal gate |
| Reset | Ganti supplier (bersih) | Confirm → clear nominal/no_ref/keterangan; tanggal tetap |

---

## Antrian patch (usulan)

1. **P0 (shared PH):** SD-B1/B2 = PH Complete lock + always-match + ownership (jangan duplikat kerja).  
2. **P0 Deposit:** SD-S1 Excel gate + strip/nominal.  
3. **P1 BE:** by-supplier drop `available()`; edit/delete rules; lock update/destroy; summary count; active supplier; draft-pivot delete guard.  
4. **P1 FE:** `canViewNominal`; date refetch; badge/filter; summary filter parity; deep-links usage→PH, retur→deposit.  
5. **P2:** unique retur_id / pivot; `created_by` auto; verify invariant; soft-cap `use`; DRY twin later.

---

## Ringkasan

Modul deposit **CRUD manual + auto retur** cukup jelas; risiko uang utama di **boundary Pembayaran Hutang** (sudah diaudit #30). Gap khas deposit: **Excel SoD**, **by-supplier filter partial**, **edit longgar vs Customer**, **FE tanpa gate nominal**, UX filter/summary, deep-link lemah.

**Gabung plan** `fix_purchase_order_0a78035b` sebagai **Bagagian F (#32)**. Fix hanya jika **execute**.
