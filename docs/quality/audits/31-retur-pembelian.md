# Audit menu — 31 Pembelian → Retur Pembelian

> **Status:** audit complete (belum patch; 2026-07-24)  
> **SSoT kode:**  
> - BE: `PurchaseReturnController.php` · `Actions/PurchaseReturn/{Create,Update,Lock,Approve}*` · Concerns `ValidatesPoHeaderMatch` / `ValidatesSerialIntakeHeaderMatch` / `PreparesSerialReturnDetails` · `PurchaseReturnCalculationService` · `SupplierHutang::recordReturnCredit`  
> - Routes FE: `pembelian-retur*` · API: `/purchase-returns*` (`api.php` 545–560)  
> - Menu: `AppMenu.vue` Pembelian → Retur Pembelian (`retur-beli.view`) — setelah Pembayaran Hutang, sebelum Deposit Supplier  
> - Twin: Sales Return (lock/approve pola lebih aman); DRY sibling: `PurchaseOrderFormPage.vue`  
> - Tes: `PurchaseReturnCrudTest` · `PurchaseReturnPoLinkedTest` · `PurchaseReturnSerialIntakeLinkedTest` · `SerialPurchaseReturnTest`  
> - E2E FE: **tidak ada** flow; hanya `docs-helpers` route screenshot  
> **Jika konflik:** ikuti kode.  
> **Urutan:** setelah Pembayaran Hutang.

## Status machine (BE)

```
draft ──lock──► lock ──approve──► approved
         (stok OUT / serial retur)   (hutang credit + deposit excess)
```

Lock = barang ke vendor (stok−, kartu `PURCHASE_RETURN`). Approve = uang (net hutang PO/PBS/FIFO, sisa → deposit). **Tidak** void/unlock. HPP agregat **tidak** weighted-recalc pada lock (parity §2B; serial card pakai avg `cost_per_unit` unit).

---

## Temuan BE (tambahan / race — prioritas tinggi)

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RB-BE1 | P0 | **Double-approve race:** `canApprove()` di luar TX; setelah `lockForUpdate` header **tidak** re-check status → kredit hutang + deposit dobel. Sales Return re-check di dalam lock. | `ApprovePurchaseReturnAction` 24–28 vs 38–95 | Re-assert `canApprove()` / status=`lock` setelah lock header. |
| RB-BE2 | P0 | **Double-lock race:** header retur **tidak** `lockForUpdate` di awal Lock — dua lock paralel bisa sama-sama OUT stok. | `LockPurchaseReturnAction` 35–41 | Lock header + re-check `canLock` di dalam TX (mirror Sales). |
| RB-BE3 | P0 | **`po_detail_id` trust:** tidak assert milik `po_id` header; free retur bisa bawa `po_detail_id` asing dan makan returnable PO lain. Line PO-linked tanpa `po_detail_id` bypass cap qty. | Create store detail; Lock 60–99 | Wajib `po_detail_id ∈ po` bila `po_id` set; tolak foreign detail. |
| RB-BE4 | P1 | Validasi stok per baris vs qty **awal**, bukan running — PCS+BOX produk sama bisa lolos lalu overdraw. | Lock 120–128 vs runningStocks | Validasi terhadap running. |
| RB-BE5 | P1 | Update vs lock: update tidak lock header → race recreate details. | Update Action; Lock | Lock draft header di Update. |
| RB-BE6 | P1 | Returnable/last-price endpoints leak harga tanpa `po.view_harga`. | Controller returnable ~782; last-price | Strip. |
| RB-BE7 | P1 | Tidak ada tes `purchase_allow_free=false`; concurrent lock/approve. | tests | Tambah. |
| RB-BE8 | P2 | `(int) qty_in_base` truncation; tax/stock-setting ungated; calculate hanya `create`. | Lock; Controller | Samakan pola PO/PBS. |

---

## Temuan FE (dari audit UI)

Severity: **P0** harus · **P1** kuat · **P2** perbaikan · **P3** polish.

### Logika bisnis / UX kritis

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RB-B1 | P0 | **Detail footer Approve rusak & selalu tampil.** Tanpa `v-if="canApprove && status==='lock'"`, `@click="submitApprove"` (bukan `openApproveDialog`). Twin Sales Return sudah benar. | `PurchaseReturnPage.vue` **716** vs `SalesReturnPage.vue` **704** | Mirror Sales: `v-if` + `openApproveDialog(detailData)`. |
| RB-B2 | P0 | **Prefill PO tanpa `_uid` sementara DataTable `dataKey="_uid"`.** | Form `loadReturnableDetails` **411–433**; DataTable **1164** | Map `_uid: nextUid()`. |
| RB-B3 | P1 | Mode bebas OFF + tanpa `showClear` → terjebak setelah PO/PBS habis. | Form Select **1079** | Clear/ganti referensi. |
| RB-B4 | P1 | Tidak preview hutang/deposit sebelum approve. | Approve dialog | Hint sisa hutang opsional. |
| RB-B5 | P2 | PBS prefill semua unit tercentang. | Form **349–376** | Default kosong / confirm. |
| RB-B6 | P2 | Ganti PO/PBS wipe details termasuk free lines. | watch docRefKey | Confirm. |

### Keamanan / permission

| ID | Sev | Temuan | Bukti | Usulan |
|----|-----|--------|-------|--------|
| RB-S1 | P1 | Form tidak gate `po.view_harga` (gudang create tanpa view_harga). | Form; Seeder 135 | Gate field. |
| RB-S2 | P1 | helpers products/last-price/calculate/returnable = **create** only. | Controller | `create\|\|update`. |
| RB-S3 | P2 | `getStockSetting` dead di FE; BE tanpa can(). | api module | Pakai atau hapus. |
| RB-S4 | P2 | List/PDF tanpa FE gate view_harga. | Page | `v-if`. |

### Cross / DRY / FE polish

| ID | Sev | Temuan | Usulan |
|----|-----|--------|--------|
| RB-X1 | P1 | PO list picker limit 100 | Search/paginate |
| RB-X2 | P2 | Detail/PDF Ref PO only — PBS nomor hilang | Tampilkan PBS |
| RB-X3 | P2 | Deposit card tanpa link Deposit Supplier | Link |
| RB-U1 | P1 | DRY besar vs Sales/PO form | Extract later |
| RB-U5/U6 | P2 | Filter badge + DatePicker clear | Shared fix |
| RB-T1 | P2 | Tidak ada E2E flow | Optional smoke |

---

## Antrian patch (usulan prioritas)

1. **P0 BE** RB-BE1/BE2 — lock header + re-check di Lock & Approve (port Sales Return).  
2. **P0 BE** RB-BE3 — bind `po_detail_id` ke `po_id`.  
3. **P0 FE** RB-B1/B2 — detail Approve + `_uid` PO prefill.  
4. **P1** RB-BE4/BE5/BE6, RB-S1/S2, RB-B3, RB-X1.  
5. **P2** PBS detail nomor, deposit link, free-mode tes, E2E smoke.

---

## Tes terkait

| File | Coverage / gap |
|------|----------------|
| `PurchaseReturnCrudTest` | free lock/approve sequential |
| `PurchaseReturnPoLinkedTest` | returnable qty — **tanpa** approve hutang / cross po_detail |
| `PurchaseReturnSerialIntakeLinkedTest` | PBS hutang |
| `SerialPurchaseReturnTest` | serial OUT / HPP |
| **Missing** | concurrent lock/approve; free-mode OFF; po_detail foreign |

---

## Ringkasan eksekutif

Alur draft→lock→approve **benar secara sequential**, tapi **race Lock/Approve** lebih lemah dari Sales Return; **`po_detail_id` bisa disalahgunakan**; FE detail Approve + PO `_uid` rusak. Port pola Sales + fix FE kecil = shortest path.

**Gabung plan #27–#30.** Fix hanya jika **execute**. Berikut: Deposit Supplier.
