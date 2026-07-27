# Aturan wajib (AI Agent)

> **Status:** canonical  
> **SSoT kode:** seluruh monorepo  
> **Jika konflik:** ikuti kode.

## Single Source of Truth

Urutan keputusan:

1. **Kode** di `syilex/` + `syilex-frontend/`
2. Global Settings (`settings` / `SettingService`)
3. Docs domain (`docs/domain/…`)
4. Docs agent ini

Konflik/ambigu → **STOP dan tanya user**. Jangan berasumsi.

## No assumption

- Setiap tindakan berbasis perintah eksplisit user ATAU docs/kode valid
- Instruksi tidak jelas → minta klarifikasi dulu

## Reuse dulu

- Cek [`syilex-frontend/src/components/common/`](../../syilex-frontend/src/components/common/) dan [`composables/`](../../syilex-frontend/src/composables/) sebelum bikin baru
- Jangan buat component/composable baru tanpa perlu / tanpa approval bila scope besar

## Design & DRY

- Konsistensi UI prioritas tinggi
- Jangan duplikasi pola tanpa alasan sistematis

## Cross-module

- Perubahan berdampak modul lain → identifikasi dependensi dulu
- Relasi tidak dipahami → STOP dan tanya

## Permission

- Endpoint create/update: permission eksplisit
- Update [`RolePermissionSeeder.php`](../../syilex/database/seeders/RolePermissionSeeder.php) (bukan `UserSeeder`)

## POS Kasir

- Ubah `PosKasirPage` / alur checkout POS hanya untuk bug / wave audit eksplisit
- Kasir responsive (Wave R2): `<md` toggle Katalog|Keranjang; Drawer **Lainnya** untuk Disc/Biaya/Hapus; `md+` dual-pane

## Task completion

Setiap tugas selesai WAJIB:

1. Ringkasan perubahan (faktual)
2. Status (selesai / tertunda / butuh klarifikasi)
3. Tanya apakah docs perlu di-update

## Tech stack (ringkas)

| Layer | Stack |
|-------|--------|
| Backend | Laravel 12, MySQL 8, Sanctum, Spatie Permission, queue `database` |
| Frontend | Vue 3, Pinia, Vue Router, PrimeVue 4, Tailwind 4 |
| Form FE | `validate()` lokal + `errors` — **bukan** vee-validate/zod |
| Test DB | `phpunit.xml` → `posip_db_test` |
