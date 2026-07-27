# Audit — 69 Permission matrix & checklist

> **Status:** patched (2026-07-28)  
> **SSoT kode:** `RolePermissionSeeder.php` · `EnsurePermissionsCommand.php` · `RolePage.vue` · `RoleController`  
> **Cross:** [64-role.md](64-role.md) · AppMenu visibility via `authStore.can`  
> **Jika konflik:** ikuti kode.

## Ringkas

Checklist permission di Role create/edit diisi dari **baris tabel `permissions`** (`GET /roles/permissions`), dikelompokkan prefix. Prefix tanpa row = grup hilang di UI. Sidebar menu **bukan** sumber checklist: `authStore.can` (super-admin FE selalu `true`) bisa menampilkan menu Penjualan sementara checklist kosong.

## Command aman (produksi / VPS)

```bash
php artisan permissions:ensure
```

| Melakukan | Tidak melakukan |
|-----------|-----------------|
| Insert permission catalog yang belum ada | Sync role `admin` / `kasir` / `gudang` |
| Sync pivot **super-admin** saja | Wipe custom role matrices |
| Reset cache Spatie | Ubah assignment user |

Seeder penuh `RolePermissionSeeder::run()` = fresh install saja (ikut seed default roles).

## API / FE

| Surface | Perilaku |
|---------|----------|
| `GET /roles/permissions` | Katalog dari DB; filter elektronik bila OFF |
| `RolePage.loadPermissions` | **Selalu refetch** (tanpa cache abadi) |
| Super-admin FE `can()` | Bypass → menu tampil meski matrix kosong |

## Patched

| ID | Fix |
|----|-----|
| PM-1 | `catalog()` / `ensurePermissions()` / `syncSuperAdmin()` dipisah dari sync default roles |
| PM-2 | Artisan `permissions:ensure` |
| PM-3 | RolePage hapus forever-cache matrix |

## Ops

Setelah deploy versi baru yang menambah permission: jalankan `permissions:ensure` di tiap environment. **Jangan** re-run seeder penuh di DB yang sudah punya role custom.
