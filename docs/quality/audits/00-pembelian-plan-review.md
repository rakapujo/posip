# QA Review — Plan Pembelian #27–#32

> **Tanggal:** 2026-07-25 (executed)  
> **Plan:** `fix_purchase_order_0a78035b.plan.md`  
> **Status:** **EXECUTED** (P0 + P1 core)  
> **Jika konflik:** ikuti kode.

## Patched (ringkas)

| Area | Fix |
|------|-----|
| PO/PBS | Update/destroy `lockForUpdate` + re-assert draft; PBS forceDelete in TX |
| PH | Complete port Piutang (header lock, always-match deposit, ownership, orderBy id); Create/Update wajib usages + Q5 over-bayar |
| Hutang | strip `nominal_retur`; export `hutang.view`+`laporan.export`; search PBS; aging wire FE |
| Retur | Lock/Approve header lock + re-check; `po_detail_id` ownership; FE Approve/_uid |
| Deposit | Excel SoD; by-supplier hasBalance; canBeEdited unused+no pivot; CTA Tambah deposit; reset-on-supplier; FE nominal |
| Q3/Q4 | qty integer; skip hutang bila GT≤0 |
| PBS | HistoryHargaBeli + serial_intake_id migration |

## Sisa P2 (YAGNI / later)

Unique retur_id / pivot unique; DRY twin deposit; E2E penuh; aging bucket exact BE filter; unit_konversi resolve master (jika belum penuh).

## Berikutnya

Penjualan AppMenu.
