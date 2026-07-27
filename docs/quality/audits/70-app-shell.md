# Audit — 70 App Shell (layout / menu / auth chrome)

> **Status:** draft (audit + P0 RolePage sudah lewat #69)  
> **SSoT kode:** `AppLayout` · `AppMenu` · `AppTopbar` · `AppFooter` · `AppSidebar` · guards router · `Login`  
> **Cross:** [69-permission-matrix-checklist.md](69-permission-matrix-checklist.md) · [65-global-settings.md](65-global-settings.md)  
> **Jika konflik:** ikuti kode.

## Scope

Chrome aplikasi (bukan halaman bisnis): layout, sidebar visibility, topbar branding global, footer, login, session/guard UX.

## Temuan utama

| ID | Sev | Temuan | Status |
|----|-----|--------|--------|
| SH-1 | P0 | RolePage matrix cache abadi → checklist stale | FIXED (#69) |
| SH-2 | info | Super-admin FE `can()` bypass ≠ DB checklist | Docs only — by design |
| SH-3 | info | Menu `visible` vs `meta.permission` harus selaras seeder | Monitor via #69 ensure |
| SH-4 | info | Logo/nama Login + Topbar = **global** `settings.store` (bukan terminal override) | By design (#71) |
| SH-5 | P2 | Session banner / FOUC theme — tidak di-patch gelombang ini | Deferred |

## Batas branding

Shell **tidak** memakai override terminal (`store_*`). Identitas outlet hanya dokumen POS — lihat [71-terminal-store-branding.md](71-terminal-store-branding.md).

## Sisa (opsional)

- Audit mendalam AppConfigurator / preferences FOUC
- Harmonisasi label menu vs permission name di seeder
