# Audit menu — 12 Kategori Customer

> **Status:** patched (scope A)  
> **SSoT kode:** trait yang sama dengan Tipe · `KategoriCustomerPage.vue` · checkout + promo category  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Klasifikasi Customer → Kategori (`/app/master/kategori-customer`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| KC-S1 | P0 | Mirror TC-S1 (trait + FE omit diskon) | FIXED |
| KC-B1 | P1 | Checkout abaikan diskon kategori inactive | FIXED |
| KC-X1 | P1 | Delete diblok jika masih dipakai promo | FIXED |
| KC-E1 | P1 | Export FE = `kategori-customer.view` | FIXED |
