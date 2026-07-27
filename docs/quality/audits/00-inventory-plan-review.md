# Review QA — Inventory plan #17–#26

> **Status:** review complete + **executed** (2026-07-24)  
> **SSoT:** kode + audit `17`–`26` + plan `fix_inventory_stok_01972f4e.plan.md`  
> **Execute:** P0+P1 + review deltas (HM-M1, KS-M1, TR-B5b/`?->`, AD skip-card, dll.). Tes filter inventory: **158 passed**.  
> **Jika konflik:** ikut kode.

## Ringkasan

| Bucket | Hasil |
|--------|--------|
| P0 fokus (ST-S1, KS-B1, HM-B1/B2/S0, SU-B1, SH-B1/B3, SO-B2–B5, AD-B1/B2, TR-B1/B2, RP-B1, HC-B1/B2) | **CONFIRMED** |
| FALSE_POSITIVE keras (klaim salah di kode) | **Tidak ada** pada P0 |
| OVERSTATED (perilaku benar, framing “wajib ubah” berlebih) | SO-B1 freeze; AD-B3/B4; SH-B6/AD-B7 void; HC-S1 seed gudang; KS-C1/HM-C1 sort 500; SC-02/HM-U1 DRY; HM-X3 |
| Missed gap baru (masuk plan execute) | HM-M1, KS-M1, TR-B5b, AD-X1b, RP-B5 fix `?->` |

Plan keputusan **root cause P0** benar. Koreksi plan di bawah.

---

## Per menu (verdict singkat)

### #17 Stok
- **CONFIRMED:** ST-S1/X1 POS `avg_cost`; ST-B1–B4; ST-C2 finally; ST-C4 search group; ST-Q1 summary.
- **FP:** ST-C1 `isDirty` (P2) — Laravel 12 masih dirty di `updated` → abaikan sebagai bug.
- **Missed ringan:** ST-M1 header `p.avg_cost` vs row `inventory_stock.avg_cost` (P2, sudah dekat ST-B5).

### #18 Kartu Stok
- **CONFIRMED:** KS-B1 opening/ending racun `TYPES_NO_QTY` / null WH.
- **OVERSTATED → P2:** KS-C1 sort whitelist (FE hanya sort `tanggal`); SC-02 twin DRY.
- **Missed P1:** **KS-M1** — setelah summary di-skip, **kolom Saldo list** tetap 0 pada baris HPP (menyesatkan). Plan B harus cover label/skip display saldo untuk `TYPES_NO_QTY`.

### #19 Pergerakan HPP
- **CONFIRMED:** HM-B1/B2/S0/X1.
- **OVERSTATED → P2:** HM-C1 sort; HM-U1 DRY; HM-X3 margin gate.
- **Missed P1:** **HM-M1** — plan “FE stop kirim flag” **tidak cukup**; BE `hppSummary` harus **abaikan** `hpp_changed_only` (client lain tetap bisa kirim).

### #20 Register Unit Serial
- Semua P0/P1 fokus **CONFIRMED**. SU-M1 = warn pakai `pagination.total` (sudah di plan warn).

### #21 Koreksi HPP Serial
- SH-B1/B2/B3/S1/X1 **CONFIRMED**.
- **OVERSTATED:** SH-B6 void (BY DESIGN docs).
- Create reject non-tersedia vs approve silent-skip = jendela SH-B1 (sudah ditutup keputusan 422).

### #22 Stock Opname
- SO-B2–B5/S1 **CONFIRMED**.
- **OVERSTATED:** SO-B1 sebagai “wajib freeze” — perilaku set-at-approve **CONFIRMED** + ditest; plan BY DESIGN + banner **benar**. Turunkan framing audit ke P1 produk/SOP (bukan P0 defect).
- **OVERSTATED:** SO-B7 surplus HPP (BY DESIGN ADJUSTMENT_IN).

### #23 Adjustment
- AD-B1/B2 **CONFIRMED** (+ skip juga lewati serial + HPP_RESET → **AD-X1b** understated).
- **OVERSTATED / BY DESIGN:** AD-B3 debit cost; AD-B4 no Metode A; AD-B7 no void.

### #24 Transfer
- TR-B1/B2 **CONFIRMED**.
- **Missed P1:** **TR-B5b** — `$stocksFrom[$id]->qty ?? 0` crash bila row stok hilang (= twin RP-B5). Fix `?->qty ?? 0`.

### #25 Repack
- RP-B1/B4/B5/S1 **CONFIRMED** (S1 nyata untuk **gudang** — beda HC-S1).
- Plan fix RP-B5 harus **`?->qty ?? 0`**, bukan hanya kurung `()`.

### #26 Koreksi HPP retail
- HC-B1/B2/B3/X1 **CONFIRMED**.
- **OVERSTATED:** HC-S1 untuk seed **gudang** (tidak punya `hpp.*`); tetap valid untuk role custom `hpp.*` tanpa `view_hpp` → strip tetap di plan.

---

## Koreksi plan (terkunci ulang)

1. **Bagian C / HM-M1:** `getHppSummary` **abaikan** `hpp_changed_only` di BE (bukan hanya FE).
2. **Bagian B / KS-M1:** List Kartu Stok — Saldo kosong/`—` untuk baris `TYPES_NO_QTY` (atau jangan tampilkan running balance menipu).
3. **Bagian F:** SO-B1 tetap BY DESIGN warn (bukan freeze); severity audit = P1 SOP.
4. **Bagian G / AD-X1b:** Hapus skip-card menutup juga skip serial/HPP_RESET — tes race harus assert unit+verify.
5. **Bagian H / TR-B5b:** Nullsafe stock from (+ to bila perlu) mirror Repack.
6. **Bagian I:** RP-B5 = `?->qty ?? 0`.
7. **Bagian J:** HC-S1 wording = custom role / defense-in-depth (bukan “gudang bocor”).
8. **Demote execute P2:** KS-C1/HM-C1 sort footgun; SC-02 twin full merge (tetap twin shell minimal OK).

## Execute delta (tambah ke todos)

- BE ignore `hpp_changed_only` on hpp-summary  
- KS list Saldo for NO_QTY rows  
- Transfer + Repack `?->qty`  
- AD race test covers serial skip path  
- Docs mark OVERSTATED / BY DESIGN on SO-B1, AD-B3/B4, HC-S1 gudang  

## Tidak mengubah

Semua P0 race lock header, strip HPP, serial contract opname, HM WH OR null, SU paginate-loop, SH approve 422, HC draft lock.
