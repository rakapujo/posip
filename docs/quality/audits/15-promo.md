# Audit menu — 15 Promo

> **Status:** patched (scope A)  
> **SSoT kode:** `PromoController` show/`makeVisible` · `PromoFormPage.vue` · active-promos  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Promo (`/app/master/promo`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| PR-E1 | P0 | Show: `makeVisible('id')` pada nest scope (edit tidak wipe scope) | FIXED |
| PR-B1 | P1 | FE tier key `min_qty` konsisten dengan BE | FIXED |
| PR-P1 | P1 | `activePromos` expose `id` yang dibutuhkan FE | FIXED |
| PR-S1 | P2 | Kasir `override_promo` = by design (zero slot DB) | BY DESIGN |

## Mobile DetailDialog (2026-07-30)

Baris diskon (Target / Min Qty / Diskon 1–4): `lg+` tabel; `<lg` kartu per baris.
