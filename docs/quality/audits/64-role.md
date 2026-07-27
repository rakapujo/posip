# Audit menu — 64 Pengaturan → Role & Permission

> **Status:** patched (scope P0+P1)  
> **SSoT kode:** `RoleController` + `GuardsRoleAssignments` + `RolePage.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Pengaturan → Role (`/app/pengaturan/role`).  
> **Plan:** `audit_pengaturan_role_aec6f7f0.plan.md`

## Inventory

| Area | Path |
|------|------|
| BE | `syilex/app/Http/Controllers/Api/V1/RoleController.php` |
| Trait | `syilex/app/Http/Controllers/Concerns/GuardsRoleAssignments.php` |
| FE | `syilex-frontend/src/views/pengaturan/RolePage.vue` |
| Perms | `role.view\|create\|update\|delete` |
| Tests | `tests/Feature/Pengaturan/RoleCrudTest.php`, `Authz/RoleUserPrivilegeEscalationTest.php` |

## Gap P0 + P1 (IN_SCOPE)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| ROL-BE-1 | P0 | update/destroy assert existing role perms (ceiling); serial carve-out if elektronik OFF | FIXED |
| ROL-BE-1b | P0 | non-SA cannot PUT role `super-admin` | FIXED |
| ROL-BE-2 | P1 | destroy txn + lock; assignee count `model_type=User` incl soft-deleted | FIXED |
| ROL-BE-3 | P1 | activity log on destroy | FIXED |
| ROL-BE-4 | P1 | store/update/destroy transactional | FIXED |
| ROL-BE-5 | P1 | `GET /roles/permissions` = `canAny(role.create\|update)` | FIXED |
| ROL-FE-1 | P0 | chips / selectAll / group = grantable only; superior role read-only | FIXED |
| ROL-FE-2 | P0 | `authStore.fetchUser()` after edit own role | FIXED |
| ROL-FE-3 | P1 | name regex client `^[a-z0-9-]+$` | FIXED |
| ROL-FE-4 | P1 | Simpan disabled for SA / superior / empty matrix | FIXED |
| ROL-FE-5 | P1 | delete disabled if `users_count > 0` | FIXED |
| ROL-FE-6 | P1 | block dialog if matrix load fails | FIXED |
| ROL-TEST | P1 | feature coverage P0/P1 | FIXED |

## OUT / P2 (docs only)

Built-in lock rename `admin`/`kasir`/`gudang`; FormRequest; DetailDialog view-only; a11y mass; export; invalidate permission cache on elektronik toggle; sort `users_count`; full `useMasterCrud` rewrite.
