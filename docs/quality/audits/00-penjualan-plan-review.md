# QA Review — Plan Penjualan #33–#38 + POS Shift

> **Tanggal:** 2026-07-25  
> **Plan:** `audit_penjualan_sales_7133f9c0.plan.md`  
> **Status:** **EXECUTED Wave A + B + C**  
> **Jika konflik:** ikuti kode.

## Keputusan produk (dikunci)

| ID | Jawaban |
|----|--------|
| Q1 | Rebuild promo saat simpan+approve + banner |
| Q2 | Nilai diakui retur ≤ kalkulasi |
| Q3 | Blok void jika shift sudah tutup |
| Q4 | Laporan shift tutup: `terminal.view`; buka: owner/force-release |
| Q5 | Elektronik OFF: jual serial diblok; retur SN tetap boleh |

## Wave A (P0)

Sales · Retur · Piutang · Bayar · Deposit · Shift — lihat riwayat plan.

## Wave B (P1 core)

Aging, export gates, Q5 bayar, hard-fail use, UNIQUE retur_id, deep-links, shift scope, view_harga form, dll.

## Wave C (polish) — executed

| Item | Fix |
|------|-----|
| Deposit Tabs | PrimeVue Tabs + DataTable pemakaian |
| Deposit Excel | SoD export |
| Filter badge | Skip default dates |
| E2E | Smoke deposit |
| **PHPUnit ketat** | Access matrix penuh + `PenjualanEndpointStrictTest` + `PosShiftEndpointStrictTest` |
| **Walk-in BO** | FE `jenis=spesifik` + BE `CustomerRules::backofficeBlockMessage` (Sales/Retur/Deposit/Bayar) |

## PHPUnit suite (endpoint-strict)

| File | Isi |
|------|-----|
| `PenjualanAccessCoverageTest` | 403 tiap endpoint Sales/Retur/Piutang/Bayar/Deposit |
| `PenjualanEndpointStrictTest` | Konversi master, hpp strip, serial OFF, nilai_diakui cap, Q5 overpay, cash+deposit, destroy, deposit pivot/use |
| `PosShiftEndpointStrictTest` | Cash IDOR, void after close, report ACL, list scope |
| `PosAccessCoverageTest` | + cash/shift-report/shifts index |
| `CustomerArBackendTest` | Q5 overpay di create; export/strip |

Jalankan: `php artisan test --filter="PenjualanAccessCoverageTest\|PenjualanEndpointStrictTest\|PosShiftEndpointStrictTest\|PosAccessCoverageTest\|CustomerArBackendTest"`

## Defer (YAGNI)

Full DRY form/AR extract · multi-draft reserve · rewrite kasir UI.
