# Audit menu — 04 Perubahan Data Serial

> **Status:** patched (scope A)  
> **SSoT kode:** `syilex/app/Actions/SerialChange/*` · `SerialChangeController.php` · `syilex-frontend/src/views/master/SerialChangeFormPage.vue` · `docs/domain/serial.md`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Master → Perubahan Data Serial (`/app/master/serial-change`). Gate: `feature.elektronik`.

## Temuan kunci

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| SC-F1 | P0 | FE tidak blok SN duplikat antar baris (domain izinkan kembar/swap) | FIXED |
| SC-F2 | P0 | Rematch edit by **ulid**, bukan `serial_number` | FIXED |

### Catatan SC-F2 (docs only)

Rematch di form sudah memakai kedua bentuk relasi API:

`det.serial_unit?.ulid ?? det.serialUnit?.ulid`

Jangan “perbaiki” lagi ke salah satu casing saja — Laravel bisa serialize `serialUnit` (camel) atau `serial_unit` tergantung konteks.

P1 approve-all-skipped / lock header (SC-A*) — cek kode; bila belum ikut Scope A batch → OPEN.
