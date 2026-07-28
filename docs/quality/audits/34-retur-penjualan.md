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

## Sisa P1

hpp strip show; returnable create\|update; view_harga FE; picker search; serial expand; void-block draft sales; master rules BO.
