# Audit menu — 16 Print Barcode

> **Status:** patched (scope A; checkbox eksplisit 2026-07-30)  
> **SSoT kode:** `PrintBarcodePage.vue` · `PosController` product-by-barcode · permission `produk.print-barcode`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Print Barcode (`/app/master/print-barcode`).

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| PB-X1 | P0 | POS scan: match `barcode` **atau** `kode_produk` | FIXED |
| PB-W1 | P1 | Print tanpa field barcode: **boleh** + warning UI | FIXED |
| PB-UI1 | P1 | `Column selectionMode="multiple"` hilang di production build — ganti Checkbox eksplisit (sama Register Serial) | FIXED |

Keputusan produk Scope A: label boleh pakai fallback `kode_produk`; scan harus menerima keduanya.
