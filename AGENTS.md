# AGENTS.md — POSIP

Instruksi singkat untuk AI agent (Cursor dan lainnya).

## Source of truth

1. **Kode** di `syilex/` dan `syilex-frontend/`
2. Hub docs: [`docs/README.md`](docs/README.md)
3. Panduan agent: [`docs/ai/README.md`](docs/ai/README.md) (mulai [rules](docs/ai/rules.md))
4. Konvensi docs: [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)
5. Rules Cursor: [`.cursor/rules/`](.cursor/rules/)
6. Skills: [`.cursor/skills/`](.cursor/skills/)

Jika dokumen ≠ kode → **ikuti kode**, lalu tanya apakah docs perlu di-update.

## Wajib (ringkas)

- Baca `.cursor/rules/` (core + business-flows + frontend/backend saat relevan)
- Baca `docs/ai/rules.md` + `business-rules.md` sebelum ubah bisnis kritis
- Jangan berasumsi; klarifikasi jika ambigu
- Reuse `components/common` + composables (lihat rule `posip-frontend-components`)
- Permission baru → `RolePermissionSeeder`
- POS Kasir: jangan diubah kecuali bug eksplisit
- Jangan commit/push kecuali user minta

## Paket

| Path | Peran |
|------|--------|
| `syilex/` | Laravel API |
| `syilex-frontend/` | Vue + PrimeVue |
| `docs/` | Dokumentasi hub |
