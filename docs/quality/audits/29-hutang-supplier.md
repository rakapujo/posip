# Audit menu — 29 Pembelian → Hutang Supplier

> **Status:** audit complete (belum patch; 2026-07-24)  
> **SSoT kode:**  
> - FE: `syilex-frontend/src/views/pembelian/SupplierHutangPage.vue` · `api/modules/supplierHutangs.js`  
> - BE: `syilex/app/Http/Controllers/Api/V1/SupplierHutangController.php` · `Models/SupplierHutang.php` · `Exports/SupplierHutangExport.php`  
> - Writers (bukan menu ini): `ApprovePurchaseOrderAction` · `ApproveSerialIntakeAction` · `CompletePembayaranHutangAction` · `SettlesCashPayment` · `ApprovePurchaseReturnAction` (`recordPayment` / `recordReturnCredit`)  
> - Twin FE: `CustomerPiutangPage.vue` (clone hampir 1:1)  
> - Routes FE: `pembelian-hutang` · API: `/supplier-hutangs*` (`api.php` 535–541)  
> - Menu: `AppMenu.vue` Pembelian → Hutang Supplier (setelah PBS; sebelum Pembayaran Hutang)  
> - Tes: `tests/Feature/Enhancements/HutangAgingTest.php` · `PembelianAccessCoverageTest` · partial PO/PBS/retur/pembayaran  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Pembelian Serial di AppMenu.

## Scope

**Register/ledger read-only.** Tidak ada CRUD hutang di API ini. Baris lahir dari approve PO/PBS; berkurang via pembayaran complete / retur credit / cash settle. UI: list + summary + aging buckets + detail dialog + Excel/PDF export.

| Endpoint | Permission | Dipakai |
|----------|------------|---------|
| `GET /` | `hutang.view` (+ strip nominal tanpa `view_nominal`) | List |
| `GET /summary` | `hutang.view` (totals null tanpa nominal) | MoneySummaryPanel |
| `GET /aging-summary` | `view` **+** `view_nominal` | AgingBucketPanel |
| `GET /by-supplier` | `hutang.view` | **API orphan di halaman ini** (pembayaran pakai outstanding lain) |
| `GET /export` | **`laporan.export` saja** (bukan `hutang.view`) | Excel |
| `GET /{ulid}` | `hutang.view` (+ strip) | Detail |
| FE / AppMenu | `hutang.view` | |

**CRUD di halaman:** view/filter/sort/paginate/export/detail. **Tidak:** create/edit/delete/approve hutang; bayar (itu menu Pembayaran).

---

## Lifecycle (ringkas)

```
Approve PO/PBS → SupplierHutang (unpaid)
  → SettlesCashPayment (opsional) → paid
  → CompletePembayaran → recordPayment → partial|paid
  → Approve Retur → recordReturnCredit → partial|paid
sisa = max(0, awal − terbayar − retur)
```

---

## Temuan

### Logika bisnis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-B1 | P1 | **Search tidak mencakup nomor PBS** — `scopeSearch` hanya PO + nama supplier. | Model ~191–200 | `orWhereHas('serialIntake', …)` |
| SH-B2 | P1 | **Aging click FE tidak memfilter list** — kontrak panel “click → filter”; BE index tanpa `aging_bucket`. Twin Piutang sama. | Page selectAgingBucket; AgingBucketPanel | Wire filter (due/overdue params) **atau** non-interactive buckets. |
| SH-B3 | P2 | PO selalu buat hutang; PBS skip jika GT≤0 / tanpa supplier — asymmetry. | Approve PO vs PBS | Dokumentasikan / samakan. |
| SH-B4 | P2 | Aging pakai `sisa>0`; summary pakai status scopes; Dashboard `status!=paid` — 3 definisi outstanding. | Controller aging/summary; Dashboard | Satu helper “outstanding”. |
| SH-B5 | P2 | `VerifyDataInvariants` hutang **tidak** assert `terbayar === SUM(payments)` (piutang lebih ketat). | VerifyDataInvariants | Samakan parity. |
| SH-B6 | P3 | Zero-total PO tetap buat baris unpaid 0. | Approve PO | OK / skip. |

### Keamanan

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-S1 | P0 | **`nominal_retur` tidak di-strip** tanpa `hutang.view_nominal` (index/show/bySupplier). Gudang lihat kredit retur. | Controller 92–95, 136–137 | `makeHidden` include `nominal_retur`. |
| SH-S2 | P1 | **Export hanya `laporan.export`** — tanpa `hutang.view` bisa unduh register (nominal tetap gated view_nominal). | Controller export ~290 | Require `hutang.view` (+ export). |
| SH-S3 | P3 | `$hidden` model tidak include `serial_intake_id` (id/po_id hidden). | Model 47–51 | Hide serial_intake_id. |

### Kode

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-C1 | P1 | = SH-S1 strip retur | Controller | Fix |
| SH-C2 | P2 | `sort_order` tidak di-whitelist asc/desc (piutang coerce). | Controller 75–79 | Coerce. |
| SH-C3 | P2 | Tidak ada suite `SupplierHutang*Test` untuk search-serial / strip retur / export gate. | tests | Tambah |
| SH-C4 | P3 | Aging load all outstanding ke PHP — scale. | agingSummary | SQL CASE bila perlu. |

### Cross-modul

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-X1 | P1 | **`getOutstandingHutangs` / bySupplier / show pembayaran** eager **PO only** — nomor PBS blank di picker pembayaran. Export map sudah handle Serial. | PembayaranHutangController outstanding; bySupplier | Eager `serialIntake`. |
| SH-X2 | P1 | Detail hutang **tidak link** ke PO/PBS/Pembayaran meski ULID ada di payload. | FE detail footer Tutup only | CTA Bayar + buka dokumen. |
| SH-X3 | P2 | `by-supplier` API tidak dipakai halaman Hutang. | api module vs page | Pakai atau dokumentasikan untuk pembayaran saja. |
| SH-X4 | — | Writers locked di approve/complete — OK | Actions | — |

### Tampilan / DRY / FE

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-U1 | P1 | = SH-B2 aging click kosmetik | Page | Wire / disable |
| SH-U2 | P1 | Supplier change **tidak** `loadAging` | onFilterChange | Panggil loadAging |
| SH-U3 | P1 | Summary/aging **abaikan** date/status/due list filters → kartu ≠ tabel | summary/aging params | Align atau label “semua outstanding” |
| SH-U4 | P1 | `activeFilterCount` hitung default bulan → badge selalu ≥2 | Page | Jangan hitung default range |
| SH-U5 | P1 | Export PDF/Excel **tanpa kolom Retur** (list punya) | Export + PDF builder | Tambah retur jika view_nominal |
| SH-U6 | P2 | DRY ≈ clone `CustomerPiutangPage` | kedua vue | Extract AR/AP list composable (nanti) |
| SH-U7 | P2 | Tag Sumber fallback “Serial” jika bukan PO | Page | `-` |
| SH-U8 | P2 | Select due/overdue tanpa `filter`; overdue row tanpa dark | Page | Polish |
| SH-U9 | P3 | Search placeholder hanya “PO” | Page | + PBS |
| SH-U10 | P3 | E2E shell only | docs-helpers | Optional smoke |

### DB / query

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| SH-D1 | P2 | Tidak UNIQUE `po_id` / `serial_intake_id` — double hutang mungkin jika insert bypass. | migration | Unique nullable |
| SH-D2 | P2 | Tidak XOR po vs serial di DB | migration | App assert / check |
| SH-D3 | P2 | Index `tanggal` hilang (default sort) | create migration | Tambah index |
| SH-D4 | — | List eager PO+serial — anti-N+1 OK | index with | — |

---

## Matriks aksi UI

| Aksi | Ada? |
|------|------|
| List / filter / sort / paginate | Ya |
| Summary + Aging | Ya (aging click broken) |
| Detail dialog | Ya (tanpa deep-link) |
| Excel / PDF | Ya |
| by-supplier view | API only |
| Bayar / buat hutang | Tidak (menu lain) |

---

## Antrian patch (usulan)

1. **P0** SH-S1 — strip `nominal_retur`.  
2. **P1** SH-B1 search PBS; SH-X1 eager serial di pembayaran/bySupplier; SH-S2 export + `hutang.view`.  
3. **P1** FE: aging wire/disable, loadAging on filter, badge count, deep-links Bayar+dokumen, export Retur.  
4. **P2** unique FK, index tanggal, verify parity, Tag fallback, DRY later.  

---

## Ringkasan

Register hutang **benar read-only**; mutasi hanya lewat PO/PBS/pembayaran/retur. Gap dalam: **leak `nominal_retur`**, **search/eager PBS**, **aging UI dust**, **export gate**, **tidak ada menu-lanjutan** ke pembayaran/dokumen.

**Gabung plan #27+#28.** Fix hanya jika **execute**. Berikut AppMenu: Pembayaran Hutang.
