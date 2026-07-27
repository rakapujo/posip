# Audit menu — 11 Tipe Customer

> **Status:** patched (scope A)  
> **SSoT kode:** trait discount validation · `TipeCustomerPage.vue` · `CheckoutSalesAction`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Klasifikasi Customer → Tipe (`/app/master/tipe-customer`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| TC-S1 | P0 | Gate `customer-discount.manage` hanya saat diskon berubah; FE omit `diskon_*` jika tanpa perm | FIXED |
| TC-B1 | P1 | Checkout abaikan diskon tipe **inactive** | FIXED |
| TC-X1 | P1 | Delete diblok jika masih dipakai promo | FIXED |
| TC-E1 | P1 | Export FE = `tipe-customer.view` | FIXED |
