# Audit menu — 14 Metode Pembayaran

> **Status:** patched (scope A)  
> **SSoT kode:** `ImportController` · `CheckoutSalesAction` / calc biaya · `MetodePembayaransExport` · `MetodePembayaranPage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Metode Pembayaran (`/app/master/metode-pembayaran`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| MP-I1 | P0 | Import map `persen`/`percent` → enum DB **`percent`** | FIXED |
| MP-S1 | P0 | Checkout **recalc** `biaya_tambahan` dari master (jangan trust FE) | FIXED |
| MP-S2 | P1 | Allow-list metode bayar (terminal / jenis diizinkan) | FIXED |
| MP-E1 | P1 | Export label biaya: cek `percent` (bukan `persen`) | FIXED |
| MP-E2 | P1 | Export FE = `metode-bayar.view` | FIXED |
