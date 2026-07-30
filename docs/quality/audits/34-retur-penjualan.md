# Audit menu — 34 Penjualan → Retur Penjualan

> **Status:** patched Wave A P0 (2026-07-25); P1 residual di plan  
> **Review:** [00-penjualan-plan-review.md](00-penjualan-plan-review.md)  
> **SSoT:** `BackofficeSalesReturnController` · `ProcessSalesReturnAction` · `SalesReturnPage` / `SalesReturnFormPage`  
> **Jika konflik:** ikuti kode.

## Patched P0

| ID | Fix |
|----|-----|
| RJ-C1/C2 | POS force `product_id` + `warehouse_id` dari nota |
| RJ-S1 Q2=A | Cap `nilai_diakui ≤ grand_total` |
| RJ-X1 | Lock confirm: stok dikembalikan |
| RJ-X2 | Index filter customer/WH |
| Q5=A | Retur serial tetap boleh saat elektronik OFF |
| WI-1 | Walk-in dikecualikan picker FE + BE free-return `CustomerRules::backofficeBlockMessage` |

## Patched — SN di struk retur (2026-07-28)

POS struk jual (ESC/PDF) + StrukOnline menampilkan KI/SN di riwayat retur; `SalesReturnController@store` attach `serial_units`; ESC `_wrap` pecah token panjang. Wire cetak `buildReturReceipt` deferred. Lihat [40-pos-kasir.md](40-pos-kasir.md).

## Patched — picker re-render row (2026-07-29)

`SalesReturnFormPage` `applyPickerSelect` sekarang replace `details[index]` (bukan mutate object lama) supaya input inline sinkron saat pilih produk/satuan dari picker.

## Patched — returnable products search terpadu (2026-07-29)

- Tambah endpoint `sales-returns/returnable-products` untuk mode retur bebas.
- Scope hasil dibatasi ke produk yang benar-benar pernah terjual (customer + warehouse + source manual + masih returnable).
- Keyword mendukung `kode_produk`, `nama_produk`, `barcode`, `kode_internal`, `serial_number`.
- Untuk serial: KI/SN dipakai untuk menemukan produk terjual; pemilihan unit final tetap di `SerialUnitPicker`.

## Patched — gap close P0/P1 (2026-07-29)

| Item | Fix |
|------|-----|
| Free sold-only | `validateFree` enforce terjual customer+WH+manual (+ draft reserve) di create/update/lock |
| Concurrent draft | `validateReturnable` SUM status `draft\|lock\|approved` |
| Free konversi | `calculateFree` `qty_base = qty × konversi` master |
| PDF/detail | FE remap `unit`/`qty`/`harga_satuan`/`jumlah`; show attach `serial_units` |
| Q5 SN OFF | `GET serial-units/available` di luar `feature.elektronik`; FE `allowWhenDisabled` |
| available terjual | filter `sale.source=manual` saat filter customer |

**Export:** PDF dokumen ada; Excel list **tidak** (by design, seperti Retur Beli).

## Patched — flag histori mode bebas (2026-07-29)

| Setting | Default | Efek |
|---------|---------|------|
| `returns.sales_free_require_sold` | true | Free non-serial wajib pernah terjual; serial selalu SN terjual |
| (pair beli) | lihat audit 31 | |

## Patched — picker mode + harga serial (2026-07-29)

| Item | Fix |
|------|-----|
| `modeHint` di drawer | Mirror Message mode bebas/dokumen ke `ProductUnitPickerDrawer` |
| Serial returnable Rp.0 | `returnableProducts` AVG `su.harga_jual` + fallback master `harga_4..1` |
| FE resolve | Shared `resolveSerialPickerPrice` |

## Sisa residual

view_harga FE polish; void-block draft sales messaging; approve-confirm copy dust.
