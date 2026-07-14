# POSIP by Siapngeweb

Frontend (Vue 3 + Vite) untuk aplikasi **Point of Sales (POSIP)**.

- **Backend:** Laravel di `../syilex/`
- **API base URL:** `/api/v1` (lihat `.env` → `VITE_API_URL`)
- Build di-deploy ke `syilex/public/` (via `deploy.sh` atau `npm run build` manual)

## Development

```bash
npm install
npm run dev          # http://localhost:5173
```

## Testing

```bash
npm run test:unit    # unit tests (print thermal, policy, isolation)
npm run build        # compile production
npm run lint         # ESLint
npx playwright test  # E2E — butuh backend + DB (lihat tests/README.md)
```

Dokumentasi lengkap: [`tests/README.md`](tests/README.md) · matrix cetak: [`../docs/print-support-matrix.md`](../docs/print-support-matrix.md)

## Build & Deploy

```bash
bash deploy.sh       # npm run build + copy dist/ ke ../syilex/public/
```

UI: [PrimeVue](https://primevue.org) + Tailwind CSS.
