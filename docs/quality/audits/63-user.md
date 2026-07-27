# Audit menu — 63 Pengaturan → User

> **Status:** patched (scope P0+P1)  
> **SSoT kode:** `UserController` + `UserPage.vue` + `AppTopbar.vue`  
> **Jika konflik:** ikuti kode, lalu update dokumen ini.  
> **Urutan:** Pengaturan → User (`/app/pengaturan/user`).  
> **Plan:** `audit_pengaturan_user_78b576d4.plan.md`

## Inventory

| Area | Path |
|------|------|
| BE | `syilex/app/Http/Controllers/Api/V1/UserController.php` |
| FE | `syilex-frontend/src/views/pengaturan/UserPage.vue` |
| Cross | `AppTopbar.vue`, `UploadController`, POS `active_user_id` / shifts, Sanctum |
| Middleware | `EnsureUserIsActive` (`active.user`) on `auth:sanctum` group |
| Perms | `user.view\|create\|update\|delete` |
| Tests | `tests/Feature/Pengaturan/UserCrudTest.php`, `Authz/RoleUserPrivilegeEscalationTest.php` |

## Gap P0 + P1 (IN_SCOPE)

| ID | Sev | Ringkas | Status |
|----|-----|---------|--------|
| USR-BE-1 | P0 | destroy/deactivate/role-loss clear POS occupancy | FIXED |
| USR-BE-2 | P0 | update block demote/deactivate last active super-admin + self-role | FIXED |
| USR-BE-3 | P0 | `EnsureUserIsActive` on `auth:sanctum` | FIXED |
| USR-BE-4 | P1 | deactivate → `releaseUserFromPos` | FIXED |
| USR-BE-6 | P1 | activity log on role assign/sync | FIXED |
| USR-BE-7 | P1 | upload avatars/users = `user.create\|\|user.update` | FIXED |
| USR-BE-8 | P1 | `/users/list?permission=` whitelist `pos.access` | FIXED |
| USR-BE-10 | P1 | role-loss unassign → full POS release | FIXED |
| USR-BE-11 | P1 | store/update/destroy/toggle transactional | FIXED |
| USR-FE-1 | P1 | Topbar Edit Profile gated `user.view`+`user.update` | FIXED |
| USR-FE-2 | P1 | self status/role disabled in form | FIXED |
| USR-FE-4 | P1 | edit row refetch via `usersApi.get` | FIXED |
| USR-TEST | P1 | feature coverage P0/P1 | FIXED |

## OUT / P2 (docs only)

FormRequest extract; password_confirmation / PIN denylist; restore/trash API; export Excel/PDF; full `useMasterCrud` rewrite; DetailDialog `created_by`; aria polish mass; FE password strength mirror; `countUserRecords` Schema cache; `$fillable` trim `is_protected`; soft-delete email anonymize; protected-user 403 vs 404 masking; migrasi confirm dialog penuh.
